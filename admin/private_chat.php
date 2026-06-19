<?php
require_once 'includes/user_auth.php';
require_once 'includes/view.php';
require_once 'includes/private_chat/service.php';

requireUserLogin();

$pdo = getDB();
$userId = getCurrentUserId();
$partnerId = intval($_GET['user_id'] ?? 0);

if ($partnerId <= 0 || $partnerId === $userId) {
    header('Location: /private_messages');
    exit;
}

$context = loadPrivateChatContext($pdo, $userId, $partnerId);
if (!$context['partner']) {
    header('Location: /private_messages');
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

view('admin/private_chat.twig', [
    'page_title' => '私信对话',
    'admin_css_mtime' => @filemtime(BASE_PATH . '/assets/css/style.css') ?: time(),
] + $context + [
    'user_id' => $userId,
    'partner_id' => $partnerId,
]);
