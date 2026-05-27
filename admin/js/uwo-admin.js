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
});
