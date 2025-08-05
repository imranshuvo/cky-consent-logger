<?php
// includes/cookie-tracker.php

// Schedule daily cookie scan
add_action('wp', 'ccl_schedule_cookie_scan');
add_action('ccl_daily_cookie_scan', 'ccl_perform_cookie_scan');

function ccl_schedule_cookie_scan() {
    if (!wp_next_scheduled('ccl_daily_cookie_scan')) {
        wp_schedule_event(strtotime('02:00:00'), 'daily', 'ccl_daily_cookie_scan');
    }
}

// Deactivation hook to clear scheduled events
register_deactivation_hook(CCL_PATH . 'cookieyes-consent-logger.php', function() {
    wp_clear_scheduled_hook('ccl_daily_cookie_scan');
});

function ccl_perform_cookie_scan() {
    ccl_log_activity('Starting daily cookie scan');
    
    // Get current cookies from the site
    $current_cookies = ccl_scan_website_cookies();
    
    // Get previously stored cookies
    $stored_cookies = get_option('ccl_tracked_cookies', []);
    
    // Compare and find new cookies
    $new_cookies = array_diff_key($current_cookies, $stored_cookies);
    
    if (!empty($new_cookies)) {
        ccl_log_activity('Found ' . count($new_cookies) . ' new cookies');
        
        // Categorize new cookies
        foreach ($new_cookies as $cookie_name => $cookie_data) {
            $category = ccl_auto_categorize_cookie($cookie_name, $cookie_data);
            $current_cookies[$cookie_name]['category'] = $category;
            $current_cookies[$cookie_name]['discovered'] = current_time('mysql');
        }
        
        // Update stored cookies
        update_option('ccl_tracked_cookies', $current_cookies);
        
        // Try to add to CookieYes if active
        if (ccl_is_cookieyes_active()) {
            ccl_add_cookies_to_cookieyes($new_cookies);
        }
        
        // Send notification to admin
        ccl_send_cookie_notification($new_cookies);
    } else {
        ccl_log_activity('No new cookies found');
    }
}

function ccl_scan_website_cookies() {
    $cookies = [];
    
    // Method 1: Use WordPress HTTP API to scan homepage
    $response = wp_remote_get(home_url(), [
        'timeout' => 30,
        'headers' => [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]
    ]);
    
    if (!is_wp_error($response)) {
        $headers = wp_remote_retrieve_headers($response);
        if (isset($headers['set-cookie'])) {
            $set_cookies = is_array($headers['set-cookie']) ? $headers['set-cookie'] : [$headers['set-cookie']];
            foreach ($set_cookies as $cookie_header) {
                $parsed = ccl_parse_set_cookie_header($cookie_header);
                if ($parsed) {
                    $cookies[$parsed['name']] = $parsed;
                }
            }
        }
    }
    
    // Method 2: Scan JavaScript files for common cookie patterns
    $js_cookies = ccl_scan_js_for_cookies();
    $cookies = array_merge($cookies, $js_cookies);
    
    // Method 3: Check common WordPress plugin cookies
    $plugin_cookies = ccl_scan_known_plugin_cookies();
    $cookies = array_merge($cookies, $plugin_cookies);
    
    return $cookies;
}

function ccl_parse_set_cookie_header($header) {
    $parts = explode(';', $header);
    if (empty($parts[0])) return null;
    
    $name_value = explode('=', trim($parts[0]), 2);
    if (count($name_value) !== 2) return null;
    
    $cookie = [
        'name' => trim($name_value[0]),
        'value' => trim($name_value[1]),
        'domain' => '',
        'path' => '/',
        'secure' => false,
        'httponly' => false,
        'samesite' => '',
        'expires' => '',
        'source' => 'http_response'
    ];
    
    // Parse additional attributes
    for ($i = 1; $i < count($parts); $i++) {
        $attr = explode('=', trim($parts[$i]), 2);
        $attr_name = strtolower(trim($attr[0]));
        $attr_value = count($attr) > 1 ? trim($attr[1]) : true;
        
        switch ($attr_name) {
            case 'domain':
                $cookie['domain'] = $attr_value;
                break;
            case 'path':
                $cookie['path'] = $attr_value;
                break;
            case 'expires':
                $cookie['expires'] = $attr_value;
                break;
            case 'secure':
                $cookie['secure'] = true;
                break;
            case 'httponly':
                $cookie['httponly'] = true;
                break;
            case 'samesite':
                $cookie['samesite'] = $attr_value;
                break;
        }
    }
    
    return $cookie;
}

