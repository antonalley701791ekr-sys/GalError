<?php
function loadSmtpSettingsQueryContext(PDO $pdo): array {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'smtp_%' OR setting_key LIKE 'mail_%' OR setting_key LIKE 'resend_%'");
    $settings = [];
    foreach ($stmt->fetchAll() as $row) { $settings[$row['setting_key']] = $row['setting_value']; }
    $currentDriver = $settings['mail_driver'] ?? '';
    if (!$currentDriver) {
        $apiKey = $settings['resend_api_key'] ?? (defined('RESEND_API_KEY') ? RESEND_API_KEY : '');
        $currentDriver = !empty($apiKey) ? 'resend' : 'smtp';
    }
    return compact('settings', 'currentDriver');
}
