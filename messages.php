<?php
require_once 'includes/user_auth.php';
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
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>站内信 - <?php echo h(SITE_NAME); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER; ?>">
    <link rel="stylesheet" href="/assets/css/user.css?v=<?php echo ASSETS_VER; ?>">
    <?php renderSiteHead(); ?>
</head>
<body class="has-fixed-nav">
    <?php include 'includes/header.php'; ?>

    <main class="main">
        <div class="container" style="max-width:800px;">
            <div class="mb-20" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
                <h2>站内信</h2>
                <?php if ($total > 0): ?>
                    <a href="?read_all=1" class="btn btn-secondary btn-sm">全部标记已读</a>
                <?php endif; ?>
            </div>

            <?php if (!empty($messages)): ?>
                <?php foreach ($messages as $msg): ?>
                    <?php
                    $msgLink = normalizeMessageRedirectUrl((string)($msg['link_url'] ?? ''));
                    $msgTargetHref = '';
                    if ($msgLink && str_starts_with($msgLink, '/')) {
                        $msgTargetHref = '?read=' . intval($msg['id']) . '&redirect_to=' . urlencode($msgLink);
                    }
                    ?>
                    <div class="card" style="margin-bottom:12px;transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease;<?php echo !$msg['is_read'] ? 'border-left:3px solid var(--accent-purple);' : ''; ?><?php echo $msgTargetHref ? 'cursor:pointer;' : ''; ?>"<?php if ($msgTargetHref): ?> onmouseenter="this.style.transform='translateY(-2px)';this.style.boxShadow='var(--shadow-card-hover)';" onmouseleave="this.style.transform='';this.style.boxShadow='';" onclick="window.location.href='<?php echo h($msgTargetHref); ?>'" role="link" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();window.location.href='<?php echo h($msgTargetHref); ?>';}"<?php endif; ?>>
                        <div class="card-body" style="padding:16px 20px;">
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
                                <div style="flex:1;">
                                    <h4 style="font-size:0.95rem;color:var(--text-primary);margin-bottom:6px;">
                                        <?php if (!$msg['is_read']): ?>
                                            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--accent-purple);margin-right:6px;"></span>
                                        <?php endif; ?>
                                        <?php echo h($msg['title']); ?>
                                    </h4>
                                    <p style="font-size:0.88rem;color:var(--text-secondary);line-height:1.6;">
                                        <?php echo h($msg['content']); ?>
                                    </p>
                                    <span style="font-size:0.78rem;color:var(--text-muted);"><?php echo date('Y-m-d H:i', strtotime($msg['created_at'])); ?></span>
                                </div>
                                <?php if (!$msg['is_read']): ?>
                                    <a href="?read=<?php echo $msg['id']; ?>" class="btn btn-secondary btn-sm" onclick="event.stopPropagation();">标记已读</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if ($pagination['totalPages'] > 1): ?>
                    <div class="pagination">
                        <?php if ($pagination['hasPrev']): ?>
                            <a href="?page=<?php echo $pagination['page'] - 1; ?>">上一页</a>
                        <?php endif; ?>
                        <?php for ($i = max(1, $pagination['page'] - 2); $i <= min($pagination['totalPages'], $pagination['page'] + 2); $i++): ?>
                            <?php if ($i == $pagination['page']): ?>
                                <span class="current"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($pagination['hasNext']): ?>
                            <a href="?page=<?php echo $pagination['page'] + 1; ?>">下一页</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="card">
                    <div class="card-body text-center empty-state">
                        <p>暂无站内信</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>
</html>