function ccl_scan_js_for_cookies() {
    $cookies = [];
    
    // Common cookie setting patterns in JavaScript
    $cookie_patterns = [
        '/document\.cookie\s*=\s*["\']([^"\'=]+)=/',
        '/setCookie\(["\']([^"\']+)["\']/',
        '/Cookies\.set\(["\']([^"\']+)["\']/',
        '/_ga|_gid|_gat|_gtm|_fbp|_fbc/', // Google Analytics & Facebook
    ];
    
    // Scan active theme's JS files
    $theme_path = get_stylesheet_directory();
    $js_files = ccl_find_js_files($theme_path);
    
    // Scan plugin JS files (limited scan to avoid performance issues)
    $plugins_path = WP_PLUGIN_DIR;
    $common_plugins = ['google-analytics', 'facebook-pixel', 'mailchimp', 'contact-form-7'];
    
    foreach ($common_plugins as $plugin) {
        $plugin_path = $plugins_path . '/' . $plugin;
        if (is_dir($plugin_path)) {
            $plugin_js_files = ccl_find_js_files($plugin_path, 5); // Limit to 5 files per plugin
            $js_files = array_merge($js_files, $plugin_js_files);
        }
    }
    
    foreach ($js_files as $js_file) {
        $content = file_get_contents($js_file);
        if ($content) {
            foreach ($cookie_patterns as $pattern) {
                if (preg_match_all($pattern, $content, $matches)) {
                    foreach ($matches[1] as $cookie_name) {
                        $cookies[$cookie_name] = [
                            'name' => $cookie_name,
                            'source' => 'javascript',
                            'file' => basename($js_file),
                            'discovered' => current_time('mysql')
                        ];
                    }
                }
            }
        }
    }
    
    return $cookies;
}

function ccl_find_js_files($directory, $limit = 20) {
    $js_files = [];
    $count = 0;
    
    if (!is_dir($directory)) return $js_files;
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    
    foreach ($iterator as $file) {
        if ($count >= $limit) break;
        
        if ($file->isFile() && $file->getExtension() === 'js') {
            // Skip minified files to avoid false positives
            if (strpos($file->getFilename(), '.min.') === false) {
                $js_files[] = $file->getPathname();
                $count++;
            }
        }
    }
    
    return $js_files;
}

