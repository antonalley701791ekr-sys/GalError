<?php
function articleReviewArticleHandleActions(PDO $pdo, array &$state): void {
    $action = $state['action'] ?? '';
    if ($action !== 'approve_article' && $action !== 'reject_article' && $action !== 'delete_article') {
        return;
    }

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        $state['message'] = '无效的文章ID';
        $state['messageType'] = 'error';
        return;
    }

    if ($action === 'approve_article') {
        $pdo->prepare("UPDATE articles SET status = 'approved', reject_reason = NULL WHERE id = ?")->execute([$id]);
        $state['message'] = '文章已通过';
        $state['messageType'] = 'success';
    } elseif ($action === 'reject_article') {
        $rejectReason = trim((string)($_POST['reject_reason'] ?? ''));
        $pdo->prepare("UPDATE articles SET status = 'rejected', reject_reason = ? WHERE id = ?")->execute([$rejectReason !== '' ? $rejectReason : '未通过审核', $id]);
        $state['message'] = '文章已拒绝';
        $state['messageType'] = 'success';
    } elseif ($action === 'delete_article') {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM article_revisions WHERE article_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM comments WHERE content_type = 'article' AND content_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM articles WHERE id = ?")->execute([$id]);
            $pdo->commit();
            $state['message'] = '文章已删除';
            $state['messageType'] = 'success';
            $state['redirect'] = '/admin/article_review.php';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $state['message'] = '文章删除失败';
            $state['messageType'] = 'error';
        }
    }
}
