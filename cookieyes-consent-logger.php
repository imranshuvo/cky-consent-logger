<?php
/**
 * Plugin Name: CookieYes Consent Logger
 * Description: GDPR-compliant logging of CookieYes consent with pagination, search, PDF generation, and automatic cookie tracking.
 * Version: 2.0.0
 * Author: Imran Khan, Webkonsulenterne
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Text Domain: cookieyes-consent-logger
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

// Define plugin constants
define('CCL_VERSION', '2.0.0');
define('CCL_PATH', plugin_dir_path(__FILE__));
define('CCL_URL', plugin_dir_url(__FILE__));
define('CCL_BASENAME', plugin_basename(__FILE__));

// Include required files
require_once CCL_PATH .	'vendor/autoload.php';
require_once CCL_PATH . 'includes/db.php';
require_once CCL_PATH . 'includes/logger.php';
require_once CCL_PATH . 'includes/admin.php';
require_once CCL_PATH . 'includes/pdf.php';
require_once CCL_PATH . 'includes/cookie-tracker.php';

// Activation hook
register_activation_hook(__FILE__, 'ccl_activate_plugin');
register_deactivation_hook(__FILE__, 'ccl_deactivate_plugin');
register_uninstall_hook(__FILE__, 'ccl_uninstall_plugin');

function ccl_activate_plugin() {
    // Create database tables
    ccl_create_consent_log_table();
    ccl_create_activity_log_table();
    
    // Set default options
    $default_settings = [
        'scan_enabled' => true,
        'scan_time' => '02:00',
        'email_notifications' => true,
        'auto_categorize' => true,
        'cookieyes_integration' => true
    ];
    add_option('ccl_cookie_tracker_settings', $default_settings);
    
    // Schedule cookie scan
    if (!wp_next_scheduled('ccl_daily_cookie_scan')) {
        wp_schedule_event(strtotime('02:00:00'), 'daily', 'ccl_daily_cookie_scan');
    }
    
    // Log activation
    ccl_log_activity('CookieYes Consent Logger activated');
}

function ccl_deactivate_plugin() {
    // Clear scheduled events
    wp_clear_scheduled_hook('ccl_daily_cookie_scan');
    
    // Log deactivation
    ccl_log_activity('CookieYes Consent Logger deactivated');
}

function ccl_uninstall_plugin() {
    global $wpdb;
    
    // Remove database tables
    $tables = [
        $wpdb->prefix . 'cookieyes_consent_logs',
        $wpdb->prefix . 'cookieyes_activity_log'
    ];
    
    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
    }
    
    // Remove options
    delete_option('ccl_tracked_cookies');
    delete_option('ccl_cookie_tracker_settings');
    
    // Clear scheduled events
    wp_clear_scheduled_hook('ccl_daily_cookie_scan');
}

// Enhanced CSS and styling
add_action('wp_head', function(){
    ?>
    <style>
        html body .cky-consent-container {
            top: 50%;
            left: 50%;
            bottom: auto;
            transform: translate(-50%, -50%);
            z-index: 99999999;
        }
        .cookieYesNotClicked .cky-overlay.cky-hide {
            display: block;
        }
        
        /* Cookie categories styling for admin */
        .ccl-category {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }
        .ccl-category-necessary {
            background: #e3f2fd;
            color: #1565c0;
        }
        .ccl-category-functional {
            background: #f3e5f5;
            color: #7b1fa2;
        }
        .ccl-category-analytics {
            background: #e8f5e8;
            color: #2e7d32;
        }
        .ccl-category-advertisement {
            background: #fff3e0;
            color: #ef6c00;
        }
        .ccl-category-unknown {
            background: #f5f5f5;
            color: #616161;
        }
    </style>
    <?php 
});

