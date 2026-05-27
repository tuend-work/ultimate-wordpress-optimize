<?php
namespace UWO;

/**
 * Fired during plugin deactivation.
 */
class Deactivator {

    /**
     * Deactivate the plugin.
     *
     * Clean up transient cache and rewrite rules.
     */
    public static function deactivate() {
        flush_rewrite_rules();
    }
}
