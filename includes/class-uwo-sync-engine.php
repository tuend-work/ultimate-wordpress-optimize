<?php
namespace UWO;

/**
 * SyncEngine listens to WordPress hooks and synchronizes posts/products to the flat index table.
 */
class SyncEngine {

    private static $instance = null;

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
     * Constructor registers the sync hooks.
     */
    private function __construct() {
        // Universal Post Sync: Hook into post updates
        add_action('save_post', array($this, 'handle_save_post'), 20, 3);
        
        // WooCommerce Hooks (to capture specialized product modifications)
        add_action('woocommerce_update_product', array($this, 'sync_post'), 20);
        add_action('woocommerce_save_product_variation', array($this, 'sync_post'), 20);

        // Deletion and Trashing hooks to keep index synchronized
        add_action('before_delete_post', array($this, 'handle_delete_post'), 10);
        add_action('wp_trash_post', array($this, 'handle_delete_post'), 10);
    }

    /**
     * Handler for the standard save_post hook.
     */
    public function handle_save_post($post_id, $post, $update) {
        // Avoid auto-drafts and revisions
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        $this->sync_post($post_id);
    }

    /**
     * Delete an item from the flat index.
     */
    public function handle_delete_post($post_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'uwo_items_index';

        $wpdb->delete($table_name, array('post_id' => $post_id));

        // Sync with Redis if class is present
        if (class_exists('UWO\Redis')) {
            $redis = Redis::get_instance();
            $redis->delete_item($post_id);
        }

        // Sync with OpenSearch if class is present
        if (class_exists('UWO\OpenSearch')) {
            $opensearch = OpenSearch::get_instance();
            $opensearch->delete_item($post_id);
        }
    }

    /**
     * Main sync logic to map standard post metadata and taxonomies to the flat indexing table.
     */
    public function sync_post($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        $post_type = $post->post_type;
        $enabled_types = get_option('uwo_enabled_post_types', array('product'));

        // Handle variations if products are accelerated
        $is_variation = ($post_type === 'product_variation');
        if ($is_variation) {
            if (!in_array('product', $enabled_types, true)) {
                return;
            }
        } else {
            if (!in_array($post_type, $enabled_types, true)) {
                return;
            }
        }

        // 1. Gather SKU, Price, Stock Status (WC Specific or fallback)
        $sku = '';
        $price = null;
        $stock_status = '';
        $parent_id = $post->post_parent;

        if (class_exists('WooCommerce')) {
            if ($post_type === 'product' || $post_type === 'product_variation') {
                $product = wc_get_product($post_id);
                if ($product) {
                    $sku = $product->get_sku();
                    $price = $product->get_price();
                    $stock_status = $product->get_stock_status();
                }
            }
        }

        // Fallbacks if not WooCommerce or if WooCommerce is deactivated
        if (empty($sku)) {
            $sku = get_post_meta($post_id, '_sku', true);
        }
        if (null === $price || '' === $price) {
            $price_meta = get_post_meta($post_id, '_price', true);
            $price = ($price_meta !== '') ? (float) $price_meta : null;
        }
        if (empty($stock_status)) {
            $stock_status = get_post_meta($post_id, '_stock_status', true);
            if (empty($stock_status) && ($post_type === 'product' || $post_type === 'product_variation')) {
                $stock_status = 'instock';
            }
        }

        // 2. Fetch taxonomies and map primary category & dynamic physical columns
        $taxonomies = get_object_taxonomies($post_type, 'names');
        $primary_cat_id = 0;
        $all_terms = array();
        $attrs = array();
        $taxonomy_slugs = array();

        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_post_terms($post_id, $taxonomy, array('fields' => 'all'));
            $slugs = array();
            if (!is_wp_error($terms) && !empty($terms)) {
                foreach ($terms as $term) {
                    $all_terms[] = $term->name;
                    $attrs[] = 'tax_' . $taxonomy . '_' . $term->slug;
                    $slugs[] = $term->slug;

                    // Assign primary category (product_cat for WooCommerce, category for CPT)
                    if ($primary_cat_id === 0 && ($taxonomy === 'product_cat' || $taxonomy === 'category')) {
                        $primary_cat_id = $term->term_id;
                    }
                }
            }
            $taxonomy_slugs[$taxonomy] = $slugs;
        }
        $attributes_filter = implode(' ', $attrs);

        // 3. Assemble Custom Fields and Build search_text
        $meta = get_post_meta($post_id);
        $cf_values = array();
        $custom_fields_payload = array();

