<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/view.php';
require_once '../includes/solutions/service.php';

checkLogin();
requirePermission('errors', 'edit');

require_once '../includes/retired_entry.php'; // 一次性入口已下线（任务5）

$pdo = getDB();
$action = $_POST['action'] ?? ($_GET['action'] ?? '');
$context = loadSolutionsContext($pdo, [
    'action' => $action === 'migrate' ? 'migrate' : '',
]);

view('admin/migrate_error_solutions.twig', array_merge([
    'page_title' => '解决方案迁移',
    'admin_css_mtime' => @filemtime(BASE_PATH . '/assets/css/style.css') ?: time(),
], $context));
