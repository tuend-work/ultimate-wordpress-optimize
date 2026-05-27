<?php
namespace UWO;

/**
 * Handles Admin Interface, styling enqueue, options registration, and settings saving.
 */
class Admin {

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
     * Private constructor registering actions.
     */
    private function __construct() {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    /**
     * Register Top Level Admin Menu.
     */
    public function register_menu() {
        add_menu_page(
            __('WooCommerce Flat Index', 'ultimate-woocommerce-optimize'),
            __('UWO Optimize', 'ultimate-woocommerce-optimize'),
            'manage_options',
            'uwo-optimize',
            array($this, 'render_dashboard'),
            'dashicons-performance',
            58
        );
    }

    /**
     * Register configuration options.
     */
    public function register_settings() {
        register_setting('uwo_settings_group', 'uwo_enabled_post_types');
        register_setting('uwo_settings_group', 'uwo_engine_mode');
        
        // Redis settings
        register_setting('uwo_settings_group', 'uwo_redis_host');
        register_setting('uwo_settings_group', 'uwo_redis_port');
        register_setting('uwo_settings_group', 'uwo_redis_password');
        register_setting('uwo_settings_group', 'uwo_redis_db');

        // OpenSearch settings
        register_setting('uwo_settings_group', 'uwo_opensearch_host');
        register_setting('uwo_settings_group', 'uwo_opensearch_user');
        register_setting('uwo_settings_group', 'uwo_opensearch_pass');
        register_setting('uwo_settings_group', 'uwo_opensearch_index');
    }

    /**
     * Enqueue stylesheets and scripts with glassmorphism styles and real-time triggers.
     */
    public function enqueue_assets($hook) {
        if ('toplevel_page_uwo-optimize' !== $hook) {
            return;
        }

        // Enqueue Google Font - Outfit for premium visual typography
        wp_enqueue_style('uwo-google-font-outfit', 'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap', array(), UWO_VERSION);

        // Enqueue premium glassmorphism css
        wp_enqueue_style('uwo-admin-premium-css', UWO_URL . 'admin/css/uwo-admin-premium.css', array(), UWO_VERSION);

        // Enqueue JS scripting with AJAX parameters localizations
        wp_enqueue_script('uwo-admin-js', UWO_URL . 'admin/js/uwo-admin.js', array('jquery'), UWO_VERSION, true);

        // Reindex count calculations
        $post_types = get_option('uwo_enabled_post_types', array('product'));
        $total_posts = 0;
        if (!empty($post_types)) {
            $query_args = array(
                'post_type'      => $post_types,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids'
            );
            $query = new \WP_Query($query_args);
            $total_posts = count($query->posts);
        }

        wp_localize_script('uwo-admin-js', 'uwo_admin_params', array(
            'rest_nonce'   => wp_create_nonce('wp_rest'),
            'reindex_url'  => get_rest_url(null, 'uwo/v1/reindex'),
            'total_posts'  => $total_posts,
            'i18n'         => array(
                'indexing'      => __('Indexing in progress...', 'ultimate-woocommerce-optimize'),
                'indexed'       => __('Successfully indexed %s records!', 'ultimate-woocommerce-optimize'),
                'completed'     => __('Indexing Completed!', 'ultimate-woocommerce-optimize'),
                'failed'        => __('Indexing Failed. Try again.', 'ultimate-woocommerce-optimize'),
                'nothing_sync'  => __('No records to sync.', 'ultimate-woocommerce-optimize'),
            )
        ));
    }

    /**
     * Render main dashboard view.
     */
    public function render_dashboard() {
        // Load the dashboard view template
        $template_file = UWO_PATH . 'templates/admin-dashboard.php';
        if (file_exists($template_file)) {
            include_once $template_file;
        } else {
            echo '<div class="wrap"><h2>Template file not found.</h2></div>';
        }
    }
}
