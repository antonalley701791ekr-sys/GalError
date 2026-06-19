<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/sensitive_filter.php';
require_once '../includes/view.php';
require_once '../includes/sensitive_logs/service.php';

checkLogin();
requirePermission('sensitive_logs', 'view');

$logFile = BASE_PATH . 'data/sensitive_hits.log';
$message = '';
$messageType = '';
$keyword = trim($_GET['keyword'] ?? '');
$domainFilter = trim($_GET['domain'] ?? '');
$limit = max(20, min(200, intval($_GET['limit'] ?? 100)));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['cleanup_noise_logs'])) {
        requirePermission('sensitive_logs', 'delete');
        $result = cleanupSensitiveLogsNoise($logFile);
    } elseif (isset($_POST['clear_logs'])) {
        requirePermission('sensitive_logs', 'delete');
        $result = clearSensitiveLogs($logFile);
    } elseif (isset($_POST['add_selected_domains'])) {
        requirePermission('sensitive_logs', 'add');
        $result = addSensitiveLogsSelectedDomains($_POST['selected_domains'] ?? []);
    } elseif (isset($_POST['add_filtered_domains'])) {
        requirePermission('sensitive_logs', 'edit');
        $result = addSensitiveLogsFilteredDomains($_POST['filtered_domains'] ?? []);
    } elseif (isset($_POST['add_hit_word_whitelist'])) {
        requirePermission('sensitive_logs', 'add');
        $result = appendSensitiveWordWhitelist([$_POST['hit_word'] ?? '']);
        $result = [
            'message' => $result['success'] ? '已加入敏感词白名单' : '加入失败',
            'messageType' => $result['success'] ? 'success' : 'error',
        ];
    } else {
        $result = ['message' => '', 'messageType' => ''];
    }
    $message = $result['message'];
    $messageType = $result['messageType'];
}

$context = loadSensitiveLogsContext(['keyword' => $keyword, 'domain' => $domainFilter, 'limit' => $limit], $logFile);
view('admin/sensitive_logs.twig', ['page_title' => '敏感词日志', 'admin_css_mtime' => @filemtime(BASE_PATH . '/assets/css/style.css') ?: time(), 'message' => $message, 'message_type' => $messageType] + $context);
