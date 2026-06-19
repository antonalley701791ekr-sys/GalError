<?php
function discussionGetContext(PDO $pdo, int $id, array $input = []): array {
    if (!$id) {
        return ['discussion' => null, 'comments' => [], 'commentCount' => 0, 'commentPagination' => ['page' => 1, 'totalPages' => 1, 'hasPrev' => false, 'hasNext' => false, 'offset' => 0], 'viewCounts' => ['user_views' => 0, 'guest_views' => 0]];
    }

    $stmt = $pdo->prepare("SELECT d.*, u.username, u.avatar FROM discussions d JOIN users u ON d.user_id = u.id WHERE d.id = ? AND d.status = 'active'");
    $stmt->execute([$id]);
    $discussion = $stmt->fetch();
    if (!$discussion) {
        return ['discussion' => null, 'comments' => [], 'commentCount' => 0, 'commentPagination' => ['page' => 1, 'totalPages' => 1, 'hasPrev' => false, 'hasNext' => false, 'offset' => 0], 'viewCounts' => ['user_views' => 0, 'guest_views' => 0]];
    }

    $tags = array_filter(explode(',', $discussion['tags']));
    $viewCounts = getViewCount('discussion', $id);
    $commentPage = max(1, intval($input['comment_page'] ?? 1));
    $commentPerPage = getCommentPerPage();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE content_type = 'discussion' AND content_id = ? AND status = 'active'");
    $stmt->execute([$id]);
    $commentCount = (int)$stmt->fetchColumn();
    $commentPagination = paginate($commentCount, $commentPage, $commentPerPage);

    $stmt = $pdo->prepare("SELECT c.*, u.username, u.avatar, c.parent_id, pc.user_id as parent_user_id, pu.username as parent_username, pc.content as parent_content FROM comments c JOIN users u ON c.user_id = u.id LEFT JOIN comments pc ON c.parent_id = pc.id LEFT JOIN users pu ON pc.user_id = pu.id WHERE c.content_type = 'discussion' AND c.content_id = ? AND c.status = 'active' ORDER BY c.created_at ASC, c.id ASC LIMIT {$commentPagination['offset']}, {$commentPerPage}");
    $stmt->execute([$id]);
    $comments = $stmt->fetchAll();
    foreach ($comments as &$comment) {
        $comment['content_html'] = md_to_html($comment['content'] ?? '');
        $comment['parent_quote_url'] = !empty($comment['parent_id']) ? '#comment-' . (int)$comment['parent_id'] : null;
    }
    unset($comment);

    if (!isset($discussion['content_html'])) {
        $discussion['content_html'] = md_to_html($discussion['content'] ?? '');
    }

    $currentUserId = (int)getCurrentUserId();
    $discussionOwnerId = (int)($discussion['user_id'] ?? 0);
    $canEditDiscussion = isUserLoggedIn() && ($currentUserId === $discussionOwnerId || isAdmin());
    $canDeleteDiscussion = isUserLoggedIn() && ($currentUserId === $discussionOwnerId || isAdmin());

    return compact('discussion', 'tags', 'viewCounts', 'commentPage', 'commentPerPage', 'commentCount', 'commentPagination', 'comments') + [
        'canEditDiscussion' => $canEditDiscussion,
        'canDeleteDiscussion' => $canDeleteDiscussion,
        'discussionEditUrl' => '/submit_discussion.php?edit=' . (int)$discussion['id'],
        'discussionDeleteUrl' => '/discussion_delete.php?id=' . (int)$discussion['id'] . '&_csrf=' . urlencode(csrf_token('default')),
        'current_user_id' => $currentUserId,
        'is_admin' => isAdmin(),
        '_is_login' => isUserLoggedIn(),
    ];
}
