<?php
/**
 * PostGlider Adapter — Search handler
 *
 * Proxies /wp-json/postglider/v1/search to the PostGlider
 * gallery-search Supabase Edge Function and formats the
 * response as SearchIQ-compatible JSON.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function pg_register_search_route() {
    register_rest_route( 'postglider/v1', '/search', [
        'methods'             => 'GET',
        'callback'            => 'pg_search_handler',
        'permission_callback' => '__return_true', // public search — auth lives in Supabase
        'args'                => [
            'q' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => function( $val ) {
                    return is_string( $val ) && strlen( $val ) > 0 && strlen( $val ) <= 200;
                },
            ],
            'limit' => [
                'required'          => false,
                'type'              => 'integer',
                'default'           => 24,
                'sanitize_callback' => 'absint',
            ],
        ],
    ] );
}
add_action( 'rest_api_init', 'pg_register_search_route' );

function pg_search_handler( WP_REST_Request $request ) {
    $q     = $request->get_param( 'q' );
    $limit = min( max( 1, (int) $request->get_param( 'limit' ) ), 100 );

    $supabase_url  = pg_get_option( 'supabase_url' );
    $gallery_token = pg_get_option( 'gallery_token' );
    // anon key is network-wide (same for all subsites)
    $anon_key      = get_site_option( 'postglider_anon_key', '' );

    if ( ! $supabase_url || ! $gallery_token || ! $anon_key ) {
        return new WP_Error( 'pg_not_configured', 'PostGlider adapter not configured.', [ 'status' => 503 ] );
    }

    $endpoint = trailingslashit( $supabase_url ) . 'functions/v1/gallery-search';

    $response = wp_remote_post( $endpoint, [
        'timeout' => 10,
        'headers' => [
            'X-Gallery-Token' => $gallery_token,
            'Content-Type'    => 'application/json',
            'apikey'          => $anon_key,
            'Authorization'   => 'Bearer ' . $anon_key,
        ],
        'body' => wp_json_encode( [ 'q' => $q, 'limit' => $limit ] ),
    ] );

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'pg_upstream_error', $response->get_error_message(), [ 'status' => 502 ] );
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 ) {
        return new WP_Error( 'pg_upstream_error', $body['error'] ?? 'Upstream error', [ 'status' => $code ] );
    }

    $images = $body['images'] ?? [];

    // Format for SearchIQ: title, description, image, url
    $results = array_map( function( $img ) {
        $tag_label = implode( ', ', array_slice( $img['tags'] ?? [], 0, 6 ) );
        return [
            'title'       => $tag_label ?: 'Image',
            'description' => $img['description'] ?? '',
            'image'       => $img['public_url'],
            'url'         => $img['public_url'],
            'id'          => $img['id'],
        ];
    }, $images );

    return rest_ensure_response( $results );
}
