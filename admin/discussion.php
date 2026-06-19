<?php
require_once 'includes/user_auth.php';
require_once 'includes/markdown.php';
require_once 'includes/view.php';
require_once 'includes/discussion/service.php';

requireUserLogin();
$pdo = getDB();
$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: /discussions'); exit; }
$context = loadDiscussionContext($pdo, $id, ['comment_page' => $_GET['comment_page'] ?? 1]);
if (!$context['discussion']) { header('Location: /discussions'); exit; }
view('admin/discussion.twig', ['page_title' => $context['discussion']['title'] . ' - 讨论区'] + $context);
