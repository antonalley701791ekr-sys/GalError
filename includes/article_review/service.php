<?php
require_once __DIR__ . '/domain/queries.php';
require_once __DIR__ . '/domain/article_actions.php';
require_once __DIR__ . '/domain/revision_actions.php';

function loadArticleReviewContext(PDO $pdo, array $input): array {
    $state = [
        'action' => $input['action'] ?? '',
        'status' => $input['status'] ?? '',
        'page' => max(1, (int)($input['page'] ?? 1)),
        'perPage' => (int)($input['perPage'] ?? 20),
        'revStatus' => $input['revStatus'] ?? '',
        'message' => '',
        'messageType' => '',
    ];

    articleReviewArticleHandleActions($pdo, $state);
    articleReviewRevisionHandleActions($pdo, $state);

    $context = loadArticleReviewQueryContext($pdo, $state);

    return array_merge($context, [
        'message' => $state['message'],
        'messageType' => $state['messageType'],
        'action' => $state['action'],
    ]);
}