// Enhanced JavaScript with improved functionality
add_action('wp_footer', function () {
    ?>
<script>
(function () {
    // Utility functions
    function getCookie(name) {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) return parts.pop().split(';').shift();
    }
    
    function isBrave() {
        return (navigator.brave && navigator.brave.isBrave);
    }

    function isConsentAlreadyLogged(consentId) {
        const last = getCookie('ccl_consent_logged');
        return consentId && last === consentId;
    }

    function markConsentLogged(consentId) {
        if (!consentId) return;
        document.cookie = `ccl_consent_logged=${consentId}; max-age=31536000; path=/; secure; samesite=strict`;
    }

    function clearConsentLoggedFlag() {
        document.cookie = `ccl_consent_logged=; max-age=0; path=/`;
    }

    function determineStatus(consent) {
        const optionalKeys = ['functional', 'analytics', 'performance', 'advertisement'];
        const allRejected = optionalKeys.every(key => consent.categories?.[key] === false);
        return allRejected ? 'rejected' : 'accepted';
    }

    function logConsent(status, consentData) {
        const consentId = consentData?.consentID || '';
        if (!consentId || isConsentAlreadyLogged(consentId)) return;

        // Prevent double logging via page reload
        if (sessionStorage.getItem('ccl_logged_this_session') === 'yes') return;
        sessionStorage.setItem('ccl_logged_this_session', 'yes');

        // Enhanced payload with additional data
        const payload = {
            status: status,
            categories: consentData.categories,
            consentId: consentId,
            timestamp: new Date().toISOString(),
            userAgent: navigator.userAgent,
            screenResolution: `${screen.width}x${screen.height}`,
            language: navigator.language,
            referrer: document.referrer || '',
            pageUrl: window.location.href
        };

        fetch('/wp-admin/admin-ajax.php?action=ccl_log_consent', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(payload)
        }).then(response => {
            if (response.ok) {
                markConsentLogged(consentId);
                
                // Dispatch custom event for developers
                window.dispatchEvent(new CustomEvent('cclConsentLogged', {
                    detail: { status, consentId, categories: consentData.categories }
                }));
            }
        }).catch(error => {
            console.warn('CCL: Failed to log consent:', error);
        });
    }

    function processConsent() {
        const consent = typeof getCkyConsent === 'function' ? getCkyConsent() : null;
        if (!consent?.isUserActionCompleted || !consent?.consentID) return;

        document.body.classList.remove('cookieYesNotClicked');

        const status = determineStatus(consent);
        logConsent(status, consent);
    }

    // Enhanced initialization
    document.addEventListener("DOMContentLoaded", function () {
        // Add body class if user hasn't interacted yet
        const consent = typeof getCkyConsent === 'function' ? getCkyConsent() : null;
        
        if (!consent?.isUserActionCompleted) {
            document.body.classList.add('cookieYesNotClicked');
        }
        
        // Remove class for Brave browser (has built-in cookie blocking)
        if(isBrave()){
            document.body.classList.remove('cookieYesNotClicked');
        }

        // Initial consent check with retry mechanism
        let tries = 0;
        const maxTries = 15; // Increased for better reliability
        const retryInterval = setInterval(() => {
            const consent = typeof getCkyConsent === 'function' ? getCkyConsent() : null;
            if (consent?.isUserActionCompleted && consent?.consentID) {
                processConsent();
                clearInterval(retryInterval);
            }
            if (++tries >= maxTries) {
                clearInterval(retryInterval);
                console.warn('CCL: Could not find valid consent data after multiple attempts');
            }
        }, 500);

        // Enhanced consent update handler
        document.addEventListener('cookieyes_consent_update', function (event) {
            clearConsentLoggedFlag(); // Allow re-logging
            
            // Small delay to ensure consent data is updated
            setTimeout(() => {
                processConsent();
            }, 100);
        });
        
        // Additional CookieYes event listeners
        const cookieYesEvents = [
            'cookieyes_consent_accept',
            'cookieyes_consent_reject', 
            'cookieyes_consent_close',
            'cky_consent_accept',
            'cky_consent_reject'
        ];
        
        cookieYesEvents.forEach(eventName => {
            document.addEventListener(eventName, function(event) {
                clearConsentLoggedFlag();
                setTimeout(() => processConsent(), 200);
            });
        });

        // Google Consent Mode integration (if available)
        if (typeof gtag !== 'undefined' || typeof dataLayer !== 'undefined') {
            window.addEventListener('cclConsentLogged', function(event) {
                const { status, categories } = event.detail;
                
                // Update Google Consent Mode
                if (typeof gtag === 'function') {
                    gtag('consent', 'update', {
                        ad_storage: categories?.advertisement ? 'granted' : 'denied',
                        ad_user_data: categories?.advertisement ? 'granted' : 'denied', 
                        ad_personalization: categories?.advertisement ? 'granted' : 'denied',
                        analytics_storage: categories?.analytics ? 'granted' : 'denied',
                        functionality_storage: categories?.functional ? 'granted' : 'denied',
                        personalization_storage: categories?.functional ? 'granted' : 'denied',
                        security_storage: 'granted' // Always granted for necessary cookies
                    });
                }
            });
        }
    });

    // Enhanced error handling and debugging
    window.addEventListener('error', function(event) {
        if (event.error && event.error.message && event.error.message.includes('getCkyConsent')) {
            console.warn('CCL: CookieYes function not available yet, will retry...');
        }
    });

    // Expose API for developers
    window.CookieYesLogger = {
        version: '<?php echo CCL_VERSION; ?>',
        logConsent: logConsent,
        getLastLoggedConsent: () => getCookie('ccl_consent_logged'),
        clearLog: clearConsentLoggedFlag,
        isConsentLogged: isConsentAlreadyLogged
    };

})();
</script>
    <?php
});

