<?php
namespace UWO;

/**
 * Intercepts default WordPress/WooCommerce queries and accelerates them using
 * the Flat MySQL index, Redis cache, or OpenSearch.
 */
class QueryEngine {

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
     * Private constructor registering filters.
     */
    private function __construct() {
        // Intercept query requests
        add_filter('posts_pre_query', array($this, 'intercept_posts_query'), 10, 2);
    }

    /**
     * Intercept standard WP_Query requests for accelerated CPTs and optimize them.
     */
    public function intercept_posts_query($posts, $query) {
        // Avoid intercepting inside admin, non-main queries, singular views, or specific post ID/slug lookups
        if (is_admin() || !$query->is_main_query() || $query->is_singular() || $query->is_single() || $query->is_page()) {
            return $posts;
        }

        // Avoid if targeting a specific post via parameters directly
        if ($query->get('p') || $query->get('name') || $query->get('page_id') || $query->get('pagename')) {
            return $posts;
        }

        $enabled_types = get_option('uwo_enabled_post_types', array('product'));
        $query_post_types = (array) $query->get('post_type');

        // Check if query is targeting our accelerated CPTs
        $intersect = array_intersect($query_post_types, $enabled_types);
        if (empty($intersect)) {
            return $posts;
        }

        // Build search arguments from WP_Query
        $args = array(
            'post_type'      => count($intersect) === 1 ? reset($intersect) : $intersect,
            'posts_per_page' => $query->get('posts_per_page') ?: get_option('posts_per_page', 12),
            'paged'          => $query->get('paged') ?: 1,
            'search'         => $query->get('s') ?: '',
        );

        // Map tax query to flat tax structure
        $tax_query = $query->get('tax_query');
        if (!empty($tax_query)) {
            $args['tax_query'] = $tax_query;
        }

        // Map meta query (e.g. WooCommerce price filters)
        $meta_query = $query->get('meta_query');
        if (!empty($meta_query)) {
            $args['meta_query'] = $meta_query;
        }

        // Dynamically parse all valid table columns and registered taxonomies from $_GET for standard page reloads
        $db = Database::get_instance();
        $db_cols = $db->get_table_columns();
        $get_params = $_GET;
        
        // 1. Price bounds in GET
        if (isset($get_params['price_min']) || isset($get_params['price_max'])) {
            $min = isset($get_params['price_min']) && $get_params['price_min'] !== '' ? (float) $get_params['price_min'] : 0.0;
            $max = isset($get_params['price_max']) && $get_params['price_max'] !== '' ? (float) $get_params['price_max'] : 9999999.0;
            if (!isset($args['meta_query'])) {
                $args['meta_query'] = array();
            }
            $args['meta_query'][] = array(
                'key'     => '_price',
                'value'   => array($min, $max),
                'compare' => 'BETWEEN',
            );
        }

        // 2. Stock status in GET
        if (!empty($get_params['stock_status'])) {
            if (!isset($args['meta_query'])) {
                $args['meta_query'] = array();
            }
            $args['meta_query'][] = array(
                'key'     => '_stock_status',
                'value'   => sanitize_text_field($get_params['stock_status']),
                'compare' => '=',
            );
        }

        // 3. Text search GET fallback
        if (empty($args['search']) && !empty($get_params['s'])) {
            $args['search'] = sanitize_text_field($get_params['s']);
        }

        // 4. Taxonomies and Custom fields in GET
        foreach ($get_params as $key => $value) {
            $param_key = sanitize_key($key);
            if (empty($value)) {
                continue;
            }

            if (taxonomy_exists($param_key)) {
                $sanitized_value = is_array($value) ? array_map('sanitize_text_field', $value) : sanitize_text_field($value);
                if (!isset($args['tax_query'])) {
                    $args['tax_query'] = array();
                }
                $args['tax_query'][] = array(
                    'taxonomy' => $param_key,
                    'field'    => 'slug',
                    'terms'    => $sanitized_value,
                    'operator' => is_array($value) ? 'IN' : 'AND',
                );
            }
            elseif (in_array($param_key, $db_cols, true)) {
                if (in_array($param_key, array('price', 'id', 'post_id', 'parent_id', 'post_type', 'slug', 'payload_json', 'search_text', 'updated_at', 'attributes_filter'), true)) {
                    continue;
                }

                $sanitized_value = is_array($value) ? array_map('sanitize_text_field', $value) : sanitize_text_field($value);
                $meta_key = (strpos($param_key, 'cf_') === 0) ? substr($param_key, 3) : $param_key;

                if (!isset($args['meta_query'])) {
                    $args['meta_query'] = array();
                }
                $args['meta_query'][] = array(
                    'key'     => $meta_key,
                    'value'   => $sanitized_value,
                    'compare' => is_array($value) ? 'IN' : '=',
                );
            }
        }

        // Handle sorting / orderby
        $orderby = $query->get('orderby');
        $order = $query->get('order') ?: 'DESC';
        if ($orderby) {
            $args['orderby'] = $orderby;
            $args['order'] = $order;
        }

        // Execute accelerated search
        $results = $this->query_items($args);

        if ($results) {
            // Set total found rows for pagination
            $query->found_posts = $results['total'];
            $query->max_num_pages = ceil($results['total'] / $args['posts_per_page']);

            // Instantiate posts from DB to satisfy WP core requirements
            $post_objects = array();
            if (!empty($results['ids'])) {
                foreach ($results['ids'] as $id) {
                    $post_obj = get_post($id);
                    if ($post_obj) {
                        $post_objects[] = $post_obj;
                    }
                }
            }
            return $post_objects;
        }

        return $posts;
    }

