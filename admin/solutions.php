<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/view.php';
require_once '../includes/solutions/service.php';

checkLogin();
requirePermission('errors', 'view');

$pdo = getDB();
$action = $_POST['action'] ?? ($_GET['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'view' && !empty($_GET['id'])) {
    $solutionId = (int)$_GET['id'];
    if ($solutionId > 0) {
        $stmt = $pdo->prepare("SELECT error_id FROM error_solutions WHERE id = ? LIMIT 1");
        $stmt->execute([$solutionId]);
        $errorId = (int)$stmt->fetchColumn();
        if ($errorId > 0) {
            header('Location: /error_detail.php?id=' . $errorId . '&from_admin=1');
            exit;
        }
    }
}

$context = loadSolutionsContext($pdo, [
    'action' => $action,
    'id' => $_GET['id'] ?? 0,
    'status' => $_GET['status'] ?? '',
    'error_id' => $_GET['error_id'] ?? 0,
]);

$redirect = $context['redirect'] ?? '';
if ($redirect !== '') {
    header('Location: ' . $redirect . '?message=' . urlencode($context['message'] ?? '') . '&message_type=' . urlencode($context['messageType'] ?? 'success'));
    exit;
}

view('admin/solutions.twig', array_merge([
    'page_title' => '解决方案管理',
    'admin_css_mtime' => @filemtime(BASE_PATH . '/assets/css/style.css') ?: time(),
], $context));
