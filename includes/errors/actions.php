<?php
function errorsHandleActions(PDO $pdo, array &$state): void {
    $action = $state['action'] ?? '';
    $message = '';
    $messageType = '';
    $viewError = null;
    $revisions = [];

    if ($action === 'view' && isset($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT e.*, g.title as game_title, g.vndb_id, c.name as category_name, u.username AS submitter_name FROM errors e JOIN games g ON e.game_id = g.id JOIN error_categories c ON e.category_id = c.id LEFT JOIN users u ON e.user_id = u.id WHERE e.id = ?");
        $stmt->execute([(int)$_GET['id']]);
        $viewError = $stmt->fetch();
    }


    if ($action === 'approve' && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_GET['id'])) {
        $errorId = (int)$_GET['id'];
        $pdo->prepare("UPDATE errors SET status = 'approved', reject_reason = NULL WHERE id = ?")->execute([$errorId]);
        $state['message'] = '报错已通过';
        $state['messageType'] = 'success';
        $state['redirect'] = '/admin/errors.php';
    }

    if ($action === 'reject' && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_GET['id'])) {
        $errorId = (int)$_GET['id'];
        $rejectReason = trim((string)($_POST['reject_reason'] ?? ''));
        $pdo->prepare("UPDATE errors SET status = 'rejected', reject_reason = ? WHERE id = ?")->execute([$rejectReason !== '' ? $rejectReason : '未通过审核', $errorId]);
        $state['message'] = '报错已拒绝';
        $state['messageType'] = 'success';
        $state['redirect'] = '/admin/errors.php';
    }

    if ($action === 'delete' && $state['action'] === 'delete' && !empty($_GET['id'])) {
        $errorId = (int)$_GET['id'];
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM error_revisions WHERE error_id = ?")->execute([$errorId]);
            $pdo->prepare("DELETE FROM error_solutions WHERE error_id = ?")->execute([$errorId]);
            $pdo->prepare("DELETE FROM comments WHERE content_type = 'error' AND content_id = ?")->execute([$errorId]);
            $pdo->prepare("DELETE FROM errors WHERE id = ?")->execute([$errorId]);
            $pdo->commit();
            $state['message'] = '报错已删除';
            $state['messageType'] = 'success';
            $state['redirect'] = '/admin/errors.php';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $state['message'] = '删除失败';
            $state['messageType'] = 'error';
        }
    }

    if ($action === 'revisions') {
        $revStmt = $pdo->query("SELECT r.*, e.title as error_title, g.title as game_title, u.username as submitter_name FROM error_revisions r JOIN errors e ON r.error_id = e.id JOIN games g ON e.game_id = g.id LEFT JOIN users u ON r.user_id = u.id ORDER BY r.created_at DESC LIMIT 20");
        $revisions = $revStmt->fetchAll();
    }

    $state['message'] = $message;
    $state['messageType'] = $messageType;
    $state['viewError'] = $viewError;
    $state['revisions'] = $revisions;
}