    /**
     * Query flat engine with fallback architecture (Redis -> OpenSearch -> MySQL Flat Table).
     */
    public function query_items($args) {
        $engine_mode = get_option('uwo_engine_mode', 'mysql');
        
        // Build unique cache key
        $cache_key = 'query_' . serialize($args);

        // 1. Try Redis Cache first
        if ($engine_mode !== 'mysql') {
            $redis = Redis::get_instance();
            $cached = $redis->get_cached_query($cache_key);
            if ($cached !== false) {
                return $cached;
            }
        }

        $results = null;

        // 2. OpenSearch fallback/primary if selected
        if ($engine_mode === 'opensearch') {
            $opensearch = OpenSearch::get_instance();
            if ($opensearch->is_connected()) {
                $results = $this->query_via_opensearch($args);
            }
        }

        // 3. Flat MySQL query (Default / Fallback)
        if (null === $results) {
            $results = $this->query_via_mysql($args);
        }

        // Store back in Redis if connected
        if ($results && $engine_mode !== 'mysql') {
            $redis = Redis::get_instance();
            $redis->cache_query($cache_key, $results, 600); // 10 minutes cache TTL
        }

        return $results;
    }

    /**
     * Compile and run flat MySQL search query.
     */
    private function query_via_mysql($args) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'uwo_items_index';

        $where = array('1=1');
        $join = '';

        // Filter by Post Type
        if (!empty($args['post_type'])) {
            if (is_array($args['post_type'])) {
                $types = array_map('esc_sql', $args['post_type']);
                $where[] = "post_type IN ('" . implode("','", $types) . "')";
            } else {
                $where[] = $wpdb->prepare("post_type = %s", $args['post_type']);
            }
        }

        // Search text using MySQL FULLTEXT
        if (!empty($args['search'])) {
            $search_term = esc_sql($args['search']);
            // Fallback to simple LIKE if search has special chars or boolean mode logic is simple
            if (strlen($search_term) > 2) {
                $where[] = $wpdb->prepare("MATCH(search_text) AGAINST(%s IN BOOLEAN MODE)", $search_term . '*');
            } else {
                $where[] = $wpdb->prepare("title LIKE %s", '%' . $wpdb->esc_like($search_term) . '%');
            }
        }

