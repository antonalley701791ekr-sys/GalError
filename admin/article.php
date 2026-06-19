<?php
require_once 'includes/user_auth.php';
require_once 'includes/markdown.php';
require_once 'includes/view.php';
require_once 'includes/article/service.php';

$pdo = getDB(); $id = intval($_GET['id'] ?? 0); if (!$id) { header('Location: /articles'); exit; }
$context = loadArticleContext($pdo, $id, ['comment_page' => $_GET['comment_page'] ?? 1]); if (empty($context['article'])) { header('Location: /articles'); exit; }
view('admin/article.twig', $context);
