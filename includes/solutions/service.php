<?php
require_once __DIR__ . '/query.php';
require_once __DIR__ . '/actions.php';

function loadSolutionsContext(PDO $pdo, array $input): array {
    $context = solutionsGetContext($pdo, $input);
    $action = $input['action'] ?? '';
    $message = '';
    $messageType = '';

    $redirect = '';
    if ($action === 'migrate') {
        $result = solutionsMigrateHistory($pdo);
        $message = $result['message'];
        $messageType = $result['messageType'];
        unset($result['message'], $result['messageType']);
        $context = array_merge($context, $result);
    } else {
        $result = solutionsHandleActions($pdo, $input);
        $message = $result['message'];
        $messageType = $result['messageType'];
        $redirect = (string)($result['redirect'] ?? '');
    }

    return array_merge($context, compact('message', 'messageType', 'redirect'));
}