function ccl_scan_known_plugin_cookies() {
    $cookies = [];
    
    // Define known plugin cookies
    $known_cookies = [
        // WordPress core
        'wordpress_test_cookie' => ['category' => 'necessary', 'description' => 'WordPress test cookie'],
        'wordpress_logged_in_' => ['category' => 'necessary', 'description' => 'WordPress login cookie'],
        'wp-settings-' => ['category' => 'necessary', 'description' => 'WordPress user settings'],
        
        // Google Analytics
        '_ga' => ['category' => 'analytics', 'description' => 'Google Analytics - Main cookie'],
        '_gid' => ['category' => 'analytics', 'description' => 'Google Analytics - Session cookie'],
        '_gat' => ['category' => 'analytics', 'description' => 'Google Analytics - Throttling cookie'],
        '_gtm' => ['category' => 'analytics', 'description' => 'Google Tag Manager'],
        
        // Facebook
        '_fbp' => ['category' => 'advertisement', 'description' => 'Facebook Pixel'],
        '_fbc' => ['category' => 'advertisement', 'description' => 'Facebook Click ID'],
        
        // WooCommerce
        'woocommerce_cart_hash' => ['category' => 'necessary', 'description' => 'WooCommerce cart hash'],
        'woocommerce_items_in_cart' => ['category' => 'necessary', 'description' => 'WooCommerce cart items'],
        'wp_woocommerce_session_' => ['category' => 'necessary', 'description' => 'WooCommerce session'],
        
        // Common plugins
        'mailchimp_landing_site' => ['category' => 'functional', 'description' => 'Mailchimp landing page tracking'],
        'PHPSESSID' => ['category' => 'necessary', 'description' => 'PHP Session ID'],
    ];
    
    // Check which plugins are active and add their known cookies
    $active_plugins = get_option('active_plugins', []);
    
    foreach ($active_plugins as $plugin) {
        $plugin_slug = dirname($plugin);
        
        switch ($plugin_slug) {
            case 'google-analytics-for-wordpress':
            case 'google-analytics-dashboard-for-wp':
            case 'googleanalytics':
                $cookies['_ga'] = array_merge($known_cookies['_ga'], ['source' => 'plugin_' . $plugin_slug]);
                $cookies['_gid'] = array_merge($known_cookies['_gid'], ['source' => 'plugin_' . $plugin_slug]);
                break;
                
            case 'facebook-for-woocommerce':
            case 'official-facebook-pixel':
                $cookies['_fbp'] = array_merge($known_cookies['_fbp'], ['source' => 'plugin_' . $plugin_slug]);
                break;
                
            case 'woocommerce':
                foreach (['woocommerce_cart_hash', 'woocommerce_items_in_cart', 'wp_woocommerce_session_'] as $woo_cookie) {
                    $cookies[$woo_cookie] = array_merge($known_cookies[$woo_cookie], ['source' => 'plugin_woocommerce']);
                }
                break;
        }
    }
    
    return $cookies;
}

function ccl_auto_categorize_cookie($cookie_name, $cookie_data) {
    // Define categorization rules
    $rules = [
        'necessary' => [
            'patterns' => ['/^wordpress_/', '/^wp-/', '/PHPSESSID/', '/session/', '/csrf/', '/security/'],
            'keywords' => ['login', 'auth', 'session', 'security', 'csrf', 'nonce', 'cart', 'checkout']
        ],
        'functional' => [
            'patterns' => ['/^pref_/', '/^settings_/', '/language/', '/currency/'],
            'keywords' => ['preference', 'settings', 'language', 'currency', 'region', 'theme']
        ],
        'analytics' => [
            'patterns' => ['/^_ga/', '/^_gid/', '/^_gat/', '/^_gtm/', '/analytics/', '/stats/'],
            'keywords' => ['analytics', 'tracking', 'statistics', 'stats', 'visitor', 'pageview']
        ],
        'advertisement' => [
            'patterns' => ['/^_fb/', '/^fr$/', '/ads/', '/doubleclick/', '/adsystem/'],
            'keywords' => ['ads', 'advertising', 'marketing', 'retargeting', 'facebook', 'google-ads']
        ]
    ];
    
    $cookie_name_lower = strtolower($cookie_name);
    $description = isset($cookie_data['description']) ? strtolower($cookie_data['description']) : '';
    $source = isset($cookie_data['source']) ? strtolower($cookie_data['source']) : '';
    
    foreach ($rules as $category => $rule) {
        // Check patterns
        foreach ($rule['patterns'] as $pattern) {
            if (preg_match($pattern, $cookie_name_lower)) {
                return $category;
            }
        }
        
        // Check keywords
        foreach ($rule['keywords'] as $keyword) {
            if (strpos($cookie_name_lower, $keyword) !== false || 
                strpos($description, $keyword) !== false || 
                strpos($source, $keyword) !== false) {
                return $category;
            }
        }
    }
    
    // Default category for unknown cookies
    return 'functional';
}

