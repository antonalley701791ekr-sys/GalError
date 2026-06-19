<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/view.php';
require_once '../includes/ads/service.php';

checkLogin();
requirePermission('ads', 'view');

$pdo = getDB();
$message = '';
$messageType = '';
$action = $_POST['action'] ?? ($_GET['action'] ?? '');
$id = intval($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'add') {
        requirePermission('ads', 'add');
        $result = handleAdsAdd($pdo, $_POST, $_FILES);
    } elseif ($action === 'edit' && $id) {
        requirePermission('ads', 'edit');
        $result = handleAdsEdit($pdo, $id, $_POST, $_FILES);
    } elseif ($action === 'delete' && $id) {
        requirePermission('ads', 'delete');
        $result = handleAdsDelete($pdo, $id);
    } elseif ($action === 'toggle' && $id) {
        requirePermission('ads', 'edit');
        $result = handleAdsToggle($pdo, $id);
    } else {
        $result = ['message' => '', 'messageType' => ''];
    }
    $message = $result['message'];
    $messageType = $result['messageType'];
    $action = '';
}

$context = loadAdsContext($pdo, ['action' => $action, 'id' => $id]);
view('admin/ads.twig', ['page_title' => '广告管理', 'admin_css_mtime' => @filemtime(BASE_PATH . '/assets/css/style.css') ?: time(), 'message' => $message, 'message_type' => $messageType] + $context);
