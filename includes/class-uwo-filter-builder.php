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
        $layout = !empty($filter['layout']) ? $filter['layout'] : 'vertical';
        ?>
        <form id="<?php echo $unique_form_id; ?>" class="uwo-fe-filter-form">
            <input type="hidden" name="post_type" value="<?php echo esc_attr($post_type); ?>" />
            
            <div class="<?php echo esc_attr($layout); ?>">
                <?php foreach ($filter['fields'] as $column => $settings) : 
                    $label = $settings['label'];
                    $type = $settings['type'];
                    
                    // Price Range
                    if ($column === 'price' && $type === 'range') :
                        global $wpdb;
                        $table_name = $wpdb->prefix . 'uwo_items_index';
                        $bounds = $wpdb->get_row($wpdb->prepare("SELECT MIN(price) as min, MAX(price) as max FROM `{$table_name}` WHERE post_type = %s", $post_type));
                        $min = $bounds ? floor($bounds->min) : 0;
                        $max = $bounds ? ceil($bounds->max) : 1000;
                    ?>
                        <div>
                            <h4><?php echo esc_html($label); ?></h4>
                            <div>
                                <input type="number" name="price_min" placeholder="<?php echo $min; ?>" min="<?php echo $min; ?>" max="<?php echo $max; ?>" />
                                <input type="number" name="price_max" placeholder="<?php echo $max; ?>" min="<?php echo $min; ?>" max="<?php echo $max; ?>" />
                            </div>
                        </div>
                    <?php 
                    // Dropdown Select or Checkboxes
                    else : 
                        $mapped_options = $this->get_unique_column_values($column);
                        if (empty($mapped_options)) continue;
                    ?>
                        <div>
                            <h4><?php echo esc_html($label); ?></h4>
                            <?php if ($type === 'select') : ?>
                                <select name="<?php echo esc_attr($column); ?>">
                                    <option value=""><?php printf(__('All %s', 'ultimate-wordpress-optimize'), esc_html($label)); ?></option>
                                    <?php foreach ($mapped_options as $val => $display_name) : ?>
                                        <option value="<?php echo esc_attr($val); ?>"><?php echo esc_html($display_name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else : // Checkboxes ?>
                                <div>
                                    <?php 
                                    $opt_index = 0;
                                    foreach ($mapped_options as $val => $display_name) : 
                                        $unique_id = $unique_form_id . '-' . $column . '-' . $opt_index;
                                        $opt_index++;
                                    ?>
                                        <label for="<?php echo $unique_id; ?>">
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

            <button type="submit">Filter Items</button>
        </form>

        <!-- Target display for matching results -->
        <div id="<?php echo $unique_form_id; ?>-results" class="products row row-small large-columns-4 medium-columns-3 small-columns-2"></div>

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
                                    var permalink = item.permalink ? item.permalink : '#';
                                    var featured_img = item.featured_image ? item.featured_image : '';
                                    var price_text = '';
                                    if (item.price) {
                                        price_text = parseFloat(item.price).toLocaleString('vi-VN') + ' <span class="woocommerce-Price-currencySymbol">&#8363;</span>';
                                    } else {
                                        price_text = 'Liên hệ';
                                    }
                                    var stock_status = item.stock_status ? item.stock_status : 'instock';
                                    var out_of_stock_badge = '';
                                    if (stock_status === 'outofstock') {
                                        out_of_stock_badge = '<div class="badge-inner back-in-stock-badge out-of-stock-badge"><span class="out-of-stock-title">Hết hàng</span></div>';
                                    }
                                    
                                    var cardHtml = 
                                        '<div class="product-small col has-hover post-' + item.id + ' product type-product status-publish ' + stock_status + ' has-post-thumbnail">' +
                                            '<div class="col-inner">' +
                                                '<div class="badge-container">' + out_of_stock_badge + '</div>' +
                                                '<div class="product-fade">' +
                                                    '<div class="image-fade">' +
                                                        '<a href="' + permalink + '">' +
                                                            '<img src="' + featured_img + '" alt="' + item.title + '" width="300" height="300" class="attachment-woocommerce_thumbnail size-woocommerce_thumbnail" />' +
                                                        '</a>' +
                                                    '</div>' +
                                                '</div>' +
                                                '<div class="box-text box-text-products text-center grid-style-2">' +
                                                    '<div class="title-wrapper">' +
                                                        '<p class="name product-title woocommerce-loop-product__title">' +
                                                            '<a href="' + permalink + '">' + item.title + '</a>' +
                                                        '</p>' +
                                                    '</div>' +
                                                    '<div class="price-wrapper">' +
                                                        '<span class="price">' +
                                                            '<span class="woocommerce-Price-amount amount"><bdi>' + price_text + '</bdi></span>' +
                                                        '</span>' +
                                                    '</div>' +
                                                '</div>' +
                                            '</div>' +
                                        '</div>';
                                    $results.append(cardHtml);
                                });
                            } else {
                                $results.append('<p class="woocommerce-info" style="width: 100%; text-align: center; grid-column: 1/-1;">Không tìm thấy sản phẩm nào khớp với lựa chọn của bạn.</p>');
                            }
                        },
                        error: function() {
                            $results.css('opacity', '1').empty().append('<p style="width: 100%; text-align: center; grid-column: 1/-1; color: #ef4444;">Failed to fetch results. Check console.</p>');
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
