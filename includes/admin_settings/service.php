<?php
function loadAdminSettingsContext(PDO $pdo, int $adminId): array {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$adminId]);
    return ['currentAdmin' => $stmt->fetch()];
}
