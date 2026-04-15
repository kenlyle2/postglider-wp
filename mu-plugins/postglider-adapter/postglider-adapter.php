<?php
/**
 * Plugin Name:       PostGlider Gallery Adapter
 * Plugin URI:        https://postglider.com
 * Description:       Connects your PostGlider AI-tagged Media Vault to WordPress via a searchable REST endpoint.
 * Version:           0.1.0
 * Author:            PostGlider
 * Author URI:        https://postglider.com
 * License:           Proprietary
 * Text Domain:       postglider-adapter
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/search.php';
require_once __DIR__ . '/includes/admin-settings.php';
require_once __DIR__ . '/includes/network-api.php';
