<?php
namespace UWO;

/**
 * Exposes ultra-fast REST API endpoints for Headless Commerce and AJAX filters.
 */
class RestApi {

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
     * Private constructor registering REST hooks.
     */
    private function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Register premium fast API routes.
     */
    public function register_routes() {
        register_rest_route('uwo/v1', '/search', array(
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => array($this, 'handle_search'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('uwo/v1', '/facets', array(
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => array($this, 'handle_facets'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('uwo/v1', '/reindex', array(
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'handle_ajax_reindex'),
            'permission_callback' => array($this, 'check_admin_permissions'),
        ));
    }

    /**
     * Verify user has admin privileges.
     */
    public function check_admin_permissions() {
        return current_user_can('manage_options');
    }

    /**
     * Handle search request returning high performance JSON payloads.
     */
    public function handle_search($request) {
        $params = $request->get_params();

        // Standardize arguments
        $args = array(
            'post_type'      => !empty($params['post_type']) ? sanitize_text_field($params['post_type']) : 'product',
            'posts_per_page' => !empty($params['posts_per_page']) ? (int) $params['posts_per_page'] : 12,
            'paged'          => !empty($params['page']) ? (int) $params['page'] : 1,
            'search'         => !empty($params['s']) ? sanitize_text_field($params['s']) : '',
            'orderby'        => !empty($params['orderby']) ? sanitize_text_field($params['orderby']) : 'id',
            'order'          => !empty($params['order']) ? sanitize_text_field($params['order']) : 'DESC',
            'meta_query'     => array(),
            'tax_query'      => array(),
        );

        // Price Filters
        if (isset($params['price_min']) || isset($params['price_max'])) {
            $min = isset($params['price_min']) ? (float) $params['price_min'] : 0.0;
            $max = isset($params['price_max']) ? (float) $params['price_max'] : 9999999.0;
            $args['meta_query'][] = array(
                'key'     => '_price',
                'value'   => array($min, $max),
                'compare' => 'BETWEEN',
            );
        }

        // Stock Status
        if (!empty($params['stock_status'])) {
            $args['meta_query'][] = array(
                'key'     => '_stock_status',
                'value'   => sanitize_text_field($params['stock_status']),
                'compare' => '=',
            );
        }

        // Parse custom taxonomy fields (e.g. category or brands)
        if (!empty($params['category'])) {
            $args['tax_query'][] = array(
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => sanitize_text_field($params['category']),
            );
        }

        // Intercept query through Query Engine
        $engine = QueryEngine::get_instance();
        $start_time = microtime(true);
        $results = $engine->query_items($args);
        $execution_time = round((microtime(true) - $start_time) * 1000, 2); // ms

        if (!$results) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => 'Query error or empty index',
            ), 500);
        }

        return new \WP_REST_Response(array(
            'success'        => true,
            'execution_time' => $execution_time . 'ms',
            'total'          => $results['total'],
            'pages'          => ceil($results['total'] / $args['posts_per_page']),
            'current_page'   => $args['paged'],
            'data'           => $results['payloads'],
        ), 200);
    }

    /**
     * Aggregates count facets for attributes, stock status, categories dynamically.
     */
    public function handle_facets($request) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'uwo_items_index';
        $params = $request->get_params();

        $post_type = !empty($params['post_type']) ? sanitize_text_field($params['post_type']) : 'product';

        // Fast Facet Aggregations using optimized MySQL queries
        $start_time = microtime(true);

        // 1. Category Facets
        $cat_facets = $wpdb->get_results($wpdb->prepare("
            SELECT primary_cat_id as cat_id, COUNT(*) as count 
            FROM `{$table_name}` 
            WHERE post_type = %s AND primary_cat_id IS NOT NULL 
            GROUP BY primary_cat_id
        ", $post_type));

        $categories = array();
        if ($cat_facets) {
            foreach ($cat_facets as $facet) {
                $term = get_term($facet->cat_id);
                if ($term && !is_wp_error($term)) {
                    $categories[] = array(
                        'id'    => (int)$facet->cat_id,
                        'name'  => $term->name,
                        'slug'  => $term->slug,
                        'count' => (int)$facet->count
                    );
                }
            }
        }

        // 2. Stock Status Facets
        $stock_facets = $wpdb->get_results($wpdb->prepare("
            SELECT stock_status, COUNT(*) as count 
            FROM `{$table_name}` 
            WHERE post_type = %s AND stock_status IS NOT NULL AND stock_status != ''
            GROUP BY stock_status
        ", $post_type));

        $stock = array();
        if ($stock_facets) {
            foreach ($stock_facets as $facet) {
                $stock[] = array(
                    'status' => $facet->stock_status,
                    'count'  => (int)$facet->count
                );
            }
        }

        // 3. Price Bounds Facets
        $price_bounds = $wpdb->get_row($wpdb->prepare("
            SELECT MIN(price) as min_price, MAX(price) as max_price 
            FROM `{$table_name}` 
            WHERE post_type = %s AND price IS NOT NULL
        ", $post_type));

        $execution_time = round((microtime(true) - $start_time) * 1000, 2); // ms

        return new \WP_REST_Response(array(
            'success'        => true,
            'execution_time' => $execution_time . 'ms',
            'facets'         => array(
                'categories' => $categories,
                'stock'      => $stock,
                'price'      => array(
                    'min' => $price_bounds ? (float)$price_bounds->min_price : 0.0,
                    'max' => $price_bounds ? (float)$price_bounds->max_price : 0.0
                )
            )
        ), 200);
    }

    /**
     * AJAX action to reindex records step-by-step from admin dashboard.
     */
    public function handle_ajax_reindex($request) {
        $params = $request->get_json_params();
        $offset = isset($params['offset']) ? (int) $params['offset'] : 0;
        $batch_size = isset($params['batch_size']) ? (int) $params['batch_size'] : 100;
        $post_types = get_option('uwo_enabled_post_types', array('product'));

        if (empty($post_types)) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => 'No Custom Post Types enabled in settings.',
            ), 400);
        }

        // Get total posts count left to process
        $query_args = array(
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => $batch_size,
            'offset'         => $offset,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC'
        );

        $query = new \WP_Query($query_args);
        $post_ids = $query->posts;
        
        // Re-count all total posts across these types
        $count_args = array(
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids'
        );
        $total_query = new \WP_Query($count_args);
        $total_count = count($total_query->posts);

        $sync = SyncEngine::get_instance();
        $processed = 0;

        foreach ($post_ids as $post_id) {
            $sync->sync_post($post_id);
            $processed++;
        }

        return new \WP_REST_Response(array(
            'success'   => true,
            'processed' => $processed,
            'offset'    => $offset + $processed,
            'total'     => $total_count,
            'finished'  => ($offset + $processed) >= $total_count,
        ), 200);
    }
}
