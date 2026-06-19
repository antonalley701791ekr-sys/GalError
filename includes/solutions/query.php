<?php
function solutionsGetContext(PDO $pdo, array $input): array {
    $status = $input['status'] ?? '';
    $errorId = (int)($input['error_id'] ?? 0);
    $where = [];
    $params = [];
    if ($status !== '') { $where[] = 's.status = ?'; $params[] = $status; }
    if ($errorId > 0) { $where[] = 's.error_id = ?'; $params[] = $errorId; }
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $solutions = $pdo->prepare("SELECT s.*, e.title AS error_title, g.title AS game_title, u.username AS author_name FROM error_solutions s JOIN errors e ON s.error_id = e.id JOIN games g ON e.game_id = g.id LEFT JOIN users u ON s.user_id = u.id {$whereClause} ORDER BY s.created_at DESC LIMIT 200");
    $solutions->execute($params);
    $solutions = $solutions->fetchAll();

    $solutionNumberMap = [];
    $solutionSeqByError = [];
    foreach ($solutions as $solutionRow) {
        $eid = (int)$solutionRow['error_id'];
        if (!isset($solutionSeqByError[$eid])) $solutionSeqByError[$eid] = 1;
        $solutionNumberMap[(int)$solutionRow['id']] = $solutionSeqByError[$eid]++;
    }

    $stats = [
        'total' => (int)$pdo->query("SELECT COUNT(*) FROM error_solutions")->fetchColumn(),
        'pending' => (int)$pdo->query("SELECT COUNT(*) FROM error_solutions WHERE status = 'pending'")->fetchColumn(),
        'approved' => (int)$pdo->query("SELECT COUNT(*) FROM error_solutions WHERE status = 'approved'")->fetchColumn(),
        'rejected' => (int)$pdo->query("SELECT COUNT(*) FROM error_solutions WHERE status = 'rejected'")->fetchColumn(),
        'primary_errors' => (int)$pdo->query("SELECT COUNT(DISTINCT error_id) FROM error_solutions WHERE is_primary = 1 AND status = 'approved'")->fetchColumn(),
    ];

    return compact('solutions', 'solutionNumberMap', 'stats', 'status', 'errorId');
}
