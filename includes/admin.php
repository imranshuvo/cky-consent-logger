<?php
add_action('admin_menu', function () {
    add_submenu_page(
        'cookie-law-info',
        __('Consent Logs', 'cookieyes-consent-logger'),
        __('Consent Logs', 'cookieyes-consent-logger'),
        'manage_options',
        'cookieyes-consent-logs',
        'ccl_render_admin_page'
    );
});

function ccl_render_admin_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'cookieyes_consent_logs';
    $logs = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 100");

    echo '<div class="wrap"><h1>' . esc_html__('Consent Logs', 'cookieyes-consent-logger') . '</h1>';
    echo '<div class="notice notice-warning"><p>For GDPR compliance, consent logs must be retained and not deleted or altered for at least 12 months.</p></div>';
    echo '<table class="widefat striped"><thead><tr><th>Date (UTC)</th><th>Consent ID</th><th>Status</th><th>IP</th><th>PDF</th></tr></thead><tbody>';
    foreach ($logs as $log) {
        echo '<tr>';
        echo '<td>' . esc_html($log->created_at) . '</td>';
        echo '<td>' . esc_html($log->consent_id) . '</td>';
        echo '<td>' . esc_html($log->status) . '</td>';
        echo '<td>' . esc_html($log->ip) . '</td>';
        echo '<td><a href="' . esc_url(admin_url('admin-ajax.php?action=ccl_download_pdf&consent_id=' . $log->consent_id)) . '" target="_blank">Download</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