function ccl_is_cookieyes_active() {
    // Check if CookieYes plugin is active
    $active_plugins = get_option('active_plugins', []);
    foreach ($active_plugins as $plugin) {
        if (strpos($plugin, 'cookie-law-info') !== false || 
            strpos($plugin, 'cookieyes') !== false ||
            strpos($plugin, 'cookie-yes') !== false) {
            return true;
        }
    }
    return false;
}

function ccl_add_cookies_to_cookieyes($new_cookies) {
    // This function would integrate with CookieYes plugin's API/database
    // Implementation depends on CookieYes plugin structure
    
    ccl_log_activity('Attempting to add ' . count($new_cookies) . ' cookies to CookieYes');
    
    // Get CookieYes settings (this may vary based on plugin version)
    $cookieyes_settings = get_option('cookie_law_info_settings', []);
    
    if (empty($cookieyes_settings)) {
        ccl_log_activity('CookieYes settings not found');
        return false;
    }
    
    // Add new cookies to respective categories
    foreach ($new_cookies as $cookie_name => $cookie_data) {
        $category = $cookie_data['category'];
        
        // Map our categories to CookieYes categories
        $cookieyes_category = ccl_map_to_cookieyes_category($category);
        
        if ($cookieyes_category) {
            ccl_add_cookie_to_cookieyes_category($cookie_name, $cookie_data, $cookieyes_category);
        }
    }
    
    return true;
}

function ccl_map_to_cookieyes_category($our_category) {
    $mapping = [
        'necessary' => 'necessary',
        'functional' => 'functional', 
        'analytics' => 'analytics',
        'advertisement' => 'advertisement'
    ];
    
    return isset($mapping[$our_category]) ? $mapping[$our_category] : 'functional';
}

function ccl_add_cookie_to_cookieyes_category($cookie_name, $cookie_data, $category) {
    global $wpdb;
    
    // Check if CookieYes uses database table for cookie management
    $table_name = $wpdb->prefix . 'cli_cookie_scan';
    
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name) {
        // Insert into CookieYes cookie scan table
        $wpdb->insert(
            $table_name,
            [
                'cookie_id' => sanitize_text_field($cookie_name),
                'category_id' => sanitize_text_field($category),
                'cookie_name' => sanitize_text_field($cookie_name),
                'cookie_description' => sanitize_text_field($cookie_data['description'] ?? 'Auto-discovered cookie'),
                'duration' => sanitize_text_field($cookie_data['expires'] ?? 'Session'),
                'type' => sanitize_text_field($cookie_data['source'] ?? 'http_cookie'),
                'status' => 1,
                'created_at' => current_time('mysql')
            ]
        );
        
        ccl_log_activity("Added cookie '{$cookie_name}' to CookieYes category '{$category}'");
    } else {
        // Fallback: Try to update CookieYes settings directly
        ccl_update_cookieyes_settings($cookie_name, $cookie_data, $category);
    }
}

function ccl_update_cookieyes_settings($cookie_name, $cookie_data, $category) {
    $settings = get_option('cookie_law_info_settings', []);
    
    // Add to the appropriate category in settings
    if (!isset($settings['cookie_list'])) {
        $settings['cookie_list'] = [];
    }
    
    if (!isset($settings['cookie_list'][$category])) {
        $settings['cookie_list'][$category] = [];
    }
    
    $settings['cookie_list'][$category][$cookie_name] = [
        'name' => $cookie_name,
        'description' => $cookie_data['description'] ?? 'Auto-discovered cookie',
        'duration' => $cookie_data['expires'] ?? 'Session',
        'type' => $cookie_data['source'] ?? 'http_cookie',
        'auto_added' => true,
        'added_date' => current_time('mysql')
    ];
    
    update_option('cookie_law_info_settings', $settings);
    ccl_log_activity("Updated CookieYes settings with cookie '{$cookie_name}' in category '{$category}'");
}

