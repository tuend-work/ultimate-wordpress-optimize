<?php
/**
 * Plugin Name: Ultimate WordPress Optimize
 * Plugin URI: https://github.com/tuend-work/ultimate-wordpress-optimize
 * Description: Universal Flat Indexing, Redis Caching, and OpenSearch engine to supercharge Custom Post Types search & filtering.
 * Version: 1.0.0
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

define('UWO_VERSION', '1.0.0');
define('UWO_PATH', plugin_dir_path(__FILE__));
define('UWO_URL', plugin_dir_url(__FILE__));

// Register Custom Autoloader for UWO Namespace
spl_autoload_register(function ($class) {
    if (strpos($class, 'UWO\\') !== 0) {
        return;
    }

    $relative_class = substr($class, 4);
    $parts = explode('\\', $relative_class);
    $class_name = array_pop($parts);

    // Convert CamelCase/PascalCase to kebab-case
    $kebab_class_name = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $class_name));
    $kebab_class_name = str_replace('_', '-', $kebab_class_name);
    
    // Correct potential double dashes or other issues
    $kebab_class_name = preg_replace('/-+/', '-', $kebab_class_name);
    
    $file_name = 'class-uwo-' . $kebab_class_name . '.php';

    // Check if the class resides in admin folder or is Admin itself
    if ($class_name === 'Admin' || (!empty($parts) && strtolower($parts[0]) === 'admin')) {
        $file_path = UWO_PATH . 'admin/' . $file_name;
    } else {
        $subpath = '';
        if (!empty($parts)) {
            $subpath = implode('/', array_map(function($part) {
                return strtolower(str_replace('_', '-', preg_replace('/(?<!^)[A-Z]/', '-$0', $part)));
            }, $parts)) . '/';
        }
        $file_path = UWO_PATH . 'includes/' . $subpath . $file_name;
    }

    if (file_exists($file_path)) {
        require_once $file_path;
        return;
    }

    // Dynamic double check fallback: search other directory if not found
    if (strpos($file_path, '/includes/') !== false) {
        $fallback = str_replace('/includes/', '/admin/', $file_path);
        if (file_exists($fallback)) {
            require_once $fallback;
        }
    } elseif (strpos($file_path, '/admin/') !== false) {
        $fallback = str_replace('/admin/', '/includes/', $file_path);
        if (file_exists($fallback)) {
            require_once $fallback;
        }
    }
});

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
