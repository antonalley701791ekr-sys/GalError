<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/view.php';
require_once '../includes/admin_article_review_service.php';

checkLogin();
requirePermission('articles', 'view');

$pdo = getDB();
$action = $_POST['action'] ?? ($_GET['action'] ?? '');
$status = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$revStatus = $_GET['rev_status'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'view' && !empty($_GET['id'])) {
    $articleId = (int)$_GET['id'];
    if ($articleId > 0) {
        header('Location: /article.php?id=' . $articleId . '&from_admin=1');
        exit;
    }
}

$context = loadArticleReviewContext($pdo, [
    'action' => $action,
    'status' => $status,
    'page' => $page,
    'perPage' => $perPage,
    'revStatus' => $revStatus,
]);

view('admin/article_review.twig', array_merge([
    'page_title' => '文章管理',
    'admin_css_mtime' => @filemtime(BASE_PATH . '/assets/css/style.css') ?: time(),
    'action' => $action,
    'status' => $status,
    'revStatus' => $revStatus,
], $context));
