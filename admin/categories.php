<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/view.php';
require_once '../includes/categories/service.php';

checkLogin();
requirePermission('categories', 'view');

$pdo = getDB();
$message = '';
$messageType = '';
$action = $_POST['action'] ?? ($_GET['action'] ?? '');
$id = intval($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'add') { requirePermission('categories', 'add'); $result = handleCategoriesAdd($pdo, $_POST); }
    elseif ($action === 'edit' && $id) { requirePermission('categories', 'edit'); $result = handleCategoriesEdit($pdo, $id, $_POST); }
    elseif ($action === 'delete' && $id) { requirePermission('categories', 'delete'); $result = handleCategoriesDelete($pdo, $id); }
    else { $result = ['message' => '', 'messageType' => '']; }
    $message = $result['message'];
    $messageType = $result['messageType'];
    if ($messageType === 'success' && $action === 'edit') $action = '';
}

$context = loadCategoriesContext($pdo, ['action' => $action, 'id' => $id]);
$categories = $context['categories'];
foreach ($categories as &$category) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM errors WHERE category_id = ?");
    $stmt->execute([$category['id']]);
    $category['error_count'] = (int)($stmt->fetch()['count'] ?? 0);
}

view('admin/categories.twig', ['page_title' => '报错分类管理', 'admin_css_mtime' => @filemtime(BASE_PATH . '/assets/css/style.css') ?: time(), 'message' => $message, 'message_type' => $messageType] + $context + ['categories' => $categories]);
