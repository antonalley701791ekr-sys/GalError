<?php
function loadSiteSettingsQueryContext(PDO $pdo): array {
    $stmt = $pdo->query('SELECT setting_key, setting_value FROM site_settings');
    $settings = [];
    foreach ($stmt->fetchAll() as $row) { $settings[$row['setting_key']] = $row['setting_value']; }
    return ['settings' => $settings, 'vndbStatus' => vndbCircuitPublicStatus(), 'vndbCacheStats' => vndbCacheStats()];
}
