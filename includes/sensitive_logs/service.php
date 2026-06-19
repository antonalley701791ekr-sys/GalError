<?php
require_once __DIR__ . '/query.php';
require_once __DIR__ . '/actions.php';

function loadSensitiveLogsContext(array $filters, string $logFile): array {
    return loadSensitiveLogsQueryContext($filters, $logFile);
}
