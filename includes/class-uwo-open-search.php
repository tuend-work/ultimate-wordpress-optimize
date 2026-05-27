<?php
namespace UWO;

/**
 * OpenSearch Connector class.
 * Built using native WordPress HTTP API for optimal performance and composer-free design.
 */
class OpenSearch {

    private static $instance = null;
    private $host = '';
    private $username = '';
    private $password = '';
    private $index_name = '';
    private $connected = null;

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
     * Private constructor loading options.
     */
    private function __construct() {
        $this->host = defined('UWO_OPENSEARCH_HOST') ? UWO_OPENSEARCH_HOST : get_option('uwo_opensearch_host', '');
        $this->username = defined('UWO_OPENSEARCH_USER') ? UWO_OPENSEARCH_USER : get_option('uwo_opensearch_user', '');
        $this->password = defined('UWO_OPENSEARCH_PASS') ? UWO_OPENSEARCH_PASS : get_option('uwo_opensearch_pass', '');
        $this->index_name = defined('UWO_OPENSEARCH_INDEX') ? UWO_OPENSEARCH_INDEX : get_option('uwo_opensearch_index', 'uwo_items_index');
        
        $this->host = rtrim($this->host, '/');
    }

    /**
     * Build headers for request including Basic Auth if provided.
     */
    private function get_headers() {
        $headers = array(
            'Content-Type' => 'application/json',
        );

        if (!empty($this->username) && !empty($this->password)) {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->username . ':' . $this->password);
        }

        return $headers;
    }

    /**
     * Test connection to OpenSearch cluster.
     */
    public function is_connected() {
        if (empty($this->host)) {
            return false;
        }

        if (null !== $this->connected) {
            return $this->connected;
        }

        $url = $this->host;
        $args = array(
            'method'    => 'GET',
            'headers'   => $this->get_headers(),
            'timeout'   => 2, // fast timeout for ping
            'sslverify' => false
        );

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $this->connected = false;
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $this->connected = ($code === 200);

        if ($this->connected) {
            $this->ensure_index();
        }

        return $this->connected;
    }

    /**
     * Create index with appropriate mappings if it does not exist.
     */
    public function ensure_index() {
        $url = $this->host . '/' . $this->index_name;
        
        // Check if index exists
        $args = array(
            'method'    => 'HEAD',
            'headers'   => $this->get_headers(),
            'sslverify' => false
        );
        $response = wp_remote_request($url, $args);
        $code = wp_remote_retrieve_response_code($response);

        if ($code === 404) {
            // Create Index with mapping
            $mapping = array(
                'settings' => array(
                    'number_of_shards' => 1,
                    'number_of_replicas' => 0,
                    'analysis' => array(
                        'analyzer' => array(
                            'uwo_analyzer' => array(
                                'type' => 'custom',
                                'tokenizer' => 'standard',
                                'filter' => array('lowercase', 'asciifolding')
                            )
                        )
                    )
                ),
                'mappings' => array(
                    'properties' => array(
                        'post_id' => array('type' => 'long'),
                        'parent_id' => array('type' => 'long'),
                        'post_type' => array('type' => 'keyword'),
                        'sku' => array('type' => 'keyword'),
                        'title' => array('type' => 'text', 'analyzer' => 'uwo_analyzer', 'boost' => 3.0),
                        'slug' => array('type' => 'keyword'),
                        'price' => array('type' => 'float'),
                        'stock_status' => array('type' => 'keyword'),
                        'primary_cat_id' => array('type' => 'long'),
                        'attributes_filter' => array('type' => 'text', 'analyzer' => 'whitespace'),
                        'search_text' => array('type' => 'text', 'analyzer' => 'uwo_analyzer'),
                        'updated_at' => array('type' => 'date', 'format' => 'yyyy-MM-dd HH:mm:ss')
                    )
                )
            );

            $create_args = array(
                'method'    => 'PUT',
                'headers'   => $this->get_headers(),
                'body'      => wp_json_encode($mapping),
                'sslverify' => false
            );
            wp_remote_request($url, $create_args);
        }
    }

    /**
     * Index item in OpenSearch.
     */
    public function sync_item($post_id, $data) {
        if (!$this->is_connected()) {
            return false;
        }

        // Clean values for JSON conversion
        $payload = array(
            'post_id' => (int) $data['post_id'],
            'parent_id' => (int) $data['parent_id'],
            'post_type' => $data['post_type'],
            'sku' => $data['sku'],
            'title' => $data['title'],
            'slug' => $data['slug'],
            'price' => null !== $data['price'] ? (float) $data['price'] : null,
            'stock_status' => $data['stock_status'],
            'primary_cat_id' => null !== $data['primary_cat_id'] ? (int) $data['primary_cat_id'] : null,
            'attributes_filter' => $data['attributes_filter'],
            'search_text' => $data['search_text'],
            'updated_at' => $data['updated_at']
        );

        // Include any ACF custom columns
        foreach ($data as $key => $val) {
            if (strpos($key, 'cf_') === 0) {
                $payload[$key] = $val;
            }
        }

        $url = $this->host . '/' . $this->index_name . '/_doc/' . $post_id;
        $args = array(
            'method'    => 'PUT',
            'headers'   => $this->get_headers(),
            'body'      => wp_json_encode($payload),
            'sslverify' => false,
            'timeout'   => 5
        );

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            error_log('UWO OpenSearch indexing failed: ' . $response->get_error_message());
            return false;
        }

        return wp_remote_retrieve_response_code($response) === 200 || wp_remote_retrieve_response_code($response) === 201;
    }

    /**
     * Delete item from OpenSearch index.
     */
    public function delete_item($post_id) {
        if (!$this->is_connected()) {
            return false;
        }

        $url = $this->host . '/' . $this->index_name . '/_doc/' . $post_id;
        $args = array(
            'method'    => 'DELETE',
            'headers'   => $this->get_headers(),
            'sslverify' => false,
            'timeout'   => 5
        );

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        return $code === 200 || $code === 404; // 404 is acceptable if it was already deleted
    }

    /**
     * Perform complex search in OpenSearch.
     */
    public function search($query_body) {
        if (!$this->is_connected()) {
            return false;
        }

        $url = $this->host . '/' . $this->index_name . '/_search';
        $args = array(
            'method'    => 'POST',
            'headers'   => $this->get_headers(),
            'body'      => wp_json_encode($query_body),
            'sslverify' => false,
            'timeout'   => 10
        );

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return false;
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }
}
