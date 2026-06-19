<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/markdown.php';
require_once '../includes/view.php';
require_once '../includes/errors/service.php';

checkLogin();
requirePermission('errors', 'view');

$pdo = getDB();
$action = $_POST['action'] ?? ($_GET['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'view' && !empty($_GET['id'])) {
    $redirectId = (int)$_GET['id'];
    if ($redirectId > 0) {
        header('Location: /error_detail.php?id=' . $redirectId . '&from_admin=1');
        exit;
    }
}

$context = loadErrorsContext($pdo, [
    'action' => $action,
    'status' => $_GET['status'] ?? '',
    'system_category' => $_GET['system_category'] ?? '',
    'page' => $_GET['page'] ?? 1,
    'perPage' => 20,
]);

if (!empty($context['redirect'])) {
    header('Location: ' . $context['redirect']);
    exit;
}

view('admin/errors.twig', array_merge([
    'page_title' => '报错管理',
    'admin_css_mtime' => @filemtime(BASE_PATH . '/assets/css/style.css') ?: time(),
    'action' => $action,
], $context));
