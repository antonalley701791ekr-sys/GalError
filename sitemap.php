<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/url_helpers.php';

header('Content-Type: application/xml; charset=UTF-8');

$baseUrl = rtrim(SITE_URL, '/');

function xmlEscape($value) {
    return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

$urls = [
    ['loc' => $baseUrl . '/', 'changefreq' => 'daily', 'priority' => '1.0'],
    ['loc' => $baseUrl . '/articles', 'changefreq' => 'daily', 'priority' => '0.9'],
    ['loc' => $baseUrl . '/games', 'changefreq' => 'daily', 'priority' => '0.9'],
    ['loc' => $baseUrl . '/discussions', 'changefreq' => 'daily', 'priority' => '0.8'],
    ['loc' => $baseUrl . '/search', 'changefreq' => 'weekly', 'priority' => '0.6'],
    ['loc' => $baseUrl . '/page/about', 'changefreq' => 'monthly', 'priority' => '0.5'],
    ['loc' => $baseUrl . '/page/legal', 'changefreq' => 'monthly', 'priority' => '0.4'],
    ['loc' => $baseUrl . '/page/entry-guide', 'changefreq' => 'monthly', 'priority' => '0.4'],
];

$pdo = getDB();

$articles = $pdo->query("SELECT id, updated_at, created_at FROM articles WHERE status = 'approved' ORDER BY updated_at DESC, id DESC LIMIT 5000")->fetchAll();
foreach ($articles as $row) {
    $urls[] = [
        'loc' => $baseUrl . urlArticle((int)$row['id']),
        'lastmod' => date('c', strtotime($row['updated_at'] ?: $row['created_at'])),
        'changefreq' => 'weekly',
        'priority' => '0.8',
    ];
}

$games = $pdo->query("SELECT id, updated_at, created_at FROM games WHERE status = 'approved' ORDER BY updated_at DESC, id DESC LIMIT 5000")->fetchAll();
foreach ($games as $row) {
    $urls[] = [
        'loc' => $baseUrl . urlGame((int)$row['id']),
        'lastmod' => date('c', strtotime($row['updated_at'] ?: $row['created_at'])),
        'changefreq' => 'weekly',
        'priority' => '0.8',
    ];
}

$errors = $pdo->query("SELECT id, updated_at, created_at FROM errors WHERE status = 'approved' ORDER BY updated_at DESC, id DESC LIMIT 10000")->fetchAll();
foreach ($errors as $row) {
    $urls[] = [
        'loc' => $baseUrl . urlError((int)$row['id']),
        'lastmod' => date('c', strtotime($row['updated_at'] ?: $row['created_at'])),
        'changefreq' => 'weekly',
        'priority' => '0.7',
    ];
}

$discussions = $pdo->query("SELECT id, updated_at, created_at FROM discussions WHERE status = 'active' ORDER BY updated_at DESC, id DESC LIMIT 5000")->fetchAll();
foreach ($discussions as $row) {
    $urls[] = [
        'loc' => $baseUrl . urlDiscussion((int)$row['id']),
        'lastmod' => date('c', strtotime($row['updated_at'] ?: $row['created_at'])),
        'changefreq' => 'daily',
        'priority' => '0.7',
    ];
}

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo "  <url>\n";
    echo '    <loc>' . xmlEscape($u['loc']) . "</loc>\n";
    if (!empty($u['lastmod'])) {
        echo '    <lastmod>' . xmlEscape($u['lastmod']) . "</lastmod>\n";
    }
    echo '    <changefreq>' . xmlEscape($u['changefreq']) . "</changefreq>\n";
    echo '    <priority>' . xmlEscape($u['priority']) . "</priority>\n";
    echo "  </url>\n";
}
echo "</urlset>";
