<?php
require_once __DIR__ . '/query.php';
require_once __DIR__ . '/actions.php';

function loadErrorsContext(PDO $pdo, array $input): array {
    $state = errorsBuildQuery($input);
    $state['action'] = $input['action'] ?? '';
    $state['message'] = '';
    $state['messageType'] = '';

    errorsHandleActions($pdo, $state);

    if (!empty($state['redirect'])) {
        return $state;
    }

    $query = errorsQueryData($pdo, $state);
    return array_merge($state, $query);
}

function errorsQueryData(PDO $pdo, array $state): array {
    $whereClause = $state['where'] ? 'WHERE ' . implode(' AND ', $state['where']) : '';
    $page = $state['page'];
    $perPage = $state['perPage'];
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM errors e {$whereClause}");
    $stmt->execute($state['params']);
    $total = (int)($stmt->fetch()['count'] ?? 0);
    $pagination = paginate($total, $page, $perPage);
    $stmt = $pdo->prepare("SELECT e.*, g.title as game_title, g.vndb_id, c.name as category_name FROM errors e JOIN games g ON e.game_id = g.id JOIN error_categories c ON e.category_id = c.id {$whereClause} ORDER BY e.created_at DESC LIMIT {$offset}, {$perPage}");
    $stmt->execute($state['params']);
    $errors = $stmt->fetchAll();
    $stats = [
        'total' => (int)$pdo->query("SELECT COUNT(*) FROM errors")->fetchColumn(),
        'pending' => (int)$pdo->query("SELECT COUNT(*) FROM errors WHERE status = 'pending'")->fetchColumn(),
        'approved' => (int)$pdo->query("SELECT COUNT(*) FROM errors WHERE status = 'approved'")->fetchColumn(),
        'rejected' => (int)$pdo->query("SELECT COUNT(*) FROM errors WHERE status = 'rejected'")->fetchColumn(),
    ];

    return compact('total', 'pagination', 'errors', 'stats');
}
