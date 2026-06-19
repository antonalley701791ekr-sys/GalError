<?php
require_once __DIR__ . '/query.php';

function loadArticleContext(PDO $pdo, int $id, array $input = []): array {
    $ctx = loadArticleQueryContext($pdo, $id, $input);
    return $ctx;
}
