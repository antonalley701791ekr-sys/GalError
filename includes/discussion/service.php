<?php
require_once __DIR__ . '/query.php';

function loadDiscussionContext(PDO $pdo, int $id, array $input = []): array {
    $context = discussionGetContext($pdo, $id, $input);
    if (!$context['discussion']) {
        return $context;
    }

    ob_start();
    renderSiteHead();
    $siteHeadHtml = ob_get_clean();

    ob_start();
    include 'includes/header.php';
    $headerHtml = ob_get_clean();

    ob_start();
    include 'includes/footer.php';
    $footerHtml = ob_get_clean();

    return array_merge($context, [
        'site_head_html' => $siteHeadHtml,
        'header_html' => $headerHtml,
        'footer_html' => $footerHtml,
    ]);
}
