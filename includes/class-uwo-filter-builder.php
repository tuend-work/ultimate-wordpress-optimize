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
                'hide_empty' => false,
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
        $fields_active = !empty($_POST['fields_active']) ? $_POST['fields_active'] : array();

        $fields = array();
        if (is_array($fields_raw)) {
            foreach ($fields_raw as $col => $settings) {
                $col_key = sanitize_key($col);
                if (!isset($fields_active[$col_key])) {
                    continue; // Only save active checked fields
                }
                $fields[$col_key] = array(
                    'label' => !empty($settings['label']) ? sanitize_text_field($settings['label']) : ucfirst(str_replace('cf_', '', $col)),
                    'type'  => !empty($settings['type']) ? sanitize_key($settings['type']) : 'checkbox', // checkbox, select, range
                    'width' => !empty($settings['width']) ? sanitize_text_field($settings['width']) : '',
                );
            }
        }

        $layout = !empty($_POST['layout']) ? sanitize_key($_POST['layout']) : 'vertical';
        $enable_ajax = isset($_POST['enable_ajax']) ? (int)$_POST['enable_ajax'] : 1;

        $filters = self::get_all_filters();
        $filters[$filter_id] = array(
            'id'          => $filter_id,
            'name'        => $name,
            'post_type'   => $post_type,
            'layout'      => $layout,
            'enable_ajax' => $enable_ajax,
            'fields'      => $fields,
            'created'     => current_time('mysql')
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

        // 1. Detect Archive Taxonomy Context (e.g. Category/Tag page)
        $current_tax = '';
        $current_term = '';
        if (is_tax() || is_category() || is_tag()) {
            $queried_obj = get_queried_object();
            if ($queried_obj && !is_wp_error($queried_obj)) {
                $current_tax = $queried_obj->taxonomy;
                $current_term = $queried_obj->slug;
            }
        }

        $form_action = '';
        if (is_tax() || is_category() || is_tag()) {
            $term_link = get_term_link(get_queried_object());
            $form_action = !is_wp_error($term_link) ? $term_link : '';
        }
        if (empty($form_action)) {
            $form_action = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : get_permalink();
        }

        $enable_ajax = isset($filter['enable_ajax']) ? (int)$filter['enable_ajax'] : 1;

        ob_start();
        $unique_form_id = 'uwo-filter-form-' . esc_attr($filter['id']);
        $layout = !empty($filter['layout']) ? $filter['layout'] : 'vertical';
        ?>
        <form id="<?php echo $unique_form_id; ?>" class="uwo-fe-filter-form" action="<?php echo esc_url($form_action); ?>" method="GET">
            <input type="hidden" name="post_type" value="<?php echo esc_attr($post_type); ?>" />
            
            <div class="<?php echo esc_attr($layout); ?>" style="<?php echo $layout === 'horizontal' ? 'display: flex; flex-wrap: wrap; align-items: flex-end; gap: 15px;' : ''; ?>">
                <?php foreach ($filter['fields'] as $column => $settings) : 
                    $label = $settings['label'];
                    $type = $settings['type'];
                    $width = !empty($settings['width']) ? $settings['width'] : '';
                    $style_attr = !empty($width) ? 'style="width:' . esc_attr($width) . ';"' : '';
                    
                    // 1. Text Search Box (Title / Search Text) - Omit Widget Title/h4 entirely
                    if ($column === 'title' || $column === 'search_text') :
                        $s_val = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
                    ?>
                        <div class="uwo-fe-filter-widget uwo-fe-search-widget" <?php echo $style_attr; ?>>
                            <input type="text" name="s" value="<?php echo esc_attr($s_val); ?>" placeholder="<?php echo esc_attr($label); ?>" style="width: 100%;" />
                        </div>
                    <?php 
                    // 2. Price Range
                    elseif ($column === 'price' && $type === 'range') :
                        global $wpdb;
                        $table_name = $wpdb->prefix . 'uwo_items_index';
                        $bounds = $wpdb->get_row($wpdb->prepare("SELECT MIN(price) as min, MAX(price) as max FROM `{$table_name}` WHERE post_type = %s", $post_type));
                        $min = $bounds ? floor($bounds->min) : 0;
                        $max = $bounds ? ceil($bounds->max) : 1000;
                        
                        $price_min_val = isset($_GET['price_min']) ? sanitize_text_field($_GET['price_min']) : '';
                        $price_max_val = isset($_GET['price_max']) ? sanitize_text_field($_GET['price_max']) : '';
                    ?>
                        <div class="uwo-fe-filter-widget" <?php echo $style_attr; ?>>
                            <h4 style="margin: 0 0 5px 0; font-size: 13px; font-weight: 600;"><?php echo esc_html($label); ?></h4>
                            <div style="display: flex; gap: 10px;">
                                <input type="number" name="price_min" placeholder="<?php echo $min; ?>" min="<?php echo $min; ?>" max="<?php echo $max; ?>" value="<?php echo esc_attr($price_min_val); ?>" style="width: 50%;" />
                                <input type="number" name="price_max" placeholder="<?php echo $max; ?>" min="<?php echo $min; ?>" max="<?php echo $max; ?>" value="<?php echo esc_attr($price_max_val); ?>" style="width: 50%;" />
                            </div>
                        </div>
                    <?php 
                    // 3. Dropdown Select or Checkboxes
                    else : 
                        $mapped_options = $this->get_unique_column_values($column);
                        if (empty($mapped_options)) continue;
                    ?>
                        <div class="uwo-fe-filter-widget" <?php echo $style_attr; ?>>
                            <h4 style="margin: 0 0 5px 0; font-size: 13px; font-weight: 600;"><?php echo esc_html($label); ?></h4>
                            <?php if ($type === 'select') : 
                                $selected_val = isset($_GET[$column]) ? sanitize_text_field($_GET[$column]) : '';
                                if (empty($selected_val) && $column === $current_tax) {
                                    $selected_val = $current_term;
                                }
                            ?>
                                <select name="<?php echo esc_attr($column); ?>" style="width: 100%;">
                                    <option value=""><?php printf(__('All %s', 'ultimate-wordpress-optimize'), esc_html($label)); ?></option>
                                    <?php foreach ($mapped_options as $val => $display_name) : 
                                        $selected = ($val === $selected_val) ? 'selected="selected"' : '';
                                    ?>
                                        <option value="<?php echo esc_attr($val); ?>" <?php echo $selected; ?>><?php echo esc_html($display_name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else : // Checkboxes 
                                $checked_vals = isset($_GET[$column]) ? (array)$_GET[$column] : array();
                                if (empty($checked_vals) && $column === $current_tax) {
                                    $checked_vals = array($current_term);
                                }
                            ?>
                                <div>
                                    <?php 
                                    $opt_index = 0;
                                    foreach ($mapped_options as $val => $display_name) : 
                                        $unique_id = $unique_form_id . '-' . $column . '-' . $opt_index;
                                        $opt_index++;
                                        $checked = in_array($val, $checked_vals, true) ? 'checked="checked"' : '';
                                    ?>
                                        <label for="<?php echo $unique_id; ?>" style="display: block; margin-bottom: 5px; cursor: pointer; font-size: 13px;">
                                            <input type="checkbox" id="<?php echo $unique_id; ?>" name="<?php echo esc_attr($column); ?>[]" value="<?php echo esc_attr($val); ?>" <?php echo $checked; ?> />
                                            <span><?php echo esc_html($display_name); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>

                <?php
                // Render hidden input for active taxonomy category archive context if not in form fields
                if (!empty($current_tax) && !empty($current_term) && !isset($filter['fields'][$current_tax])) {
                    echo '<input type="hidden" name="' . esc_attr($current_tax) . '[]" value="' . esc_attr($current_term) . '" />';
                }
                ?>
                
                <!-- Action Buttons aligned inline in Horizontal, stacked in Vertical -->
                <div class="uwo-filter-submit-group" style="<?php echo $layout === 'horizontal' ? 'display: flex; gap: 10px; align-items: flex-end; margin-bottom: 0; padding-bottom: 5px;' : 'margin-top: 15px;'; ?>">
                    <button type="submit" class="uwo-filter-btn" style="cursor:pointer; padding: 6px 16px;">Lọc</button>
                    <button type="button" class="uwo-clear-btn" onclick="var $f = jQuery('#<?php echo $unique_form_id; ?>'); $f.find('input[type=text], input[type=number], select').val(''); $f.find('input[type=checkbox]').prop('checked', false); $f.trigger('submit');" style="cursor:pointer; padding: 6px 16px;">Xoá lọc</button>
                </div>
            </div>
        </form>

        <!-- Target display for matching results (Unstyled container wrapper) -->
        <div id="<?php echo $unique_form_id; ?>-results"></div>

        <script>
            jQuery(document).ready(function($) {
                var $form = $('#<?php echo $unique_form_id; ?>');
                var $results = $('#<?php echo $unique_form_id; ?>-results');

                // Detect native WooCommerce products container
                var $shopLoop = $('.products.row').first();
                if ($shopLoop.length === 0) {
                    $shopLoop = $('ul.products').first();
                }

                var isArchive = $shopLoop.length > 0;
                if (isArchive) {
                    $results = $shopLoop;
                    // Hide original pagination
                    $('.woocommerce-pagination, .pagination').hide();
                }

                $form.on('submit', function(e) {
                    var enableAjax = <?php echo $enable_ajax ? 'true' : 'false'; ?>;
                    if (!enableAjax) {
                        // Let GET submit redirect normally
                        return;
                    }

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
                            if (response.success && response.html) {
                                $results.html(response.html);
                            } else {
                                $results.append('<p class="woocommerce-info" style="width: 100%; text-align: center;">Không tìm thấy sản phẩm nào khớp với lựa chọn của bạn.</p>');
                            }
                        },
                        error: function() {
                            $results.css('opacity', '1').empty().append('<p style="width: 100%; text-align: center; color: #ef4444;">Failed to fetch results. Check console.</p>');
                        }
                    });
                });

                // Auto-submit only on custom pages where there is no pre-rendered loop
                if (!isArchive) {
                    $form.trigger('submit');
                }
            });
        </script>
        <?php
        return ob_get_clean();
    }
}
