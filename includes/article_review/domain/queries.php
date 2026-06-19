<?php
function articleReviewStats(PDO $pdo): array {
    return [
        'total' => (int)$pdo->query("SELECT COUNT(*) FROM articles")->fetchColumn(),
        'pending' => (int)$pdo->query("SELECT COUNT(*) FROM articles WHERE status = 'pending'")->fetchColumn(),
        'approved' => (int)$pdo->query("SELECT COUNT(*) FROM articles WHERE status = 'approved'")->fetchColumn(),
        'rejected' => (int)$pdo->query("SELECT COUNT(*) FROM articles WHERE status = 'rejected'")->fetchColumn(),
    ];
}

function articleReviewPendingRevisionCount(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM article_revisions WHERE status = 'pending'")->fetchColumn();
}

function articleReviewListContext(PDO $pdo, string $status, int $page, int $perPage): array {
    $where = [];
    $params = [];
    if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
        $where[] = 'status = ?';
        $params[] = $status;
    }
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM articles $whereClause");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $pagination = paginate($total, $page, $perPage);
    $offset = ($page - 1) * $perPage;
    $stmt = $pdo->prepare("SELECT articles.*, users.username AS username FROM articles LEFT JOIN users ON users.id = articles.user_id $whereClause ORDER BY articles.created_at DESC LIMIT $offset, $perPage");
    $stmt->execute($params);
    return ['articles' => $stmt->fetchAll(), 'total' => $total, 'articleTotal' => $total, 'articlePagination' => $pagination];
}

function articleReviewRevisionListContext(PDO $pdo, string $revStatus, int $page, int $perPage): array {
    $where = [];
    $params = [];
    if (in_array($revStatus, ['pending', 'approved', 'rejected'], true)) {
        $where[] = 'status = ?';
        $params[] = $revStatus;
    }
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM article_revisions $whereClause");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $pagination = paginate($total, $page, $perPage);
    $offset = ($page - 1) * $perPage;
    $stmt = $pdo->prepare("SELECT * FROM article_revisions $whereClause ORDER BY created_at DESC LIMIT $offset, $perPage");
    $stmt->execute($params);
    return ['revisionsList' => $stmt->fetchAll(), 'revisionTotal' => $total, 'revisionPagination' => $pagination];
}

function articleReviewArticleDetailContext(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare("SELECT * FROM articles WHERE id = ?");
    $stmt->execute([$id]);
    return ['viewArticle' => $stmt->fetch() ?: null];
}

function articleReviewRevisionDetailContext(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare("SELECT * FROM article_revisions WHERE id = ?");
    $stmt->execute([$id]);
    return ['viewRevision' => $stmt->fetch() ?: null];
}

function loadArticleReviewQueryContext(PDO $pdo, array $input): array {
    $action = $input['action'] ?? '';
    $status = $input['status'] ?? '';
    $page = max(1, (int)($input['page'] ?? 1));
    $perPage = (int)($input['perPage'] ?? 20);
    $revStatus = $input['revStatus'] ?? '';

    $context = [
        'articleStats' => articleReviewStats($pdo),
        'pendingRevisions' => articleReviewPendingRevisionCount($pdo),
    ];

    $context = array_merge($context, articleReviewListContext($pdo, $status, $page, $perPage));

    if ($action === 'revisions') {
        $context = array_merge($context, articleReviewRevisionListContext($pdo, $revStatus, $page, $perPage));
    }

    if ($action === 'view' && !empty($input['id'])) {
        $context = array_merge($context, articleReviewArticleDetailContext($pdo, (int)$input['id']));
    }

    if ($action === 'view_revision' && !empty($input['rev_id'])) {
        $context = array_merge($context, articleReviewRevisionDetailContext($pdo, (int)$input['rev_id']));
    }

    return $context;
}
