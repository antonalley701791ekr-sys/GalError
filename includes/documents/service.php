<?php
require_once __DIR__ . '/query.php';
require_once __DIR__ . '/actions.php';

function loadDocumentsContext(PDO $pdo, array $input = []): array {
    $context = loadDocumentsQueryContext($pdo);
    $action = $input['action'] ?? '';
    $id = isset($input['id']) ? intval($input['id']) : 0;
    $message = $_SESSION['admin_msg'] ?? '';
    $messageType = $_SESSION['admin_msg_type'] ?? '';
    unset($_SESSION['admin_msg'], $_SESSION['admin_msg_type']);
    $editDoc = null;
    if ($action === 'edit' && $id) { $stmt = $pdo->prepare('SELECT * FROM documents WHERE id = ?'); $stmt->execute([$id]); $editDoc = $stmt->fetch(); }
    return array_merge($context, compact('action', 'id', 'message', 'messageType', 'editDoc'));
}
