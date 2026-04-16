<?php
/**
 * PostGlider Adapter — Auto-updater
 *
 * Hooks into WordPress's native plugin update mechanism.
 * metadata.json is fetched on every update check — WP itself rate-limits
 * these to once per 12 hours, so no separate transient is needed.
 * "Check Again" in Network Admin → Updates always picks up new versions.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'POSTGLIDER_ADAPTER_METADATA_URL',
    'https://raw.githubusercontent.com/kenlyle2/postglider-wp/main/metadata.json'
);

add_filter( 'pre_set_site_transient_update_plugins', 'pg_check_for_update' );

function pg_check_for_update( $transient ) {
    if ( empty( $transient->checked ) ) return $transient;

    $metadata = pg_fetch_metadata();
    if ( ! $metadata || empty( $metadata->version ) ) return $transient;

    $plugin_file = 'postglider-adapter/postglider-adapter.php';

    if ( version_compare( $metadata->version, POSTGLIDER_ADAPTER_VERSION, '>' ) ) {
        $transient->response[ $plugin_file ] = (object) [
            'slug'         => 'postglider-adapter',
            'plugin'       => $plugin_file,
            'new_version'  => $metadata->version,
            'url'          => 'https://github.com/kenlyle2/postglider-wp',
            'package'      => $metadata->download_url,
            'requires'     => $metadata->requires      ?? '6.0',
            'requires_php' => $metadata->requires_php  ?? '8.0',
            'sections'     => (array) ( $metadata->sections ?? new stdClass() ),
        ];
    }

    return $transient;
}

add_filter( 'plugins_api', 'pg_plugin_info', 10, 3 );

function pg_plugin_info( $result, $action, $args ) {
    if ( $action !== 'plugin_information' || ( $args->slug ?? '' ) !== 'postglider-adapter' ) return $result;
    $metadata = pg_fetch_metadata();
    if ( ! $metadata ) return $result;
    return (object) [
        'name'          => $metadata->name         ?? 'PostGlider Gallery Adapter',
        'slug'          => 'postglider-adapter',
        'version'       => $metadata->version,
        'requires'      => $metadata->requires     ?? '6.0',
        'requires_php'  => $metadata->requires_php ?? '8.0',
        'author'        => '<a href="https://postglider.com">PostGlider</a>',
        'download_link' => $metadata->download_url,
        'sections'      => (array) ( $metadata->sections ?? new stdClass() ),
    ];
}

function pg_fetch_metadata(): ?stdClass {
    $response = wp_remote_get( POSTGLIDER_ADAPTER_METADATA_URL, [
        'timeout'    => 10,
        'user-agent' => 'PostGlider/' . POSTGLIDER_ADAPTER_VERSION . '; ' . get_bloginfo( 'url' ),
    ] );
    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) return null;
    $data = json_decode( wp_remote_retrieve_body( $response ) );
    return $data ?: null;
}
