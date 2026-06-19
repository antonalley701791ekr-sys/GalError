<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/sensitive_filter.php';
require_once '../includes/view.php';
require_once '../includes/url_whitelist/service.php';

checkLogin();
requirePermission('url_whitelist', 'view');

$whitelistFile = SENSITIVE_URL_WHITELIST_FILE;
$message = '';
$messageType = '';
$canEdit = hasPermission('url_whitelist', 'edit');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission('url_whitelist', 'edit');
    $domains = normalizeWhitelistInput($_POST['domains'] ?? '');
    if (saveWhitelistDomains($whitelistFile, $domains)) {
        $message = 'URL 白名单已保存';
        $messageType = 'success';
    } else {
        $message = '保存失败，请检查文件写入权限';
        $messageType = 'error';
    }
}

$currentDomains = readWhitelistDomains($whitelistFile);
$formValue = implode("\n", $currentDomains);

view('admin/url_whitelist.twig', ['page_title' => '链接白名单', 'admin_css_mtime' => @filemtime(BASE_PATH . '/assets/css/style.css') ?: time(), 'message' => $message, 'message_type' => $messageType, 'can_edit' => $canEdit, 'formValue' => $formValue, 'currentDomains' => $currentDomains]);
