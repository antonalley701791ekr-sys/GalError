<?php
require_once 'includes/user_auth.php';
require_once 'includes/markdown.php';
require_once 'includes/view.php';

$slug = trim($_GET['slug'] ?? '');
$allowedSlugs = ['about', 'legal', 'entry-guide', 'admin-guide'];

if (!$slug || !in_array($slug, $allowedSlugs, true)) {
    header('Location: /');
    exit;
}

if ($slug === 'admin-guide' && !isAdmin()) {
    header('Location: /');
    exit;
}

$pdo = getDB();
$stmt = $pdo->prepare("SELECT * FROM site_pages WHERE slug = ?");
$stmt->execute([$slug]);
$page = $stmt->fetch();

if (!$page) {
    header('Location: /');
    exit;
}

$content = trim((string)($page['content'] ?? ''));

ob_start(); renderSiteHead(); $siteHeadHtml = ob_get_clean();
ob_start(); include __DIR__ . '/includes/header.php'; $headerHtml = ob_get_clean();
ob_start(); include __DIR__ . '/includes/footer.php'; $footerHtml = ob_get_clean();

view('front/page.twig', [
    'site_name' => getSiteSetting('site_name', SITE_NAME),
    'page' => [
        'title' => (string)$page['title'],
        'content_html' => $content !== '' ? md_to_html($content) : '',
    ],
    'site_head_html' => $siteHeadHtml,
    'header_html' => $headerHtml,
    'footer_html' => $footerHtml,
]);
