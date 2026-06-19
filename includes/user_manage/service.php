<?php
require_once __DIR__ . '/query.php';
require_once __DIR__ . '/actions.php';

function loadUserManageContext(PDO $pdo, array $filters): array {
    return loadUserManageQueryContext($pdo, $filters);
}
