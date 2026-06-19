<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/user_auth.php';
require_once '../includes/sanitizer.php';
require_once '../includes/markdown.php';
require_once '../includes/view.php';

checkLogin();
requirePermission('errors', 'view');

$pdo = getDB();
$id = intval($_GET['id'] ?? 0);

if ($id > 0) {
    header('Location: /error_detail.php?id=' . $id . '&from_admin=1');
    exit;
}

if (!$id) {
    header('Location: errors.php');
    exit;
}

// 获取报错详情（后台可查看任意状态）
$stmt = $pdo->prepare("
    SELECT e.*, g.title as game_title, g.id as game_id, c.name as category_name, u.username as submitter_name,
           es.solution AS solution_text, es.user_id AS solution_user_id, su.username AS solution_author_name, es.created_at AS solution_created_at
    FROM errors e
    JOIN games g ON e.game_id = g.id
    JOIN error_categories c ON e.category_id = c.id
    LEFT JOIN users u ON e.user_id = u.id
    LEFT JOIN error_solutions es ON es.error_id = e.id AND es.is_primary = 1 AND es.status = 'approved'
    LEFT JOIN users su ON su.id = es.user_id
    WHERE e.id = ?
");
$stmt->execute([$id]);
$error = $stmt->fetch();

if (!$error) {
    header('Location: errors.php');
    exit;
}

// 获取浏览量
$viewCounts = getViewCount('error', $id);

// 获取该报错的已审核修改记录
$stmt = $pdo->prepare("
    SELECT r.*, u.username as submitter_name
    FROM error_revisions r
    LEFT JOIN users u ON r.user_id = u.id
    WHERE r.error_id = ? AND r.status = 'approved'
    ORDER BY r.created_at ASC
");
$stmt->execute([$id]);
$revisions = $stmt->fetchAll();

// 获取同游戏的其他报错作为相关推荐（排除当前）
$stmt = $pdo->prepare("
    SELECT e.id, e.title, e.solution, e.created_at, c.name as category_name
    FROM errors e
    JOIN error_categories c ON e.category_id = c.id
    WHERE e.game_id = ? AND e.id != ? AND e.status = 'approved'
    ORDER BY e.created_at DESC
    LIMIT 5
");
$stmt->execute([$error['game_id'], $id]);
$relatedErrors = $stmt->fetchAll();

// 批量获取相关报错的浏览量
$relatedIds = array_column($relatedErrors, 'id');
$relatedViews = getViewCountsBatch('error', $relatedIds);

// 获取评论列表（含回复信息）
$commentPage = max(1, intval($_GET['comment_page'] ?? 1));
$commentPerPage = getCommentPerPage();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE content_type = 'error' AND content_id = ? AND status = 'active'");
$stmt->execute([$id]);
$errorCommentCount = (int)$stmt->fetchColumn();
$commentPagination = paginate($errorCommentCount, $commentPage, $commentPerPage);

$stmt = $pdo->prepare("
    SELECT c.*, u.username, u.avatar, c.parent_id,
           pc.user_id as parent_user_id, pu.username as parent_username, pc.content as parent_content
    FROM comments c
    JOIN users u ON c.user_id = u.id
    LEFT JOIN comments pc ON c.parent_id = pc.id
    LEFT JOIN users pu ON pc.user_id = pu.id
    WHERE c.content_type = 'error' AND c.content_id = ? AND c.status = 'active'
    ORDER BY c.created_at ASC, c.id ASC
    LIMIT {$commentPagination['offset']}, {$commentPerPage}
");
$stmt->execute([$id]);
$errorComments = $stmt->fetchAll();
ob_start();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($error['title']); ?> - <?php echo h(SITE_NAME); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER; ?>">
    <link rel="stylesheet" href="/assets/css/user.css?v=<?php echo ASSETS_VER; ?>">
    <link rel="stylesheet" href="/assets/css/markdown-editor.css">
    <style>
        .comment-item {
            transition: background-color .35s ease, box-shadow .35s ease, border-color .35s ease, transform .35s ease;
        }
        .comment-body .comment-reply-header,
        .comment-body .comment-reply-header a,
        .comment-body .comment-reply-header a:hover,
        .comment-body .comment-reply-header a:visited,
        .comment-body .comment-reply-header a:active {
            color: rgba(148, 163, 184, 0.88) !important;
            text-decoration: none !important;
            opacity: 1 !important;
            font-style: italic;
            font-weight: 400;
        }
        .comment-body .comment-reply-header a:hover {
            color: rgba(176, 190, 207, 0.94) !important;
        }
        .comment-body .comment-reply-quote,
        .comment-body .comment-reply-quote:hover,
        .comment-body .comment-reply-quote:visited,
        .comment-body .comment-reply-quote:active {
            color: rgba(148, 163, 184, 0.88) !important;
            text-decoration: none !important;
            opacity: 1 !important;
        }

        @media (max-width: 768px) {
            .error-card .error-meta {
                display: flex;
                flex-wrap: wrap;
                gap: 6px 10px;
                align-items: flex-start;
                justify-content: flex-start;
            }

            .error-card .error-meta > span {
                min-width: 0;
            }

            .error-card .error-meta .article-tag-sm {
                display: inline-flex;
                align-items: center;
                max-width: 100%;
                min-width: 0;
                width: auto;
                box-sizing: border-box;
                flex: 0 1 auto;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                vertical-align: middle;
            }
        }
    </style>
    <?php renderSiteHead(); ?>
</head>
<body class="has-fixed-nav">
    <?php include '../includes/header.php'; ?>

    <div class="admin-layout">
        <?php renderAdminSidebar('errors.php'); ?>
        <div class="admin-content">
            <main class="admin-main">
        <div class="container" style="max-width: 900px;">
            <!-- 面包屑 -->
            <div class="article-breadcrumb-row mb-20">
                <span>
                    <a href="index.php">控制台</a> &gt;
                    <a href="errors.php">报错管理</a> &gt;
                    <span style="color:var(--text-secondary);"><?php echo h(mb_substr($error['title'], 0, 30)); ?></span>
                </span>
                <div style="display:flex;gap:8px;">
                    <a href="errors.php?action=edit&id=<?php echo intval($error['id']); ?>" class="btn btn-secondary btn-sm">编辑报错</a>
                    <a href="errors.php" class="btn btn-secondary btn-sm">返回列表</a>
                </div>
            </div>

            <!-- 详情卡片 -->
            <article class="card article-detail-card">
                <div class="card-body" style="padding:28px;">
                    <!-- 标题 -->
                    <h1 class="article-detail-title"><?php echo h($error['title']); ?></h1>

                    <!-- 元信息 -->
                    <div class="article-detail-meta">
                        <?php if (!empty($error['submitter_name'])): ?>
                            <span class="article-author">
                                <a href="/profile?user_id=<?php echo intval($error['user_id']); ?>" class="detail-author-link"><?php echo h($error['submitter_name']); ?></a>
                            </span>
                        <?php endif; ?>
                        <span>
                            <a href="game.php?id=<?php echo intval($error['game_id']); ?>" class="detail-author-link"><?php echo h($error['game_title']); ?></a>
                        </span>
                        <span>
                            <span class="article-tag-sm"><?php echo h($error['category_name']); ?></span>
                        </span>
                        <span>
                            <?php
                                $statusLabel = $error['status'] === 'approved' ? '已通过' : ($error['status'] === 'pending' ? '待审核' : '已拒绝');
                                $statusClass = $error['status'] === 'approved' ? 'status-approved' : ($error['status'] === 'pending' ? 'status-pending' : 'status-rejected');
                            ?>
                            <span class="status <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
                        </span>
                        <span><?php echo date('Y-m-d H:i', strtotime($error['created_at'])); ?></span>
                        <span class="view-count-detail" title="浏览量">
                            <svg class="view-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            登录浏览：<span id="view-count-user"><?php echo $viewCounts['user_views']; ?></span> | 访客浏览：<span id="view-count-guest"><?php echo $viewCounts['guest_views']; ?></span>
                        </span>
                    </div>

                    <?php if ($error['status'] === 'rejected' && !empty(trim((string)$error['reject_reason']))): ?>
                    <div class="error-detail-section" style="margin-top:16px;">
                        <h3>拒绝理由</h3>
                        <p style="color:#ef4444;"><?php echo nl2br(h($error['reject_reason'])); ?></p>
                    </div>
                    <?php endif; ?>

                    <!-- 正文内容 -->
                    <div class="article-detail-content">
                        <?php if ($error['phenomenon']): ?>
                        <div class="error-detail-section">
                            <h3>问题描述</h3>
                            <p><?php echo nl2br(h($error['phenomenon'])); ?></p>
                        </div>
                        <?php endif; ?>

                        <?php if ($error['system_info']): ?>
                        <div class="error-detail-section">
                            <h3>系统信息</h3>
                            <p><?php echo nl2br(h($error['system_info'])); ?></p>
                        </div>
                        <?php endif; ?>

                        <?php if ($error['patch_info']): ?>
                        <div class="error-detail-section">
                            <h3>汉化补丁</h3>
                            <p><?php echo nl2br(h($error['patch_info'])); ?></p>
                        </div>
                        <?php endif; ?>

                        <div class="error-detail-section">
                            <h3>解决方案</h3>
                            <?php if (!empty(trim($error['solution']))): ?>
                                <p><?php echo nl2br(h($error['solution'])); ?></p>
                            <?php else: ?>
                                <p class="text-muted">暂无解决方案，等待补充</p>
                            <?php endif; ?>
                        </div>

                        <?php if ($error['screenshots']): ?>
                        <div class="error-detail-section">
                            <h3>报错截图</h3>
                            <div class="screenshot-list">
                                <?php
                                $screenshots = explode(',', $error['screenshots']);
                                foreach ($screenshots as $screenshot):
                                    if (trim($screenshot)):
                                ?>
                                    <img src="<?php echo h(UPLOAD_URL . trim($screenshot)); ?>" alt="报错截图" class="screenshot-thumb js-image-viewer-trigger" data-viewer-src="<?php echo h(UPLOAD_URL . trim($screenshot)); ?>" data-viewer-alt="报错截图">
                                <?php
                                    endif;
                                endforeach;
                                ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($error['solution_screenshots']): ?>
                        <div class="error-detail-section">
                            <h3>解决方案截图</h3>
                            <div class="screenshot-list">
                                <?php
                                $solScreenshots = explode(',', $error['solution_screenshots']);
                                foreach ($solScreenshots as $screenshot):
                                    if (trim($screenshot)):
                                ?>
                                    <img src="<?php echo h(UPLOAD_URL . trim($screenshot)); ?>" alt="解决方案截图" class="screenshot-thumb js-image-viewer-trigger" data-viewer-src="<?php echo h(UPLOAD_URL . trim($screenshot)); ?>" data-viewer-alt="解决方案截图">
                                <?php
                                    endif;
                                endforeach;
                                ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- 修改记录 -->
                    <?php if (!empty($revisions)): ?>
                    <div class="error-detail-section" style="margin-top:24px;">
                        <h3>修改记录</h3>
                        <div class="revision-history" style="margin-top:8px;">
                            <?php foreach ($revisions as $rev): ?>
                            <div class="revision-item">
                                <div class="revision-meta">
                                    <span>修改者：<?php echo h($rev['submitter_name'] ?? '匿名用户'); ?></span>
                                    <span>时间：<?php echo date('Y-m-d H:i', strtotime($rev['created_at'])); ?></span>
                                </div>
                                <?php
                                $oldD = json_decode($rev['old_data'], true) ?: [];
                                $newD = json_decode($rev['new_data'], true) ?: [];
                                $fieldLabels = [
                                    'title' => '报错标题',
                                    'phenomenon' => '问题描述',
                                    'system_info' => '系统信息',
                                    'patch_info' => '汉化补丁',
                                    'solution' => '解决方案',
                                ];
                                foreach ($fieldLabels as $field => $label):
                                    $oldVal = $oldD[$field] ?? '';
                                    $newVal = $newD[$field] ?? '';
                                    if ($oldVal !== $newVal):
                                ?>
                                <div class="revision-diff">
                                    <strong><?php echo $label; ?>：</strong>
                                    <?php if (empty($oldVal) && !empty($newVal)): ?>
                                        <div class="diff-added"><?php echo nl2br(h($newVal)); ?></div>
                                    <?php elseif (!empty($oldVal) && empty($newVal)): ?>
                                        <div class="diff-removed"><?php echo nl2br(h($oldVal)); ?></div>
                                    <?php else: ?>
                                        <div class="diff-removed"><?php echo nl2br(h($oldVal)); ?></div>
                                        <div class="diff-added"><?php echo nl2br(h($newVal)); ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php
                                    endif;
                                endforeach;

                                // 报错截图变化
                                $oldSc = array_filter(array_map('trim', explode(',', $rev['old_screenshots'] ?? '')));
                                $newSc = array_filter(array_map('trim', explode(',', $rev['new_screenshots'] ?? '')));
                                $addedSc = array_diff($newSc, $oldSc);
                                $removedSc = array_diff($oldSc, $newSc);
                                if (!empty($addedSc) || !empty($removedSc)):
                                ?>
                                <div class="revision-diff">
                                    <strong>报错截图变化：</strong>
                                    <?php if (!empty($removedSc)): ?>
                                        <div class="diff-removed-imgs">
                                            <span>删除：</span>
                                            <?php foreach ($removedSc as $sc): ?>
                                                <img src="<?php echo h(UPLOAD_URL . $sc); ?>" alt="已删除截图" style="width:80px;height:60px;object-fit:cover;border-radius:4px;opacity:0.6;border:2px solid #e74c3c;">
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($addedSc)): ?>
                                        <div class="diff-added-imgs">
                                            <span>新增：</span>
                                            <?php foreach ($addedSc as $sc): ?>
                                                <img src="<?php echo h(UPLOAD_URL . $sc); ?>" alt="新增截图" style="width:80px;height:60px;object-fit:cover;border-radius:4px;border:2px solid #27ae60;">
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>

                                <?php
                                // 解决方案截图变化
                                $oldSolSc = array_filter(array_map('trim', explode(',', $rev['old_solution_screenshots'] ?? '')));
                                $newSolSc = array_filter(array_map('trim', explode(',', $rev['new_solution_screenshots'] ?? '')));
                                $addedSolSc = array_diff($newSolSc, $oldSolSc);
                                $removedSolSc = array_diff($oldSolSc, $newSolSc);
                                if (!empty($addedSolSc) || !empty($removedSolSc)):
                                ?>
                                <div class="revision-diff">
                                    <strong>解决方案截图变化：</strong>
                                    <?php if (!empty($removedSolSc)): ?>
                                        <div class="diff-removed-imgs">
                                            <span>删除：</span>
                                            <?php foreach ($removedSolSc as $sc): ?>
                                                <?php $imgPath = BASE_PATH . UPLOAD_PATH . $sc; ?>
                                                <?php if (file_exists($imgPath)): ?>
                                                    <img src="<?php echo h(UPLOAD_URL . $sc); ?>" alt="历史截图" style="width:80px;height:60px;object-fit:cover;border-radius:4px;opacity:0.6;border:2px solid #e74c3c;">
                                                <?php else: ?>
                                                    <span class="diff-removed" style="display:inline-block;padding:2px 6px;font-size:12px;">历史截图文件缺失：<?php echo h($sc); ?></span>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($addedSolSc)): ?>
                                        <div class="diff-added-imgs">
                                            <span>新增：</span>
                                            <?php foreach ($addedSolSc as $sc): ?>
                                                <img src="<?php echo h(UPLOAD_URL . $sc); ?>" alt="新增截图" style="width:80px;height:60px;object-fit:cover;border-radius:4px;border:2px solid #27ae60;">
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- 操作 -->
                    <div class="article-detail-actions">
                        <a href="errors.php?action=edit&id=<?php echo intval($error['id']); ?>" class="btn btn-secondary btn-sm">编辑报错</a>
                        <a href="errors.php" class="btn btn-secondary btn-sm">返回列表</a>
                    </div>
                </div>
            </article>

            <!-- 相关报错推荐 -->
            <?php if (!empty($relatedErrors)): ?>
            <section class="mb-20" style="margin-top:24px;">
                <h2 class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    该游戏的其他报错
                    <a href="<?php echo urlGame($error['game_id']); ?>" class="btn btn-secondary btn-sm" style="font-size:0.8rem;">查看全部</a>
                </h2>
                <div class="card-body">
                    <?php foreach ($relatedErrors as $rel): ?>
                        <a class="error-card" href="<?php echo urlError($rel['id']); ?>">
                            <h3 class="error-title"><?php echo h($rel['title']); ?></h3>
                            <div class="error-meta">
                                <span class="article-tag-sm"><?php echo h($rel['category_name']); ?></span>
                                <span>时间：<?php echo date('Y-m-d', strtotime($rel['created_at'])); ?></span>
                                <span class="view-count-inline" title="浏览量">
                                    <svg class="view-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <?php echo $relatedViews[$rel['id']] ?? 0; ?>
                                </span>
                            </div>
                            <div class="error-content">
                                <div class="error-section">
                                    <h4>解决方案：</h4>
                                    <?php if (!empty(trim($rel['solution']))): ?>
                                        <p><?php echo h(mb_substr($rel['solution'], 0, 80) . (mb_strlen($rel['solution']) > 80 ? '...' : '')); ?></p>
                                    <?php else: ?>
                                        <p class="text-muted">暂无解决方案</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

            <!-- 评论区 -->
            <section class="comment-section" style="margin-top:24px;">
                <div class="comment-section-header">
                    <h3>评论区（<span id="commentCount"><?php echo $errorCommentCount; ?></span> 条）</h3>
                    <?php if (isUserLoggedIn()): ?>
                        <button class="btn btn-sm" onclick="openCommentModal()">发表评论</button>
                    <?php else: ?>
                        <a href="/login?redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" class="btn btn-sm">登录后评论</a>
                    <?php endif; ?>
                </div>
                <div class="comment-list" id="commentList">
                    <?php if (!empty($errorComments)): ?>
                        <?php foreach ($errorComments as $comment): ?>
                            <div class="comment-item" id="comment-<?php echo $comment['id']; ?>" data-comment-content="<?php echo h($comment['content']); ?>">
                                <div class="comment-author">
                                    <span class="comment-author-info">
                                        <?php
                                        $cAvatarUrl = '';
                                        if ($comment['avatar'] && file_exists(BASE_PATH . $comment['avatar'])) {
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
                                            <span class="comment-reply-to">回复 
                                                <a href="/profile?user_id=<?php echo intval($comment['parent_user_id'] ?? 0); ?>" class="comment-user-link">
                                                    <?php echo h($comment['parent_username']); ?>
                                                </a>
                                            </span>
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
                                            <a href="<?php echo h(buildCommentTargetUrl('error', $id, (int)$comment['parent_id'], $commentPerPage)); ?>" class="comment-reply-quote"><?php echo nl2br(h(mb_strimwidth(trim((string)($comment['parent_content'] ?? '')), 0, 240, '…', 'UTF-8'))); ?></a>
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
                        <?php if ($commentPagination['page'] > 1): ?>
                            <a href="error_detail.php?id=<?php echo $id; ?>&comment_page=1#commentList">第一页</a>
                        <?php endif; ?>
                        <?php if ($commentPagination['hasPrev']): ?>
                            <a href="error_detail.php?id=<?php echo $id; ?>&comment_page=<?php echo $commentPagination['page'] - 1; ?>#commentList">上一页</a>
                        <?php endif; ?>
                        <span class="current"><?php echo $commentPagination['page']; ?> / <?php echo $commentPagination['totalPages']; ?></span>
                        <?php if ($commentPagination['hasNext']): ?>
                            <a href="error_detail.php?id=<?php echo $id; ?>&comment_page=<?php echo $commentPagination['page'] + 1; ?>#commentList">下一页</a>
                        <?php endif; ?>
                        <?php if ($commentPagination['page'] < $commentPagination['totalPages']): ?>
                            <a href="error_detail.php?id=<?php echo $id; ?>&comment_page=<?php echo $commentPagination['totalPages']; ?>#commentList">最后一页</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <!-- 评论弹窗 -->
    <div class="comment-modal-overlay" id="commentModalOverlay" style="display:none;">
        <div class="comment-modal">
            <div class="comment-modal-header">
                <h3><span id="commentModalTitle">发表评论</span><span id="replyIndicator" style="display:none;font-size:0.85rem;color:var(--accent-purple);margin-left:8px;"></span></h3>
                <button class="comment-modal-close" onclick="closeCommentModal()">&times;</button>
            </div>
            <div class="comment-modal-body">
                <div class="md-editor-wrap">
                    <div class="md-mode-tabs">
                        <button type="button" class="md-mode-tab active" data-mode="edit" onclick="switchCommentEditorMode('edit')">编写</button>
                        <button type="button" class="md-mode-tab" data-mode="preview" onclick="switchCommentEditorMode('preview')">预览</button>
                    </div>
                    <div class="md-editor-toolbar" id="commentToolbar">
                        <button type="button" data-action="bold" title="加粗"><b>B</b></button>
                        <button type="button" data-action="italic" title="斜体"><i>I</i></button>
                        <button type="button" data-action="strikethrough" title="删除线"><s>S</s></button>
                        <span class="md-toolbar-separator"></span>
                        <button type="button" data-action="link" title="链接">&#128279;</button>
                        <button type="button" data-action="image" title="上传图片">&#128247;</button>
                        <button type="button" data-action="mention" title="提及用户">@用户</button>
                        <button type="button" data-action="code" title="行内代码">`</button>
                        <button type="button" data-action="codeblock" title="代码块">&lt;/&gt;</button>
                        <span class="md-toolbar-separator"></span>
                        <button type="button" data-action="ul" title="无序列表">&#8226;</button>
                        <button type="button" data-action="ol" title="有序列表">1.</button>
                        <button type="button" data-action="quote" title="引用">&gt;</button>
                    </div>
                    <div class="md-editor-body mode-edit" id="commentEditorBody">
                        <textarea id="commentTextarea" class="md-editor-textarea" placeholder="输入评论内容，支持 Markdown 语法..." style="min-height:200px;"></textarea>
                        <div id="commentPreview" class="md-editor-preview-pane"></div>
                    </div>
                </div>
            </div>
            <div class="comment-modal-footer">
                <button class="btn" id="commentSubmitBtn" onclick="submitComment()">提交评论</button>
                <button class="btn btn-secondary" id="cancelReplyBtn" onclick="cancelReply()" style="display:none;">取消回复</button>
                <button class="btn btn-secondary" onclick="closeCommentModal()">取消</button>
            </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>
    <div id="view-counter-config" data-type="error" data-id="<?php echo $id; ?>" data-csrf="<?php echo h(csrf_token('default')); ?>" style="display:none;"></div>
    <script src="/assets/js/view-counter.js"></script>
    <script src="/assets/js/marked.min.js"></script>
    <script src="/assets/js/comment-collapse.js?v=<?php echo ASSETS_VER; ?>"></script>
    <script src="/assets/js/comment-interactions.js?v=<?php echo ASSETS_VER; ?>"></script>

    <script>
    function commentProxyImageUrl(url) {
        var value = String(url || '').trim();
        if (!value) return '';
        if (/^(?:https?:)?\/\//i.test(value)) {
            if (value.indexOf('//') === 0) {
                value = window.location.protocol + value;
            }
            return '/image_proxy?url=' + encodeURIComponent(value);
        }
        return value;
    }

    function commentNormalizePlainImageUrls(text) {
        return String(text || '').replace(
            /(^|[\s>\(])((?:https?:)?\/\/[^\s<]+\.(?:jpg|jpeg|png|gif|webp|svg)(?:\?[^\s<]*)?(?:#[^\s<]*)?)(?=$|[\s<\)])/gi,
            function(match, prefix, url) {
                return prefix + '![](' + url + ')';
            }
        );
    }

    function commentPreserveExtraBlankLinesForPreview(text) {
        return String(text || '').replace(/\n{3,}/g, function(match) {
            var extraCount = match.length - 2;
            var result = '\n\n';
            for (var i = 0; i < extraCount; i++) {
                result += '@@MD_EXTRA_BLANK_LINE@@\n';
            }
            return result;
        });
    }

    function commentRestoreExtraBlankLinesPreviewHtml(html) {
        var out = String(html || '');
        out = out.replace(/<p>@@MD_EXTRA_BLANK_LINE@@<\/p>/g, '<p class="md-extra-blank-line" aria-hidden="true"><br></p>');
        out = out.replace(/@@MD_EXTRA_BLANK_LINE@@<br\s*\/?\s*>/g, '<br>');
        out = out.replace(/@@MD_EXTRA_BLANK_LINE@@/g, '<br>');
        return out;
    }

    // 配置 marked：链接和图片在新标签页打开
    (function() {
        if (typeof marked === 'undefined') return;
        var renderer = new marked.Renderer();
        renderer.link = function(token) {
            var href = token.href || '';
            var title = token.title || '';
            var text = token.text || href;
            var titleAttr = title ? ' title="' + escapeHtml(title) + '"' : '';
            return '<a href="' + escapeHtml(href) + '"' + titleAttr + ' target="_blank" rel="noopener noreferrer">' + text + '</a>';
        };
        renderer.image = function(token) {
            var href = token.href || '';
            var title = token.title || '';
            var text = token.text || '';
            var titleAttr = title ? ' title="' + escapeHtml(title) + '"' : '';
            var src = commentProxyImageUrl(href);
            return '<img src="' + escapeHtml(src) + '" alt="' + escapeHtml(text) + '"' + titleAttr + ' style="max-width:100%;">';
        };
        marked.setOptions({ breaks: true, renderer: renderer });
    })();

    // ========== 评论弹窗 Markdown 编辑器（独立实例） ==========
    var CommentEditor = (function() {
        var editor = null;
        var preview = null;
        var mentionState = createMentionState();
        var orderedListHandledAt = 0;

        function createMentionState() {
            return {
                popup: null,
                list: [],
                selectedIndex: 0,
                visible: false,
                activeRange: null,
                requestToken: 0,
                inputTimer: null
            };
        }

        function init() {
            editor = document.getElementById('commentTextarea');
            preview = document.getElementById('commentPreview');
            if (!editor) return;

            document.getElementById('commentToolbar').addEventListener('click', function(e) {
                var btn = e.target.closest('button[data-action]');
                if (!btn) return;
                handleAction(btn.getAttribute('data-action'));
                editor.focus();
            });

            editor.addEventListener('beforeinput', function(e) {
                if (!e || e.inputType !== 'insertLineBreak') return;
                if (handleOrderedListContinue()) {
                    orderedListHandledAt = Date.now();
                    e.preventDefault();
                }
            });

            editor.addEventListener('keydown', function(e) {
                if (mentionState.visible) {
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        moveMentionSelection(1);
                        return;
                    }
                    if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        moveMentionSelection(-1);
                        return;
                    }
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        chooseMentionSelection();
                        return;
                    }
                    if (e.key === 'Escape') {
                        e.preventDefault();
                        hideMentionAutocomplete();
                        return;
                    }
                }
                if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.altKey && !e.metaKey) {
                    if (Date.now() - orderedListHandledAt < 80) {
                        e.preventDefault();
                        return;
                    }
                    if (handleOrderedListContinue()) {
                        orderedListHandledAt = Date.now();
                        e.preventDefault();
                        return;
                    }
                }
                if (e.key === 'Tab') {
                    e.preventDefault();
                    insertAtCursor('    ');
                }
            });
            editor.addEventListener('input', function() {
                clearTimeout(mentionState.inputTimer);
                mentionState.inputTimer = setTimeout(syncMentionAutocomplete, 120);
            });
            editor.addEventListener('click', syncMentionAutocomplete);
            editor.addEventListener('keyup', function(e) {
                if (e.key === 'ArrowUp' || e.key === 'ArrowDown' || e.key === 'Enter' || e.key === 'Escape') return;
                syncMentionAutocomplete();
            });
            ensureMentionAutocomplete();
            document.addEventListener('click', function(e) {
                if (!mentionState.popup) return;
                if (e.target === editor || mentionState.popup.contains(e.target)) return;
                hideMentionAutocomplete();
            });
            window.addEventListener('resize', positionMentionAutocomplete);
            window.addEventListener('scroll', positionMentionAutocomplete, true);
        }

        function uploadCommentImage() {
            if (!editor) return;
            var picker = document.createElement('input');
            picker.type = 'file';
            picker.accept = 'image/jpeg,image/png,image/gif,image/webp';

            picker.addEventListener('change', function() {
                if (!picker.files || !picker.files.length) return;
                var file = picker.files[0];
                if (!file) return;

                var maxSize = 2 * 1024 * 1024;
                if (file.size > maxSize) {
                    alert('图片大小不能超过 2MB');
                    return;
                }

                var fd = new FormData();
                fd.append('action', 'upload_image');
                fd.append('image', file);

                var csrfTokenEl = document.querySelector('#view-counter-config[data-csrf]');
                var csrfToken = csrfTokenEl ? (csrfTokenEl.getAttribute('data-csrf') || '') : '';

                fetch('/api/comment.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: csrfToken ? { 'X-CSRF-Token': csrfToken } : {},
                    body: fd
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data || !data.success || !data.url) {
                        alert((data && data.message) ? data.message : '图片上传失败');
                        return;
                    }
                    insertAtCursor('![](' + data.url + ')');
                    editor.focus();
                })
                .catch(function() {
                    alert('网络错误，图片上传失败');
                });
            });

            picker.click();
        }

        function handleAction(action) {
            switch (action) {
                case 'bold': wrapSelection('**', '**', '粗体文本'); break;
                case 'italic': wrapSelection('*', '*', '斜体文本'); break;
                case 'strikethrough': wrapSelection('~~', '~~', '删除线文本'); break;
                case 'link':
                    var url = prompt('请输入链接地址:', 'https://');
                    if (!url) return;
                    var s = editor.selectionStart, e2 = editor.selectionEnd;
                    var sel = editor.value.substring(s, e2);
                    if (sel) {
                        var ins = '[' + sel + '](' + url + ')';
                        editor.value = editor.value.substring(0, s) + ins + editor.value.substring(e2);
                    } else {
                        editor.value = editor.value.substring(0, s) + url + editor.value.substring(e2);
                    }
                    break;
                case 'image':
                    uploadCommentImage();
                    break;
                case 'mention':
                    var mentionName = prompt('请输入要提及的站内用户名:', '');
                    if (mentionName === null) return;
                    mentionName = String(mentionName).trim().replace(/^@+/, '');
                    if (!mentionName) return;
                    insertAtCursor('@' + mentionName + ' ');
                    break;
                case 'code': wrapSelection('`', '`', '代码'); break;
                case 'codeblock':
                    var s2 = editor.selectionStart;
                    var pre = s2 > 0 && editor.value[s2-1] !== '\n' ? '\n' : '';
                    var ins2 = pre + '```\n代码内容\n```\n';
                    editor.value = editor.value.substring(0, s2) + ins2 + editor.value.substring(s2);
                    break;
                case 'ul': insertAtLineStart('- '); break;
                case 'ol': insertAtLineStart('1. '); break;
                case 'quote': insertAtLineStart('> '); break;
            }
        }

        function ensureMentionAutocomplete() {
            if (mentionState.popup || !editor) return;
            var popup = document.createElement('div');
            popup.className = 'md-mention-popup';
            popup.style.display = 'none';
            popup.innerHTML = '<div class="md-mention-list"></div>';
            document.body.appendChild(popup);
            popup.addEventListener('mousedown', function(e) {
                var item = e.target.closest('.md-mention-item');
                if (!item) return;
                e.preventDefault();
                mentionState.selectedIndex = parseInt(item.getAttribute('data-index') || '0', 10) || 0;
                chooseMentionSelection();
            });
            mentionState.popup = popup;
        }

        function syncMentionAutocomplete() {
            var info = getActiveMentionQuery();
            if (!info || !info.query) {
                hideMentionAutocomplete();
                return;
            }
            mentionState.activeRange = info;
            fetchMentionSuggestions(info.query);
        }

        function getActiveMentionQuery() {
            var cursor = editor.selectionStart;
            if (cursor !== editor.selectionEnd) return null;
            var beforeCursor = editor.value.slice(0, cursor);
            var match = beforeCursor.match(/(^|[^\u4e00-\u9fa5A-Za-z0-9_])@([\u4e00-\u9fa5A-Za-z0-9_]*)$/);
            if (!match) return null;
            return { query: match[2], start: cursor - match[2].length - 1, end: cursor };
        }

        function fetchMentionSuggestions(query) {
            var currentToken = ++mentionState.requestToken;
            fetch('/api/mention.php?q=' + encodeURIComponent(query), { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (currentToken !== mentionState.requestToken) return;
                    if (!data || !data.success || !Array.isArray(data.users) || !data.users.length) {
                        hideMentionAutocomplete();
                        return;
                    }
                    mentionState.list = data.users;
                    mentionState.selectedIndex = 0;
                    renderMentionAutocomplete();
                })
                .catch(function() {
                    if (currentToken !== mentionState.requestToken) return;
                    hideMentionAutocomplete();
                });
        }

        function renderMentionAutocomplete() {
            if (!mentionState.popup || !mentionState.list.length) {
                hideMentionAutocomplete();
                return;
            }
            mentionState.popup.querySelector('.md-mention-list').innerHTML = mentionState.list.map(function(user, index) {
                return '<button type="button" class="md-mention-item' + (index === mentionState.selectedIndex ? ' is-active' : '') + '" data-index="' + index + '">@' + escapeHtml(user.username) + '</button>';
            }).join('');
            positionMentionAutocomplete();
            mentionState.popup.style.display = 'block';
            mentionState.visible = true;
        }

        function positionMentionAutocomplete() {
            if (!mentionState.popup || !editor) return;
            var rect = editor.getBoundingClientRect();
            mentionState.popup.style.left = (window.scrollX + rect.left + 16) + 'px';
            mentionState.popup.style.top = (window.scrollY + rect.bottom - 12) + 'px';
            mentionState.popup.style.width = Math.min(rect.width - 32, 320) + 'px';
        }

        function moveMentionSelection(step) {
            if (!mentionState.visible || !mentionState.list.length) return;
            var total = mentionState.list.length;
            mentionState.selectedIndex = (mentionState.selectedIndex + step + total) % total;
            renderMentionAutocomplete();
        }

        function chooseMentionSelection() {
            if (!mentionState.visible || !mentionState.list.length || !mentionState.activeRange) return;
            var selected = mentionState.list[mentionState.selectedIndex];
            if (!selected) return;
            var replacement = '@' + selected.username + ' ';
            editor.value = editor.value.slice(0, mentionState.activeRange.start) + replacement + editor.value.slice(mentionState.activeRange.end);
            var newPos = mentionState.activeRange.start + replacement.length;
            editor.selectionStart = editor.selectionEnd = newPos;
            hideMentionAutocomplete();
            editor.focus();
        }

        function hideMentionAutocomplete() {
            mentionState.visible = false;
            mentionState.list = [];
            mentionState.activeRange = null;
            if (mentionState.popup) mentionState.popup.style.display = 'none';
        }

        function wrapSelection(before, after, defaultText) {
            var s = editor.selectionStart, e2 = editor.selectionEnd;
            var sel = editor.value.substring(s, e2);
            if (sel) {
                editor.value = editor.value.substring(0, s) + before + sel + after + editor.value.substring(e2);
                editor.selectionStart = s + before.length;
                editor.selectionEnd = e2 + before.length;
            } else {
                var ins = before + defaultText + after;
                editor.value = editor.value.substring(0, s) + ins + editor.value.substring(e2);
                editor.selectionStart = s + before.length;
                editor.selectionEnd = s + before.length + defaultText.length;
            }
        }

        function insertAtLineStart(prefix) {
            var s = editor.selectionStart;
            var lineStart = editor.value.lastIndexOf('\n', s - 1) + 1;
            editor.value = editor.value.substring(0, lineStart) + prefix + editor.value.substring(lineStart);
            editor.selectionStart = editor.selectionEnd = s + prefix.length;
        }

        function insertAtCursor(text) {
            var s = editor.selectionStart;
            editor.value = editor.value.substring(0, s) + text + editor.value.substring(s);
            editor.selectionStart = editor.selectionEnd = s + text.length;
        }

        function handleOrderedListContinue() {
            if (!editor) return false;
            var start = editor.selectionStart;
            var end = editor.selectionEnd;
            if (start !== end) return false;

            var text = editor.value;
            var lineStart = text.lastIndexOf('\n', start - 1) + 1;
            var lineEnd = text.indexOf('\n', start);
            if (lineEnd === -1) lineEnd = text.length;

            var line = text.substring(lineStart, lineEnd);
            var match = line.match(/^(\s*)(\d+)\.\s*(.*)$/);
            if (!match) return false;

            var indent = match[1] || '';
            var number = parseInt(match[2], 10);
            if (!isFinite(number)) return false;
            var content = match[3] || '';

            if (!content.trim()) {
                var removeUntil = lineEnd;
                if (removeUntil < text.length && text.charAt(removeUntil) === '\n') {
                    removeUntil += 1;
                }
                editor.value = text.substring(0, lineStart) + text.substring(removeUntil);
                editor.selectionStart = editor.selectionEnd = lineStart;
                return true;
            }

            var contentStartInLine = match[0].length - content.length;
            var cursorInContent = start - (lineStart + contentStartInLine);
            if (cursorInContent < 0) cursorInContent = 0;
            if (cursorInContent > content.length) cursorInContent = content.length;

            var beforeContent = content.slice(0, cursorInContent);
            var afterContent = content.slice(cursorInContent);

            var currentPrefix = indent + number + '. ';
            var nextPrefix = indent + (number + 1) + '. ';

            var newCurrentLine = currentPrefix + beforeContent;
            var newNextLine = nextPrefix + afterContent.replace(/^\s+/, '');

            var replacement = newCurrentLine + '\n' + newNextLine;
            editor.value = text.substring(0, lineStart) + replacement + text.substring(lineEnd);

            var newPos = lineStart + newCurrentLine.length + 1 + nextPrefix.length;
            editor.selectionStart = editor.selectionEnd = newPos;
            return true;
        }

        function getValue() { return editor ? editor.value : ''; }
        function setValue(value) { if (editor) editor.value = value || ''; if (preview) preview.innerHTML = ''; }
        function clear() { if (editor) editor.value = ''; if (preview) preview.innerHTML = ''; }

        function renderPreview() {
            if (!preview || typeof marked === 'undefined') return;
            var text = commentNormalizePlainImageUrls(editor.value);
            text = commentPreserveExtraBlankLinesForPreview(text);
            if (text) {
                var html = marked.parse(text);
                preview.innerHTML = '<div class="markdown-body">' + commentRestoreExtraBlankLinesPreviewHtml(html) + '</div>';
            } else {
                preview.innerHTML = '<div class="md-preview-empty">预览区域</div>';
            }
        }

        return { init: init, getValue: getValue, setValue: setValue, clear: clear, renderPreview: renderPreview };
    })();

    CommentEditor.init();

    function switchCommentEditorMode(mode) {
        var tabs = document.querySelectorAll('#commentModalOverlay .md-mode-tab');
        tabs.forEach(function(t) { t.classList.remove('active'); });
        tabs.forEach(function(t) { if (t.getAttribute('data-mode') === mode) t.classList.add('active'); });
        var body = document.getElementById('commentEditorBody');
        body.className = 'md-editor-body mode-' + mode;
        if (mode === 'preview') CommentEditor.renderPreview();
    }

    // ========== 回复与编辑功能 ==========
    window._replyParentId = 0;
    window._editingCommentId = 0;

    function setCommentModalMode(mode) {
        var title = document.getElementById('commentModalTitle');
        var submitBtn = document.getElementById('commentSubmitBtn');
        if (title) title.textContent = mode === 'edit' ? '编辑评论' : '发表评论';
        if (submitBtn) submitBtn.textContent = mode === 'edit' ? '保存修改' : '提交评论';
    }

    function replyToComment(commentId, username) {
        window._editingCommentId = 0;
        window._replyParentId = commentId;
        setCommentModalMode('create');
        CommentEditor.clear();
        var indicator = document.getElementById('replyIndicator');
        indicator.textContent = '回复 ' + username;
        indicator.style.display = 'inline';
        document.getElementById('cancelReplyBtn').style.display = '';
        openCommentModal();
    }

    function editComment(commentId) {
        var item = document.getElementById('comment-' + commentId);
        if (!item) return;
        window._replyParentId = 0;
        window._editingCommentId = commentId;
        setCommentModalMode('edit');
        CommentEditor.setValue(item.getAttribute('data-comment-content') || '');
        var indicator = document.getElementById('replyIndicator');
        indicator.style.display = 'none';
        indicator.textContent = '';
        document.getElementById('cancelReplyBtn').style.display = 'none';
        openCommentModal();
    }

    function cancelReply() {
        window._replyParentId = 0;
        var indicator = document.getElementById('replyIndicator');
        indicator.style.display = 'none';
        indicator.textContent = '';
        document.getElementById('cancelReplyBtn').style.display = 'none';
    }

    function openCommentModal() {
        if (!window._replyParentId && !window._editingCommentId) {
            setCommentModalMode('create');
            CommentEditor.clear();
            var indicator = document.getElementById('replyIndicator');
            indicator.style.display = 'none';
            indicator.textContent = '';
            document.getElementById('cancelReplyBtn').style.display = 'none';
        }
        document.getElementById('commentModalOverlay').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeCommentModal() {
        document.getElementById('commentModalOverlay').style.display = 'none';
        document.body.style.overflow = '';
        window._replyParentId = 0;
        window._editingCommentId = 0;
        setCommentModalMode('create');
        var indicator = document.getElementById('replyIndicator');
        indicator.style.display = 'none';
        indicator.textContent = '';
        document.getElementById('cancelReplyBtn').style.display = 'none';
    }



    function submitComment() {
        var rawContent = CommentEditor.getValue();
        if (!rawContent || !rawContent.trim()) {
            alert('请输入评论内容');
            return;
        }

        var content = rawContent;

        var btn = document.getElementById('commentSubmitBtn');
        btn.disabled = true;
        btn.textContent = '提交中...';

        if (window._editingCommentId) {
            fetch('/api/comment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'update',
                    id: window._editingCommentId,
                    content: content
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                btn.disabled = false;
                btn.textContent = '保存修改';
                if (!data.success) {
                    alert(handleApiError(data, '保存失败'));
                    return;
                }
                var item = document.getElementById('comment-' + window._editingCommentId);
                if (item) {
                    item.setAttribute('data-comment-content', data.comment.content || content);
                    var contentEl = item.querySelector('.comment-main-content');
                    if (contentEl) {
                        contentEl.innerHTML = data.comment.content_html || '';
                        if (window.initCommentCollapses) window.initCommentCollapses(item);
                    }
                }
                CommentEditor.clear();
                closeCommentModal();
            })
            .catch(function() {
                btn.disabled = false;
                btn.textContent = '保存修改';
                alert('网络错误，请稍后重试');
            });
            return;
        }

        fetch('/api/comment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'create',
                content_type: 'error',
                content_id: <?php echo $id; ?>,
                content: content,
                parent_id: window._replyParentId || null
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.textContent = '提交评论';
            if (!data.success) {
                alert(data.message || '提交失败');
                return;
            }
            window._replyParentId = 0;
            CommentEditor.clear();
            closeCommentModal();
            var redirectUrl = data.redirect_url || (window.location.pathname + window.location.search + (data.comment && data.comment.id ? ('#comment-' + data.comment.id) : ''));
            window.location.assign(redirectUrl);
        })
        .catch(function() {
            btn.disabled = false;
            btn.textContent = '提交评论';
            alert('网络错误，请稍后重试');
        });
    }

    function deleteComment(commentId) {
        if (!confirm('确定删除此评论？')) return;

        fetch('/api/comment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', id: commentId })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                var el = document.getElementById('comment-' + commentId);
                if (el) {
                    el.style.opacity = '0';
                    el.style.transition = 'opacity 0.3s';
                    setTimeout(function() { el.remove(); }, 300);
                }
                var countEl = document.getElementById('commentCount');
                var newCount = Math.max(0, parseInt(countEl.textContent, 10) - 1);
                countEl.textContent = newCount;
                if (newCount === 0) {
                    document.getElementById('commentList').innerHTML = '<div class="comment-empty" id="commentEmpty">暂无评论，来发表第一条评论吧</div>';
                }
            } else {
                alert(data.message || '删除失败');
            }
        })
        .catch(function() { alert('网络错误'); });
    }

    if (window.GalCommentInteractions && typeof window.GalCommentInteractions.init === 'function') {
        window.GalCommentInteractions.init({
            onReply: replyToComment,
            onEdit: editComment,
            onDelete: deleteComment
        });
    }

    function escapeHtml(str) {
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }
    </script>
    <?php renderAdminFooterScripts(); ?>
</body>
</html>
<?php
$pageHtml = ob_get_clean();
view('admin/error_detail.twig', ['page_html' => $pageHtml]);

