<?php
require_once __DIR__ . '/actions.php';

function loadCategoriesContext(PDO $pdo, array $input = []): array {
    $action = $input['action'] ?? '';
    $categories = $pdo->query("SELECT * FROM error_categories ORDER BY sort_order ASC, id ASC")->fetchAll();
    $editCategory = null;
    if ($action === 'edit' && !empty($input['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM error_categories WHERE id = ?");
        $stmt->execute([intval($input['id'])]);
        $editCategory = $stmt->fetch();
    }
    return compact('action', 'categories', 'editCategory');
}
