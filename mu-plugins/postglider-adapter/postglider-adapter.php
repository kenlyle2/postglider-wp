<?php
/**
 * Plugin Name:       PostGlider Gallery Adapter
 * Plugin URI:        https://postglider.com
 * Description:       Connects your PostGlider AI-tagged Media Vault to WordPress via a searchable REST endpoint.
 * Version:           0.2.1
 * Author:            PostGlider
 * Author URI:        https://postglider.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       postglider-adapter
 * Requires at least: 6.0
 * Requires PHP:      8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'POSTGLIDER_ADAPTER_VERSION', '0.2.1' );
define( 'POSTGLIDER_ADAPTER_DIR', plugin_dir_path( __FILE__ ) );

require_once POSTGLIDER_ADAPTER_DIR . 'includes/auth.php';
require_once POSTGLIDER_ADAPTER_DIR . 'includes/search.php';
require_once POSTGLIDER_ADAPTER_DIR . 'includes/admin-settings.php';
require_once POSTGLIDER_ADAPTER_DIR . 'includes/network-api.php';
require_once POSTGLIDER_ADAPTER_DIR . 'includes/updater.php';
