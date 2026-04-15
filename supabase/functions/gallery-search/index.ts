/**
 * gallery-search Edge Function
 *
 * Semantic image search over a PostGlider client's image library.
 * Uses Postgres full-text search on the user_images.search_tsv column.
 *
 * Auth (two paths):
 *   1. X-Gallery-Token: pg_gallery_<hex>  — long-lived WP adapter token
 *      Looks up user_id in gallery_tokens table, then queries as that user.
 *   2. Authorization: Bearer <jwt>         — standard Supabase user JWT (dev/testing)
 *
 * POST body: { q: string, limit?: number }
 * Response:  { images: [{ id, public_url, tags, description, quality_score }] }
 */

import { serve } from 'https://deno.land/std@0.168.0/http/server.ts';
import { createClient } from 'https://esm.sh/@supabase/supabase-js@2';

const CORS = {
    'Access-Control-Allow-Origin':  '*',
    'Access-Control-Allow-Headers': 'authorization, x-client-info, apikey, content-type, x-gallery-token',
};

const SUPABASE_URL     = Deno.env.get('SUPABASE_URL')!;
const SUPABASE_ANON    = Deno.env.get('SUPABASE_ANON_KEY')!;
const SUPABASE_SERVICE = Deno.env.get('SUPABASE_SERVICE_ROLE_KEY')!;

serve(async (req) => {
    if (req.method === 'OPTIONS') {
        return new Response('ok', { headers: CORS });
    }

    try {
        const { q, limit = 24 } = await req.json();

        if (!q || typeof q !== 'string' || q.trim().length === 0) {
            return json({ error: 'q is required' }, 400);
        }
        if (q.length > 200) {
            return json({ error: 'q exceeds maximum length of 200 characters' }, 400);
        }

        const safeLimit = Math.min(Math.max(1, Number(limit) || 24), 100);

        // ── Resolve caller identity ───────────────────────────────────────────
        const galleryToken = req.headers.get('x-gallery-token');
        const authHeader   = req.headers.get('authorization');

        let userId: string | null = null;
        const admin = createClient(SUPABASE_URL, SUPABASE_SERVICE, {
            auth: { autoRefreshToken: false, persistSession: false },
        });

        if (galleryToken) {
            // Validate gallery token via service role
            const { data: tokenRow, error: tokenErr } = await admin
                .from('gallery_tokens')
                .select('user_id')
                .eq('token', galleryToken)
                .maybeSingle();

            if (tokenErr || !tokenRow) {
                return json({ error: 'Invalid gallery token' }, 401);
            }

            userId = tokenRow.user_id;

            // Update last_used_at fire-and-forget
            admin.from('gallery_tokens')
                 .update({ last_used_at: new Date().toISOString() })
                 .eq('token', galleryToken)
                 .then(() => {});

        } else if (authHeader) {
            // Standard JWT path
            const supabase = createClient(SUPABASE_URL, SUPABASE_ANON, {
                global: { headers: { Authorization: authHeader } },
            });
            const { data: { user } } = await supabase.auth.getUser();
            if (!user) return json({ error: 'Unauthorized' }, 401);
            userId = user.id;

        } else {
            return json({ error: 'Unauthorized' }, 401);
        }

        // ── Run search scoped to this user via service role ───────────────────
        const { data, error } = await admin
            .from('user_images')
            .select('id, public_url, tags, description, quality_score')
            .eq('user_id', userId)
            .textSearch('search_tsv', q.trim(), { type: 'websearch' })
            .eq('archived', false)
            .order('quality_score', { ascending: false, nullsFirst: false })
            .limit(safeLimit);

        if (error) throw error;

        return json({ images: data ?? [] });

    } catch (err: any) {
        console.error('[gallery-search]', err.message);
        return json({ error: err.message ?? 'Search failed' }, 500);
    }
});

function json(body: unknown, status = 200) {
    return new Response(JSON.stringify(body), {
        status,
        headers: { ...CORS, 'Content-Type': 'application/json' },
    });
}
