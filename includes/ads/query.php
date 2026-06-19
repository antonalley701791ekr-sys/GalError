<?php
function loadAdsQueryContext(PDO $pdo, array $input = []): array {
    $ads = $pdo->query("SELECT * FROM ads ORDER BY sort_order ASC, id DESC")->fetchAll();
    $typeText = ['image' => '图片', 'text' => '文字'];
    $posText = ['header' => '顶部', 'sidebar' => '侧边栏', 'between' => '内容间'];
    return compact('ads', 'typeText', 'posText');
}