        foreach ($meta as $key => $values) {
            if (strpos($key, '_') === 0) {
                continue;
            }
            $value = maybe_unserialize($values[0]);
            $custom_fields_payload[$key] = $value;

            if (is_array($value)) {
                $cf_values[] = implode(' ', array_map('strval', $value));
            } elseif (is_string($value) || is_numeric($value)) {
                $cf_values[] = (string) $value;
            }
        }

        $search_parts = array(
            $post->post_title,
            $sku,
            $post->post_content,
            implode(' ', $all_terms),
            implode(' ', $cf_values)
        );
        $search_text = strip_tags(implode(' ', array_filter($search_parts)));

        // 4. Create premium payload_json for rapid O(1) rendering
        $payload = array(
            'id' => $post_id,
            'parent_id' => $parent_id,
            'post_type' => $post_type,
            'title' => $post->post_title,
            'slug' => $post->post_name,
            'sku' => $sku,
            'price' => $price,
            'stock_status' => $stock_status,
            'permalink' => get_permalink($post_id),
            'featured_image' => get_the_post_thumbnail_url($post_id, 'medium') ?: '',
            'excerpt' => $post->post_excerpt,
            'custom_fields' => $custom_fields_payload,
            'updated_at' => current_time('mysql')
        );
        $payload_json = wp_json_encode($payload);

        // 5. Populate flat MySQL structure
        $db = Database::get_instance();
        
        // Dynamic Column Engine: Check and create columns for existing custom fields on the fly during sync
        $columns = $db->get_table_columns();
        $columns_updated = false;
        
        // 1. Create taxonomy dynamic columns (tax_*)
        foreach ($taxonomy_slugs as $tax_name => $slugs) {
            $column_name = 'tax_' . sanitize_key($tax_name);
            if (!in_array($column_name, $columns, true)) {
                $db->add_dynamic_column($column_name);
                $columns_updated = true;
            }
        }

        // 2. Create Custom Field dynamic columns (cf_*)
        foreach ($custom_fields_payload as $key => $val) {
            $column_name = 'cf_' . sanitize_key($key);
            if (!in_array($column_name, $columns, true)) {
                $db->add_dynamic_column($column_name);
                $columns_updated = true;
            }
        }

        if ($columns_updated) {
            $columns = $db->get_table_columns(true);
        }

        $data = array(
            'post_id' => $post_id,
            'parent_id' => $parent_id,
            'post_type' => $post_type,
            'sku' => $sku,
            'title' => $post->post_title,
            'slug' => $post->post_name,
            'price' => $price,
            'stock_status' => $stock_status,
            'primary_cat_id' => $primary_cat_id ?: null,
            'attributes_filter' => $attributes_filter,
            'payload_json' => $payload_json,
            'search_text' => $search_text,
            'updated_at' => current_time('mysql')
        );

        // Map ACF, Taxonomies, and other dynamic columns
        foreach ($columns as $column) {
            if (strpos($column, 'tax_') === 0) {
                $tax_name = substr($column, 4);
                if (isset($taxonomy_slugs[$tax_name])) {
                    $data[$column] = wp_json_encode($taxonomy_slugs[$tax_name]);
                } else {
                    $data[$column] = null;
                }
            } elseif (strpos($column, 'cf_') === 0) {
                $meta_key = substr($column, 3);
                if (isset($custom_fields_payload[$meta_key])) {
                    $val = $custom_fields_payload[$meta_key];
                    if (is_array($val) || is_object($val)) {
                        $data[$column] = wp_json_encode($val);
                    } else {
                        $data[$column] = $val;
                    }
                } else {
                    $data[$column] = null;
                }
            }
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'uwo_items_index';

        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM `{$table_name}` WHERE post_id = %d", $post_id));

        if ($exists) {
            $wpdb->update($table_name, $data, array('post_id' => $post_id));
        } else {
            $wpdb->insert($table_name, $data);
        }

        // 6. Redis Object Cache sync (Invoked dynamically)
        if (class_exists('UWO\Redis')) {
            $redis = Redis::get_instance();
            $redis->sync_item($post_id, $data);
        }

        // 7. OpenSearch Search Sync (Invoked dynamically)
        if (class_exists('UWO\OpenSearch')) {
            $opensearch = OpenSearch::get_instance();
            $opensearch->sync_item($post_id, $data);
        }
    }
}
