<?php
require_once 'includes/user_auth.php';
require_once 'includes/sanitizer.php';
require_once 'includes/view.php';
require_once 'includes/error_solutions/service.php';

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
if (preg_match('#/error_solutions\.php$#', $requestPath)) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    $target = '/error_solutions' . ($qs !== '' ? ('?' . $qs) : '');
    header('Location: ' . $target, true, 301);
    exit;
}

$pdo = getDB();
$context = loadErrorSolutionsContext($pdo, [
    'page' => $_GET['page'] ?? 1,
    'perPage' => 20,
    'category_id' => $_GET['category_id'] ?? 0,
    'system_category' => $_GET['system_category'] ?? '',
]);

view('front/error_solutions.twig', $context);
