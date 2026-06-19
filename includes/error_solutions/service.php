<?php
require_once __DIR__ . '/query.php';

function loadErrorSolutionsContext(PDO $pdo, array $input): array {
    $context = errorSolutionsBuildQuery($pdo, $input);

    $errorIds = array_column($context['errors'], 'id');
    $errorViews = getViewCountsBatch('error', $errorIds);
    $errorCommentCounts = [];
    if (!empty($errorIds)) {
        $placeholders = implode(',', array_fill(0, count($errorIds), '?'));
        $commentStmt = $pdo->prepare("SELECT content_id, COUNT(*) as cnt FROM comments WHERE content_type = 'error' AND status = 'active' AND content_id IN ($placeholders) GROUP BY content_id");
        $commentStmt->execute($errorIds);
        foreach ($commentStmt->fetchAll() as $row) $errorCommentCounts[$row['content_id']] = (int)$row['cnt'];
    }

    foreach ($context['errors'] as &$error) {
        $errorId = (int)($error['id'] ?? 0);
        $error['url'] = urlError($errorId);
        $error['created_date'] = !empty($error['created_at']) ? date('Y-m-d', strtotime($error['created_at'])) : '';
        $error['view_count'] = (int)($errorViews[$errorId] ?? 0);
        $error['comment_count'] = (int)($errorCommentCounts[$errorId] ?? 0);
        $solution = trim((string)($error['solution_text'] ?? ''));
        $error['solution_summary'] = $solution !== '' ? mb_substr(preg_replace('/\s+/u', ' ', trim(strip_tags($solution))), 0, 100) . (mb_strlen($solution) > 100 ? '...' : '') : '';
    }
    unset($error);

    ob_start(); renderSiteHead(); $siteHeadHtml = ob_get_clean();
    ob_start(); include 'includes/header.php'; $headerHtml = ob_get_clean();
    ob_start(); include 'includes/footer.php'; $footerHtml = ob_get_clean();

    return array_merge($context, [
        'site_head_html' => $siteHeadHtml,
        'header_html' => $headerHtml,
        'footer_html' => $footerHtml,
    ]);
}
