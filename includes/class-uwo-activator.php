<?php
namespace UWO;

/**
 * Fired during plugin activation.
 */
class Activator {

    /**
     * Activate the plugin.
     *
     * Setup custom index tables, default options, and trigger initial schema creation.
     */
    public static function activate() {
        // Trigger database table creation
        Database::install();

        // Set default options
        if (false === get_option('uwo_enabled_post_types')) {
            update_option('uwo_enabled_post_types', array('product'));
        }

        if (false === get_option('uwo_engine_mode')) {
            update_option('uwo_engine_mode', 'mysql'); // mysql, redis, opensearch
        }

        // Flush rewrite rules for custom REST API or routing
        flush_rewrite_rules();
    }
}
