<?php
if (!defined('ABSPATH')) {
    exit;
}

// 1. Fetch public post types to offer indexing checklists
$all_post_types = get_post_types(array('public' => true), 'objects');
$enabled_post_types = get_option('uwo_enabled_post_types', array('product'));
$active_mode = get_option('uwo_engine_mode', 'mysql');

// 2. Perform live diagnostic connections
$redis = \UWO\Redis::get_instance();
$redis_online = $redis->is_connected();

$opensearch = \UWO\OpenSearch::get_instance();
$opensearch_online = $opensearch->is_connected();

global $wpdb;
$table_name = $wpdb->prefix . 'uwo_items_index';
$db_online = ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name);

// 3. Count total records to index
$total_posts = 0;
if (!empty($enabled_post_types)) {
    $count_query = new \WP_Query(array(
        'post_type'      => $enabled_post_types,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids'
    ));
    $total_posts = count($count_query->posts);
}

// 4. Count current indexed items
$indexed_posts = 0;
if ($db_online) {
    $indexed_posts = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table_name}`");
}
?>

<div class="wrap uwo-admin-wrap">
    
    <!-- Anchoring point for premium AJAX messages -->
    <div id="uwo-notice-anchor" style="display:none;"></div>

    <!-- Stunning Header -->
    <header class="uwo-header">
        <div class="uwo-header-content">
            <div class="uwo-logo-area">
                <h1>Ultimate WooCommerce Optimize</h1>
                <p>Flat Indexing Acceleration Engine & Headless REST API</p>
            </div>
            <div>
                <span class="uwo-badge-premium">Premium Enterprise v1.0</span>
            </div>
        </div>
    </header>

    <div class="uwo-grid">
        
        <!-- Column 1: Config Fields -->
        <main>
            <form method="post" action="options.php">
                <?php settings_fields('uwo_settings_group'); ?>
                
                <!-- Card: CPT Settings -->
                <section class="uwo-card">
                    <h2 class="uwo-card-title">
                        <span class="dashicons dashicons-admin-post"></span>
                        Custom Post Types to Accelerate
                    </h2>
                    <p style="color: #94a3b8; font-size: 0.9rem; margin-bottom: 20px;">
                        Select which custom post types will be automatically synchronized and mapped to the flat database table.
                    </p>
                    <div class="uwo-checkbox-grid">
                        <?php foreach ($all_post_types as $slug => $obj) : 
                            if (in_array($slug, array('attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request'), true)) continue;
                            $checked = in_array($slug, $enabled_post_types, true) ? 'checked' : '';
                        ?>
                            <label class="uwo-checkbox-card">
                                <input type="checkbox" name="uwo_enabled_post_types[]" value="<?php echo esc_attr($slug); ?>" <?php echo $checked; ?> />
                                <span><?php echo esc_html($obj->labels->name); ?></span>
                                <code style="font-size:0.75rem; opacity:0.6;">(<?php echo esc_html($slug); ?>)</code>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </section>

                <!-- Card: Acceleration Engine Mode Selection -->
                <section class="uwo-card">
                    <h2 class="uwo-card-title">
                        <span class="dashicons dashicons-dashboard"></span>
                        Acceleration Engine Mode
                    </h2>
                    
                    <div class="uwo-mode-selector">
                        <label class="uwo-mode-card <?php echo ($active_mode === 'mysql') ? 'active' : ''; ?>">
                            <input type="radio" name="uwo_engine_mode" value="mysql" <?php checked($active_mode, 'mysql'); ?> />
                            <h3>MySQL Flat Only</h3>
                            <p>Fast single-table indexing. Perfect for sites with 5k - 20k records.</p>
                        </label>
                        
                        <label class="uwo-mode-card <?php echo ($active_mode === 'redis') ? 'active' : ''; ?>">
                            <input type="radio" name="uwo_engine_mode" value="redis" <?php checked($active_mode, 'redis'); ?> />
                            <h3>MySQL + Redis</h3>
                            <p>Supercharges list & key queries with Redis Object Caching.</p>
                        </label>
                        
                        <label class="uwo-mode-card <?php echo ($active_mode === 'opensearch') ? 'active' : ''; ?>">
                            <input type="radio" name="uwo_engine_mode" value="opensearch" <?php checked($active_mode, 'opensearch'); ?> />
                            <h3>MySQL + OS Search</h3>
                            <p>Primary indexing for 100k - 1M+ items. Headless ready.</p>
                        </label>
                    </div>

                    <!-- Subcard: Redis Settings -->
                    <div id="uwo-redis-settings" style="display:none; border-top: 1px solid rgba(255,255,255,0.05); padding-top:20px; margin-top:20px;">
                        <h4 style="margin-top:0; color:#f8fafc; font-size:1rem; font-weight:600;">Redis Cache Credentials</h4>
                        <div style="display:grid; grid-template-columns: 2fr 1fr; gap:15px;">
                            <div class="uwo-form-group">
                                <label>Host IP/DNS</label>
                                <input type="text" name="uwo_redis_host" value="<?php echo esc_attr(get_option('uwo_redis_host', '127.0.0.1')); ?>" placeholder="127.0.0.1" />
                            </div>
                            <div class="uwo-form-group">
                                <label>Port</label>
                                <input type="number" name="uwo_redis_port" value="<?php echo esc_attr(get_option('uwo_redis_port', '6379')); ?>" placeholder="6379" />
                            </div>
                        </div>
                        <div style="display:grid; grid-template-columns: 2fr 1fr; gap:15px;">
                            <div class="uwo-form-group">
                                <label>Auth Password</label>
                                <input type="password" name="uwo_redis_password" value="<?php echo esc_attr(get_option('uwo_redis_password', '')); ?>" placeholder="••••••••" />
                            </div>
                            <div class="uwo-form-group">
                                <label>Database Index</label>
                                <input type="number" name="uwo_redis_db" value="<?php echo esc_attr(get_option('uwo_redis_db', '0')); ?>" placeholder="0" />
                            </div>
                        </div>
                    </div>

                    <!-- Subcard: OpenSearch Settings -->
                    <div id="uwo-opensearch-settings" style="display:none; border-top: 1px solid rgba(255,255,255,0.05); padding-top:20px; margin-top:20px;">
                        <h4 style="margin-top:0; color:#f8fafc; font-size:1rem; font-weight:600;">OpenSearch / Elasticsearch Credentials</h4>
                        <div class="uwo-form-group">
                            <label>Cluster Endpoint URL</label>
                            <input type="text" name="uwo_opensearch_host" value="<?php echo esc_attr(get_option('uwo_opensearch_host', '')); ?>" placeholder="https://your-opensearch-cluster.com" />
                        </div>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                            <div class="uwo-form-group">
                                <label>Username</label>
                                <input type="text" name="uwo_opensearch_user" value="<?php echo esc_attr(get_option('uwo_opensearch_user', '')); ?>" placeholder="admin" />
                            </div>
                            <div class="uwo-form-group">
                                <label>Password</label>
                                <input type="password" name="uwo_opensearch_pass" value="<?php echo esc_attr(get_option('uwo_opensearch_pass', '')); ?>" placeholder="••••••••" />
                            </div>
                        </div>
                        <div class="uwo-form-group">
                            <label>Index Alias Name</label>
                            <input type="text" name="uwo_opensearch_index" value="<?php echo esc_attr(get_option('uwo_opensearch_index', 'uwo_items_index')); ?>" placeholder="uwo_items_index" />
                        </div>
                    </div>
                </section>

                <div class="uwo-submit-area">
                    <button type="submit" class="uwo-btn uwo-btn-primary">Save Optimization Settings</button>
                </div>
            </form>
        </main>

        <!-- Column 2: Dashboard Real-time status -->
        <aside>
            
            <!-- Connection Diagnostics Card -->
            <section class="uwo-card">
                <h2 class="uwo-card-title">
                    <span class="dashicons dashicons-admin-plugins"></span>
                    Live Cluster Status
                </h2>
                
                <div class="uwo-status-row">
                    <div class="uwo-status-info">
                        <span class="dashicons dashicons-database" style="color:#3b82f6;"></span>
                        <span class="status-title">Flat DB Schema</span>
                    </div>
                    <div class="uwo-indicator">
                        <span class="indicator-dot <?php echo $db_online ? 'online' : 'offline'; ?>"></span>
                        <span><?php echo $db_online ? 'Installed' : 'Corrupted'; ?></span>
                    </div>
                </div>

                <div class="uwo-status-row">
                    <div class="uwo-status-info">
                        <span class="dashicons dashicons-update" style="color:#a78bfa;"></span>
                        <span class="status-title">Redis Cache Node</span>
                    </div>
                    <div class="uwo-indicator">
                        <span class="indicator-dot <?php echo $redis_online ? 'online' : 'offline'; ?>"></span>
                        <span><?php echo $redis_online ? 'Online' : 'Offline'; ?></span>
                    </div>
                </div>

                <div class="uwo-status-row">
                    <div class="uwo-status-info">
                        <span class="dashicons dashicons-search" style="color:#f59e0b;"></span>
                        <span class="status-title">OpenSearch Cluster</span>
                    </div>
                    <div class="uwo-indicator">
                        <span class="indicator-dot <?php echo $opensearch_online ? 'online' : 'offline'; ?>"></span>
                        <span><?php echo $opensearch_online ? 'Online' : 'Offline'; ?></span>
                    </div>
                </div>
            </section>

            <!-- Sync Control Card -->
            <section class="uwo-card">
                <h2 class="uwo-card-title">
                    <span class="dashicons dashicons-controls-play"></span>
                    Sync Control Center
                </h2>
                
                <div class="uwo-sync-panel">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                        <span style="color:#cbd5e1; font-weight:500;">Indexed Items</span>
                        <span class="uwo-badge-green" style="font-size:0.9rem; padding:4px 10px;"><?php echo $indexed_posts; ?> / <?php echo $total_posts; ?></span>
                    </div>

                    <!-- Progress bar -->
                    <div id="uwo-progress-panel" style="display: none;">
                        <div class="uwo-progress-container">
                            <div id="uwo-progress-bar-fill" class="uwo-progress-bar"></div>
                        </div>
                        <div class="uwo-progress-stats">
                            <span id="uwo-progress-percentage">0%</span>
                            <span id="uwo-progress-count">0 / 0</span>
                        </div>
                    </div>

                    <button type="button" id="uwo-start-reindex" class="uwo-btn uwo-btn-primary uwo-btn-block" style="margin-top:10px;">
                        Reindex All Records
                    </button>
                    <p style="margin: 15px 0 0 0; color: #94a3b8; font-size: 0.78rem; line-height: 1.4;">
                        Synchronizes all currently published posts of selected CPTs directly into the flat tables. This occurs step-by-step to prevent server timeouts.
                    </p>
                </div>
            </section>
        </aside>
    </div>
</div>
