<?php
namespace UWO;

/**
 * Handles Redis Object Caching, connection state, and key-value sync.
 */
class Redis {

    private static $instance = null;
    private $redis = null;
    private $connected = false;

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
     * Private constructor to establish connection.
     */
    private function __construct() {
        $this->connect();
    }

    /**
     * Connect to Redis instance using native Redis extension.
     */
    public function connect() {
        if (!class_exists('Redis')) {
            $this->connected = false;
            return;
        }

        $host = defined('UWO_REDIS_HOST') ? UWO_REDIS_HOST : get_option('uwo_redis_host', '127.0.0.1');
        $port = defined('UWO_REDIS_PORT') ? UWO_REDIS_PORT : get_option('uwo_redis_port', 6379);
        $password = defined('UWO_REDIS_PASSWORD') ? UWO_REDIS_PASSWORD : get_option('uwo_redis_password', '');
        $db = defined('UWO_REDIS_DB') ? UWO_REDIS_DB : get_option('uwo_redis_db', 0);

        try {
            $this->redis = new \Redis();
            $conn = $this->redis->connect($host, (int) $port, 1.5); // 1.5s timeout
            
            if ($conn) {
                if (!empty($password)) {
                    $this->redis->auth($password);
                }
                $this->redis->select((int) $db);
                $this->connected = true;
            } else {
                $this->connected = false;
            }
        } catch (\Exception $e) {
            $this->connected = false;
            error_log('UWO Redis Connection failed: ' . $e->getMessage());
        }
    }

    /**
     * Check if Redis is successfully connected.
     */
    public function is_connected() {
        if (!$this->connected) {
            // Retry connecting just in case
            $this->connect();
        }
        return $this->connected;
    }

    /**
     * Sync single item to Redis cache for instant retrieve.
     */
    public function sync_item($post_id, $data) {
        if (!$this->is_connected()) {
            return false;
        }

        $key = 'uwo:item:' . $post_id;
        try {
            // Store item payload
            $this->redis->set($key, wp_json_encode($data));
            // Cache item under post_type set for fast count queries
            $this->redis->sAdd('uwo:types:' . $data['post_type'], $post_id);
            return true;
        } catch (\Exception $e) {
            error_log('UWO Redis sync failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete single item from Redis cache.
     */
    public function delete_item($post_id) {
        if (!$this->is_connected()) {
            return false;
        }

        try {
            $key = 'uwo:item:' . $post_id;
            $data_json = $this->redis->get($key);
            if ($data_json) {
                $data = json_decode($data_json, true);
                if (isset($data['post_type'])) {
                    $this->redis->sRem('uwo:types:' . $data['post_type'], $post_id);
                }
            }
            $this->redis->del($key);
            return true;
        } catch (\Exception $e) {
            error_log('UWO Redis delete failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Cache query results with TTL.
     */
    public function cache_query($query_key, $results, $ttl = 3600) {
        if (!$this->is_connected()) {
            return false;
        }

        try {
            $key = 'uwo:query:' . md5($query_key);
            $this->redis->setEx($key, $ttl, wp_json_encode($results));
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get cached query results.
     */
    public function get_cached_query($query_key) {
        if (!$this->is_connected()) {
            return false;
        }

        try {
            $key = 'uwo:query:' . md5($query_key);
            $data = $this->redis->get($key);
            return $data ? json_decode($data, true) : false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Flush all cached items and queries belonging to UWO.
     */
    public function flush_cache() {
        if (!$this->is_connected()) {
            return false;
        }

        try {
            $keys = $this->redis->keys('uwo:*');
            if (!empty($keys)) {
                $this->redis->del($keys);
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
