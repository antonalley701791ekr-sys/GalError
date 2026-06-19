<?php
require_once __DIR__ . '/query.php';
require_once __DIR__ . '/actions.php';

function loadAdsContext(PDO $pdo, array $input = []): array {
    $context = loadAdsQueryContext($pdo, $input);
    $action = $input['action'] ?? '';
    $id = isset($input['id']) ? intval($input['id']) : 0;
    $editAd = null;
    if ($action === 'edit' && $id) {
        $stmt = $pdo->prepare("SELECT * FROM ads WHERE id = ?");
        $stmt->execute([$id]);
        $editAd = $stmt->fetch();
    }
    return array_merge($context, compact('action', 'id', 'editAd'));
}