function ccl_send_cookie_notification($new_cookies) {
    $admin_email = get_option('admin_email');
    $site_name = get_bloginfo('name');
    
    $subject = sprintf('[%s] New Cookies Detected - Action Required', $site_name);
    
    $message = "Hello,\n\n";
    $message .= "The CookieYes Consent Logger has detected " . count($new_cookies) . " new cookies on your website:\n\n";
    
    foreach ($new_cookies as $cookie_name => $cookie_data) {
        $message .= "• {$cookie_name}\n";
        $message .= "  Category: " . ucfirst($cookie_data['category']) . "\n";
        $message .= "  Source: " . ($cookie_data['source'] ?? 'Unknown') . "\n";
        if (isset($cookie_data['description'])) {
            $message .= "  Description: {$cookie_data['description']}\n";
        }
        $message .= "\n";
    }
    
    $message .= "These cookies have been automatically categorized and ";
    if (ccl_is_cookieyes_active()) {
        $message .= "added to your CookieYes configuration.\n\n";
    } else {
        $message .= "stored in the system. Please review and add them to your cookie consent banner manually.\n\n";
    }
    
    $message .= "Please review these cookies in your WordPress admin area:\n";
    $message .= admin_url('admin.php?page=cookieyes-consent-logs&tab=cookies') . "\n\n";
    
    $message .= "Best regards,\n";
    $message .= "CookieYes Consent Logger\n";
    $message .= get_site_url();
    
    wp_mail($admin_email, $subject, $message);
    ccl_log_activity('Notification email sent to ' . $admin_email);
}

function ccl_log_activity($message) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('CCL Cookie Tracker: ' . $message);
    }
    
    // Also store in database for admin review
    global $wpdb;
    $table = $wpdb->prefix . 'cookieyes_activity_log';
    
    // Create activity log table if it doesn't exist
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        ccl_create_activity_log_table();
    }
    
    $wpdb->insert(
        $table,
        [
            'activity' => sanitize_text_field($message),
            'created_at' => current_time('mysql')
        ]
    );
}

function ccl_create_activity_log_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'cookieyes_activity_log';
    $charset_collate = $wpdb->get_charset_collate();
    
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        activity TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $charset_collate;";
    dbDelta($sql);
}

// Admin interface for cookie management
add_action('admin_menu', function() {
    add_submenu_page(
        'cookieyes-consent-logs',
        __('Cookie Tracker', 'cookieyes-consent-logger'),
        __('Cookie Tracker', 'cookieyes-consent-logger'),
        'manage_options',
        'cookieyes-cookie-tracker',
        'ccl_render_cookie_tracker_page'
    );
});

function ccl_render_cookie_tracker_page() {
    $tracked_cookies = get_option('ccl_tracked_cookies', []);
    $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'cookies';
    
    ?>
    <div class="wrap">
        <h1><?php _e('Cookie Tracker', 'cookieyes-consent-logger'); ?></h1>
        
        <nav class="nav-tab-wrapper">
            <a href="?page=cookieyes-cookie-tracker&tab=cookies" 
               class="nav-tab <?php echo $tab === 'cookies' ? 'nav-tab-active' : ''; ?>">
                <?php _e('Tracked Cookies', 'cookieyes-consent-logger'); ?>
            </a>
            <a href="?page=cookieyes-cookie-tracker&tab=activity" 
               class="nav-tab <?php echo $tab === 'activity' ? 'nav-tab-active' : ''; ?>">
                <?php _e('Activity Log', 'cookieyes-consent-logger'); ?>
            </a>
            <a href="?page=cookieyes-cookie-tracker&tab=settings" 
               class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                <?php _e('Settings', 'cookieyes-consent-logger'); ?>
            </a>
        </nav>
        
        <?php
        switch ($tab) {
            case 'cookies':
                ccl_render_cookies_tab($tracked_cookies);
                break;
            case 'activity':
                ccl_render_activity_tab();
                break;
            case 'settings':
                ccl_render_settings_tab();
                break;
        }
        ?>
    </div>
    <?php
}

