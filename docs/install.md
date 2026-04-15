# PostGlider Gallery Adapter — Install Guide

## Prerequisites

- WordPress 6.0+ (single-site or multisite)
- SearchIQ plugin installed and activated
- A PostGlider account with images tagged

## 1. Deploy the Supabase edge function

From the `postglider-wp` repo root:

```bash
supabase functions deploy gallery-search --project-ref <your-project-ref>
```

The function uses the existing `user_images` RLS — no additional database changes required beyond the `search_tsv` column (see step 2).

## 2. Add the search index column (one-time migration)

Run this in the Supabase SQL editor or as a migration:

```sql
ALTER TABLE user_images
  ADD COLUMN IF NOT EXISTS search_tsv tsvector
    GENERATED ALWAYS AS (
      to_tsvector('english',
        array_to_string(tags, ' ') || ' ' || coalesce(description, '')
      )
    ) STORED;

CREATE INDEX IF NOT EXISTS idx_user_images_search_tsv
  ON user_images USING gin(search_tsv);
```

## 3. Install the adapter

Copy `mu-plugins/postglider-adapter/` into your WordPress installation:

```
wp-content/mu-plugins/postglider-adapter/
```

No activation needed — mu-plugins load automatically.

## 4. Configure per site

In WP Admin → Settings → PostGlider:

- **Supabase Project URL**: `https://xxxx.supabase.co`
- **Client JWT**: long-lived JWT from your PostGlider account

On Multisite, each subsite configures its own JWT, scoping search to that client's images.

## 5. Configure SearchIQ

In SearchIQ settings, add a custom data source:

- **Type**: REST API
- **Endpoint**: `https://yoursite.com/wp-json/postglider/v1/search`
- **Query param**: `q`
- **Field mapping**: `title`, `description`, `image`, `url`

## Generating a client JWT

> TODO: add a "Generate Gallery Token" button to the PostGlider client dashboard that issues a long-lived, read-only JWT for this purpose.

For now, use the Supabase dashboard → Authentication → Generate a link, or issue one via the service role key scoped to the client's `user_id`.
