/**
 * Ultimate WooCommerce Optimize - Admin dashboard JS controls
 */
jQuery(document).ready(function($) {
    
    // Toggle Settings panel visibility based on active engine mode
    function toggleEnginePanels() {
        var selectedMode = $('input[name="uwo_engine_mode"]:checked').val();
        
        // Mode cards styling
        $('.uwo-mode-card').removeClass('active');
        $('input[name="uwo_engine_mode"]:checked').closest('.uwo-mode-card').addClass('active');

        if (selectedMode === 'mysql') {
            $('#uwo-redis-settings').slideUp(300);
            $('#uwo-opensearch-settings').slideUp(300);
        } else if (selectedMode === 'redis') {
            $('#uwo-redis-settings').slideDown(300);
            $('#uwo-opensearch-settings').slideUp(300);
        } else if (selectedMode === 'opensearch') {
            $('#uwo-redis-settings').slideDown(300);
            $('#uwo-opensearch-settings').slideDown(300);
        }
    }

    // Initialize toggle state
    toggleEnginePanels();

    // Trigger toggle when radio changes
    $('input[name="uwo_engine_mode"]').on('change', function() {
        toggleEnginePanels();
    });

    // Step-by-step Ajax Reindexing
    var isIndexing = false;
    var currentOffset = 0;
    var batchSize = 100;
    var totalPosts = parseInt(uwo_admin_params.total_posts) || 0;

    $('#uwo-start-reindex').on('click', function(e) {
        e.preventDefault();
        
        if (isIndexing) return;
        if (totalPosts === 0) {
            alert(uwo_admin_params.i18n.nothing_sync);
            return;
        }

        isIndexing = true;
        currentOffset = 0;
        
        $(this).prop('disabled', true).text(uwo_admin_params.i18n.indexing);
        $('#uwo-progress-panel').slideDown(300);
        updateProgressBar(0);
        
        // Start recursive batch run
        runReindexBatch();
    });

    function runReindexBatch() {
        $.ajax({
            url: uwo_admin_params.reindex_url,
            method: 'POST',
            contentType: 'application/json',
            headers: {
                'X-WP-Nonce': uwo_admin_params.rest_nonce
            },
            data: JSON.stringify({
                offset: currentOffset,
                batch_size: batchSize
            }),
            success: function(response) {
                if (response.success) {
                    currentOffset = response.offset;
                    var percent = Math.min(100, Math.round((currentOffset / totalPosts) * 100));
                    
                    updateProgressBar(percent, currentOffset, totalPosts);

                    if (!response.finished && currentOffset < totalPosts) {
                        // Queue next batch
                        runReindexBatch();
                    } else {
                        // Indexing completed successfully!
                        indexingCompleted(true);
                    }
                } else {
                    indexingCompleted(false);
                }
            },
            error: function() {
                indexingCompleted(false);
            }
        });
    }

    function updateProgressBar(percent, current, total) {
        $('#uwo-progress-bar-fill').css('width', percent + '%');
        $('#uwo-progress-percentage').text(percent + '%');
        
        if (current !== undefined && total !== undefined) {
            $('#uwo-progress-count').text(current + ' / ' + total);
        }
    }

    function indexingCompleted(success) {
        isIndexing = false;
        $('#uwo-start-reindex').prop('disabled', false).text('Reindex All Records');
        
        if (success) {
            updateProgressBar(100, totalPosts, totalPosts);
            
            // Render beautiful custom completion notice
            var noticeHtml = '<div class="uwo-notice"><span class="dashicons dashicons-yes uwo-notice-icon"></span>' + 
                             uwo_admin_params.i18n.completed + ' ' + 
                             uwo_admin_params.i18n.indexed.replace('%s', totalPosts) + '</div>';
            
            $('#uwo-notice-anchor').html(noticeHtml).slideDown(300);
            
            // Dissolve notice after 6 seconds
            setTimeout(function() {
                $('#uwo-notice-anchor').slideUp(300);
            }, 6000);
        } else {
            alert(uwo_admin_params.i18n.failed);
        }
    }

    // GitHub self update execution
    var isUpdating = false;

    $('#uwo-github-update-btn').on('click', function(e) {
        e.preventDefault();
        
        if (isUpdating) return;
        
        if (!confirm('Are you sure you want to pull the latest version and update this plugin from GitHub? This will overwrite local files.')) {
            return;
        }

        isUpdating = true;
        var $btn = $(this);
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin" style="vertical-align: text-bottom; margin-right: 5px;"></span> ' + uwo_admin_params.i18n.updating);

        $.ajax({
            url: uwo_admin_params.update_url,
            method: 'POST',
            contentType: 'application/json',
            headers: {
                'X-WP-Nonce': uwo_admin_params.rest_nonce
            },
            success: function(response) {
                isUpdating = false;
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-cloud" style="vertical-align: text-bottom; margin-right: 5px;"></span> Update from GitHub');
                
                if (response.success) {
                    var noticeHtml = '<div class="uwo-notice"><span class="dashicons dashicons-yes uwo-notice-icon"></span>' + 
                                     uwo_admin_params.i18n.updated + '</div>';
                    $('#uwo-notice-anchor').html(noticeHtml).slideDown(300);
                    
                    // Reload page after 3 seconds to apply any updated admin panel files
                    setTimeout(function() {
                        location.reload();
                    }, 3000);
                } else {
                    alert(response.message || uwo_admin_params.i18n.update_failed);
                }
            },
            error: function(xhr) {
                isUpdating = false;
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-cloud" style="vertical-align: text-bottom; margin-right: 5px;"></span> Update from GitHub');
                
                var errorMsg = uwo_admin_params.i18n.update_failed;
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg = xhr.responseJSON.message;
                }
                alert(errorMsg);
            }
        });
    });

    // === Filter Builder Interactivity & AJAX hooks ===

    // 1. Manage Column Checklist: toggle disabled state of input fields in real-time
    $('.uwo-column-checkbox').on('change', function() {
        var $checkbox = $(this);
        var $row = $checkbox.closest('.uwo-column-row');
        var isChecked = $checkbox.is(':checked');

        $row.find('input[type="text"], select').prop('disabled', !isChecked);

        if (isChecked) {
            $row.css('background', 'rgba(139, 92, 246, 0.05)').css('border-color', 'rgba(139, 92, 246, 0.3)');
        } else {
            $row.css('background', 'rgba(255, 255, 255, 0.01)').css('border-color', 'rgba(255, 255, 255, 0.02)');
        }
    });

    // 2. Click to automatically copy shortcode
    $('.uwo-copy-shortcode-input').on('click', function() {
        var $input = $(this);
        $input.select();
        document.execCommand('copy');

        // Custom temporary visually stunning visual copy feedback
        var originalColor = $input.css('color');
        $input.css('color', '#10b981').css('border-color', '#10b981');
        setTimeout(function() {
            $input.css('color', originalColor).css('border-color', 'rgba(255,255,255,0.08)');
        }, 1500);
    });

    // 3. Submit Creator Form via AJAX to Save Filter
    $('#uwo-filter-builder-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $btn = $('#uwo-create-filter-btn');

        // Ensure at least one column is checked
        if ($('.uwo-column-checkbox:checked').length === 0) {
            alert('Please select at least one column to build your filter!');
            return;
        }

        $btn.prop('disabled', true).text('Generating Shortcode...');

        // Perform AJAX call using WordPress ajaxurl
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: $form.serialize() + '&action=uwo_save_filter',
            success: function(response) {
                $btn.prop('disabled', false).text('Generate Filter Shortcode');
                if (response.success) {
                    var noticeHtml = '<div class="uwo-notice"><span class="dashicons dashicons-yes uwo-notice-icon"></span>' + response.data.message + '</div>';
                    $('#uwo-notice-anchor').html(noticeHtml).slideDown(300);

                    setTimeout(function() {
                        location.reload();
                    }, 1200);
                } else {
                    alert(response.data.message || 'Failed to save filter.');
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('Generate Filter Shortcode');
                alert('Connection error occurred while saving the filter.');
            }
        });
    });

    // 4. Delete Filter via AJAX
    $('.uwo-delete-filter-btn').on('click', function(e) {
        e.preventDefault();

        var $btn = $(this);
        var filterId = $btn.data('filter-id');
        var security = $('#uwo-filter-builder-form').find('input[name="security"]').val();

        if (!confirm('Are you sure you want to delete this custom filter? This cannot be undone.')) {
            return;
        }

        $btn.prop('disabled', true).text('Deleting...');

        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'uwo_delete_filter',
                filter_id: filterId,
                security: security
            },
            success: function(response) {
                if (response.success) {
                    var noticeHtml = '<div class="uwo-notice"><span class="dashicons dashicons-yes uwo-notice-icon"></span>' + response.data.message + '</div>';
                    $('#uwo-notice-anchor').html(noticeHtml).slideDown(300);

                    setTimeout(function() {
                        location.reload();
                    }, 1200);
                } else {
                    $btn.prop('disabled', false).text('Delete');
                    alert(response.data.message || 'Failed to delete filter.');
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('Delete');
                alert('Connection error occurred while deleting the filter.');
            }
        });
    });
});
