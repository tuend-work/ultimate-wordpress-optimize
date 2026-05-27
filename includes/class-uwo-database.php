<?php
namespace UWO;

/**
 * Handles all database interactions, schema design, and Dynamic Column Engine.
 */
class Database {

    private static $instance = null;
    private static $columns = null;

    /**
     * Singleton instance.
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor registers hooks for Dynamic Column Engine.
     */
    private function __construct() {
        // Dynamic Column Engine: listen to meta additions and modifications
        add_action('added_post_meta', array($this, 'check_new_meta_field'), 10, 4);
        add_action('updated_post_meta', array($this, 'check_new_meta_field'), 10, 4);
    }

    /**
     * Install base table structure using dbDelta.
     */
    public static function install() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'uwo_items_index';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
            post_type varchar(50) NOT NULL,
            sku varchar(100) DEFAULT NULL,
            title varchar(255) NOT NULL,
            slug varchar(200) NOT NULL,
            price decimal(19,4) DEFAULT NULL,
            stock_status varchar(20) DEFAULT NULL,
            primary_cat_id bigint(20) unsigned DEFAULT NULL,
            attributes_filter varchar(500) DEFAULT NULL,
            payload_json json DEFAULT NULL,
            search_text longtext DEFAULT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY post_id (post_id),
            KEY parent_id (parent_id),
            KEY post_type (post_type),
            KEY sku (sku),
            KEY slug (slug),
            KEY price (price),
            KEY stock_status (stock_status),
            KEY primary_cat_id (primary_cat_id),
            KEY updated_at (updated_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // dbDelta sometimes struggles with FULLTEXT keys. We will add them manually if they do not exist.
        self::ensure_fulltext_indexes();
    }

    /**
     * Ensure FULLTEXT indexes exist on the table.
     */
    private static function ensure_fulltext_indexes() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'uwo_items_index';

        // Check and create FULLTEXT index for title
        $title_index = $wpdb->get_results("SHOW INDEX FROM `{$table_name}` WHERE Key_name = 'idx_title_search'");
        if (empty($title_index)) {
            $wpdb->query("ALTER TABLE `{$table_name}` ADD FULLTEXT INDEX `idx_title_search` (`title`)");
        }

        // Check and create FULLTEXT index for search_text
        $text_index = $wpdb->get_results("SHOW INDEX FROM `{$table_name}` WHERE Key_name = 'idx_text_search'");
        if (empty($text_index)) {
            $wpdb->query("ALTER TABLE `{$table_name}` ADD FULLTEXT INDEX `idx_text_search` (`search_text`)");
        }
    }

    /**
     * Dynamic Column Engine: Detect when a new post meta field is added or updated.
     */
    public function check_new_meta_field($meta_id, $object_id, $meta_key, $_meta_value) {
        // Skip private or system internal fields
        if (strpos($meta_key, '_') === 0) {
            return;
        }

        // Verify the object is a post type we want to accelerate
        $post_type = get_post_type($object_id);
        if (!$post_type) {
            return;
        }

        $enabled_post_types = get_option('uwo_enabled_post_types', array('product'));
        if (!in_array($post_type, $enabled_post_types, true)) {
            return;
        }

        $column_name = 'cf_' . sanitize_key($meta_key);
        
        // Check in-memory column cache to avoid DESCRIBE queries
        if (!$this->column_exists($column_name)) {
            $this->add_dynamic_column($column_name);
        }
    }

    /**
     * Get all columns of the flat index table, caching the result.
     */
    public function get_table_columns($force_refresh = false) {
        if (null !== self::$columns && !$force_refresh) {
            return self::$columns;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'uwo_items_index';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return array();
        }

        $cols = $wpdb->get_col("DESCRIBE `{$table_name}`");
        self::$columns = $cols ? $cols : array();
        return self::$columns;
    }

    /**
     * Check if a column exists in the flat table.
     */
    public function column_exists($column_name) {
        $columns = $this->get_table_columns();
        return in_array($column_name, $columns, true);
    }

    /**
     * Dynamically add a column to the flat table and index it.
     */
    public function add_dynamic_column($column_name) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'uwo_items_index';

        // Strict validation of the column name to prevent SQL Injection
        $column_name = preg_replace('/[^a-zA-Z0-9_]/', '', $column_name);
        if (empty($column_name) || strlen($column_name) > 64) {
            return false;
        }

        // Add column
        $alter_col_sql = "ALTER TABLE `{$table_name}` ADD COLUMN `{$column_name}` LONGTEXT DEFAULT NULL";
        $wpdb->query($alter_col_sql);

        // Add index on prefix length 191 (safe for all InnoDB configurations and utf8mb4)
        $alter_idx_sql = "ALTER TABLE `{$table_name}` ADD INDEX `idx_{$column_name}` (`{$column_name}`(191))";
        $wpdb->query($alter_idx_sql);

        // Flush in-memory column cache
        $this->get_table_columns(true);

        return true;
    }
}
