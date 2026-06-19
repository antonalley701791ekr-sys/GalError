<?php
function siteSettingsSet(PDO $pdo, string $key, string $value): void {
    $stmt = $pdo->prepare('SELECT COUNT(*) as c FROM site_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    if ((int)($stmt->fetch()['c'] ?? 0) > 0) { $pdo->prepare('UPDATE site_settings SET setting_value = ? WHERE setting_key = ?')->execute([$value, $key]); }
    else { $pdo->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)')->execute([$value, $key]); }
}

function siteSettingsUploadImage(array $file, ?string $oldPath, PDO $pdo, string $settingKey): array {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) return ['success' => false, 'message' => '文件上传失败'];
    $result = handleSiteImageUpload($file);
    if (!$result['success']) return $result;
    if ($oldPath && file_exists(BASE_PATH . $oldPath)) { @unlink(BASE_PATH . $oldPath); }
    siteSettingsSet($pdo, $settingKey, $result['path']);
    return ['success' => true, 'path' => $result['path']];
}

function siteSettingsDeleteImage(?string $oldPath, PDO $pdo, string $settingKey): void {
    if ($oldPath && file_exists(BASE_PATH . $oldPath)) { @unlink(BASE_PATH . $oldPath); }
    siteSettingsSet($pdo, $settingKey, '');
}

function handleSiteSettingsSave(PDO $pdo, array $input, array $files, array $settings): array {
    siteSettingsSet($pdo, 'site_name', trim($input['site_name'] ?? ''));
    siteSettingsSet($pdo, 'announcement_text', trim($input['announcement_text'] ?? ''));
    siteSettingsSet($pdo, 'announcement_enabled', isset($input['announcement_enabled']) ? '1' : '0');
    siteSettingsSet($pdo, 'footer_text', trim($input['footer_text'] ?? ''));
    siteSettingsSet($pdo, 'meta_description', trim($input['meta_description'] ?? ''));
    siteSettingsSet($pdo, 'meta_keywords', trim($input['meta_keywords'] ?? ''));
    if (isset($files['favicon']) && ($files['favicon']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) { $r = siteSettingsUploadImage($files['favicon'], $settings['favicon'] ?? '', $pdo, 'favicon'); if (!$r['success']) return ['message' => $r['message'], 'messageType' => 'error']; }
    if (isset($files['site_logo']) && ($files['site_logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) { $r = siteSettingsUploadImage($files['site_logo'], $settings['site_logo'] ?? '', $pdo, 'site_logo'); if (!$r['success']) return ['message' => $r['message'], 'messageType' => 'error']; }
    if (isset($input['delete_logo'])) siteSettingsDeleteImage($settings['site_logo'] ?? '', $pdo, 'site_logo');
    if (isset($files['site_bg']) && ($files['site_bg']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) { $r = siteSettingsUploadImage($files['site_bg'], $settings['site_bg'] ?? '', $pdo, 'site_bg'); if (!$r['success']) return ['message' => $r['message'], 'messageType' => 'error']; }
    if (isset($input['delete_bg'])) siteSettingsDeleteImage($settings['site_bg'] ?? '', $pdo, 'site_bg');
    if (isset($files['site_bg_light']) && ($files['site_bg_light']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) { $r = siteSettingsUploadImage($files['site_bg_light'], $settings['site_bg_light'] ?? '', $pdo, 'site_bg_light'); if (!$r['success']) return ['message' => $r['message'], 'messageType' => 'error']; }
    if (isset($input['delete_bg_light'])) siteSettingsDeleteImage($settings['site_bg_light'] ?? '', $pdo, 'site_bg_light');
    siteSettingsSet($pdo, 'custom_css', trim($input['custom_css'] ?? ''));
    siteSettingsSet($pdo, 'vndb_opt_enabled', isset($input['vndb_opt_enabled']) ? '1' : '0');
    siteSettingsSet($pdo, 'vndb_opt_fail_threshold', (string)max(1, intval($input['vndb_opt_fail_threshold'] ?? 5)));
    siteSettingsSet($pdo, 'vndb_opt_open_seconds', (string)max(30, intval($input['vndb_opt_open_seconds'] ?? 600)));
    siteSettingsSet($pdo, 'vndb_cache_ttl', (string)max(60, intval($input['vndb_cache_ttl'] ?? (6 * 3600))));
    return ['message' => '设置已保存', 'messageType' => 'success'];
}
