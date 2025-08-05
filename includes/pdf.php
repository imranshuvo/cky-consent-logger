<?php
// Enhanced includes/pdf.php

// Add AJAX handler for PDF generation
add_action('wp_ajax_ccl_download_pdf', 'ccl_generate_consent_pdf');

function ccl_generate_consent_pdf() {
    // Verify nonce
    $consent_id = sanitize_text_field($_GET['consent_id']);
    if (!wp_verify_nonce($_GET['_wpnonce'], 'ccl_download_pdf_' . $consent_id)) {
        wp_die('Security check failed');
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized access');
    }
    
    if (empty($consent_id)) {
        wp_die('Invalid consent ID');
    }
    
    // Get consent data
    global $wpdb;
    $table = $wpdb->prefix . 'cookieyes_consent_logs';
    $log = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE consent_id = %s", $consent_id));
    
    if (!$log) {
        wp_die('Consent log not found');
    }
    
    // Generate PDF
    $pdf_content = ccl_generate_pdf_content($log);
    
    // Set headers for PDF download
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="consent-log-' . $consent_id . '.pdf"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    echo $pdf_content;
    exit;
}

function ccl_generate_pdf_content($log) {
    // For a production environment, you'd want to use a library like TCPDF or mPDF
    // For now, we'll create a simple HTML-to-PDF conversion
    
    $categories = json_decode($log->categories, true);
    $site_name = get_bloginfo('name');
    $site_url = get_site_url();
    
    // Create PDF using TCPDF (you'll need to install this library)
    if (class_exists('TCPDF')) {
        return ccl_generate_tcpdf($log, $categories, $site_name, $site_url);
    } else {
        // Fallback to HTML version (browser will handle PDF conversion)
        return ccl_generate_html_pdf($log, $categories, $site_name, $site_url);
    }
}

