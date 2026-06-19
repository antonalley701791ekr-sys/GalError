<?php
require_once 'includes/user_auth.php';
require_once 'includes/view.php';
requireUserLogin();

$pdo = getDB();
$userId = getCurrentUserId();

function normalizeMessageRedirectUrl($url) {
    $url = trim((string)$url);
    if ($url === '') {
        return '';
    }

    if ($url === '/page?slug=entry-guide') {
        return '/page/entry-guide';
    }
    if ($url === '/page?slug=admin-guide') {
        return '/page/admin-guide';
    }

    $parts = parse_url($url);
    if ($parts === false) {
        return $url;
    }

    $path = $parts['path'] ?? '';
    $fragment = $parts['fragment'] ?? '';
    $queryParams = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $queryParams);
    }

    if (!preg_match('/^comment-(\d+)$/', $fragment, $commentMatch)) {
        return $url;
    }

    $commentId = (int)$commentMatch[1];
    if ($commentId <= 0) {
        return $url;
    }

    $contentType = '';
    $contentId = 0;

    if ($path === '/article.php' && !empty($queryParams['id'])) {
        $contentType = 'article';
        $contentId = (int)$queryParams['id'];
    } elseif ($path === '/discussion.php' && !empty($queryParams['id'])) {
        $contentType = 'discussion';
        $contentId = (int)$queryParams['id'];
    } elseif ($path === '/error_detail.php' && !empty($queryParams['id'])) {
        $contentType = 'error';
        $contentId = (int)$queryParams['id'];
    } elseif (preg_match('#^/article/(\d+)$#', $path, $match)) {
        $contentType = 'article';
        $contentId = (int)$match[1];
    } elseif (preg_match('#^/discussion/(\d+)$#', $path, $match)) {
        $contentType = 'discussion';
        $contentId = (int)$match[1];
    } elseif (preg_match('#^/error/(\d+)$#', $path, $match)) {
        $contentType = 'error';
        $contentId = (int)$match[1];
    }

    if ($contentType === '' || $contentId <= 0) {
        return $url;
    }

    return buildCommentTargetUrl($contentType, $contentId, $commentId);
}

// 标记已读
if (isset($_GET['read'])) {
    $readId = intval($_GET['read']);
    $redirectTo = trim($_GET['redirect_to'] ?? '');
    $stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$readId, $userId]);
    if ($redirectTo !== '' && str_starts_with($redirectTo, '/') && !str_starts_with($redirectTo, '//')) {
        header('Location: ' . $redirectTo);
    } else {
        header('Location: /messages');
    }
    exit;
}

// 全部标记已读
if (isset($_GET['read_all'])) {
    $pdo->prepare("UPDATE messages SET is_read = 1 WHERE user_id = ?")->execute([$userId]);
    header('Location: /messages');
    exit;
}

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE user_id = ?");
$stmt->execute([$userId]);
$total = (int)$stmt->fetchColumn();

$pagination = paginate($total, $page, $perPage);

$stmt = $pdo->prepare("SELECT * FROM messages WHERE user_id = ? ORDER BY created_at DESC LIMIT {$pagination['offset']}, {$perPage}");
$stmt->execute([$userId]);
$messages = $stmt->fetchAll();

foreach ($messages as &$msg) {
    $msgLink = normalizeMessageRedirectUrl((string)($msg['link_url'] ?? ''));
    $msgTargetHref = '';
    if ($msgLink && str_starts_with($msgLink, '/')) {
        $msgTargetHref = '?read=' . intval($msg['id']) . '&redirect_to=' . urlencode($msgLink);
    }

    $msg['target_href'] = $msgTargetHref;
    $msg['created_text'] = !empty($msg['created_at']) ? date('Y-m-d H:i', strtotime($msg['created_at'])) : '';
    $msg['is_read'] = (int)($msg['is_read'] ?? 0);
}
unset($msg);

ob_start();
renderSiteHead();
$siteHeadHtml = ob_get_clean();

ob_start();
include 'includes/header.php';
$headerHtml = ob_get_clean();

ob_start();
include 'includes/footer.php';
$footerHtml = ob_get_clean();

view('front/messages.twig', [
    'total' => $total,
    'messages' => $messages,
    'pagination' => $pagination,
    'site_head_html' => $siteHeadHtml,
    'header_html' => $headerHtml,
    'footer_html' => $footerHtml,
]);
