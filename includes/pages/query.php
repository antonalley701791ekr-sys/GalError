<?php
function loadPagesQueryContext(PDO $pdo): array {
    $pageMeta = [
        'about' => ['title' => '关于我们', 'desc' => '介绍网站用途、定位、宗旨'],
        'legal' => ['title' => '版权及法律声明', 'desc' => '版权声明、法律条款'],
        'entry-guide' => ['title' => '入站须知', 'desc' => '新用户入站须知'],
        'admin-guide' => ['title' => '管理员须知', 'desc' => '管理员行为规范及操作须知'],
    ];
    $pages = $pdo->query("SELECT * FROM site_pages ORDER BY FIELD(slug, 'about', 'legal', 'entry-guide', 'admin-guide')")->fetchAll();
    return compact('pageMeta', 'pages');
}
