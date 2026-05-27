<?php
namespace UWO;

/**
 * FilterBuilder class handles custom filter creation, shortcode rendering, and database values scanning.
 */
class FilterBuilder {

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
     * Constructor registers shortcodes and admin AJAX hooks.
     */
    private function __construct() {
        add_shortcode('uwo_filter', array($this, 'render_filter_shortcode'));
        
        // Admin Ajax actions for saving/deleting filters
        add_action('wp_ajax_uwo_save_filter', array($this, 'ajax_save_filter'));
        add_action('wp_ajax_uwo_delete_filter', array($this, 'ajax_delete_filter'));

        // Frontend styling enqueues
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
    }

    /**
     * Enqueue frontend styling and interactive scripts.
     */
    public function enqueue_frontend_assets() {
        wp_enqueue_style('uwo-google-font-outfit', 'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap');
        
        // We will inject the inline CSS/JS directly or enqueue standard packages
        wp_enqueue_script('jquery');
    }

    /**
     * Scan unique column or taxonomy values.
     */
    public function get_unique_column_values($column) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'uwo_items_index';

        // 1. If it is a registered taxonomy (e.g. product_cat, product_tag)
        if (taxonomy_exists($column)) {
            $terms = get_terms(array(
                'taxonomy'   => $column,
                'hide_empty' => true,
            ));
            $mapped = array();
            if (!is_wp_error($terms) && !empty($terms)) {
                foreach ($terms as $term) {
                    $mapped[$term->slug] = $term->name;
                }
            }
            return $mapped;
        }

        // 2. Sanitize column name strictly to prevent SQL injection
        $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
        if (empty($column)) {
            return array();
        }

        // Verify column exists in table
        $db = Database::get_instance();
        if (!$db->column_exists($column)) {
            return array();
        }

        $results = $wpdb->get_col("SELECT DISTINCT `{$column}` FROM `{$table_name}` WHERE `{$column}` IS NOT NULL AND `{$column}` != '' ORDER BY `{$column}` ASC");
        
