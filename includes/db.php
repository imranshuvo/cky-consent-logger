<?php
function ccl_create_consent_log_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'cookieyes_consent_logs';
    $charset_collate = $wpdb->get_charset_collate();

    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        consent_id VARCHAR(64) NOT NULL,
        domain VARCHAR(255) NOT NULL,
        status VARCHAR(20) NOT NULL,
        ip VARCHAR(45),
        ua TEXT,
        country VARCHAR(100),
        categories TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $charset_collate;";
    dbDelta($sql);
}