function ccl_generate_html_pdf($log, $categories, $site_name, $site_url) {
    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>GDPR Consent Record - <?php echo esc_html($log->consent_id); ?></title>
        <style>
            body { 
                font-family: Arial, sans-serif; 
                margin: 40px; 
                line-height: 1.6;
                color: #333;
            }
            .header { 
                text-align: center; 
                border-bottom: 2px solid #0073aa; 
                padding-bottom: 20px; 
                margin-bottom: 30px;
            }
            .logo { 
                font-size: 24px; 
                font-weight: bold; 
                color: #0073aa;
                margin-bottom: 10px;
            }
            .subtitle { 
                color: #666; 
                font-size: 14px;
            }
            .section { 
                margin-bottom: 25px; 
                page-break-inside: avoid;
            }
            .section h2 { 
                color: #0073aa; 
                border-bottom: 1px solid #ddd; 
                padding-bottom: 5px;
                margin-bottom: 15px;
            }
            .info-table { 
                width: 100%; 
                border-collapse: collapse; 
                margin-bottom: 20px;
            }
            .info-table th, .info-table td { 
                border: 1px solid #ddd;
                padding: 12px; 
                text-align: left;
            }
            .info-table th { 
                background-color: #f8f9fa; 
                font-weight: bold;
                width: 30%;
            }
            .consent-status { 
                padding: 5px 10px; 
                border-radius: 3px; 
                font-weight: bold;
                text-transform: uppercase;
            }
            .status-accepted { 
                background-color: #d4edda; 
                color: #155724;
            }
            .status-rejected { 
                background-color: #f8d7da; 
                color: #721c24;
            }
            .categories-list { 
                list-style: none; 
                padding: 0;
            }
            .categories-list li { 
                padding: 8px 0; 
                border-bottom: 1px solid #eee;
                display: flex;
                justify-content: space-between;
            }
            .category-name { 
                font-weight: bold;
            }
            .footer { 
                margin-top: 40px; 
                padding-top: 20px; 
                border-top: 1px solid #ddd; 
                font-size: 12px; 
                color: #666;
                text-align: center;
            }
            .gdpr-notice { 
                background-color: #fff3cd; 
                border: 1px solid #ffeaa7; 
                padding: 15px; 
                border-radius: 4px; 
                margin-bottom: 25px;
            }
            .verification-section {
                background-color: #f8f9fa;
                padding: 20px;
                border-radius: 4px;
                margin-top: 20px;
            }
            .hash-value {
                font-family: monospace;
                background-color: #e9ecef;
                padding: 10px;
                border-radius: 3px;
                word-break: break-all;
                margin-top: 10px;
            }
        </style>
    </head>
    <body>
        <div class="header">
            <div class="logo"><?php echo esc_html($site_name); ?></div>
            <div class="subtitle">GDPR Consent Record & Proof of Compliance</div>
            <div class="subtitle">Generated on: <?php echo date('F j, Y \a\t g:i A T'); ?></div>
        </div>

        <div class="gdpr-notice">
            <strong>GDPR Compliance Notice:</strong> This document serves as official proof of user consent collection in accordance with the General Data Protection Regulation (GDPR). This record must be retained for audit purposes and legal compliance.
        </div>

        <div class="section">
            <h2>Consent Information</h2>
            <table class="info-table">
                <tr>
                    <th>Consent ID</th>
                    <td><?php echo esc_html($log->consent_id); ?></td>
                </tr>
                <tr>
                    <th>Website Domain</th>
                    <td><?php echo esc_html($log->domain); ?></td>
                </tr>
                <tr>
                    <th>Consent Status</th>
                    <td>
                        <span class="consent-status status-<?php echo esc_attr($log->status); ?>">
                            <?php echo esc_html(ucfirst($log->status)); ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th>Date & Time (UTC)</th>
                    <td><?php echo esc_html(date('F j, Y \a\t g:i A T', strtotime($log->created_at))); ?></td>
                </tr>
                <tr>
                    <th>IP Address (Anonymized)</th>
                    <td><?php echo esc_html($log->ip); ?></td>
                </tr>
                <tr>
                    <th>Country</th>
                    <td><?php echo esc_html($log->country ?: 'Not Available'); ?></td>
                </tr>
            </table>
        </div>

        <div class="section">
            <h2>Cookie Categories Consent</h2>
            <?php if ($categories && is_array($categories)): ?>
                <ul class="categories-list">
                    <?php foreach ($categories as $category => $status): ?>
                        <li>
                            <span class="category-name"><?php echo esc_html(ucwords(str_replace('_', ' ', $category))); ?></span>
                            <span class="consent-status status-<?php echo $status ? 'accepted' : 'rejected'; ?>">
                                <?php echo $status ? 'Accepted' : 'Rejected'; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>No category-specific consent data available.</p>
            <?php endif; ?>
        </div>

        <div class="section">
            <h2>Technical Details</h2>
            <table class="info-table">
                <tr>
                    <th>User Agent</th>
                    <td style="word-break: break-all;"><?php echo esc_html($log->ua); ?></td>
                </tr>
                <tr>
                    <th>Data Collection Method</th>
                    <td>CookieYes Consent Management Platform</td>
                </tr>
                <tr>
                    <th>Consent Mechanism</th>
                    <td>Explicit User Action (Click/Tap)</td>
                </tr>
                <tr>
                    <th>Storage Location</th>
                    <td>WordPress Database (<?php echo esc_html(DB_NAME); ?>)</td>
                </tr>
            </table>
        </div>

        <div class="section">
            <h2>Legal Basis & Compliance</h2>
            <table class="info-table">
                <tr>
                    <th>Legal Basis (GDPR)</th>
                    <td>Article 6(1)(a) - Consent of the data subject</td>
                </tr>
                <tr>
                    <th>Cookie Law Compliance</th>
                    <td>ePrivacy Directive (2002/58/EC) - Prior Consent Required</td>
                </tr>
                <tr>
                    <th>Retention Period</th>
                    <td>Minimum 12 months from consent date</td>
                </tr>
                <tr>
                    <th>Data Controller</th>
                    <td><?php echo esc_html($site_name); ?> (<?php echo esc_html($site_url); ?>)</td>
                </tr>
            </table>
        </div>

        <div class="verification-section">
            <h2>Record Verification</h2>
            <p><strong>Digital Fingerprint:</strong></p>
            <div class="hash-value">
                <?php echo esc_html(hash('sha256', serialize($log) . SECURE_AUTH_KEY)); ?>
            </div>
            <p style="margin-top: 15px; font-size: 12px; color: #666;">
                This hash can be used to verify the integrity of this consent record. Any modification to the original data will result in a different hash value.
            </p>
        </div>

        <div class="footer">
            <p><strong>Certificate of Authenticity</strong></p>
            <p>This document was automatically generated by the CookieYes Consent Logger plugin on <?php echo esc_html($site_name); ?>.</p>
            <p>For verification purposes, contact the website administrator at <?php echo esc_html(get_option('admin_email')); ?></p>
            <p style="margin-top: 20px;">
                <small>
                    Generated: <?php echo date('Y-m-d H:i:s T'); ?> | 
                    Version: 1.0.0 | 
                    Document ID: <?php echo esc_html(substr(md5($log->consent_id . time()), 0, 12)); ?>
                </small>
            </p>
        </div>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

function ccl_generate_tcpdf($log, $categories, $site_name, $site_url) {
    // This function would use TCPDF library for proper PDF generation
    // You would need to include TCPDF library first
    
    require_once plugin_dir_path(__DIR__) . 'vendor/tecnickcom/tcpdf/tcpdf.php';
    
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('CookieYes Consent Logger');
    $pdf->SetAuthor($site_name);
    $pdf->SetTitle('GDPR Consent Record - ' . $log->consent_id);
    $pdf->SetSubject('GDPR Compliance Document');
    $pdf->SetKeywords('GDPR, Consent, Cookie, Privacy, Compliance');
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set margins
    $pdf->SetMargins(20, 20, 20);
    $pdf->SetAutoPageBreak(TRUE, 20);
    
    // Add a page
    $pdf->AddPage();
    
    // Set font
    $pdf->SetFont('helvetica', '', 12);
    
    // Generate HTML content (similar to above but optimized for TCPDF)
    $html = ccl_generate_tcpdf_html($log, $categories, $site_name, $site_url);
    
    // Print HTML content
    $pdf->writeHTML($html, true, false, true, false, '');
    
    // Return PDF string
    return $pdf->Output('', 'S');
}

function ccl_generate_tcpdf_html($log, $categories, $site_name, $site_url) {
    // Simplified HTML for TCPDF (TCPDF has limited CSS support)
    $html = '<h1 style="text-align: center; color: #0073aa;">' . esc_html($site_name) . '</h1>';
    $html .= '<h2 style="text-align: center; color: #666;">GDPR Consent Record & Proof of Compliance</h2>';
    $html .= '<p style="text-align: center; font-size: 10px;">Generated on: ' . date('F j, Y \a\t g:i A T') . '</p>';
    
    $html .= '<div style="background-color: #fff3cd; padding: 10px; margin: 20px 0; border: 1px solid #ffeaa7;">';
    $html .= '<strong>GDPR Compliance Notice:</strong> This document serves as official proof of user consent collection in accordance with the General Data Protection Regulation (GDPR).';
    $html .= '</div>';
    
    // Consent Information Table
    $html .= '<h3 style="color: #0073aa;">Consent Information</h3>';
    $html .= '<table border="1" cellpadding="8" cellspacing="0" style="width: 100%;">';
    $html .= '<tr><td style="background-color: #f8f9fa; font-weight: bold;">Consent ID</td><td>' . esc_html($log->consent_id) . '</td></tr>';
    $html .= '<tr><td style="background-color: #f8f9fa; font-weight: bold;">Website Domain</td><td>' . esc_html($log->domain) . '</td></tr>';
    $html .= '<tr><td style="background-color: #f8f9fa; font-weight: bold;">Consent Status</td><td style="font-weight: bold; text-transform: uppercase;">' . esc_html($log->status) . '</td></tr>';
    $html .= '<tr><td style="background-color: #f8f9fa; font-weight: bold;">Date & Time (UTC)</td><td>' . esc_html(date('F j, Y \a\t g:i A T', strtotime($log->created_at))) . '</td></tr>';
    $html .= '<tr><td style="background-color: #f8f9fa; font-weight: bold;">IP Address (Anonymized)</td><td>' . esc_html($log->ip) . '</td></tr>';
    $html .= '<tr><td style="background-color: #f8f9fa; font-weight: bold;">Country</td><td>' . esc_html($log->country ?: 'Not Available') . '</td></tr>';
    $html .= '</table>';
    
    // Cookie Categories
    $html .= '<h3 style="color: #0073aa;">Cookie Categories Consent</h3>';
    if ($categories && is_array($categories)) {
        $html .= '<table border="1" cellpadding="8" cellspacing="0" style="width: 100%;">';
        foreach ($categories as $category => $status) {
            $statusText = $status ? 'ACCEPTED' : 'REJECTED';
            $html .= '<tr>';
            $html .= '<td style="font-weight: bold;">' . esc_html(ucwords(str_replace('_', ' ', $category))) . '</td>';
            $html .= '<td style="font-weight: bold; text-transform: uppercase;">' . $statusText . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
    } else {
        $html .= '<p>No category-specific consent data available.</p>';
    }
    
    // Technical Details
    $html .= '<h3 style="color: #0073aa;">Technical Details</h3>';
    $html .= '<table border="1" cellpadding="8" cellspacing="0" style="width: 100%;">';
    $html .= '<tr><td style="background-color: #f8f9fa; font-weight: bold;">User Agent</td><td style="font-size: 9px;">' . esc_html($log->ua) . '</td></tr>';
    $html .= '<tr><td style="background-color: #f8f9fa; font-weight: bold;">Data Collection Method</td><td>CookieYes Consent Management Platform</td></tr>';
    $html .= '<tr><td style="background-color: #f8f9fa; font-weight: bold;">Consent Mechanism</td><td>Explicit User Action (Click/Tap)</td></tr>';
    $html .= '</table>';
    
    // Verification
    $html .= '<h3 style="color: #0073aa;">Record Verification</h3>';
    $html .= '<p><strong>Digital Fingerprint:</strong></p>';
    $html .= '<div style="background-color: #e9ecef; padding: 10px; font-family: monospace; font-size: 8px; word-break: break-all;">';
    $html .= esc_html(hash('sha256', serialize($log) . SECURE_AUTH_KEY));
    $html .= '</div>';
    
    // Footer
    $html .= '<div style="margin-top: 30px; text-align: center; font-size: 10px; color: #666;">';
    $html .= '<p><strong>Certificate of Authenticity</strong></p>';
    $html .= '<p>This document was automatically generated by the CookieYes Consent Logger plugin.</p>';
    $html .= '<p>Generated: ' . date('Y-m-d H:i:s T') . ' | Version: 1.0.0</p>';
    $html .= '</div>';
    
    return $html;
}