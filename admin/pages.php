<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/view.php';
require_once '../includes/pages/service.php';

checkLogin(); requirePermission('site', 'view');
$pdo = getDB(); $action = $_GET['action'] ?? ''; $slug = $_GET['slug'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'edit' && $slug) && isset($_POST['content'])) { requirePermission('site', 'edit'); $r = handlePagesSavePage($pdo, $slug, $_POST['content'], loadPagesQueryContext($pdo)['pageMeta']); if ($r['success']) { $_SESSION['admin_msg'] = $r['message']; $_SESSION['admin_msg_type'] = 'success'; header('Location: pages.php'); exit; } }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_image') { requirePermission('site', 'edit'); header('Content-Type: application/json'); echo json_encode(handlePagesUploadImage($_FILES['image'] ?? [])); exit; }
$context = loadPagesContext($pdo, ['action' => $action, 'slug' => $slug]); view('admin/pages.twig', ['page_title' => '页面管理', 'admin_css_mtime' => @filemtime(BASE_PATH . '/assets/css/style.css') ?: time()] + $context);
