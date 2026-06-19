<?php
require_once __DIR__ . '/query.php';
require_once __DIR__ . '/actions.php';

function loadSmtpSettingsContext(PDO $pdo): array {
    return loadSmtpSettingsQueryContext($pdo);
}
