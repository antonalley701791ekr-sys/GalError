<?php
require_once 'includes/user_auth.php';
require_once 'includes/markdown.php';
require_once 'includes/auth.php';
require_once 'includes/view.php';


$fromAdmin = isset($_GET['from_admin']) && $_GET['from_admin'] === '1';
if ($fromAdmin) {
    checkLogin();
    requirePermission('articles', 'view');
}

$pdo = getDB();
$id = intval($_GET['id'] ?? 0);

if (!$id) {
    header('Location: ' . ($fromAdmin ? '/admin/article_review.php' : '/articles'));
    exit;
}

$sql = "SELECT a.*, u.username, u.avatar FROM articles a JOIN users u ON a.user_id = u.id WHERE a.id = ?";
if (!$fromAdmin && !isAdmin()) {
    $sql .= " AND a.status = 'approved'";
}
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$article = $stmt->fetch();

if (!$article) {
    header('Location: ' . ($fromAdmin ? '/admin/article_review.php' : '/articles'));
    exit;
}

$tags = array_filter(array_map('trim', explode(',', (string)($article['tags'] ?? ''))));
$viewCounts = getViewCount('article', $id);

$commentPage = max(1, intval($_GET['comment_page'] ?? 1));
$commentPerPage = getCommentPerPage();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE content_type = 'article' AND content_id = ? AND status = 'active'");
$stmt->execute([$id]);
$articleCommentCount = (int)$stmt->fetchColumn();
$commentPagination = paginate($articleCommentCount, $commentPage, $commentPerPage);

