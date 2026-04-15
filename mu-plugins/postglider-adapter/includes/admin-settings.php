<?php
/**
 * PostGlider Adapter — Admin settings page
 *
 * Adds a "PostGlider" settings page under Settings in WP Admin.
 * On Multisite, each subsite admin configures their own JWT.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function pg_admin_menu() {
    add_options_page(
        'PostGlider Gallery',
        'PostGlider',
        'manage_options',
        'postglider-settings',
        'pg_settings_page'
    );
}
add_action( 'admin_menu', 'pg_admin_menu' );

function pg_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    if ( isset( $_POST['pg_save'] ) && check_admin_referer( 'pg_settings' ) ) {
        pg_set_option( 'supabase_url', sanitize_url( $_POST['pg_supabase_url'] ?? '' ) );
        pg_set_option( 'supabase_jwt', sanitize_text_field( $_POST['pg_supabase_jwt'] ?? '' ) );
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    $url = esc_attr( pg_get_option( 'supabase_url' ) ?: '' );
    $jwt = esc_attr( pg_get_option( 'supabase_jwt' ) ?: '' );
    ?>
    <div class="wrap">
        <h1>PostGlider Gallery Settings</h1>
        <form method="post">
            <?php wp_nonce_field( 'pg_settings' ); ?>
            <table class="form-table">
                <tr>
                    <th><label for="pg_supabase_url">Supabase Project URL</label></th>
                    <td>
                        <input name="pg_supabase_url" id="pg_supabase_url" type="url"
                               class="regular-text" value="<?php echo $url; ?>"
                               placeholder="https://xxxx.supabase.co" />
                        <p class="description">Your PostGlider project URL — same one used in the app.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="pg_supabase_jwt">Client JWT</label></th>
                    <td>
                        <input name="pg_supabase_jwt" id="pg_supabase_jwt" type="password"
                               class="large-text" value="<?php echo $jwt; ?>" />
                        <p class="description">Long-lived JWT issued from your PostGlider account. Scopes search to your images only.</p>
                    </td>
                </tr>
            </table>
            <p>
                <strong>Search endpoint (configure in SearchIQ):</strong><br>
                <code><?php echo esc_url( rest_url( 'postglider/v1/search' ) ); ?></code>
            </p>
            <?php submit_button( 'Save Settings', 'primary', 'pg_save' ); ?>
        </form>
    </div>
    <?php
}
