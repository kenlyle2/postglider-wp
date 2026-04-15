<?php
/**
 * Plugin Name: PostGlider Gallery Adapter
 * Description: Connects SearchIQ to your PostGlider AI-tagged image library via Supabase.
 * Version:     0.1.0
 * Author:      PostGlider
 * License:     Proprietary
 *
 * Drop into wp-content/mu-plugins/ — no activation required.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/search.php';
require_once __DIR__ . '/includes/admin-settings.php';