$stmt = $pdo->prepare("
    SELECT c.*, u.username, u.avatar, c.parent_id,
           pc.user_id as parent_user_id, pu.username as parent_username, pc.content as parent_content
    FROM comments c
    JOIN users u ON c.user_id = u.id
    LEFT JOIN comments pc ON c.parent_id = pc.id
    LEFT JOIN users pu ON pc.user_id = pu.id
    WHERE c.content_type = 'article' AND c.content_id = ? AND c.status = 'active'
    ORDER BY c.created_at ASC, c.id ASC
    LIMIT {$commentPagination['offset']}, {$commentPerPage}
");
$stmt->execute([$id]);
$articleComments = $stmt->fetchAll();

$tocItems = [];
$tocConfig = json_decode($article['toc_config'] ?? '', true);
if (is_array($tocConfig) && !empty($tocConfig)) {
    foreach ($tocConfig as $item) {
        if (!empty($item['visible'])) {
            $tocItems[] = $item;
        }
    }
} else {
    $tocItems = extract_headings_from_markdown($article['content']);
}
$hasToc = !empty($tocItems);
if ($hasToc) {
    $minLevel = PHP_INT_MAX;
    foreach ($tocItems as $item) {
        $minLevel = min($minLevel, intval($item['level']));
    }
    $counters = [];
    foreach ($tocItems as &$item) {
        $depth = intval($item['level']) - $minLevel;
        if (!isset($counters[$depth])) $counters[$depth] = 0;
        $counters[$depth]++;
        foreach (array_keys($counters) as $d) {
            if ($d > $depth) unset($counters[$d]);
        }
        $parts = [];
        for ($i = 0; $i <= $depth; $i++) {
            $parts[] = isset($counters[$i]) ? $counters[$i] : 1;
        }
        $item['number'] = implode('.', $parts);
    }
    unset($item);
}

$articleVm = [
    'id' => (int)$article['id'],
    'title' => (string)$article['title'],
    'title_short' => h(mb_substr((string)$article['title'], 0, 30)),
    'username' => (string)$article['username'],
    'user_id' => (int)$article['user_id'],
    'author_url' => '/profile?user_id=' . (int)$article['user_id'],
    'avatar_url' => (!empty($article['avatar']) && file_exists(BASE_PATH . $article['avatar'])) ? '/' . $article['avatar'] : '',
    'avatar_fallback' => mb_substr((string)$article['username'], 0, 1),
    'created_text' => date('Y-m-d H:i', strtotime($article['created_at'])),
    'status' => (string)$article['status'],
    'status_text' => ((string)$article['status'] === 'pending') ? '待审核' : '已驳回',
    'reject_reason' => (string)($article['reject_reason'] ?? ''),
    'has_pending_revision' => !empty($article['has_pending_revision']),
    'view_user' => (int)($viewCounts['user_views'] ?? 0),
    'view_guest' => (int)($viewCounts['guest_views'] ?? 0),
    'content_html' => md_to_html((string)$article['content']),
];

ob_start(); renderSiteHead(); $siteHeadHtml = ob_get_clean();
ob_start(); include __DIR__ . '/includes/header.php'; $headerHtml = ob_get_clean();
ob_start(); include __DIR__ . '/includes/footer.php'; $footerHtml = ob_get_clean();
$adminSidebarHtml = '';
$adminFooterScriptsHtml = '';
if ($fromAdmin) {
    ob_start(); renderAdminSidebar('article_review.php'); $adminSidebarHtml = ob_get_clean();
    ob_start(); renderAdminFooterScripts(); $adminFooterScriptsHtml = ob_get_clean();
}

$backListUrl = $fromAdmin ? '/admin/article_review.php' : '/articles';
$actionHtml = '';
if ($fromAdmin) {
    $actionHtml = '<a href="/submit_article.php?edit=' . (int)$article['id'] . '&from_admin=1" class="btn btn-secondary btn-sm">编辑文章</a>';
} elseif (isUserLoggedIn() && getCurrentUserId() == $article['user_id']) {
    if ($article['status'] === 'pending' || $article['status'] === 'rejected' || ($article['status'] === 'approved' && empty($article['has_pending_revision']))) {
        $actionHtml = '<a href="/submit_article.php?edit=' . (int)$article['id'] . '" class="btn btn-secondary btn-sm">编辑文章</a>';
    } elseif ($article['status'] === 'approved' && !empty($article['has_pending_revision'])) {
        $actionHtml = '<span class="btn btn-secondary btn-sm" style="opacity:0.5;cursor:not-allowed;">修改审核中</span>';
    }
}

ob_start();
?>
<section class="comment-section" style="margin-top:24px;">
    <div class="comment-section-header">
        <h3>评论区（<span id="commentCount"><?php echo $articleCommentCount; ?></span> 条）</h3>
        <?php if (isUserLoggedIn()): ?>
            <button class="btn btn-sm" onclick="openCommentModal()">发表评论</button>
        <?php else: ?>
            <a href="/login?redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" class="btn btn-sm">登录后评论</a>
        <?php endif; ?>
    </div>
    <div class="comment-list" id="commentList">
        <?php if (!empty($articleComments)): ?>
            <?php foreach ($articleComments as $comment): ?>
                <div class="comment-item" id="comment-<?php echo $comment['id']; ?>" data-comment-content="<?php echo h($comment['content']); ?>">
                    <div class="comment-author">
                        <span class="comment-author-info">
                            <?php
                            $cAvatarUrl = '';
                            if (!empty($comment['avatar']) && file_exists(BASE_PATH . $comment['avatar'])) {
                                $cAvatarUrl = '/' . $comment['avatar'];
                            }
                            ?>
                            <?php if ($cAvatarUrl): ?>
                                <img src="<?php echo h($cAvatarUrl); ?>" class="comment-author-avatar" alt="">
                            <?php else: ?>
                                <span class="comment-author-avatar fallback"><?php echo h(mb_substr($comment['username'], 0, 1)); ?></span>
                            <?php endif; ?>
                            <strong><a href="/profile?user_id=<?php echo intval($comment['user_id']); ?>" class="comment-user-link"><?php echo h($comment['username']); ?></a></strong>
                            <?php if ($comment['parent_id'] && $comment['parent_username']): ?>
                                <span class="comment-reply-to">回复 <a href="/profile?user_id=<?php echo intval($comment['parent_user_id'] ?? 0); ?>" class="comment-user-link"><?php echo h($comment['parent_username']); ?></a></span>
                            <?php endif; ?>
                        </span>
                        <span class="comment-meta-right">
                            <span class="comment-time"><?php echo date('Y-m-d H:i', strtotime($comment['created_at'])); ?></span>
                            <?php if (isUserLoggedIn()): ?>
                                <button class="comment-reply-btn" data-comment-id="<?php echo $comment['id']; ?>" data-comment-username="<?php echo h($comment['username']); ?>">回复</button>
                            <?php endif; ?>
                            <?php if (isUserLoggedIn() && (int)getCurrentUserId() === (int)$comment['user_id']): ?>
                                <button class="comment-reply-btn comment-edit-btn" data-comment-id="<?php echo $comment['id']; ?>">编辑</button>
                            <?php endif; ?>
                            <?php if (isAdmin()): ?>
                                <button class="comment-delete-btn" data-comment-id="<?php echo $comment['id']; ?>" title="删除评论">删除</button>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="comment-body markdown-body">
                        <?php if ($comment['parent_id'] && $comment['parent_username']): ?>
                            <div class="comment-reply-context">
                                <div class="comment-reply-header">回复 <span class="comment-user-link">@<?php echo h($comment['parent_username']); ?></span></div>
                                <a href="<?php echo h(buildCommentTargetUrl('article', $id, (int)$comment['parent_id'], $commentPerPage)); ?>" class="comment-reply-quote"><?php echo nl2br(h(mb_strimwidth(trim((string)($comment['parent_content'] ?? '')), 0, 240, '…', 'UTF-8'))); ?></a>
                                <div class="comment-reply-divider"></div>
                            </div>
                        <?php endif; ?>
                        <div class="comment-main-content"><?php echo md_to_html($comment['content']); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="comment-empty" id="commentEmpty">暂无评论，来发表第一条评论吧</div>
        <?php endif; ?>
    </div>
    <?php if ($commentPagination['totalPages'] > 1): ?>
        <div class="pagination" style="margin-top:18px;">
            <?php if ($commentPagination['page'] > 1): ?><a href="/article.php?id=<?php echo $id; ?>&comment_page=1#commentList">第一页</a><?php endif; ?>
            <?php if ($commentPagination['hasPrev']): ?><a href="/article.php?id=<?php echo $id; ?>&comment_page=<?php echo $commentPagination['page'] - 1; ?>#commentList">上一页</a><?php endif; ?>
            <span class="current"><?php echo $commentPagination['page']; ?> / <?php echo $commentPagination['totalPages']; ?></span>
            <?php if ($commentPagination['hasNext']): ?><a href="/article.php?id=<?php echo $id; ?>&comment_page=<?php echo $commentPagination['page'] + 1; ?>#commentList">下一页</a><?php endif; ?>
            <?php if ($commentPagination['page'] < $commentPagination['totalPages']): ?><a href="/article.php?id=<?php echo $id; ?>&comment_page=<?php echo $commentPagination['totalPages']; ?>#commentList">最后一页</a><?php endif; ?>
        </div>
    <?php endif; ?>
</section>
<?php
$commentsHtml = ob_get_clean();

$tocHtml = '';
if ($hasToc) {
    ob_start();
    ?>
    <aside class="article-toc-fixed" id="tocFixed">
        <div class="article-toc-card" id="tocCard">
            <div class="article-toc-title article-toc-mobile-toggle" id="tocToggle">目录</div>
            <nav class="article-toc-nav" id="tocNav">
                <ul>
                    <?php foreach ($tocItems as $item): ?>
                        <li>
                            <a href="#<?php echo h($item['id']); ?>" class="toc-level-<?php echo intval($item['level']); ?>">
                                <span class="toc-indicator"></span>
                                <span class="toc-number"><?php echo h($item['number']); ?></span>
                                <span class="toc-text"><?php echo h($item['text']); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </nav>
        </div>
    </aside>
    <?php
    $tocHtml = ob_get_clean();
}

ob_start();
?>
<div id="view-counter-config" data-type="article" data-id="<?php echo $id; ?>" data-csrf="<?php echo h(csrf_token('default')); ?>" style="display:none;"></div>
<script>
window.GalCommentConfig = {
    contentType: 'article',
    contentId: <?php echo $id; ?>,
    csrfToken: '<?php echo h(csrf_token('default')); ?>',
    debug: /(?:^|[?&])comment_debug=1(?:&|$)/.test(window.location.search) || (window.localStorage && window.localStorage.getItem('comment_debug') === '1')
};
</script>
<script src="/assets/js/view-counter.js?v=<?php echo ASSETS_VER; ?>"></script>
<script src="/assets/js/marked.min.js?v=<?php echo ASSETS_VER; ?>"></script>
<script src="/assets/js/comment-collapse.js?v=<?php echo ASSETS_VER; ?>"></script>
<script>
window.openCommentModal = window.openCommentModal || function() {
    if (window.GalCommentBridge && typeof window.GalCommentBridge.openCommentModal === 'function') {
        return window.GalCommentBridge.openCommentModal();
    }
    console.error('openCommentModal is not available');
};
window.closeCommentModal = window.closeCommentModal || function() {
    if (window.GalCommentBridge && typeof window.GalCommentBridge.closeCommentModal === 'function') {
        return window.GalCommentBridge.closeCommentModal();
    }
};
window.submitComment = window.submitComment || function() {
    if (window.GalCommentBridge && typeof window.GalCommentBridge.submitComment === 'function') {
        return window.GalCommentBridge.submitComment();
    }
    console.error('submitComment is not available');
};
window.replyToComment = window.replyToComment || function(commentId, username) {
    if (window.GalCommentBridge && typeof window.GalCommentBridge.replyToComment === 'function') {
        return window.GalCommentBridge.replyToComment(commentId, username);
    }
    console.error('replyToComment is not available');
};
window.editComment = window.editComment || function(commentId) {
    if (window.GalCommentBridge && typeof window.GalCommentBridge.editComment === 'function') {
        return window.GalCommentBridge.editComment(commentId);
    }
    console.error('editComment is not available');
};
window.deleteComment = window.deleteComment || function(commentId) {
    if (window.GalCommentBridge && typeof window.GalCommentBridge.deleteComment === 'function') {
        return window.GalCommentBridge.deleteComment(commentId);
    }
    console.error('deleteComment is not available');
};
</script>
<script src="/assets/js/article-comments.js?v=<?php echo ASSETS_VER . '-' . @filemtime(BASE_PATH . '/assets/js/article-comments.js'); ?>"></script>
<?php
$pageScriptsHtml = ob_get_clean();

view('front/article.twig', [
    'from_admin' => $fromAdmin,
    'article' => $articleVm,
    'tags' => $tags,
    'back_list_url' => $backListUrl,
    'site_head_html' => $siteHeadHtml,
    'header_html' => $headerHtml,
    'admin_sidebar_html' => $adminSidebarHtml,
    'admin_footer_scripts_html' => $adminFooterScriptsHtml,
    'footer_html' => $footerHtml,
    'csrf' => csrf_token('default'),
    'comments_html' => $commentsHtml,
    'toc_html' => $tocHtml,
    'action_html' => $actionHtml,
    'page_scripts_html' => $pageScriptsHtml,
]);
