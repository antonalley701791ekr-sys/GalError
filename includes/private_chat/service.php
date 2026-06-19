<?php
require_once __DIR__ . '/query.php';

function loadPrivateChatContext(PDO $pdo, int $userId, int $partnerId): array {
    return loadPrivateChatQueryContext($pdo, $userId, $partnerId);
}
