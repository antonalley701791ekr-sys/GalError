<?php
require_once 'includes/user_auth.php';
require_once 'includes/markdown.php';
require_once 'includes/view.php';

$pdo = getDB();
$id = intval($_GET['id'] ?? 0);

if (!$id) {
    header('Location: /');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ? AND enabled = 1");
$stmt->execute([$id]);
$doc = $stmt->fetch();

if (!$doc) {
    header('Location: /');
    exit;
}

ob_start(); renderSiteHead(); $siteHeadHtml = ob_get_clean();
ob_start(); include __DIR__ . '/includes/header.php'; $headerHtml = ob_get_clean();
ob_start(); include __DIR__ . '/includes/footer.php'; $footerHtml = ob_get_clean();

view('front/document.twig', [
    'site_name' => getSiteSetting('site_name', SITE_NAME),
    'doc' => [
        'title' => (string)$doc['title'],
        'title_short' => mb_substr((string)$doc['title'], 0, 40),
        'description' => (string)($doc['description'] ?? ''),
        'content_html' => md_to_html((string)($doc['content'] ?? '')),
    ],
    'site_head_html' => $siteHeadHtml,
    'header_html' => $headerHtml,
    'footer_html' => $footerHtml,
]);
