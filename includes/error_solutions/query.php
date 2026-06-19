<?php
function errorSolutionsBuildQuery(PDO $pdo, array $input): array {
    $page = max(1, (int)($input['page'] ?? 1));
    $perPage = (int)($input['perPage'] ?? 20);
    $categoryId = (int)($input['category_id'] ?? 0);
    $systemCategory = trim((string)($input['system_category'] ?? ''));

    $systemCategoryOptions = [
        'windows' => 'Windows',
        'android_emulator' => '安卓模拟器',
        'console_handheld' => '主机掌机',
        'mobile_native' => '手机原生',
        'win_handheld' => 'Win掌机',
        'cloud_streaming' => '云/串流',
        'other' => '其他',
    ];

    $categories = $pdo->query("SELECT id, name FROM error_categories ORDER BY sort_order ASC, id ASC")->fetchAll();
    $validCategoryIds = array_map(fn($c) => (int)$c['id'], $categories);
    if ($categoryId > 0 && !in_array($categoryId, $validCategoryIds, true)) $categoryId = 0;

    $where = ["e.status = 'approved'"];
    $params = [];
    if ($categoryId > 0) { $where[] = 'e.category_id = ?'; $params[] = $categoryId; }
    if ($systemCategory !== '' && isset($systemCategoryOptions[$systemCategory])) { $where[] = 'e.system_category = ?'; $params[] = $systemCategory; }
    $whereClause = 'WHERE ' . implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM errors e {$whereClause}");
    $countStmt->execute($params);
    $total = (int)($countStmt->fetch()['total'] ?? 0);
    $pagination = paginate($total, $page, $perPage);

    $listSql = "
        SELECT e.*, g.title as game_title, c.name as category_name, es.solution AS solution_text
        FROM errors e
        JOIN games g ON e.game_id = g.id
        JOIN error_categories c ON e.category_id = c.id
        LEFT JOIN error_solutions es ON es.error_id = e.id AND es.is_primary = 1 AND es.status = 'approved'
        {$whereClause}
        ORDER BY e.created_at DESC
        LIMIT {$pagination['offset']}, {$perPage}
    ";
    $listStmt = $pdo->prepare($listSql);
    $listStmt->execute($params);
    $errors = $listStmt->fetchAll();

    return compact('page', 'perPage', 'categoryId', 'systemCategory', 'systemCategoryOptions', 'categories', 'where', 'params', 'total', 'pagination', 'errors');
}
