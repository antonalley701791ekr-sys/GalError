<?php
function articleReviewRevisionHandleActions(PDO $pdo, array &$state): void {
    $action = $state['action'] ?? '';
    if ($action !== 'approve_revision' && $action !== 'reject_revision') {
        return;
    }

    $id = (int)($_GET['rev_id'] ?? 0);
    if ($id <= 0) {
        $state['message'] = '无效的修订ID';
        $state['messageType'] = 'error';
        return;
    }

    if ($action === 'approve_revision') {
        $pdo->prepare("UPDATE article_revisions SET status = 'approved' WHERE id = ?")->execute([$id]);
        $state['message'] = '修订已通过';
        $state['messageType'] = 'success';
    } elseif ($action === 'reject_revision') {
        $rejectReason = trim((string)($_POST['reject_reason'] ?? ''));
        $pdo->prepare("UPDATE article_revisions SET status = 'rejected', reject_reason = ? WHERE id = ?")->execute([$rejectReason !== '' ? $rejectReason : '未通过审核', $id]);
        $state['message'] = '修订已拒绝';
        $state['messageType'] = 'success';
    }
}