        // Parse tax queries mapping into attributes_filter
        if (!empty($args['tax_query'])) {
            foreach ($args['tax_query'] as $tax) {
                if (is_array($tax) && isset($tax['taxonomy']) && isset($tax['terms'])) {
                    $taxonomy = $tax['taxonomy'];
                    $terms = (array) $tax['terms'];
                    $operator = isset($tax['operator']) ? strtoupper($tax['operator']) : 'IN';
                    $is_not = (strpos($operator, 'NOT') !== false);
                    
                    $tax_clauses = array();
                    foreach ($terms as $term) {
                        $slug = is_numeric($term) ? get_term($term)->slug : $term;
                        if ($slug) {
                            $like_op = $is_not ? 'NOT LIKE' : 'LIKE';
                            $tax_clauses[] = $wpdb->prepare("attributes_filter {$like_op} %s", '%' . $wpdb->esc_like('tax_' . $taxonomy . '_' . $slug) . '%');
                        }
                    }
                    if (!empty($tax_clauses)) {
                        $glue = $is_not ? ' AND ' : ' OR ';
                        $where[] = '(' . implode($glue, $tax_clauses) . ')';
                    }
                }
            }
        }

        // Parse custom field meta query (e.g. price filter)
        if (!empty($args['meta_query'])) {
            foreach ($args['meta_query'] as $meta) {
                if (is_array($meta) && isset($meta['key'])) {
                    $key = $meta['key'];
                    $value = isset($meta['value']) ? $meta['value'] : '';
                    $compare = isset($meta['compare']) ? strtoupper($meta['compare']) : '=';

                    // If it is WooCommerce price field, map it to our physical column
                    if ($key === '_price') {
                        if ($compare === 'BETWEEN' && is_array($value)) {
                            $where[] = $wpdb->prepare("price BETWEEN %f AND %f", $value[0], $value[1]);
                        } elseif ($compare === '>=') {
                            $where[] = $wpdb->prepare("price >= %f", $value);
                        } elseif ($compare === '<=') {
                            $where[] = $wpdb->prepare("price <= %f", $value);
                        }
                    } elseif ($key === '_stock_status') {
                        $where[] = $wpdb->prepare("stock_status = %s", $value);
                    } else {
                        // Check if key is a direct physical column, or if it matches custom column cf_[key]
                        $db = Database::get_instance();
                        $col = '';
                        $clean_key = sanitize_key($key);
                        if ($db->column_exists($clean_key)) {
                            $col = $clean_key;
                        } elseif ($db->column_exists('cf_' . $clean_key)) {
                            $col = 'cf_' . $clean_key;
                        }

                        if (!empty($col)) {
                            // If it is a taxonomy JSON array column (starts with tax_)
                            if (strpos($col, 'tax_') === 0) {
                                if ($compare === 'IN' && is_array($value)) {
                                    $clauses = array();
                                    foreach ($value as $val) {
                                        $clauses[] = $wpdb->prepare("`{$col}` LIKE %s", '%' . $wpdb->esc_like('"' . $val . '"') . '%');
                                    }
                                    if (!empty($clauses)) {
                                        $where[] = '(' . implode(' OR ', $clauses) . ')';
                                    }
                                } else {
                                    $val = is_array($value) ? $value[0] : $value;
                                    $where[] = $wpdb->prepare("`{$col}` LIKE %s", '%' . $wpdb->esc_like('"' . $val . '"') . '%');
                                }
                            } else {
                                if ($compare === '=') {
                                    $where[] = $wpdb->prepare("`{$col}` = %s", $value);
                                } elseif ($compare === 'IN' && is_array($value)) {
                                    $escaped_vals = array_map('esc_sql', $value);
                                    $where[] = "`{$col}` IN ('" . implode("','", $escaped_vals) . "')";
                                } elseif ($compare === 'LIKE') {
                                    $where[] = $wpdb->prepare("`{$col}` LIKE %s", '%' . $wpdb->esc_like($value) . '%');
                                }
                            }
                        }
                    }
                }
            }
        }

