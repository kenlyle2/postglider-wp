<?php
/**
 * PostGlider Adapter — Network configuration REST endpoint
 *
 * POST /wp-json/postglider/v1/configure-site
 * Auth: WordPress Application Password (super admin required)
 * Body: { "blog_id": 4, "supabase_url": "https://...", "gallery_token": "pg_gallery_..." }
 *
 * Called by PostGlider's provisionWpSubsite() immediately after site creation
 * to wire the gallery token to the new subsite without manual admin steps.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
    register_rest_route( 'postglider/v1', '/configure-site', [
        'methods'             => 'POST',
        'callback'            => 'pg_configure_site_handler',
        'permission_callback' => function () {
            return current_user_can( 'manage_network' );
        },
        'args' => [
            'blog_id' => [
                'required'          => true,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
            ],
            'supabase_url' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_url',
            ],
            'gallery_token' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'anon_key' => [
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
    ] );
} );

function pg_configure_site_handler( WP_REST_Request $request ) {
    $blog_id       = $request->get_param( 'blog_id' );
    $supabase_url  = $request->get_param( 'supabase_url' );
    $gallery_token = $request->get_param( 'gallery_token' );

    if ( ! get_blog_details( $blog_id ) ) {
        return new WP_Error( 'pg_invalid_blog', 'Blog not found.', [ 'status' => 404 ] );
    }

    update_blog_option( $blog_id, 'postglider_supabase_url',  $supabase_url );
    update_blog_option( $blog_id, 'postglider_gallery_token', $gallery_token );

    // anon key is network-wide — store once, used by all subsites
    $anon_key = $request->get_param( 'anon_key' );
    if ( $anon_key ) {
        update_site_option( 'postglider_anon_key', $anon_key );
    }

    return rest_ensure_response( [
        'ok'      => true,
        'blog_id' => $blog_id,
    ] );
}