function ccl_render_cookies_tab($tracked_cookies) {
    ?>
    <div class="ccl-tab-content">
        <div class="ccl-actions">
            <button class="button button-primary" onclick="cclRunCookieScan()">
                <?php _e('Run Cookie Scan Now', 'cookieyes-consent-logger'); ?>
            </button>
            <span id="ccl-scan-status" style="margin-left: 10px;"></span>
        </div>
        
        <?php if (empty($tracked_cookies)): ?>
            <div class="notice notice-info">
                <p><?php _e('No cookies have been tracked yet. Run a cookie scan to discover cookies on your website.', 'cookieyes-consent-logger'); ?></p>
            </div>
        <?php else: ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php _e('Cookie Name', 'cookieyes-consent-logger'); ?></th>
                        <th><?php _e('Category', 'cookieyes-consent-logger'); ?></th>
                        <th><?php _e('Source', 'cookieyes-consent-logger'); ?></th>
                        <th><?php _e('Domain', 'cookieyes-consent-logger'); ?></th>
                        <th><?php _e('Discovered', 'cookieyes-consent-logger'); ?></th>
                        <th><?php _e('Actions', 'cookieyes-consent-logger'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tracked_cookies as $cookie_name => $cookie_data): ?>
                        <tr>
                            <td><code><?php echo esc_html($cookie_name); ?></code></td>
                            <td>
                                <span class="ccl-category ccl-category-<?php echo esc_attr($cookie_data['category'] ?? 'unknown'); ?>">
                                    <?php echo esc_html(ucfirst($cookie_data['category'] ?? 'Unknown')); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($cookie_data['source'] ?? 'N/A'); ?></td>
                            <td><?php echo esc_html($cookie_data['domain'] ?? 'N/A'); ?></td>
                            <td><?php echo esc_html($cookie_data['discovered'] ?? 'N/A'); ?></td>
                            <td>
                                <button class="button button-small" onclick="cclEditCookie('<?php echo esc_js($cookie_name); ?>')">
                                    <?php _e('Edit', 'cookieyes-consent-logger'); ?>
                                </button>
                                <button class="button button-small button-link-delete" onclick="cclDeleteCookie('<?php echo esc_js($cookie_name); ?>')">
                                    <?php _e('Delete', 'cookieyes-consent-logger'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <script>
    function cclRunCookieScan() {
        const statusEl = document.getElementById('ccl-scan-status');
        statusEl.innerHTML = '<span class="spinner is-active"></span> Scanning...';
        
        fetch(ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=ccl_manual_cookie_scan&nonce=<?php echo wp_create_nonce('ccl_cookie_scan'); ?>'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                statusEl.innerHTML = '<span style="color: green;">✓ Scan completed. Found ' + data.data.new_cookies + ' new cookies.</span>';
                setTimeout(() => location.reload(), 2000);
            } else {
                statusEl.innerHTML = '<span style="color: red;">✗ Scan failed: ' + data.data.message + '</span>';
            }
        })
        .catch(error => {
            statusEl.innerHTML = '<span style="color: red;">✗ Scan failed</span>';
        });
    }
    
    function cclEditCookie(cookieName) {
        // Implementation for editing cookie details
        alert('Edit functionality coming soon for: ' + cookieName);
    }
    
    function cclDeleteCookie(cookieName) {
        if (confirm('Are you sure you want to remove this cookie from tracking?')) {
            // Implementation for deleting tracked cookie
            alert('Delete functionality coming soon for: ' + cookieName);
        }
    }
    </script>
    <?php
}