        // Build Order By
        $orderby = 'id';
        $order = !empty($args['order']) ? strtoupper($args['order']) : 'DESC';

        if (!empty($args['orderby'])) {
            if ($args['orderby'] === 'price') {
                $orderby = 'price';
            } elseif ($args['orderby'] === 'title') {
                $orderby = 'title';
            } elseif ($args['orderby'] === 'date') {
                $orderby = 'updated_at';
            }
        }

        // Pagination
        $posts_per_page = (int) $args['posts_per_page'];
        $paged = (int) $args['paged'];
        $offset = ($paged - 1) * $posts_per_page;

        $where_sql = implode(' AND ', $where);

        // Core highly-optimized query
        $query_sql = "SELECT SQL_CALC_FOUND_ROWS post_id, payload_json 
                      FROM `{$table_name}` 
                      WHERE {$where_sql} 
                      ORDER BY `{$orderby}` {$order} 
                      LIMIT {$offset}, {$posts_per_page}";

        $items = $wpdb->get_results($query_sql);
        $total = $wpdb->get_var("SELECT FOUND_ROWS()");

        $ids = array();
        $payloads = array();
        if ($items) {
            foreach ($items as $item) {
                $ids[] = (int) $item->post_id;
                $payloads[] = json_decode($item->payload_json, true);
            }
        }

