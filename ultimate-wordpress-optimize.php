<?php
/**
 * Plugin Name: Ultimate WordPress Optimize
 * Plugin URI: https://github.com/tuend-work/ultimate-wordpress-optimize
 * Description: Universal Flat Indexing, Redis Caching, and OpenSearch engine to supercharge Custom Post Types search & filtering.
 * Version: 1.0.2
 * Author: Tuend Work & Antigravity
 * Author URI: https://github.com/tuend-work
 * License: GPL2
 * Text Domain: ultimate-wordpress-optimize
 * Domain Path: /languages
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

define('UWO_VERSION', '1.0.2');
define('UWO_PATH', str_replace('\\', '/', plugin_dir_path(__FILE__)));
define('UWO_URL', plugin_dir_url(__FILE__));

// Diagnostics check to prevent hard Fatal Errors and show dynamic feedback
if (!@file_exists(UWO_PATH . 'includes/class-uwo-activator.php')) {
    $available_files = @scandir(UWO_PATH);
    wp_die(
        '<h3>Ultimate WordPress Optimize - Diagnostic Report</h3>' .
        '<p><strong>Error:</strong> Cannot find the core file <code>includes/class-uwo-activator.php</code> on your server.</p>' .
        '<p><strong>Plugin Path:</strong> <code>' . esc_html(UWO_PATH) . '</code></p>' .
        '<p><strong>Files present in this folder on your server:</strong></p>' .
        '<pre>' . esc_html(print_r($available_files, true)) . '</pre>' .
        '<p><em>Please make sure you have uploaded the entire plugin folder (including <code>includes/</code>, <code>admin/</code>, and <code>templates/</code> directories) to your WordPress plugins folder.</em></p>'
    );
}

// Explicitly import all plugin files with exact names and paths
require_once UWO_PATH . 'includes/class-uwo-activator.php';
require_once UWO_PATH . 'includes/class-uwo-deactivator.php';
require_once UWO_PATH . 'includes/class-uwo-database.php';
require_once UWO_PATH . 'includes/class-uwo-redis.php';
require_once UWO_PATH . 'includes/class-uwo-open-search.php';
require_once UWO_PATH . 'includes/class-uwo-sync-engine.php';
require_once UWO_PATH . 'includes/class-uwo-query-engine.php';
require_once UWO_PATH . 'includes/class-uwo-rest-api.php';
require_once UWO_PATH . 'admin/class-uwo-admin.php';

// Activation & Deactivation Hooks
register_activation_hook(__FILE__, 'uwo_activate_plugin');
register_deactivation_hook(__FILE__, 'uwo_deactivate_plugin');

/**
 * Handle Plugin Activation.
 */
function uwo_activate_plugin() {
    \UWO\Activator::activate();
}

/**
 * Handle Plugin Deactivation.
 */
function uwo_deactivate_plugin() {
    \UWO\Deactivator::deactivate();
}

/**
 * Initialize core components of the plugin.
 */
function uwo_init_plugin() {
    // Load Admin interface if in admin panel
    if (is_admin()) {
        \UWO\Admin::get_instance();
    }

    // Initialize core database listener and dynamic columns
    \UWO\Database::get_instance();

    // Initialize sync engine for capturing post updates
    \UWO\SyncEngine::get_instance();

    // Initialize queries interception
    \UWO\QueryEngine::get_instance();

    // Initialize REST API endpoints
    \UWO\RestApi::get_instance();
}
add_action('plugins_loaded', 'uwo_init_plugin');
