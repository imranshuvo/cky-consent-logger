<?php
// Enhanced includes/admin.php

add_action('admin_menu', 'ccl_add_submenu_page', 100);

function ccl_add_submenu_page() {
    add_submenu_page(
        'cookie-law-info',
        __('Consent Logs', 'cookieyes-consent-logger'),
        __('Consent Logs', 'cookieyes-consent-logger'),
        'manage_options',
        'cookieyes-consent-logs',
        'ccl_render_admin_page'
    );
}


// Add admin scripts and styles
add_action('admin_enqueue_scripts', function($hook) {
    if ($hook !== 'cookie-law-info_page_cookieyes-consent-logs') {
        return;
    }
    
    wp_enqueue_script('ccl-admin-js', CCL_URL . 'assets/admin.js', ['jquery'], '1.0.0', true);
    wp_enqueue_style('ccl-admin-css', CCL_URL . 'assets/admin.css', [], '1.0.0');
    
    wp_localize_script('ccl-admin-js', 'ccl_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ccl_admin_nonce')
    ]);
});

function ccl_render_admin_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'cookieyes_consent_logs';
    
    // Get pagination parameters
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 10;
    $search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
    
    // Build search query
    $where_clause = '';
    $search_params = [];
    if (!empty($search)) {
        $where_clause = "WHERE consent_id LIKE %s OR ip LIKE %s OR status LIKE %s OR domain LIKE %s";
        $search_term = '%' . $wpdb->esc_like($search) . '%';
        $search_params = [$search_term, $search_term, $search_term, $search_term];
    }
    
    // Get total count
    $count_query = "SELECT COUNT(*) FROM {$table} {$where_clause}";
    $total_items = $wpdb->get_var($wpdb->prepare($count_query, $search_params));
    
    // Calculate pagination
    $total_pages = ceil($total_items / $per_page);
    $offset = ($current_page - 1) * $per_page;
    
    // Get logs with pagination
    $logs_query = "SELECT * FROM {$table} {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d";
    $query_params = array_merge($search_params, [$per_page, $offset]);
    $logs = $wpdb->get_results($wpdb->prepare($logs_query, $query_params));
    
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Consent Logs', 'cookieyes-consent-logger'); ?></h1>
        
        <div class="notice notice-warning">
            <p><strong><?php _e('GDPR Compliance Notice:', 'cookieyes-consent-logger'); ?></strong> 
            <?php _e('Consent logs must be retained and not deleted or altered for at least 12 months.', 'cookieyes-consent-logger'); ?></p>
        </div>
        
        <!-- Search and Filters -->
        <div class="ccl-admin-controls">
            <form method="GET" class="ccl-search-form">
                <input type="hidden" name="page" value="cookieyes-consent-logs">
                <div class="ccl-search-box">
                    <input type="text" name="search" value="<?php echo esc_attr($search); ?>" 
                           placeholder="<?php _e('Search by Consent ID, IP, Status, or Domain...', 'cookieyes-consent-logger'); ?>">
                    <button type="submit" class="button"><?php _e('Search', 'cookieyes-consent-logger'); ?></button>
                    <?php if (!empty($search)): ?>
                        <a href="<?php echo admin_url('admin.php?page=cookieyes-consent-logs'); ?>" class="button">
                            <?php _e('Clear', 'cookieyes-consent-logger'); ?>
                        </a>
                    <?php endif; ?>
                </div>
                
                <div class="ccl-per-page">
                    <label for="per_page"><?php _e('Show:', 'cookieyes-consent-logger'); ?></label>
                    <select name="per_page" id="per_page" onchange="this.form.submit()">
                        <option value="10" <?php selected($per_page, 10); ?>>10</option>
                        <option value="25" <?php selected($per_page, 25); ?>>25</option>
                        <option value="50" <?php selected($per_page, 50); ?>>50</option>
                        <option value="100" <?php selected($per_page, 100); ?>>100</option>
                    </select>
                    <?php _e('per page', 'cookieyes-consent-logger'); ?>
                </div>
            </form>
        </div>
        
        <!-- Results Info -->
        <div class="ccl-results-info">
            <p><?php printf(__('Showing %d-%d of %d results', 'cookieyes-consent-logger'), 
                ($offset + 1), 
                min($offset + $per_page, $total_items), 
                $total_items
            ); ?></p>
        </div>
        
        <!-- Logs Table -->
        <table class="widefat striped ccl-logs-table">
            <thead>
                <tr>
                    <th><?php _e('Date (UTC)', 'cookieyes-consent-logger'); ?></th>
                    <th><?php _e('Consent ID', 'cookieyes-consent-logger'); ?></th>
                    <th><?php _e('Domain', 'cookieyes-consent-logger'); ?></th>
                    <th><?php _e('Status', 'cookieyes-consent-logger'); ?></th>
                    <th><?php _e('IP', 'cookieyes-consent-logger'); ?></th>
                    <th><?php _e('Country', 'cookieyes-consent-logger'); ?></th>
                    <th><?php _e('Categories', 'cookieyes-consent-logger'); ?></th>
                    <th><?php _e('Actions', 'cookieyes-consent-logger'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="8" class="no-items">
                            <?php _e('No consent logs found.', 'cookieyes-consent-logger'); ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html($log->created_at); ?></td>
                            <td class="ccl-consent-id" title="<?php echo esc_attr($log->consent_id); ?>">
                                <?php echo esc_html(substr($log->consent_id, 0, 16) . '...'); ?>
                            </td>
                            <td><?php echo esc_html($log->domain); ?></td>
                            <td>
                                <span class="ccl-status ccl-status-<?php echo esc_attr($log->status); ?>">
                                    <?php echo esc_html(ucfirst($log->status)); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($log->ip); ?></td>
                            <td><?php echo esc_html($log->country ?: 'N/A'); ?></td>
                            <td>
                                <?php 
                                $categories = json_decode($log->categories, true);
                                if ($categories) {
                                    $active_cats = array_filter($categories, function($v) { return $v === true || $v === '1' || $v === 1; });
                                    echo esc_html(implode(', ', array_keys($active_cats)));
                                } else {
                                    echo 'N/A';
                                }
                                ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url(wp_nonce_url(
                                    admin_url('admin-ajax.php?action=ccl_download_pdf&consent_id=' . $log->consent_id),
                                    'ccl_download_pdf_' . $log->consent_id
                                )); ?>" 
                                   class="button button-small ccl-download-pdf" 
                                   target="_blank">
                                    <?php _e('Download PDF', 'cookieyes-consent-logger'); ?>
                                </a>
                                <button class="button button-small ccl-view-details" 
                                        data-consent-id="<?php echo esc_attr($log->consent_id); ?>">
                                    <?php _e('View Details', 'cookieyes-consent-logger'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="ccl-pagination">
                <?php
                $base_url = admin_url('admin.php?page=cookieyes-consent-logs');
                if (!empty($search)) {
                    $base_url = add_query_arg('search', urlencode($search), $base_url);
                }
                if ($per_page != 10) {
                    $base_url = add_query_arg('per_page', $per_page, $base_url);
                }
                
                echo paginate_links([
                    'base' => add_query_arg('paged', '%#%', $base_url),
                    'format' => '',
                    'current' => $current_page,
                    'total' => $total_pages,
                    'prev_text' => '&laquo; ' . __('Previous', 'cookieyes-consent-logger'),
                    'next_text' => __('Next', 'cookieyes-consent-logger') . ' &raquo;',
                    'show_all' => false,
                    'end_size' => 1,
                    'mid_size' => 2,
                    'type' => 'plain'
                ]);
                ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Modal for viewing details -->
    <div id="ccl-details-modal" class="ccl-modal" style="display: none;">
        <div class="ccl-modal-content">
            <span class="ccl-close">&times;</span>
            <h2><?php _e('Consent Details', 'cookieyes-consent-logger'); ?></h2>
            <div id="ccl-details-content"></div>
        </div>
    </div>
    <?php
}

// AJAX handler for getting consent details
add_action('wp_ajax_ccl_get_consent_details', function() {
    check_ajax_referer('ccl_admin_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }
    
    $consent_id = sanitize_text_field($_POST['consent_id']);
    if (empty($consent_id)) {
        wp_send_json_error(['message' => 'Invalid consent ID']);
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'cookieyes_consent_logs';
    $log = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE consent_id = %s", $consent_id));
    
    if (!$log) {
        wp_send_json_error(['message' => 'Consent log not found']);
    }
    
    $categories = json_decode($log->categories, true);
    
    ob_start();
    ?>
    <table class="widefat">
        <tr><th><?php _e('Consent ID', 'cookieyes-consent-logger'); ?></th><td><?php echo esc_html($log->consent_id); ?></td></tr>
        <tr><th><?php _e('Domain', 'cookieyes-consent-logger'); ?></th><td><?php echo esc_html($log->domain); ?></td></tr>
        <tr><th><?php _e('Status', 'cookieyes-consent-logger'); ?></th><td><?php echo esc_html($log->status); ?></td></tr>
        <tr><th><?php _e('IP Address', 'cookieyes-consent-logger'); ?></th><td><?php echo esc_html($log->ip); ?></td></tr>
        <tr><th><?php _e('User Agent', 'cookieyes-consent-logger'); ?></th><td><?php echo esc_html($log->ua); ?></td></tr>
        <tr><th><?php _e('Country', 'cookieyes-consent-logger'); ?></th><td><?php echo esc_html($log->country ?: 'N/A'); ?></td></tr>
        <tr><th><?php _e('Created At', 'cookieyes-consent-logger'); ?></th><td><?php echo esc_html($log->created_at); ?></td></tr>
        <tr>
            <th><?php _e('Categories', 'cookieyes-consent-logger'); ?></th>
            <td>
                <?php if ($categories): ?>
                    <ul>
                        <?php foreach ($categories as $category => $status): ?>
                            <li>
                                <strong><?php echo esc_html(ucfirst($category)); ?>:</strong> 
                                <span class="ccl-status-<?php echo $status ? 'accepted' : 'rejected'; ?>">
                                    <?php echo $status ? __('Accepted', 'cookieyes-consent-logger') : __('Rejected', 'cookieyes-consent-logger'); ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <?php _e('No category data available', 'cookieyes-consent-logger'); ?>
                <?php endif; ?>
            </td>
        </tr>
    </table>
    <?php
    $content = ob_get_clean();
    
    wp_send_json_success(['content' => $content]);
});