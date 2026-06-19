<?php
require_once 'includes/user_auth.php';
require_once 'includes/markdown.php';
require_once 'includes/view.php';
require_once 'includes/discussion/service.php';

$pdo = getDB();
$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: /discussions'); exit; }

$context = loadDiscussionContext($pdo, $id, ['comment_page' => $_GET['comment_page'] ?? 1]);
if (!$context['discussion']) { header('Location: /discussions'); exit; }

view('front/discussion.twig', $context);