// Load text domain for internationalization
add_action('plugins_loaded', function() {
    load_plugin_textdomain('cookieyes-consent-logger', false, dirname(CCL_BASENAME) . '/languages');
});

// Add settings link to plugins page
add_filter('plugin_action_links_' . CCL_BASENAME, function($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=cookieyes-consent-logs') . '">' . __('Settings', 'cookieyes-consent-logger') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});

// Add plugin meta links
add_filter('plugin_row_meta', function($links, $file) {
    if ($file === CCL_BASENAME) {
        $links[] = '<a href="https://github.com/your-repo/cookieyes-consent-logger" target="_blank">' . __('Documentation', 'cookieyes-consent-logger') . '</a>';
        $links[] = '<a href="mailto:support@example.com">' . __('Support', 'cookieyes-consent-logger') . '</a>';
    }
    return $links;
}, 10, 2);

// Enhanced admin notices
add_action('admin_notices', function() {
    // Check if CookieYes is installed
    if (!ccl_is_cookieyes_active()) {
        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php _e('CookieYes Consent Logger:', 'cookieyes-consent-logger'); ?></strong>
                <?php _e('CookieYes plugin not detected. Some features like automatic cookie integration will not be available.', 'cookieyes-consent-logger'); ?>
                <a href="<?php echo admin_url('plugin-install.php?s=cookieyes&tab=search&type=term'); ?>" class="button button-small">
                    <?php _e('Install CookieYes', 'cookieyes-consent-logger'); ?>
                </a>
            </p>
        </div>
        <?php
    }
    
    // Check for recent cookie discoveries
    $recent_cookies = get_transient('ccl_recent_cookie_discoveries');
    if ($recent_cookies && count($recent_cookies) > 0) {
        ?>
        <div class="notice notice-info is-dismissible" data-dismiss="ccl_recent_cookies">
            <p>
                <strong><?php _e('New Cookies Discovered!', 'cookieyes-consent-logger'); ?></strong>
                <?php printf(__('Found %d new cookies on your website.', 'cookieyes-consent-logger'), count($recent_cookies)); ?>
                <a href="<?php echo admin_url('admin.php?page=cookieyes-cookie-tracker'); ?>" class="button button-small">
                    <?php _e('Review Cookies', 'cookieyes-consent-logger'); ?>
                </a>
            </p>
        </div>
        <?php
    }
});

// Handle notice dismissals
add_action('wp_ajax_ccl_dismiss_notice', function() {
    $notice = sanitize_text_field($_POST['notice']);
    if ($notice === 'ccl_recent_cookies') {
        delete_transient('ccl_recent_cookie_discoveries');
    }
    wp_send_json_success();
});

// Dashboard widget
add_action('wp_dashboard_setup', function() {
    wp_add_dashboard_widget(
        'ccl_dashboard_widget',
        __('Cookie Consent Summary', 'cookieyes-consent-logger'),
        'ccl_dashboard_widget_content'
    );
});

function ccl_dashboard_widget_content() {
    global $wpdb;
    $table = $wpdb->prefix . 'cookieyes_consent_logs';
    
    // Get recent stats
    $total_consents = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    $recent_consents = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $accepted_consents = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'accepted'");
    $rejected_consents = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'rejected'");
    
    $acceptance_rate = $total_consents > 0 ? round(($accepted_consents / $total_consents) * 100, 1) : 0;
    
    ?>
    <div class="ccl-dashboard-stats">
        <div class="ccl-stat-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
            <div class="ccl-stat-item">
                <div style="font-size: 24px; font-weight: 600; color: #0073aa;"><?php echo number_format($total_consents); ?></div>
                <div style="font-size: 12px; color: #666;"><?php _e('Total Consents', 'cookieyes-consent-logger'); ?></div>
            </div>
            <div class="ccl-stat-item">
                <div style="font-size: 24px; font-weight: 600; color: #00a32a;"><?php echo $acceptance_rate; ?>%</div>
                <div style="font-size: 12px; color: #666;"><?php _e('Acceptance Rate', 'cookieyes-consent-logger'); ?></div>
            </div>
            <div class="ccl-stat-item">
                <div style="font-size: 18px; font-weight: 500;"><?php echo number_format($recent_consents); ?></div>
                <div style="font-size: 12px; color: #666;"><?php _e('Last 7 Days', 'cookieyes-consent-logger'); ?></div>
            </div>
            <div class="ccl-stat-item">
                <div style="font-size: 18px; font-weight: 500;"><?php echo number_format(count(get_option('ccl_tracked_cookies', []))); ?></div>
                <div style="font-size: 12px; color: #666;"><?php _e('Tracked Cookies', 'cookieyes-consent-logger'); ?></div>
            </div>
        </div>
        
        <div style="text-align: center; margin-top: 15px;">
            <a href="<?php echo admin_url('admin.php?page=cookieyes-consent-logs'); ?>" class="button button-primary">
                <?php _e('View All Logs', 'cookieyes-consent-logger'); ?>
            </a>
            <a href="<?php echo admin_url('admin.php?page=cookieyes-cookie-tracker'); ?>" class="button">
                <?php _e('Cookie Tracker', 'cookieyes-consent-logger'); ?>
            </a>
        </div>
    </div>
    <?php
}

// WP-CLI integration (if WP-CLI is available)
if (defined('WP_CLI') && WP_CLI) {
    require_once CCL_PATH . 'includes/wp-cli.php';
}

// REST API endpoints
add_action('rest_api_init', function() {
    register_rest_route('ccl/v1', '/stats', [
        'methods' => 'GET',
        'callback' => 'ccl_rest_get_stats',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);
    
    register_rest_route('ccl/v1', '/cookies', [
        'methods' => 'GET', 
        'callback' => 'ccl_rest_get_cookies',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);
});

function ccl_rest_get_stats() {
    global $wpdb;
    $table = $wpdb->prefix . 'cookieyes_consent_logs';
    
    $stats = [
        'total_consents' => $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
        'accepted_consents' => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'accepted'"),
        'rejected_consents' => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'rejected'"),
        'recent_consents' => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"),
        'tracked_cookies' => count(get_option('ccl_tracked_cookies', [])),
        'plugin_version' => CCL_VERSION
    ];
    
    $stats['acceptance_rate'] = $stats['total_consents'] > 0 ? 
        round(($stats['accepted_consents'] / $stats['total_consents']) * 100, 2) : 0;
    
    return rest_ensure_response($stats);
}

function ccl_rest_get_cookies() {
    $cookies = get_option('ccl_tracked_cookies', []);
    $formatted_cookies = [];
    
    foreach ($cookies as $name => $data) {
        $formatted_cookies[] = [
            'name' => $name,
            'category' => $data['category'] ?? 'unknown',
            'source' => $data['source'] ?? 'unknown',
            'discovered' => $data['discovered'] ?? null,
            'domain' => $data['domain'] ?? null
        ];
    }
    
    return rest_ensure_response($formatted_cookies);
}

// Cleanup old logs (optional - runs weekly)
add_action('wp_scheduled_delete', function() {
    $retention_days = apply_filters('ccl_log_retention_days', 365); // 1 year default
    
    if ($retention_days > 0) {
        global $wpdb;
        $table = $wpdb->prefix . 'cookieyes_consent_logs';
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $retention_days
        ));
        
        if ($deleted > 0) {
            ccl_log_activity("Cleaned up {$deleted} old consent logs (older than {$retention_days} days)");
        }
    }
});

// Export functionality
add_action('wp_ajax_ccl_export_logs', function() {
    check_ajax_referer('ccl_export_logs', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'cookieyes_consent_logs';
    $logs = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC");
    
    $filename = 'consent-logs-' . date('Y-m-d-H-i-s') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    
    $output = fopen('php://output', 'w');
    
    // CSV headers
    fputcsv($output, [
        'ID', 'Consent ID', 'Domain', 'Status', 'IP', 'User Agent', 
        'Country', 'Categories', 'Created At'
    ]);
    
    // CSV data
    foreach ($logs as $log) {
        fputcsv($output, [
            $log->id,
            $log->consent_id,
            $log->domain,
            $log->status,
            $log->ip,
            $log->ua,
            $log->country,
            $log->categories,
            $log->created_at
        ]);
    }
    
    fclose($output);
    exit;
});

// Privacy policy integration
add_action('wp_privacy_policy_guide', function() {
    wp_add_privacy_policy_content(
        'CookieYes Consent Logger',
        ccl_get_privacy_policy_content()
    );
});

function ccl_get_privacy_policy_content() {
    $content = '<h2>' . __('Cookie Consent Logging', 'cookieyes-consent-logger') . '</h2>';
    $content .= '<p>' . __('We log your cookie consent preferences to comply with GDPR and other privacy regulations. This includes:', 'cookieyes-consent-logger') . '</p>';
    $content .= '<ul>';
    $content .= '<li>' . __('Your consent choices (accept/reject for each cookie category)', 'cookieyes-consent-logger') . '</li>';
    $content .= '<li>' . __('Timestamp of when consent was given or updated', 'cookieyes-consent-logger') . '</li>';
    $content .= '<li>' . __('Anonymized IP address', 'cookieyes-consent-logger') . '</li>';
    $content .= '<li>' . __('Browser information (user agent)', 'cookieyes-consent-logger') . '</li>';
    $content .= '</ul>';
    $content .= '<p>' . __('This data is stored securely and used solely for compliance purposes. It is retained for a minimum of 12 months as required by GDPR.', 'cookieyes-consent-logger') . '</p>';
    
    return $content;
}

// Performance optimization - cache frequently accessed data
add_action('init', function() {
    // Cache consent statistics for dashboard
    if (!get_transient('ccl_dashboard_stats')) {
        global $wpdb;
        $table = $wpdb->prefix . 'cookieyes_consent_logs';
        
        $stats = [
            'total' => $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'accepted' => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'accepted'"),
            'recent' => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")
        ];
        
        set_transient('ccl_dashboard_stats', $stats, HOUR_IN_SECONDS);
    }
});

// Plugin health check
add_filter('site_status_tests', function($tests) {
    $tests['direct']['ccl_plugin_health'] = [
        'label' => __('CookieYes Consent Logger Health', 'cookieyes-consent-logger'),
        'test' => 'ccl_health_check'
    ];
    return $tests;
});

function ccl_health_check() {
    $result = [
        'label' => __('CookieYes Consent Logger is working correctly', 'cookieyes-consent-logger'),
        'status' => 'good',
        'badge' => [
            'label' => __('Privacy', 'cookieyes-consent-logger'),
            'color' => 'blue',
        ],
        'description' => __('The plugin is properly logging consent and tracking cookies.', 'cookieyes-consent-logger'),
        'test' => 'ccl_plugin_health',
    ];
    
    // Check if tables exist
    global $wpdb;
    $consent_table = $wpdb->prefix . 'cookieyes_consent_logs';
    $activity_table = $wpdb->prefix . 'cookieyes_activity_log';
    
    if ($wpdb->get_var("SHOW TABLES LIKE '{$consent_table}'") !== $consent_table) {
        $result['status'] = 'critical';
        $result['label'] = __('CookieYes Consent Logger database tables missing', 'cookieyes-consent-logger');
        $result['description'] = __('Required database tables are missing. Try deactivating and reactivating the plugin.', 'cookieyes-consent-logger');
        return $result;
    }
    
    // Check if cookie scan is scheduled
    if (!wp_next_scheduled('ccl_daily_cookie_scan')) {
        $result['status'] = 'recommended';
        $result['label'] = __('Cookie scanning not scheduled', 'cookieyes-consent-logger');
        $result['description'] = __('Daily cookie scanning is not scheduled. Check plugin settings.', 'cookieyes-consent-logger');
    }
    
    return $result;
}

// Developer hooks and filters
do_action('ccl_plugin_loaded', CCL_VERSION);

// Final initialization
add_action('plugins_loaded', function() {
    do_action('ccl_init');
}, 20);