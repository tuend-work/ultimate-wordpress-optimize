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
                <h1>Ultimate WordPress Optimize</h1>
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

            <!-- Custom Filter Builder Center -->
            <section class="uwo-card" id="uwo-filter-builder-section" style="margin-top: 30px;">
                <h2 class="uwo-card-title">
                    <span class="dashicons dashicons-filter"></span>
                    Custom Filter Builder Center
                </h2>
                <p style="color: #94a3b8; font-size: 0.9rem; margin-bottom: 25px;">
                    Create autonomous high-performance faceted search filters. Each filter produces a unique shortcode that can be embedded into any page or widget.
                </p>

                <!-- List of Existing Filters -->
                <div class="uwo-existing-filters" style="margin-bottom: 35px;">
                    <h3 style="color:#f8fafc; font-size:1.05rem; font-weight:600; margin-bottom:15px; border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:10px;">Active Filters & Shortcodes</h3>
                    <?php 
                    $existing_filters = \UWO\FilterBuilder::get_all_filters();
                    if (empty($existing_filters)) : 
                    ?>
                        <div class="uwo-no-filters-alert" style="background:rgba(255,255,255,0.02); border: 1px dashed rgba(255,255,255,0.1); border-radius:12px; padding:20px; text-align:center; color:#94a3b8;">
                            <span class="dashicons dashicons-info" style="font-size:24px; width:24px; height:24px; margin-bottom:10px; opacity:0.6;"></span>
                            <p style="margin:0; font-size:0.9rem;">No custom filters created yet. Use the form below to build your first filter!</p>
                        </div>
                    <?php else : ?>
                        <div class="uwo-filters-grid" style="display:grid; gap:15px;">
                            <?php foreach ($existing_filters as $fid => $f) : 
                                $active_cols = array_keys($f['fields']);
                                $chips = array();
                                foreach ($active_cols as $c) {
                                    $chips[] = '<code style="background:rgba(139,92,246,0.15); color:#c084fc; border:1px solid rgba(139,92,246,0.3); border-radius:4px; padding:2px 6px; font-size:11px; margin-right:4px;">' . esc_html(str_replace('cf_', '', $c)) . '</code>';
                                }
                            ?>
                                <div class="uwo-filter-row" style="background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.04); border-radius:12px; padding:15px; display:flex; justify-content:space-between; align-items:center; gap:15px; flex-wrap:wrap;">
                                    <div>
                                        <h4 style="margin:0 0 5px 0; color:#f8fafc; font-size:0.98rem; font-weight:600;"><?php echo esc_html($f['name']); ?> <span style="font-size:11px; font-weight:400; color:#94a3b8; background:rgba(255,255,255,0.05); padding:2px 8px; border-radius:99px; margin-left:5px; text-transform:uppercase;"><?php echo esc_html($f['post_type']); ?></span></h4>
                                        <div style="display:flex; flex-wrap:wrap; gap:4px; align-items:center;">
                                            <span style="font-size:11px; color:#64748b; margin-right:5px;">Columns:</span>
                                            <?php echo implode('', $chips); ?>
                                        </div>
                                    </div>
                                    <div style="display:flex; align-items:center; gap:15px;">
                                        <div style="display:flex; flex-direction:column; align-items:flex-end;">
                                            <span style="font-size:10px; color:#64748b; margin-bottom:3px; text-transform:uppercase; font-weight:600; letter-spacing:0.5px;">Click to Copy Shortcode</span>
                                            <input type="text" readonly value='[uwo_filter id="<?php echo esc_attr($fid); ?>"]' class="uwo-copy-shortcode-input" title="Click to copy shortcode" style="width:230px; font-family:monospace; font-size:12px; padding:6px 10px; text-align:center; background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.08); border-radius:8px; cursor:pointer; color:#a78bfa; transition:all 0.2s ease;" />
                                        </div>
                                        <button type="button" class="uwo-delete-filter-btn" data-filter-id="<?php echo esc_attr($fid); ?>" style="background:rgba(239, 68, 68, 0.1); border:1px solid rgba(239, 68, 68, 0.2); color:#f87171; padding:8px 14px; border-radius:8px; font-size:12px; cursor:pointer; font-weight:600; transition:all 0.2s ease; display:flex; align-items:center; gap:5px;"><span class="dashicons dashicons-trash" style="font-size:14px; width:14px; height:14px;"></span> Delete</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Create New Filter Form -->
                <div class="uwo-new-filter-builder" style="background:rgba(255,255,255,0.01); border:1px solid rgba(255,255,255,0.03); border-radius:16px; padding:25px;">
                    <h3 style="color:#f8fafc; font-size:1.05rem; font-weight:600; margin-top:0; margin-bottom:20px;">Create New Filter</h3>
                    
                    <form id="uwo-filter-builder-form">
                        <?php wp_nonce_field('uwo-filter-builder-nonce', 'security'); ?>
                        
                        <div style="display:grid; grid-template-columns: 2fr 1fr; gap:20px; margin-bottom:25px;">
                            <div class="uwo-form-group" style="margin:0;">
                                <label style="font-weight:600; color:#cbd5e1;">Filter Name</label>
                                <input type="text" name="name" required placeholder="e.g. Shop Side Catalog Filter" style="background: rgba(255,255,255,0.03);" />
                            </div>
                            <div class="uwo-form-group" style="margin:0;">
                                <label style="font-weight:600; color:#cbd5e1;">Target Post Type</label>
                                <select name="post_type" required style="background: rgba(255,255,255,0.03);">
                                    <?php foreach ($all_post_types as $slug => $obj) : 
                                        if (!in_array($slug, $enabled_post_types, true)) continue;
                                    ?>
                                        <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($obj->labels->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="uwo-form-group">
                            <label style="font-weight:600; color:#cbd5e1; margin-bottom:12px;">Select Columns & Taxonomies to Filter</label>
                            <p style="color:#94a3b8; font-size:0.8rem; margin:-5px 0 15px 0; line-height:1.4;">
                                Toggle check on database columns or taxonomies (categories/tags) you want to offer as active filter widgets.
                            </p>

                            <!-- Column & Taxonomy Config Rows -->
                            <div style="display:grid; gap:12px; background:rgba(0,0,0,0.15); padding:15px; border-radius:12px; border:1px solid rgba(255,255,255,0.03);">
                                <div style="color: #a78bfa; font-weight:600; font-size:0.9rem; margin-bottom:5px; border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:5px;">Database Schema Columns</div>
                                <?php 
                                $db = \UWO\Database::get_instance();
                                $columns = $db->get_table_columns();
                                $exclude_cols = array('id', 'post_id', 'parent_id', 'post_type', 'slug', 'payload_json', 'search_text', 'updated_at', 'attributes_filter');
                                
                                foreach ($columns as $col) : 
                                    if (in_array($col, $exclude_cols, true)) continue;
                                    $nice_name = str_replace('cf_', '', $col);
                                    $nice_name = ucfirst(str_replace('_', ' ', $nice_name));
                                ?>
                                    <div class="uwo-column-row" style="display:grid; grid-template-columns: auto 2fr 2fr 2fr; gap:20px; align-items:center; padding:10px 15px; background:rgba(255,255,255,0.01); border:1px solid rgba(255,255,255,0.02); border-radius:8px; transition:all 0.2s ease;">
                                        <div style="display:flex; align-items:center;">
                                            <input type="checkbox" class="uwo-column-checkbox" style="width:18px; height:18px; cursor:pointer;" />
                                        </div>
                                        <div style="font-weight:600; font-size:0.92rem; color:#f1f5f9; display:flex; align-items:center; gap:8px;">
                                            <span><?php echo esc_html($nice_name); ?></span>
                                            <code style="font-size:10px; color:#8b5cf6; background:rgba(139,92,246,0.1); padding:1px 6px; border-radius:4px; font-weight:400;"><?php echo esc_html($col); ?></code>
                                        </div>
                                        <div>
                                            <input type="text" name="fields[<?php echo esc_attr($col); ?>][label]" disabled placeholder="<?php echo esc_attr($nice_name); ?>" style="width:100%; font-size:12px; padding:6px 10px; border-radius:6px; background:rgba(255,255,255,0.02);" />
                                        </div>
                                        <div>
                                            <select name="fields[<?php echo esc_attr($col); ?>][type]" disabled style="width:100%; font-size:12px; padding:6px 10px; border-radius:6px; background:rgba(255,255,255,0.02);">
                                                <option value="checkbox">Multi-Checkboxes</option>
                                                <option value="select">Dropdown Select</option>
                                                <?php if ($col === 'price' || strpos($col, 'cf_') === 0) : ?>
                                                    <option value="range">Range Field</option>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <div style="color: #a78bfa; font-weight:600; font-size:0.9rem; margin-top:15px; margin-bottom:5px; border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:5px;">Registered Taxonomies (Categories, Tags)</div>
                                <?php 
                                $taxonomies = array();
                                foreach ($enabled_post_types as $pt) {
                                    $pt_taxes = get_object_taxonomies($pt, 'objects');
                                    foreach ($pt_taxes as $tax) {
                                        if ($tax->public && $tax->show_ui) {
                                            $taxonomies[$tax->name] = $tax;
                                        }
                                    }
                                }

                                foreach ($taxonomies as $tax_name => $tax_obj) : 
                                    $nice_name = $tax_obj->label;
                                ?>
                                    <div class="uwo-column-row" style="display:grid; grid-template-columns: auto 2fr 2fr 2fr; gap:20px; align-items:center; padding:10px 15px; background:rgba(255,255,255,0.01); border:1px solid rgba(255,255,255,0.02); border-radius:8px; transition:all 0.2s ease;">
                                        <div style="display:flex; align-items:center;">
                                            <input type="checkbox" class="uwo-column-checkbox" style="width:18px; height:18px; cursor:pointer;" />
                                        </div>
                                        <div style="font-weight:600; font-size:0.92rem; color:#f1f5f9; display:flex; align-items:center; gap:8px;">
                                            <span><?php echo esc_html($nice_name); ?></span>
                                            <code style="font-size:10px; color:#3b82f6; background:rgba(59,130,246,0.1); padding:1px 6px; border-radius:4px; font-weight:400;"><?php echo esc_html($tax_name); ?></code>
                                        </div>
                                        <div>
                                            <input type="text" name="fields[<?php echo esc_attr($tax_name); ?>][label]" disabled placeholder="<?php echo esc_attr($nice_name); ?>" style="width:100%; font-size:12px; padding:6px 10px; border-radius:6px; background:rgba(255,255,255,0.02);" />
                                        </div>
                                        <div>
                                            <select name="fields[<?php echo esc_attr($tax_name); ?>][type]" disabled style="width:100%; font-size:12px; padding:6px 10px; border-radius:6px; background:rgba(255,255,255,0.02);">
                                                <option value="checkbox">Multi-Checkboxes</option>
                                                <option value="select">Dropdown Select</option>
                                            </select>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div style="text-align: right; margin-top:20px;">
                            <button type="submit" id="uwo-create-filter-btn" class="uwo-btn uwo-btn-primary" style="padding:10px 24px; font-size:0.9rem;">
                                Generate Filter Shortcode
                            </button>
                        </div>
                    </form>
                </div>
            </section>
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

            <!-- GitHub Update Center Card -->
            <section class="uwo-card">
                <h2 class="uwo-card-title">
                    <span class="dashicons dashicons-update"></span>
                    System Update
                </h2>
                <div style="text-align: center; padding: 10px 0;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                        <span style="color:#cbd5e1; font-weight:500;">Current Version</span>
                        <span class="uwo-badge-premium" style="font-size:0.85rem; padding:4px 10px; background: rgba(139, 92, 246, 0.2); box-shadow: none; border-color: rgba(139, 92, 246, 0.4); color: #c084fc;">v<?php echo UWO_VERSION; ?></span>
                    </div>
                    <button type="button" id="uwo-github-update-btn" class="uwo-btn uwo-btn-secondary uwo-btn-block">
                        <span class="dashicons dashicons-cloud" style="vertical-align: text-bottom; margin-right: 5px;"></span>
                        Update from GitHub
                    </button>
                    <p style="margin: 15px 0 0 0; color: #94a3b8; font-size: 0.78rem; line-height: 1.4;">
                        Pulls the latest stable version directly from the main branch of <a href="https://github.com/tuend-work/ultimate-wordpress-optimize" target="_blank" style="color:#8b5cf6; text-decoration:none;">GitHub</a> and applies updates seamlessly.
                    </p>
                </div>
            </section>
        </aside>
    </div>
</div>
