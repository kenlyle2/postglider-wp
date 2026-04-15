<?php
/**
 * PostGlider Adapter — One-time installer
 *
 * Upload this file to your WordPress root (same folder as wp-config.php),
 * then visit https://mypostglider.website/pg-install.php in a browser.
 * It will create the plugin files and then DELETE ITSELF.
 *
 * DO NOT leave this file on the server. It self-deletes on success,
 * but if something goes wrong remove it manually.
 */

if ( php_sapi_name() === 'cli' ) {
    exit( 'Run from a browser.' );
}

$plugin_dir = __DIR__ . '/wp-content/plugins/postglider-adapter';
$includes   = $plugin_dir . '/includes';

$files = [];

// ── postglider-adapter.php ────────────────────────────────────────────────────
$files[ $plugin_dir . '/postglider-adapter.php' ] = <<<'PHP'
<?php
/**
 * Plugin Name:       PostGlider Gallery Adapter
 * Plugin URI:        https://postglider.com
 * Description:       Connects your PostGlider AI-tagged Media Vault to WordPress via a searchable REST endpoint.
 * Version:           0.2.0
 * Author:            PostGlider
 * Author URI:        https://postglider.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       postglider-adapter
 * Requires at least: 6.0
 * Requires PHP:      8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'POSTGLIDER_ADAPTER_VERSION', '0.2.0' );
define( 'POSTGLIDER_ADAPTER_DIR', plugin_dir_path( __FILE__ ) );

require_once POSTGLIDER_ADAPTER_DIR . 'includes/auth.php';
require_once POSTGLIDER_ADAPTER_DIR . 'includes/search.php';
require_once POSTGLIDER_ADAPTER_DIR . 'includes/admin-settings.php';
require_once POSTGLIDER_ADAPTER_DIR . 'includes/network-api.php';
require_once POSTGLIDER_ADAPTER_DIR . 'includes/updater.php';
PHP;

// ── includes/auth.php ─────────────────────────────────────────────────────────
$files[ $includes . '/auth.php' ] = <<<'PHP'
<?php
/**
 * PostGlider Adapter — Auth / option helpers
 * Multisite-aware: each subsite stores its own gallery token.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function pg_get_option( string $key ) {
    $prefixed = 'postglider_' . $key;
    if ( is_multisite() ) {
        return get_blog_option( get_current_blog_id(), $prefixed, false );
    }
    return get_option( $prefixed, false );
}

function pg_set_option( string $key, $value ): bool {
    $prefixed = 'postglider_' . $key;
    if ( is_multisite() ) {
        return update_blog_option( get_current_blog_id(), $prefixed, $value );
    }
    return update_option( $prefixed, $value );
}
PHP;

// ── includes/search.php ───────────────────────────────────────────────────────
$files[ $includes . '/search.php' ] = <<<'PHP'
<?php
/**
 * PostGlider Adapter — Search handler
 * Proxies /wp-json/postglider/v1/search to the PostGlider gallery-search edge function.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function pg_register_search_route() {
    register_rest_route( 'postglider/v1', '/search', [
        'methods'             => 'GET',
        'callback'            => 'pg_search_handler',
        'permission_callback' => '__return_true',
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

    $images  = $body['images'] ?? [];
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
PHP;

// ── includes/admin-settings.php ───────────────────────────────────────────────
$files[ $includes . '/admin-settings.php' ] = <<<'PHP'
<?php
/**
 * PostGlider Adapter — Admin settings page
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function pg_admin_menu() {
    add_options_page(
        esc_html__( 'PostGlider Gallery', 'postglider-adapter' ),
        esc_html__( 'PostGlider', 'postglider-adapter' ),
        'manage_options',
        'postglider-settings',
        'pg_settings_page'
    );
}
add_action( 'admin_menu', 'pg_admin_menu' );

function pg_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    if ( isset( $_POST['pg_save'] ) && check_admin_referer( 'pg_settings' ) ) {
        pg_set_option( 'supabase_url',  sanitize_url( wp_unslash( $_POST['pg_supabase_url'] ?? '' ) ) );
        pg_set_option( 'gallery_token', sanitize_text_field( wp_unslash( $_POST['pg_gallery_token'] ?? '' ) ) );
        echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'postglider-adapter' ) . '</p></div>';
    }

    $url   = esc_attr( pg_get_option( 'supabase_url' ) ?: '' );
    $token = esc_attr( pg_get_option( 'gallery_token' ) ?: '' );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'PostGlider Gallery Settings', 'postglider-adapter' ); ?></h1>
        <form method="post">
            <?php wp_nonce_field( 'pg_settings' ); ?>
            <table class="form-table">
                <tr>
                    <th><label for="pg_supabase_url"><?php esc_html_e( 'Supabase Project URL', 'postglider-adapter' ); ?></label></th>
                    <td>
                        <input name="pg_supabase_url" id="pg_supabase_url" type="url"
                               class="regular-text" value="<?php echo $url; ?>"
                               placeholder="https://xxxx.supabase.co" />
                    </td>
                </tr>
                <tr>
                    <th><label for="pg_gallery_token"><?php esc_html_e( 'Gallery Token', 'postglider-adapter' ); ?></label></th>
                    <td>
                        <input name="pg_gallery_token" id="pg_gallery_token" type="password"
                               class="large-text" value="<?php echo $token; ?>" />
                        <p class="description"><?php esc_html_e( 'Gallery token from PostGlider Settings. Scopes search to this account\'s Media Vault.', 'postglider-adapter' ); ?></p>
                    </td>
                </tr>
            </table>
            <p>
                <strong><?php esc_html_e( 'Search endpoint:', 'postglider-adapter' ); ?></strong><br>
                <code><?php echo esc_url( rest_url( 'postglider/v1/search' ) ); ?></code>
            </p>
            <?php submit_button( esc_html__( 'Save Settings', 'postglider-adapter' ), 'primary', 'pg_save' ); ?>
        </form>
    </div>
    <?php
}
PHP;

// ── includes/network-api.php ──────────────────────────────────────────────────
$files[ $includes . '/network-api.php' ] = <<<'PHP'
<?php
/**
 * PostGlider Adapter — Network configuration REST endpoint
 * POST /wp-json/postglider/v1/configure-site  (super admin only)
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
            'blog_id'       => [ 'required' => true,  'type' => 'integer', 'sanitize_callback' => 'absint' ],
            'supabase_url'  => [ 'required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_url' ],
            'gallery_token' => [ 'required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
            'anon_key'      => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
        ],
    ] );
} );

function pg_configure_site_handler( WP_REST_Request $request ) {
    $blog_id = $request->get_param( 'blog_id' );
    if ( ! get_blog_details( $blog_id ) ) {
        return new WP_Error( 'pg_invalid_blog', 'Blog not found.', [ 'status' => 404 ] );
    }
    update_blog_option( $blog_id, 'postglider_supabase_url',  $request->get_param( 'supabase_url' ) );
    update_blog_option( $blog_id, 'postglider_gallery_token', $request->get_param( 'gallery_token' ) );

    $anon_key = $request->get_param( 'anon_key' );
    if ( $anon_key ) {
        update_site_option( 'postglider_anon_key', $anon_key );
    }

    return rest_ensure_response( [ 'ok' => true, 'blog_id' => $blog_id ] );
}
PHP;

// ── includes/updater.php ──────────────────────────────────────────────────────
$files[ $includes . '/updater.php' ] = <<<'PHP'
<?php
/**
 * PostGlider Adapter — Auto-updater
 * Polls metadata.json in GitHub; surfaces updates via standard WP admin UI.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'POSTGLIDER_ADAPTER_METADATA_URL',
    'https://raw.githubusercontent.com/kenlyle2/postglider-wp/main/metadata.json'
);

add_filter( 'pre_set_site_transient_update_plugins', 'pg_check_for_update' );

function pg_check_for_update( $transient ) {
    if ( empty( $transient->checked ) ) return $transient;
    $plugin_file = 'postglider-adapter/postglider-adapter.php';
    $metadata = pg_fetch_metadata();
    if ( ! $metadata || empty( $metadata->version ) ) return $transient;
    if ( version_compare( $metadata->version, POSTGLIDER_ADAPTER_VERSION, '>' ) ) {
        $transient->response[ $plugin_file ] = (object) [
            'slug'         => 'postglider-adapter',
            'plugin'       => $plugin_file,
            'new_version'  => $metadata->version,
            'url'          => 'https://github.com/kenlyle2/postglider-wp',
            'package'      => $metadata->download_url,
            'requires'     => $metadata->requires     ?? '6.0',
            'requires_php' => $metadata->requires_php ?? '8.0',
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
    $cached = get_site_transient( 'postglider_adapter_metadata' );
    if ( $cached ) return $cached;
    $response = wp_remote_get( POSTGLIDER_ADAPTER_METADATA_URL, [ 'timeout' => 10 ] );
    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) return null;
    $data = json_decode( wp_remote_retrieve_body( $response ) );
    if ( ! $data ) return null;
    set_site_transient( 'postglider_adapter_metadata', $data, 12 * HOUR_IN_SECONDS );
    return $data;
}
PHP;

// ── Write files ───────────────────────────────────────────────────────────────
$errors = [];

foreach ( [ $plugin_dir, $includes ] as $dir ) {
    if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) ) {
        $errors[] = "Could not create directory: $dir";
    }
}

if ( empty( $errors ) ) {
    foreach ( $files as $path => $content ) {
        if ( file_put_contents( $path, $content ) === false ) {
            $errors[] = "Could not write: $path";
        }
    }
}

// ── Report & self-destruct ────────────────────────────────────────────────────
header( 'Content-Type: text/html; charset=utf-8' );
?><!DOCTYPE html>
<html>
<head><title>PostGlider Installer</title>
<style>body{font-family:sans-serif;max-width:600px;margin:60px auto;padding:0 20px}
.ok{color:#2d6a2d;background:#eaf7ea;padding:16px;border-radius:6px}
.err{color:#7a1c1c;background:#fdecea;padding:16px;border-radius:6px}
code{display:block;margin-top:10px;font-size:13px;word-break:break-all}</style>
</head><body>
<?php if ( empty( $errors ) ) :
    @unlink( __FILE__ ); ?>
    <div class="ok">
        <strong>✓ PostGlider Adapter v0.2.0 installed successfully.</strong>
        <p>Plugin files written to <code>wp-content/plugins/postglider-adapter/</code></p>
        <ol>
            <li>Go to <strong>Network Admin → Plugins</strong></li>
            <li>Find <em>PostGlider Gallery Adapter</em> and click <strong>Network Activate</strong></li>
        </ol>
        <p><em>This installer file has been deleted.</em></p>
    </div>
<?php else : ?>
    <div class="err">
        <strong>✗ Installation failed:</strong>
        <ul><?php foreach ( $errors as $e ) echo '<li>' . htmlspecialchars( $e ) . '</li>'; ?></ul>
        <p>Check directory permissions on <code>wp-content/plugins/</code>.<br>
        <strong>Delete this file manually if it persists.</strong></p>
    </div>
<?php endif; ?>
</body></html>
