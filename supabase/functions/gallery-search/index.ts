/**
 * gallery-search Edge Function
 *
 * Semantic image search over a PostGlider client's image library.
 * Uses Postgres full-text search on the user_images.search_tsv column.
 *
 * Auth: caller must supply a valid JWT for the client's PostGlider account.
 *       Existing RLS (user_id = auth.uid()) scopes results to that client only.
 *
 * POST body: { q: string, limit?: number }
 * Response:  { images: [{ id, public_url, tags, description, quality_score }] }
 */

import { serve } from 'https://deno.land/std@0.168.0/http/server.ts';
import { createClient } from 'https://esm.sh/@supabase/supabase-js@2';

const CORS = {
    'Access-Control-Allow-Origin':  '*',
    'Access-Control-Allow-Headers': 'authorization, x-client-info, apikey, content-type',
};

serve(async (req) => {
    if (req.method === 'OPTIONS') {
        return new Response('ok', { headers: CORS });
    }

    try {
        const authHeader = req.headers.get('Authorization');
        if (!authHeader) {
            return new Response(JSON.stringify({ error: 'Unauthorized' }), {
                status: 401, headers: { ...CORS, 'Content-Type': 'application/json' },
            });
        }

        const { q, limit = 24 } = await req.json();

        if (!q || typeof q !== 'string' || q.trim().length === 0) {
            return new Response(JSON.stringify({ error: 'q is required' }), {
                status: 400, headers: { ...CORS, 'Content-Type': 'application/json' },
            });
        }

        if (q.length > 200) {
            return new Response(JSON.stringify({ error: 'q exceeds maximum length of 200 characters' }), {
                status: 400, headers: { ...CORS, 'Content-Type': 'application/json' },
            });
        }

        const safeLimit = Math.min(Math.max(1, Number(limit) || 24), 100);

        const supabase = createClient(
            Deno.env.get('SUPABASE_URL')!,
            Deno.env.get('SUPABASE_ANON_KEY')!,
            { global: { headers: { Authorization: authHeader } } },
        );

        const { data, error } = await supabase
            .from('user_images')
            .select('id, public_url, tags, description, quality_score')
            .textSearch('search_tsv', q.trim(), { type: 'websearch' })
            .eq('archived', false)
            .order('quality_score', { ascending: false, nullsFirst: false })
            .limit(safeLimit);

        if (error) throw error;

        return new Response(JSON.stringify({ images: data ?? [] }), {
            headers: { ...CORS, 'Content-Type': 'application/json' },
        });

    } catch (err: any) {
        console.error('[gallery-search]', err.message);
        return new Response(JSON.stringify({ error: err.message ?? 'Search failed' }), {
            status: 500, headers: { ...CORS, 'Content-Type': 'application/json' },
        });
    }
});