function ccl_render_activity_tab() {
    global $wpdb;
    $table = $wpdb->prefix . 'cookieyes_activity_log';
    $activities = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 100");
    
    ?>
    <div class="ccl-tab-content">
        <h3><?php _e('Recent Activity', 'cookieyes-consent-logger'); ?></h3>
        
        <?php if (empty($activities)): ?>
            <p><?php _e('No activity logged yet.', 'cookieyes-consent-logger'); ?></p>
        <?php else: ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php _e('Date & Time', 'cookieyes-consent-logger'); ?></th>
                        <th><?php _e('Activity', 'cookieyes-consent-logger'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activities as $activity): ?>
                        <tr>
                            <td><?php echo esc_html($activity->created_at); ?></td>
                            <td><?php echo esc_html($activity->activity); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

function ccl_render_settings_tab() {
    $settings = get_option('ccl_cookie_tracker_settings', [
        'scan_enabled' => true,
        'scan_time' => '02:00',
        'email_notifications' => true,
        'auto_categorize' => true,
        'cookieyes_integration' => true
    ]);
    
    if (isset($_POST['save_settings'])) {
        check_admin_referer('ccl_save_settings');
        
        $settings = [
            'scan_enabled' => isset($_POST['scan_enabled']),
            'scan_time' => sanitize_text_field($_POST['scan_time']),
            'email_notifications' => isset($_POST['email_notifications']),
            'auto_categorize' => isset($_POST['auto_categorize']),
            'cookieyes_integration' => isset($_POST['cookieyes_integration'])
        ];
        
        update_option('ccl_cookie_tracker_settings', $settings);
        
        // Reschedule if time changed
        wp_clear_scheduled_hook('ccl_daily_cookie_scan');
        wp_schedule_event(strtotime($settings['scan_time'] . ':00'), 'daily', 'ccl_daily_cookie_scan');
        
        echo '<div class="notice notice-success"><p>' . __('Settings saved successfully!', 'cookieyes-consent-logger') . '</p></div>';
    }
    
    ?>
    <div class="ccl-tab-content">
        <form method="post">
            <?php wp_nonce_field('ccl_save_settings'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Enable Cookie Scanning', 'cookieyes-consent-logger'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="scan_enabled" <?php checked($settings['scan_enabled']); ?>>
                            <?php _e('Automatically scan for new cookies daily', 'cookieyes-consent-logger'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Scan Time', 'cookieyes-consent-logger'); ?></th>
                    <td>
                        <input type="time" name="scan_time" value="<?php echo esc_attr($settings['scan_time']); ?>">
                        <p class="description"><?php _e('Daily scan time (server time)', 'cookieyes-consent-logger'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Email Notifications', 'cookieyes-consent-logger'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="email_notifications" <?php checked($settings['email_notifications']); ?>>
                            <?php _e('Send email notifications when new cookies are found', 'cookieyes-consent-logger'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Auto-Categorization', 'cookieyes-consent-logger'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="auto_categorize" <?php checked($settings['auto_categorize']); ?>>
                            <?php _e('Automatically categorize discovered cookies', 'cookieyes-consent-logger'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('CookieYes Integration', 'cookieyes-consent-logger'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="cookieyes_integration" <?php checked($settings['cookieyes_integration']); ?> <?php disabled(!ccl_is_cookieyes_active()); ?>>
                            <?php _e('Automatically add discovered cookies to CookieYes', 'cookieyes-consent-logger'); ?>
                        </label>
                        <?php if (!ccl_is_cookieyes_active()): ?>
                            <p class="description" style="color: #d63638;">
                                <?php _e('CookieYes plugin not detected. This option will be available when CookieYes is active.', 'cookieyes-consent-logger'); ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            
            <?php submit_button(__('Save Settings', 'cookieyes-consent-logger'), 'primary', 'save_settings'); ?>
        </form>
    </div>
    <?php
}

// AJAX handler for manual cookie scan
add_action('wp_ajax_ccl_manual_cookie_scan', function() {
    check_ajax_referer('ccl_cookie_scan', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }
    
    try {
        ccl_perform_cookie_scan();
        $tracked_cookies = get_option('ccl_tracked_cookies', []);
        wp_send_json_success(['new_cookies' => count($tracked_cookies)]);
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
});