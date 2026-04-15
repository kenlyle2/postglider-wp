<?php
/**
 * PostGlider Adapter — Auto-updater
 *
 * Hooks into WordPress's native plugin update mechanism.
 * Polls metadata.json in the GitHub repo; when a newer version
 * is found, the standard "Update Available" notice appears in
 * Network Admin → Plugins and one-click update works normally.
 *
 * To release a new version:
 *   1. Bump POSTGLIDER_ADAPTER_VERSION in postglider-adapter.php
 *   2. Update metadata.json (version + download_url + changelog)
 *   3. Rebuild the zip: cd mu-plugins && zip -r ../postglider-adapter.zip postglider-adapter/
 *   4. Create a GitHub release tagged v{VERSION} with the zip as an asset
 *   5. Push — WordPress sites will see the update within 12 hours (or Dashboard → Updates → Check Again)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'POSTGLIDER_ADAPTER_METADATA_URL',
    'https://raw.githubusercontent.com/kenlyle2/postglider-wp/main/metadata.json'
);

/**
 * Inject update info into WordPress's transient before it's consumed.
 */
add_filter( 'pre_set_site_transient_update_plugins', 'pg_check_for_update' );

function pg_check_for_update( $transient ) {
    if ( empty( $transient->checked ) ) {
        return $transient;
    }

    $plugin_file = 'postglider-adapter/postglider-adapter.php';

    $metadata = pg_fetch_metadata();
    if ( ! $metadata || empty( $metadata->version ) ) {
        return $transient;
    }

    if ( version_compare( $metadata->version, POSTGLIDER_ADAPTER_VERSION, '>' ) ) {
        $transient->response[ $plugin_file ] = (object) [
            'slug'        => 'postglider-adapter',
            'plugin'      => $plugin_file,
            'new_version' => $metadata->version,
            'url'         => 'https://github.com/kenlyle2/postglider-wp',
            'package'     => $metadata->download_url,
            'requires'    => $metadata->requires      ?? '6.0',
            'requires_php'=> $metadata->requires_php  ?? '8.0',
            'tested'      => $metadata->tested        ?? $metadata->requires ?? '6.0',
            'sections'    => (array) ( $metadata->sections ?? new stdClass() ),
        ];
    }

    return $transient;
}

/**
 * Provide plugin info for the "View version details" popup.
 */
add_filter( 'plugins_api', 'pg_plugin_info', 10, 3 );

function pg_plugin_info( $result, $action, $args ) {
    if ( $action !== 'plugin_information' || ( $args->slug ?? '' ) !== 'postglider-adapter' ) {
        return $result;
    }

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

/**
 * Fetch and cache metadata.json (12-hour transient).
 */
function pg_fetch_metadata(): ?stdClass {
    $cached = get_site_transient( 'postglider_adapter_metadata' );
    if ( $cached ) return $cached;

    $response = wp_remote_get( POSTGLIDER_ADAPTER_METADATA_URL, [ 'timeout' => 10 ] );
    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return null;
    }

    $data = json_decode( wp_remote_retrieve_body( $response ) );
    if ( ! $data ) return null;

    set_site_transient( 'postglider_adapter_metadata', $data, 12 * HOUR_IN_SECONDS );
    return $data;
}
