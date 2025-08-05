<?php
add_action('wp_ajax_nopriv_ccl_log_consent', 'ccl_log_consent');
add_action('wp_ajax_ccl_log_consent', 'ccl_log_consent');

function ccl_log_consent() {
    global $wpdb;

    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body || empty($body['status'])) wp_send_json_error(['error' => 'Invalid payload']);

    $status     = sanitize_text_field($body['status']);
    $categories = isset($body['categories']) ? array_map('sanitize_text_field', $body['categories']) : [];

    $data = [
        'consent_id' => !empty($body['consentId']) ? sanitize_text_field($body['consentId']) : wp_generate_uuid4(),
        'domain'     => sanitize_text_field($_SERVER['HTTP_HOST']),
        'status'     => $status,
        'ip'         => ccl_anonymize_ip($_SERVER['REMOTE_ADDR']),
        'ua'         => sanitize_textarea_field($_SERVER['HTTP_USER_AGENT']),
        'country'    => '',
        'categories' => wp_json_encode($categories),
    ];

    $wpdb->insert($wpdb->prefix . 'cookieyes_consent_logs', $data);
    wp_send_json_success(['logged' => true, 'consent_id' => $data['consent_id']]);
}

function ccl_anonymize_ip($ip) {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return preg_replace('/\d+$/', '0', $ip);
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return preg_replace('/:[a-f0-9]+$/i', ':0000', $ip);
    }
    return '';
}
