<?php
require_once __DIR__ . '/query.php';
require_once __DIR__ . '/actions.php';

function loadPagesContext(PDO $pdo, array $input): array {
    $context = loadPagesQueryContext($pdo);
    $action = $input['action'] ?? '';
    $slug = $input['slug'] ?? '';
    $message = $_SESSION['admin_msg'] ?? '';
    $messageType = $_SESSION['admin_msg_type'] ?? '';
    unset($_SESSION['admin_msg'], $_SESSION['admin_msg_type']);
    $editPage = null;
    if ($action === 'edit' && $slug) {
        $stmt = $pdo->prepare('SELECT * FROM site_pages WHERE slug = ?');
        $stmt->execute([$slug]);
        $editPage = $stmt->fetch();
    }
    return array_merge($context, compact('action', 'slug', 'message', 'messageType', 'editPage'));
}