        return array(
            'ids'      => $ids,
            'payloads' => $payloads,
            'total'    => (int) $total
        );
    }

    /**
     * Map WordPress arguments and search via OpenSearch REST endpoints.
     */
    private function query_via_opensearch($args) {
        $must = array();
        $filter = array();

        // 1. Post Type keyword match
        if (!empty($args['post_type'])) {
            if (is_array($args['post_type'])) {
                $filter[] = array('terms' => array('post_type' => $args['post_type']));
            } else {
                $filter[] = array('term' => array('post_type' => $args['post_type']));
            }
        }

        // 2. Full-text search
        if (!empty($args['search'])) {
            $must[] = array(
                'multi_match' => array(
                    'query'  => $args['search'],
                    'fields' => array('title^3', 'sku^2', 'search_text')
                )
            );
        }

        // 3. Parse Attributes whitespace filters
        if (!empty($args['tax_query'])) {
            foreach ($args['tax_query'] as $tax) {
                if (is_array($tax) && isset($tax['taxonomy']) && isset($tax['terms'])) {
                    $taxonomy = $tax['taxonomy'];
                    $terms = (array) $tax['terms'];
                    $operator = isset($tax['operator']) ? strtoupper($tax['operator']) : 'IN';
                    $is_not = (strpos($operator, 'NOT') !== false);
                    
                    $term_clauses = array();
                    foreach ($terms as $term) {
                        $slug = is_numeric($term) ? get_term($term)->slug : $term;
                        if ($slug) {
                            $term_clauses[] = 'tax_' . $taxonomy . '_' . $slug;
                        }
                    }
                    if (!empty($term_clauses)) {
                        $clause = array(
                            'match' => array(
                                'attributes_filter' => array(
                                    'query' => implode(' ', $term_clauses),
                                    'operator' => 'or'
                                )
                            )
                        );
                        if ($is_not) {
                            $query_body['query']['bool']['must_not'][] = $clause;
                        } else {
                            $must[] = $clause;
                        }
                    }
                }
            }
        }

        // 4. Meta filters (price and dynamic attributes)
        if (!empty($args['meta_query'])) {
            foreach ($args['meta_query'] as $meta) {
                if (is_array($meta) && isset($meta['key'])) {
                    $key = $meta['key'];
                    $value = isset($meta['value']) ? $meta['value'] : '';
                    $compare = isset($meta['compare']) ? strtoupper($meta['compare']) : '=';

                    if ($key === '_price') {
                        if ($compare === 'BETWEEN' && is_array($value)) {
                            $filter[] = array(
                                'range' => array(
                                    'price' => array('gte' => (float)$value[0], 'lte' => (float)$value[1])
                                )
                            );
                        } elseif ($compare === '>=') {
                            $filter[] = array('range' => array('price' => array('gte' => (float)$value)));
                        } elseif ($compare === '<=') {
                            $filter[] = array('range' => array('price' => array('lte' => (float)$value)));
                        }
                    } elseif ($key === '_stock_status') {
                        $filter[] = array('term' => array('stock_status' => $value));
                    } else {
                        // Check if key is a direct physical column, or if it matches custom column cf_[key]
                        $db = Database::get_instance();
                        $col = '';
                        $clean_key = sanitize_key($key);
                        if ($db->column_exists($clean_key)) {
                            $col = $clean_key;
                        } elseif ($db->column_exists('cf_' . $clean_key)) {
                            $col = 'cf_' . $clean_key;
                        }

                        if (!empty($col)) {
                            // If it is a taxonomy JSON array column (starts with tax_)
                            if (strpos($col, 'tax_') === 0) {
                                if ($compare === 'IN' && is_array($value)) {
                                    $filter[] = array('terms' => array($col => $value));
                                } else {
                                    $val = is_array($value) ? $value[0] : $value;
                                    $filter[] = array('term' => array($col => $val));
                                }
                            } else {
                                if ($compare === '=') {
                                    $filter[] = array('term' => array($col => $value));
                                } elseif ($compare === 'IN' && is_array($value)) {
                                    $filter[] = array('terms' => array($col => $value));
                                } elseif ($compare === 'LIKE') {
                                    $must[] = array('match' => array($col => $value));
                                }
                            }
                        }
                    }
                }
            }
        }

        // Assemble Elasticsearch Query Body
        $query_body = array(
            'from' => ((int) $args['paged'] - 1) * (int) $args['posts_per_page'],
            'size' => (int) $args['posts_per_page'],
            'query' => array(
                'bool' => array(
                    'must' => !empty($must) ? $must : array(array('match_all' => (object)array())),
                    'filter' => $filter
                )
            )
        );

        // Sorting
        if (!empty($args['orderby'])) {
            $order = !empty($args['order']) ? strtolower($args['order']) : 'desc';
            if ($args['orderby'] === 'price') {
                $query_body['sort'] = array(array('price' => array('order' => $order)));
            } elseif ($args['orderby'] === 'title') {
                $query_body['sort'] = array(array('title' => array('order' => $order)));
            } elseif ($args['orderby'] === 'date') {
                $query_body['sort'] = array(array('updated_at' => array('order' => $order)));
            }
        }

        $opensearch = OpenSearch::get_instance();
        $raw_response = $opensearch->search($query_body);

        if (!$raw_response || !isset($raw_response['hits'])) {
            return null;
        }

        $total = $raw_response['hits']['total']['value'];
        $ids = array();
        $payloads = array();

        foreach ($raw_response['hits']['hits'] as $hit) {
            $ids[] = (int) $hit['_id'];
            // Since payload isn't directly inside OpenSearch flat document fields as payload_json
            // We can fetch details or build payload from OpenSearch document fields directly
            $doc = $hit['_source'];
            $payloads[] = array(
                'id'            => (int) $hit['_id'],
                'parent_id'     => isset($doc['parent_id']) ? (int)$doc['parent_id'] : 0,
                'post_type'     => isset($doc['post_type']) ? $doc['post_type'] : '',
                'title'         => isset($doc['title']) ? $doc['title'] : '',
                'slug'          => isset($doc['slug']) ? $doc['slug'] : '',
                'sku'           => isset($doc['sku']) ? $doc['sku'] : '',
                'price'         => isset($doc['price']) ? $doc['price'] : null,
                'stock_status'  => isset($doc['stock_status']) ? $doc['stock_status'] : '',
                'permalink'     => get_permalink($hit['_id']),
                'featured_image'=> get_the_post_thumbnail_url($hit['_id'], 'medium') ?: '',
            );
        }

        return array(
            'ids'      => $ids,
            'payloads' => $payloads,
            'total'    => (int) $total
        );
    }
}
