<?php
/**
 * PostGlider Adapter — Auth / option helpers
 *
 * On a standard single-site install, options are stored with get_option().
 * On WordPress Multisite, each subsite stores its own JWT so images are
 * scoped to that client's PostGlider account.
 *
 * Usage:
 *   pg_get_option( 'supabase_url' )  → string|false
 *   pg_get_option( 'supabase_jwt' )  → string|false
 *   pg_set_option( 'supabase_jwt', $token )
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Read a PostGlider option, multisite-aware.
 */
function pg_get_option( string $key ) {
    $prefixed = 'postglider_' . $key;
    if ( is_multisite() ) {
        return get_blog_option( get_current_blog_id(), $prefixed, false );
    }
    return get_option( $prefixed, false );
}

/**
 * Write a PostGlider option, multisite-aware.
 */
function pg_set_option( string $key, $value ): bool {
    $prefixed = 'postglider_' . $key;
    if ( is_multisite() ) {
        return update_blog_option( get_current_blog_id(), $prefixed, $value );
    }
    return update_option( $prefixed, $value );
}