        // Clean values in case they are JSON arrays or serialized
        $cleaned = array();
        if ($results) {
            foreach ($results as $res) {
                $decoded = json_decode($res, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $d) {
                        $cleaned[] = (string)$d;
                    }
                } else {
                    $cleaned[] = (string)$res;
                }
            }
        }
        $cleaned = array_unique(array_filter($cleaned));

        // Build unified [value => label] array
        $mapped = array();
        foreach ($cleaned as $val) {
            if ($column === 'primary_cat_id') {
                $term = get_term((int)$val);
                if ($term && !is_wp_error($term)) {
                    $mapped[$val] = $term->name;
                } else {
                    $mapped[$val] = $val;
                }
            } elseif ($column === 'stock_status') {
                if ($val === 'instock') {
                    $mapped[$val] = __('In Stock', 'ultimate-wordpress-optimize');
                } elseif ($val === 'outofstock') {
                    $mapped[$val] = __('Out of Stock', 'ultimate-wordpress-optimize');
                } else {
                    $mapped[$val] = ucfirst($val);
                }
            } else {
                $mapped[$val] = $val;
            }
        }

        return $mapped;
    }

    /**
     * Retrieve all custom filters.
     */
    public static function get_all_filters() {
        return get_option('uwo_custom_filters', array());
    }

    /**
     * AJAX action to save a custom filter.
     */
    public function ajax_save_filter() {
        check_admin_referer('uwo-filter-builder-nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        $filter_id = !empty($_POST['filter_id']) ? sanitize_key($_POST['filter_id']) : uniqid();
        $name = !empty($_POST['name']) ? sanitize_text_field($_POST['name']) : 'Unnamed Filter';
        $post_type = !empty($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : 'product';
        $fields_raw = !empty($_POST['fields']) ? $_POST['fields'] : array();

        $fields = array();
        if (is_array($fields_raw)) {
            foreach ($fields_raw as $col => $settings) {
                $fields[sanitize_key($col)] = array(
                    'label' => !empty($settings['label']) ? sanitize_text_field($settings['label']) : ucfirst(str_replace('cf_', '', $col)),
                    'type'  => !empty($settings['type']) ? sanitize_key($settings['type']) : 'checkbox', // checkbox, select, range
                );
            }
        }

        $layout = !empty($_POST['layout']) ? sanitize_key($_POST['layout']) : 'vertical';

        $filters = self::get_all_filters();
        $filters[$filter_id] = array(
            'id'        => $filter_id,
            'name'      => $name,
            'post_type' => $post_type,
            'layout'    => $layout,
            'fields'    => $fields,
            'created'   => current_time('mysql')
        );

        update_option('uwo_custom_filters', $filters);

        wp_send_json_success(array('filter_id' => $filter_id, 'message' => 'Filter saved successfully!'));
    }

    /**
     * AJAX action to delete a custom filter.
     */
    public function ajax_delete_filter() {
        check_admin_referer('uwo-filter-builder-nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        $filter_id = !empty($_POST['filter_id']) ? sanitize_key($_POST['filter_id']) : '';
        if (empty($filter_id)) {
            wp_send_json_error(array('message' => 'Missing Filter ID'));
        }

        $filters = self::get_all_filters();
        if (isset($filters[$filter_id])) {
            unset($filters[$filter_id]);
            update_option('uwo_custom_filters', $filters);
        }

        wp_send_json_success(array('message' => 'Filter deleted successfully!'));
    }

    /**
     * Render the Custom Filter Form Shortcode [uwo_filter id="X"].
     */
    public function render_filter_shortcode($atts) {
        $args = shortcode_atts(array(
            'id' => '',
        ), $atts);

        if (empty($args['id'])) {
            return '<p style="color:#ef4444;">[uwo_filter] missing id attribute.</p>';
        }

        $filters = self::get_all_filters();
        if (!isset($filters[$args['id']])) {
            return '<p style="color:#ef4444;">Filter with ID ' . esc_html($args['id']) . ' not found.</p>';
        }

        $filter = $filters[$args['id']];
        $post_type = $filter['post_type'];

        ob_start();
        $unique_form_id = 'uwo-filter-form-' . esc_attr($filter['id']);
        ?>
        <!-- Premium Glassmorphic Frontend Filter Form -->
        <style>
            .uwo-fe-filter-container {
                background: rgba(255, 255, 255, 0.03);
                backdrop-filter: blur(15px);
                -webkit-backdrop-filter: blur(15px);
                border: 1px solid rgba(255, 255, 255, 0.08);
                border-radius: 16px;
                padding: 24px;
                margin-bottom: 30px;
                font-family: 'Outfit', sans-serif;
                color: #e2e8f0;
                box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            }
            .uwo-fe-filter-title {
                font-size: 1.25rem;
                font-weight: 700;
                margin-top: 0;
                margin-bottom: 20px;
                background: linear-gradient(135deg, #a78bfa 0%, #3b82f6 100%);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
            }
            .uwo-fe-filter-grid.horizontal {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin-bottom: 20px;
            }
            .uwo-fe-filter-grid.vertical {
                display: grid;
                grid-template-columns: 1fr;
                gap: 20px;
                margin-bottom: 20px;
            }
            .uwo-fe-filter-widget h4 {
                font-size: 0.95rem;
                font-weight: 600;
                margin-top: 0;
                margin-bottom: 12px;
                color: #f8fafc;
            }
            .uwo-fe-checkbox-list {
                max-height: 150px;
                overflow-y: auto;
                padding-right: 5px;
            }
            .uwo-fe-checkbox-list::-webkit-scrollbar {
                width: 4px;
            }
            .uwo-fe-checkbox-list::-webkit-scrollbar-thumb {
                background: rgba(255,255,255,0.1);
                border-radius: 99px;
            }
            .uwo-fe-label {
                display: flex;
                align-items: center;
                gap: 8px;
                font-size: 0.88rem;
                cursor: pointer;
                margin-bottom: 8px;
                color: #cbd5e1;
                transition: color 0.2s ease;
            }
            .uwo-fe-label:hover {
                color: #f8fafc;
            }
            .uwo-fe-select {
                width: 100%;
                background: rgba(255,255,255,0.05);
                border: 1px solid rgba(255,255,255,0.1);
                border-radius: 8px;
                padding: 8px 12px;
                color: #e2e8f0;
                font-size: 0.88rem;
                outline: none;
                cursor: pointer;
            }
            .uwo-fe-select option {
                background: #1e293b;
                color: #e2e8f0;
            }
            .uwo-fe-range-inputs {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }
            .uwo-fe-range-inputs input {
                width: 100%;
                background: rgba(255,255,255,0.05);
                border: 1px solid rgba(255,255,255,0.1);
                border-radius: 8px;
                padding: 6px 10px;
                color: #e2e8f0;
                font-size: 0.88rem;
            }
            .uwo-fe-btn {
                background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
                color: #ffffff;
                border: none;
                padding: 10px 24px;
                border-radius: 8px;
                font-weight: 600;
                cursor: pointer;
                transition: transform 0.2s ease, box-shadow 0.2s ease;
            }
            .uwo-fe-btn:hover {
                transform: translateY(-1px);
                box-shadow: 0 5px 15px rgba(139, 92, 246, 0.4);
            }
            /* Results Styling */
            .uwo-results-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
                gap: 25px;
                margin-top: 30px;
            }
            .uwo-item-card {
                background: rgba(255, 255, 255, 0.02);
                border: 1px solid rgba(255, 255, 255, 0.05);
                border-radius: 12px;
                overflow: hidden;
                box-shadow: 0 4px 15px rgba(0,0,0,0.1);
                transition: transform 0.3s ease, border-color 0.3s ease;
                display: flex;
                flex-direction: column;
            }
            .uwo-item-card:hover {
                transform: translateY(-3px);
                border-color: rgba(139, 92, 246, 0.2);
            }
            .uwo-item-image {
                aspect-ratio: 1;
                background-size: cover;
                background-position: center;
                background-color: rgba(255,255,255,0.03);
            }
            .uwo-item-details {
                padding: 15px;
                flex-grow: 1;
                display: flex;
                flex-direction: column;
            }
            .uwo-item-title {
                font-size: 0.95rem;
                font-weight: 600;
                margin: 0 0 10px 0;
                line-height: 1.4;
                color: #f8fafc;
            }
            .uwo-item-meta {
                margin-top: auto;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .uwo-item-price {
                font-weight: 700;
                color: #a78bfa;
                font-size: 1.05rem;
            }
            .uwo-item-badge {
                font-size: 0.7rem;
                font-weight: 700;
                padding: 3px 8px;
                border-radius: 5px;
                text-transform: uppercase;
            }
            .uwo-item-badge.instock {
                background: rgba(16, 185, 129, 0.15);
                color: #34d399;
            }
            .uwo-item-badge.outofstock {
                background: rgba(239, 68, 68, 0.15);
                color: #f87171;
            }
        </style>

        <?php $layout = !empty($filter['layout']) ? $filter['layout'] : 'vertical'; ?>
        <div class="uwo-fe-filter-container">
            <h3 class="uwo-fe-filter-title"><?php echo esc_html($filter['name']); ?></h3>
            <form id="<?php echo $unique_form_id; ?>" class="uwo-fe-filter-form">
                <input type="hidden" name="post_type" value="<?php echo esc_attr($post_type); ?>" />
                
                <div class="uwo-fe-filter-grid <?php echo esc_attr($layout); ?>">
                    <?php foreach ($filter['fields'] as $column => $settings) : 
                        $label = $settings['label'];
                        $type = $settings['type'];
                        
                        // Price Range
                        if ($column === 'price' && $type === 'range') :
                            // Get price bounds
                            global $wpdb;
                            $table_name = $wpdb->prefix . 'uwo_items_index';
                            $bounds = $wpdb->get_row($wpdb->prepare("SELECT MIN(price) as min, MAX(price) as max FROM `{$table_name}` WHERE post_type = %s", $post_type));
                            $min = $bounds ? floor($bounds->min) : 0;
                            $max = $bounds ? ceil($bounds->max) : 1000;
                        ?>
                            <div class="uwo-fe-filter-widget">
                                <h4><?php echo esc_html($label); ?></h4>
                                <div class="uwo-fe-range-inputs">
                                    <input type="number" name="price_min" placeholder="<?php echo $min; ?>" min="<?php echo $min; ?>" max="<?php echo $max; ?>" />
                                    <input type="number" name="price_max" placeholder="<?php echo $max; ?>" min="<?php echo $min; ?>" max="<?php echo $max; ?>" />
                                </div>
                            </div>
                        // Dropdown Select or Checkboxes
                        else : 
                            $mapped_options = $this->get_unique_column_values($column);
                            if (empty($mapped_options)) continue;
                        ?>
                            <div class="uwo-fe-filter-widget">
                                <h4><?php echo esc_html($label); ?></h4>
                                <?php if ($type === 'select') : ?>
                                    <select name="<?php echo esc_attr($column); ?>" class="uwo-fe-select">
                                        <option value=""><?php printf(__('All %s', 'ultimate-wordpress-optimize'), esc_html($label)); ?></option>
                                        <?php foreach ($mapped_options as $val => $display_name) : ?>
                                            <option value="<?php echo esc_attr($val); ?>"><?php echo esc_html($display_name); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else : // Checkboxes ?>
                                    <div class="uwo-fe-checkbox-list">
                                        <?php 
                                        $opt_index = 0;
                                        foreach ($mapped_options as $val => $display_name) : 
                                            $unique_id = $unique_form_id . '-' . $column . '-' . $opt_index;
                                            $opt_index++;
                                        ?>
                                            <label class="uwo-fe-label" for="<?php echo $unique_id; ?>">
                                                <input type="checkbox" id="<?php echo $unique_id; ?>" name="<?php echo esc_attr($column); ?>[]" value="<?php echo esc_attr($val); ?>" />
                                                <span><?php echo esc_html($display_name); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>

                <div style="text-align: right;">
                    <button type="submit" class="uwo-fe-btn">Filter Items</button>
                </div>
            </form>
        </div>

        <!-- Target display for matching results -->
        <div id="<?php echo $unique_form_id; ?>-results" class="uwo-results-grid"></div>

        <script>
            jQuery(document).ready(function($) {
                var $form = $('#<?php echo $unique_form_id; ?>');
                var $results = $('#<?php echo $unique_form_id; ?>-results');

                $form.on('submit', function(e) {
                    e.preventDefault();
                    
                    // Serialize form parameters
                    var formData = $form.serializeArray();
                    var params = {};

                    $.each(formData, function(_, field) {
                        if (field.value === "") return;
                        
                        // Handle multiple checkboxes arrays
                        if (field.name.endsWith('[]')) {
                            var cleanName = field.name.slice(0, -2);
                            if (!params[cleanName]) {
                                params[cleanName] = [];
                            }
                            params[cleanName].push(field.value);
                        } else {
                            params[field.name] = field.value;
                        }
                    });

                    // Build request query parameters
                    var requestUrl = '<?php echo esc_url(get_rest_url(null, 'uwo/v1/search')); ?>';
                    
                    $results.css('opacity', '0.5');

                    $.ajax({
                        url: requestUrl,
                        method: 'GET',
                        data: params,
                        success: function(response) {
                            $results.css('opacity', '1').empty();
                            if (response.success && response.data.length > 0) {
                                $.each(response.data, function(_, item) {
                                    var featured_img = item.featured_image ? item.featured_image : 'https://placehold.co/300x300/1e293b/e2e8f0?text=No+Image';
                                    var price_text = item.price ? parseFloat(item.price).toLocaleString() + ' đ' : 'N/A';
                                    var stock_badge = item.stock_status === 'instock' ? '<span class="uwo-item-badge instock">In stock</span>' : '<span class="uwo-item-badge outofstock">Out of stock</span>';
                                    
                                    var cardHtml = 
                                        '<div class="uwo-item-card">' +
                                            '<div class="uwo-item-image" style="background-image: url(' + featured_img + ')"></div>' +
                                            '<div class="uwo-item-details">' +
                                                '<h4 class="uwo-item-title">' + item.title + '</h4>' +
                                                '<div class="uwo-item-meta">' +
                                                    '<span class="uwo-item-price">' + price_text + '</span>' +
                                                    stock_badge +
                                                '</div>' +
                                            '</div>' +
                                        '</div>';
                                    $results.append(cardHtml);
                                });
                            } else {
                                $results.append('<p style="grid-column: 1/-1; text-align: center; color: #94a3b8;">No matching records found.</p>');
                            }
                        },
                        error: function() {
                            $results.css('opacity', '1').empty().append('<p style="grid-column: 1/-1; text-align: center; color: #f87171;">Failed to fetch results. Check console.</p>');
                        }
                    });
                });

                // Auto-submit form on load to show initial results
                $form.trigger('submit');
            });
        </script>
        <?php
        return ob_get_clean();
    }
}
