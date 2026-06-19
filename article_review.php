<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/markdown.php';

checkLogin();
requirePermission('articles', 'view');

$pdo = getDB();
$message = '';
$messageType = '';

$action = $_POST['action'] ?? ($_GET['action'] ?? '');
$status = $_GET['status'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'], $_GET['id'])) {
    $redirectId = intval($_GET['id']);
    if ($_GET['action'] === 'view' && $redirectId > 0) {
        header('Location: /article.php?id=' . $redirectId . '&from_admin=1');
        exit;
    }
    if ($_GET['action'] === 'edit' && $redirectId > 0) {
        header('Location: /submit_article.php?edit=' . $redirectId . '&from_admin=1');
        exit;
    }
}

// ========== 处理文章审核 ==========

// 审核通过文章
if ($action === 'approve' && isset($_GET['id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission('articles', 'edit');
    $id = intval($_GET['id']);
    $stmt = $pdo->prepare("UPDATE articles SET status = 'approved' WHERE id = ?");
    if ($stmt->execute([$id])) {
        $stmt2 = $pdo->prepare("SELECT user_id, title FROM articles WHERE id = ?");
        $stmt2->execute([$id]);
        $art = $stmt2->fetch();
        if ($art) {
            $msgTitle = '文章审核通过';
            $msgContent = '您提交的文章《' . $art['title'] . '》已通过审核，现已在前台展示。';
            $stmt3 = $pdo->prepare("INSERT INTO messages (user_id, title, content) VALUES (?, ?, ?)");
            $stmt3->execute([$art['user_id'], $msgTitle, $msgContent]);
        }
        $message = '文章已通过审核';
        $messageType = 'success';
    } else {
        $message = '操作失败';
        $messageType = 'error';
    }
}

// 驳回文章
if ($action === 'reject' && isset($_GET['id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission('articles', 'edit');
    $id = intval($_GET['id']);
    $reason = trim($_POST['reject_reason'] ?? '');
    if (empty($reason)) {
        $message = '请填写驳回原因';
        $messageType = 'error';
    } else {
        $stmt = $pdo->prepare("UPDATE articles SET status = 'rejected', reject_reason = ? WHERE id = ?");
        if ($stmt->execute([$reason, $id])) {
            $stmt2 = $pdo->prepare("SELECT user_id, title FROM articles WHERE id = ?");
            $stmt2->execute([$id]);
            $art = $stmt2->fetch();
            if ($art) {
                $msgTitle = '文章审核未通过';
                $msgContent = '您提交的文章《' . $art['title'] . '》未通过审核。驳回原因：' . $reason;
                $stmt3 = $pdo->prepare("INSERT INTO messages (user_id, title, content) VALUES (?, ?, ?)");
                $stmt3->execute([$art['user_id'], $msgTitle, $msgContent]);
            }
            $message = '文章已驳回';
            $messageType = 'success';
        } else {
            $message = '操作失败';
            $messageType = 'error';
        }
    }
}

// 删除文章
if ($action === 'delete' && isset($_GET['id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission('articles', 'delete');
    $id = intval($_GET['id']);
    // 同时删除相关修订记录
    $pdo->prepare("DELETE FROM article_revisions WHERE article_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM articles WHERE id = ?")->execute([$id]);
    $message = '文章已删除';
    $messageType = 'success';
    $action = '';
}

// ========== 处理修订审核 ==========

// 审核通过修订
if ($action === 'approve_revision' && isset($_GET['rev_id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission('articles', 'edit');
    $revId = intval($_GET['rev_id']);
    $stmt = $pdo->prepare("SELECT * FROM article_revisions WHERE id = ? AND status = 'pending'");
    $stmt->execute([$revId]);
    $revision = $stmt->fetch();

    if ($revision) {
        // 应用修改到文章
        $stmt = $pdo->prepare("UPDATE articles SET title = ?, content = ?, tags = ?, toc_config = ?, has_pending_revision = 0, updated_at = NOW() WHERE id = ?");
        $stmt->execute([
            $revision['new_title'],
            $revision['new_content'],
            $revision['new_tags'],
            $revision['new_toc_config'],
            $revision['article_id'],
        ]);

        // 标记修订为已通过
        $pdo->prepare("UPDATE article_revisions SET status = 'approved' WHERE id = ?")->execute([$revId]);

        // 发送站内信
        $stmt2 = $pdo->prepare("SELECT user_id FROM articles WHERE id = ?");
        $stmt2->execute([$revision['article_id']]);
        $art = $stmt2->fetch();
        if ($art) {
            $msgTitle = '文章修改审核通过';
            $msgContent = '您对文章《' . $revision['new_title'] . '》的修改已通过审核，新版本已在前台展示。';
            $pdo->prepare("INSERT INTO messages (user_id, title, content) VALUES (?, ?, ?)")->execute([$art['user_id'], $msgTitle, $msgContent]);
        }

        $message = '修改已审核通过并应用到文章';
        $messageType = 'success';
    } else {
        $message = '修订记录不存在或已处理';
        $messageType = 'error';
    }
    $action = 'revisions';
}

// 驳回修订
if ($action === 'reject_revision' && isset($_GET['rev_id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission('articles', 'edit');
    $revId = intval($_GET['rev_id']);
    $reason = trim($_POST['reject_reason'] ?? '');

    if (empty($reason)) {
        $message = '请填写驳回原因';
        $messageType = 'error';
        $action = 'revisions';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM article_revisions WHERE id = ? AND status = 'pending'");
        $stmt->execute([$revId]);
        $revision = $stmt->fetch();

        if ($revision) {
            $pdo->prepare("UPDATE article_revisions SET status = 'rejected', reject_reason = ? WHERE id = ?")->execute([$reason, $revId]);
            $pdo->prepare("UPDATE articles SET has_pending_revision = 0 WHERE id = ?")->execute([$revision['article_id']]);

            // 发送站内信
            $stmt2 = $pdo->prepare("SELECT user_id FROM articles WHERE id = ?");
            $stmt2->execute([$revision['article_id']]);
            $art = $stmt2->fetch();
            if ($art) {
                $msgTitle = '文章修改审核未通过';
                $msgContent = '您对文章《' . $revision['old_title'] . '》的修改未通过审核。驳回原因：' . $reason;
                $pdo->prepare("INSERT INTO messages (user_id, title, content) VALUES (?, ?, ?)")->execute([$art['user_id'], $msgTitle, $msgContent]);
            }

            $message = '修订已驳回';
            $messageType = 'success';
        } else {
            $message = '修订记录不存在或已处理';
            $messageType = 'error';
        }
        $action = 'revisions';
    }
}

// 查看单篇文章详情
if ($action === 'view' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $pdo->prepare("SELECT a.*, u.username FROM articles a JOIN users u ON a.user_id = u.id WHERE a.id = ?");
    $stmt->execute([$id]);
    $viewArticle = $stmt->fetch();

    // 获取该文章的修订记录
    if ($viewArticle) {
        $stmt = $pdo->prepare("SELECT ar.*, u.username as rev_username FROM article_revisions ar LEFT JOIN users u ON ar.user_id = u.id WHERE ar.article_id = ? ORDER BY ar.created_at DESC");
        $stmt->execute([$id]);
        $articleRevisions = $stmt->fetchAll();
    }
}

// 查看修订详情（diff 对比）
$viewRevision = null;
if ($action === 'view_revision' && isset($_GET['rev_id'])) {
    $revId = intval($_GET['rev_id']);
    $stmt = $pdo->prepare("SELECT ar.*, u.username as rev_username, a.title as current_title FROM article_revisions ar LEFT JOIN users u ON ar.user_id = u.id LEFT JOIN articles a ON ar.article_id = a.id WHERE ar.id = ?");
    $stmt->execute([$revId]);
    $viewRevision = $stmt->fetch();
}

// 统计数据
$articleStats = [
    'total'    => $pdo->query("SELECT COUNT(*) FROM articles")->fetchColumn(),
    'pending'  => $pdo->query("SELECT COUNT(*) FROM articles WHERE status='pending'")->fetchColumn(),
    'approved' => $pdo->query("SELECT COUNT(*) FROM articles WHERE status='approved'")->fetchColumn(),
    'rejected' => $pdo->query("SELECT COUNT(*) FROM articles WHERE status='rejected'")->fetchColumn(),
];
$pendingRevisions = $pdo->query("SELECT COUNT(*) FROM article_revisions WHERE status='pending'")->fetchColumn();

// 获取列表
if ($action === 'revisions') {
    // 修订记录列表
    $revStatus = $_GET['rev_status'] ?? '';
    $revWhere = '';
    $revParams = [];
    if ($revStatus && in_array($revStatus, ['pending', 'approved', 'rejected'])) {
        $revWhere = 'WHERE ar.status = ?';
        $revParams = [$revStatus];
    }

    $revCountStmt = $pdo->prepare("SELECT COUNT(*) as total FROM article_revisions ar $revWhere");
    $revCountStmt->execute($revParams);
    $revTotal = $revCountStmt->fetch()['total'];
    $revPagination = paginate($revTotal, $page, $perPage);

    $revStmt = $pdo->prepare("SELECT ar.*, u.username as rev_username, a.title as article_title FROM article_revisions ar LEFT JOIN users u ON ar.user_id = u.id LEFT JOIN articles a ON ar.article_id = a.id $revWhere ORDER BY ar.created_at DESC LIMIT {$revPagination['offset']}, {$perPage}");
    $revStmt->execute($revParams);
    $revisionsList = $revStmt->fetchAll();
} else {
    // 文章列表
    $validStatus = ['pending', 'approved', 'rejected'];
    $listWhere = '';
    $listParams = [];
    if ($status && in_array($status, $validStatus)) {
        $listWhere = 'WHERE a.status = ?';
        $listParams = [$status];
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM articles a $listWhere");
    $countStmt->execute($listParams);
    $total = $countStmt->fetch()['total'];
    $pagination = paginate($total, $page, $perPage);

    $stmt = $pdo->prepare("SELECT a.*, u.username FROM articles a JOIN users u ON a.user_id = u.id $listWhere ORDER BY a.created_at DESC LIMIT {$pagination['offset']}, {$perPage}");
    $stmt->execute($listParams);
    $articles = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>文章管理 - <?php echo h(SITE_NAME); ?> 管理后台</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER . '-' . @filemtime(BASE_PATH . '/assets/css/style.css'); ?>">
    <link rel="stylesheet" href="/assets/css/markdown-editor.css">
    <?php renderAdminHeadScript(); ?>
</head>
<body class="has-fixed-nav">
    <?php include '../includes/header.php'; ?>
    <div class="admin-layout">
        <?php renderAdminSidebar('article_review.php'); ?>

        <div class="admin-content">
            <main class="admin-main">

                <?php if ($action === 'view_revision' && $viewRevision): ?>
                    <!-- ========== 修订 Diff 对比视图 ========== -->
                    <div class="admin-page-header">
                        <h1>查看文章修改</h1>
                        <a href="article_review.php?action=revisions" class="btn btn-secondary">返回修订列表</a>
                    </div>

                    <?php if ($message): ?>
                        <div class="admin-alert-<?php echo $messageType; ?>"><?php echo h($message); ?></div>
                    <?php endif; ?>

                    <div class="card" style="margin-bottom:20px;">
                        <div class="card-header">修订信息</div>
                        <div class="card-body">
                            <div style="display:flex;gap:20px;flex-wrap:wrap;font-size:0.88rem;color:var(--text-muted);margin-bottom:16px;">
                                <span>修改者：<strong style="color:var(--text-primary);"><?php echo h($viewRevision['rev_username'] ?? '未知'); ?></strong></span>
                                <span>提交时间：<?php echo date('Y-m-d H:i', strtotime($viewRevision['created_at'])); ?></span>
                                <span>状态：<span class="status status-<?php echo $viewRevision['status']; ?>"><?php echo $viewRevision['status'] === 'pending' ? '待审核' : ($viewRevision['status'] === 'approved' ? '已通过' : '已驳回'); ?></span></span>
                            </div>

                            <?php if ($viewRevision['reject_reason']): ?>
                                <div class="info-block info-block-danger" style="margin-bottom:16px;">
                                    <strong>驳回原因：</strong><?php echo h($viewRevision['reject_reason']); ?>
                                </div>
                            <?php endif; ?>

                            <!-- 标题对比 -->
                            <?php if ($viewRevision['old_title'] !== $viewRevision['new_title']): ?>
                            <div style="margin-bottom:12px;">
                                <strong style="color:var(--accent-purple);">标题变更：</strong>
                                <div class="diff-removed"><?php echo h($viewRevision['old_title']); ?></div>
                                <div class="diff-added"><?php echo h($viewRevision['new_title']); ?></div>
                            </div>
                            <?php endif; ?>

                            <!-- 标签对比 -->
                            <?php if ($viewRevision['old_tags'] !== $viewRevision['new_tags']): ?>
                            <div style="margin-bottom:12px;">
                                <strong style="color:var(--accent-purple);">标签变更：</strong>
                                <div class="diff-removed"><?php echo h($viewRevision['old_tags']); ?></div>
                                <div class="diff-added"><?php echo h($viewRevision['new_tags']); ?></div>
                            </div>
                            <?php endif; ?>

                            <!-- TOC 对比 -->
                            <?php
                            $oldToc = json_decode($viewRevision['old_toc_config'] ?? '', true) ?: [];
                            $newToc = json_decode($viewRevision['new_toc_config'] ?? '', true) ?: [];
                            $tocChanged = json_encode($oldToc) !== json_encode($newToc);
                            if ($tocChanged && (!empty($oldToc) || !empty($newToc))):
                            ?>
                            <div style="margin-bottom:12px;">
                                <strong style="color:var(--accent-purple);">目录设置变更：</strong>
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:8px;">
                                    <div>
                                        <div style="font-size:0.82rem;color:#e74c3c;margin-bottom:4px;font-weight:600;">旧目录</div>
                                        <ul class="toc-diff-list">
                                            <?php foreach ($oldToc as $item): ?>
                                                <li class="<?php echo empty($item['visible']) ? 'toc-diff-hidden' : ''; ?>">
                                                    H<?php echo intval($item['level']); ?> - <?php echo h($item['text']); ?>
                                                    <?php echo empty($item['visible']) ? '(隐藏)' : ''; ?>
                                                </li>
                                            <?php endforeach; ?>
                                            <?php if (empty($oldToc)): ?><li style="color:var(--text-muted);">无</li><?php endif; ?>
                                        </ul>
                                    </div>
                                    <div>
                                        <div style="font-size:0.82rem;color:#27ae60;margin-bottom:4px;font-weight:600;">新目录</div>
                                        <ul class="toc-diff-list">
                                            <?php foreach ($newToc as $item): ?>
                                                <li class="<?php echo empty($item['visible']) ? 'toc-diff-hidden' : ''; ?>">
                                                    H<?php echo intval($item['level']); ?> - <?php echo h($item['text']); ?>
                                                    <?php echo empty($item['visible']) ? '(隐藏)' : ''; ?>
                                                </li>
                                            <?php endforeach; ?>
                                            <?php if (empty($newToc)): ?><li style="color:var(--text-muted);">无</li><?php endif; ?>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- 内容对比（左右双栏） -->
                            <?php if ($viewRevision['old_content'] !== $viewRevision['new_content']): ?>
                            <div style="margin-bottom:12px;">
                                <strong style="color:var(--accent-purple);">内容变更：</strong>
                            </div>
                            <div class="article-diff-container">
                                <div class="article-diff-panel diff-panel-old">
                                    <div class="diff-panel-header">旧版本</div>
                                    <div class="markdown-body">
                                        <?php echo md_to_html($viewRevision['old_content']); ?>
                                    </div>
                                </div>
                                <div class="article-diff-panel diff-panel-new">
                                    <div class="diff-panel-header">新版本</div>
                                    <div class="markdown-body">
                                        <?php echo md_to_html($viewRevision['new_content']); ?>
                                    </div>
                                </div>
                            </div>
                            <?php else: ?>
                            <p class="text-muted">文章内容无变化</p>
                            <?php endif; ?>

                            <!-- 审核操作 -->
                            <?php if ($viewRevision['status'] === 'pending'): ?>
                            <div style="margin-top:20px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start;">
                                <form method="post" action="article_review.php?rev_id=<?php echo $viewRevision['id']; ?>" style="display:inline;">
                                    <?php echo csrf_input('admin_form'); ?>
                                    <input type="hidden" name="action" value="approve_revision">
                                    <button type="submit" class="btn btn-success<?php echo pd('articles','edit'); ?>"<?php echo pdBtnAttr('articles','edit'); ?> onclick="<?php echo hasPermission('articles','edit') ? "return confirm('确定通过此修改？修改将直接应用到文章。');" : 'return false;'; ?>">通过修改</button>
                                </form>
                                <button type="button" class="btn btn-danger<?php echo pd('articles','edit'); ?>"<?php echo pdBtnAttr('articles','edit'); ?> onclick="<?php echo hasPermission('articles','edit') ? "openRejectModal('article_review.php?action=reject_revision&rev_id=" . $viewRevision['id'] . "')" : ''; ?>">驳回修改</button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php elseif ($action === 'view' && isset($viewArticle) && $viewArticle): ?>
                    <!-- ========== 查看文章详情 ========== -->
                    <div class="admin-page-header">
                        <h1>查看文章</h1>
                        <a href="article_review.php?status=<?php echo h($viewArticle['status']); ?>" class="btn btn-secondary">返回列表</a>
                    </div>

                    <?php if ($message): ?>
                        <div class="admin-alert-<?php echo $messageType; ?>"><?php echo h($message); ?></div>
                    <?php endif; ?>

                    <div class="card">
                        <div class="card-header"><?php echo h($viewArticle['title']); ?></div>
                        <div class="card-body">
                            <div style="margin-bottom:16px;display:flex;gap:20px;flex-wrap:wrap;font-size:0.88rem;color:var(--text-muted);">
                                <span>作者：<strong style="color:var(--text-primary);"><?php echo h($viewArticle['username']); ?></strong></span>
                                <span>提交时间：<?php echo date('Y-m-d H:i', strtotime($viewArticle['created_at'])); ?></span>
                                <span>状态：<span class="status status-<?php echo $viewArticle['status']; ?>"><?php echo $viewArticle['status'] === 'pending' ? '待审核' : ($viewArticle['status'] === 'approved' ? '已通过' : '已驳回'); ?></span></span>
                                <?php if (!empty($viewArticle['has_pending_revision'])): ?>
                                    <span class="status status-pending">有待审核修改</span>
                                <?php endif; ?>
                            </div>

                            <?php if ($viewArticle['tags']): ?>
                                <div style="margin-bottom:16px;">
                                    <?php foreach (explode(',', $viewArticle['tags']) as $tag): ?>
                                        <span class="status status-enabled" style="margin-right:6px;"><?php echo h(trim($tag)); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($viewArticle['reject_reason']): ?>
                                <div class="info-block info-block-danger" style="margin-bottom:16px;">
                                    <strong>驳回原因：</strong><?php echo h($viewArticle['reject_reason']); ?>
                                </div>
                            <?php endif; ?>

                            <div class="markdown-body" style="border:1px solid var(--glass-border);border-radius:var(--radius-sm);padding:20px;background:var(--glass-bg);">
                                <?php echo md_to_html($viewArticle['content']); ?>
                            </div>

                            <?php if ($viewArticle['status'] === 'pending'): ?>
                                <div style="margin-top:20px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start;">
                                    <form method="post" action="article_review.php?id=<?php echo $viewArticle['id']; ?>" style="display:inline;">
                                        <?php echo csrf_input('admin_form'); ?>
                                        <input type="hidden" name="action" value="approve">
                                        <button type="submit" class="btn btn-success<?php echo pd('articles','edit'); ?>"<?php echo pdBtnAttr('articles','edit'); ?> onclick="<?php echo hasPermission('articles','edit') ? "return confirm('确认通过该文章？');" : 'return false;'; ?>">通过</button>
                                    </form>
                                    <button type="button" class="btn btn-danger<?php echo pd('articles','edit'); ?>"<?php echo pdBtnAttr('articles','edit'); ?> onclick="<?php echo hasPermission('articles','edit') ? "openRejectModal('article_review.php?action=reject&id=" . $viewArticle['id'] . "')" : ''; ?>">驳回</button>
                                </div>
                            <?php endif; ?>

                            <div style="margin-top:<?php echo $viewArticle['status'] === 'pending' ? '12px' : '20px'; ?>;">
                                <form method="post" action="?id=<?php echo $viewArticle['id']; ?>" style="display:inline;">
                                    <?php echo csrf_input('admin_form'); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <button type="submit" class="btn btn-danger<?php echo pd('articles','delete'); ?>"<?php echo pdBtnAttr('articles','delete'); ?> onclick="<?php echo hasPermission('articles','delete') ? "return confirm('确定要删除这篇文章吗？');" : 'return false;'; ?>">删除</button>
                                </form>
                            </div>

                            <!-- 该文章的修订记录 -->
                            <?php if (!empty($articleRevisions)): ?>
                            <div style="margin-top:24px;border-top:1px solid var(--glass-border);padding-top:20px;">
                                <h3 style="font-size:1rem;margin-bottom:12px;">修订记录 (<?php echo count($articleRevisions); ?>)</h3>
                                <?php foreach ($articleRevisions as $rev): ?>
                                <div style="border:1px solid var(--glass-border);border-radius:var(--radius-sm);padding:12px;margin-bottom:10px;background:var(--glass-bg);">
                                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                                        <div style="font-size:0.85rem;color:var(--text-muted);">
                                            <strong style="color:var(--text-primary);"><?php echo h($rev['rev_username'] ?? '未知'); ?></strong>
                                            · <?php echo date('Y-m-d H:i', strtotime($rev['created_at'])); ?>
                                            · <span class="status status-<?php echo $rev['status']; ?>"><?php echo $rev['status'] === 'pending' ? '待审核' : ($rev['status'] === 'approved' ? '已通过' : '已驳回'); ?></span>
                                        </div>
                                        <a href="?action=view_revision&rev_id=<?php echo $rev['id']; ?>" class="btn btn-sm">查看详情</a>
                                    </div>
                                    <?php if ($rev['reject_reason']): ?>
                                        <div style="font-size:0.82rem;color:#e74c3c;margin-top:6px;">驳回原因：<?php echo h($rev['reject_reason']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php elseif ($action === 'revisions'): ?>
                    <!-- ========== 修订记录列表 ========== -->
                    <div class="admin-page-header">
                        <h1>文章修改记录</h1>
                        <a href="article_review.php" class="btn btn-secondary">返回文章列表</a>
                    </div>

                    <?php if ($message): ?>
                        <div class="admin-alert-<?php echo $messageType; ?>"><?php echo h($message); ?></div>
                    <?php endif; ?>

                    <!-- 筛选 -->
                    <div class="btn-group" style="margin-bottom:20px;">
                        <?php $revStatus = $_GET['rev_status'] ?? ''; ?>
                        <a href="?action=revisions" class="btn <?php echo !$revStatus ? 'btn-success' : 'btn-secondary'; ?>">全部</a>
                        <a href="?action=revisions&rev_status=pending" class="btn <?php echo $revStatus === 'pending' ? 'btn-success' : 'btn-secondary'; ?>">
                            待审核
                            <?php if ($pendingRevisions > 0): ?>
                                <span style="background:#e74c3c;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;margin-left:4px;"><?php echo $pendingRevisions; ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="?action=revisions&rev_status=approved" class="btn <?php echo $revStatus === 'approved' ? 'btn-success' : 'btn-secondary'; ?>">已通过</a>
                        <a href="?action=revisions&rev_status=rejected" class="btn <?php echo $revStatus === 'rejected' ? 'btn-success' : 'btn-secondary'; ?>">已驳回</a>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            修改记录列表
                            <span class="text-muted" style="float:right;font-weight:normal;">共 <?php echo $revTotal; ?> 条记录</span>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($revisionsList)): ?>
                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>文章</th>
                                                <th>修改者</th>
                                                <th>状态</th>
                                                <th>提交时间</th>
                                                <th>操作</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($revisionsList as $rev): ?>
                                                <tr>
                                                    <td><?php echo $rev['id']; ?></td>
                                                    <td style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                                        <?php echo h(mb_substr($rev['article_title'] ?? $rev['new_title'], 0, 40)); ?>
                                                    </td>
                                                    <td><?php echo h($rev['rev_username'] ?? '未知'); ?></td>
                                                    <td>
                                                        <span class="status status-<?php echo $rev['status']; ?>">
                                                            <?php echo $rev['status'] === 'pending' ? '待审核' : ($rev['status'] === 'approved' ? '已通过' : '已驳回'); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo date('Y-m-d H:i', strtotime($rev['created_at'])); ?></td>
                                                    <td>
                                                        <div class="action-btns">
                                                            <a href="?action=view_revision&rev_id=<?php echo $rev['id']; ?>" class="btn btn-sm">查看</a>
                                                            <?php if ($rev['status'] === 'pending'): ?>
                                                                <form method="post" action="?rev_id=<?php echo $rev['id']; ?>" style="display:inline;">
                                                                    <?php echo csrf_input('admin_form'); ?>
                                                                    <input type="hidden" name="action" value="approve_revision">
                                                                    <button type="submit" class="btn btn-success btn-sm<?php echo pd('articles','edit'); ?>"<?php echo pdBtnAttr('articles','edit'); ?> onclick="<?php echo hasPermission('articles','edit') ? "return confirm('确定通过此修改？');" : 'return false;'; ?>">通过</button>
                                                                </form>
                                                                <button type="button" class="btn btn-danger btn-sm<?php echo pd('articles','edit'); ?>"<?php echo pdBtnAttr('articles','edit'); ?> onclick="<?php echo hasPermission('articles','edit') ? "openRejectModal('article_review.php?action=reject_revision&rev_id=" . $rev['id'] . "')" : ''; ?>">驳回</button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- 分页 -->
                                <?php if ($revPagination['totalPages'] > 1): ?>
                                    <div class="pagination" style="margin-top:20px;">
                                        <?php $revStatusParam = $revStatus ? '&rev_status=' . h($revStatus) : ''; ?>
                                        <?php if ($revPagination['hasPrev']): ?>
                                            <a href="?action=revisions<?php echo $revStatusParam; ?>&page=<?php echo $revPagination['page'] - 1; ?>">上一页</a>
                                        <?php endif; ?>
                                        <?php for ($i = max(1, $revPagination['page'] - 2); $i <= min($revPagination['totalPages'], $revPagination['page'] + 2); $i++): ?>
                                            <?php if ($i == $revPagination['page']): ?>
                                                <span class="current"><?php echo $i; ?></span>
                                            <?php else: ?>
                                                <a href="?action=revisions<?php echo $revStatusParam; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                        <?php if ($revPagination['hasNext']): ?>
                                            <a href="?action=revisions<?php echo $revStatusParam; ?>&page=<?php echo $revPagination['page'] + 1; ?>">下一页</a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="text-center" style="padding:40px;">
                                    <span class="text-muted">暂无修改记录</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- ========== 文章列表 ========== -->
                    <div class="admin-page-header">
                        <h1>文章管理</h1>
                    </div>

                    <?php if ($message): ?>
                        <div class="admin-alert-<?php echo $messageType; ?>"><?php echo h($message); ?></div>
                    <?php endif; ?>

                    <!-- 统计卡片 -->
                    <div class="admin-stats-grid">
                        <div class="card stat-card"><div class="card-body">
                            <h3 class="stat-number stat-number--cyan"><?php echo $articleStats['total']; ?></h3>
                            <p class="stat-label">总数</p>
                        </div></div>
                        <div class="card stat-card"><div class="card-body">
                            <h3 class="stat-number stat-number--yellow"><?php echo $articleStats['pending']; ?></h3>
                            <p class="stat-label">待审核</p>
                        </div></div>
                        <div class="card stat-card"><div class="card-body">
                            <h3 class="stat-number stat-number--green"><?php echo $articleStats['approved']; ?></h3>
                            <p class="stat-label">已通过</p>
                        </div></div>
                        <div class="card stat-card"><div class="card-body">
                            <h3 class="stat-number stat-number--red"><?php echo $articleStats['rejected']; ?></h3>
                            <p class="stat-label">已驳回</p>
                        </div></div>
                        <div class="card stat-card"><div class="card-body">
                            <h3 class="stat-number stat-number--yellow"><?php echo $pendingRevisions; ?></h3>
                            <p class="stat-label">待审核修改</p>
                        </div></div>
                    </div>

                    <!-- 筛选标签 -->
                    <div class="btn-group" style="margin-bottom:20px;">
                        <a href="article_review.php" class="btn <?php echo !$status && $action !== 'revisions' ? 'btn-success' : 'btn-secondary'; ?>">全部</a>
                        <a href="?status=pending" class="btn <?php echo $status === 'pending' ? 'btn-success' : 'btn-secondary'; ?>">
                            待审核
                            <?php if ($articleStats['pending'] > 0): ?>
                                <span style="background:#e74c3c;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;margin-left:4px;"><?php echo $articleStats['pending']; ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="?status=approved" class="btn <?php echo $status === 'approved' ? 'btn-success' : 'btn-secondary'; ?>">已通过</a>
                        <a href="?status=rejected" class="btn <?php echo $status === 'rejected' ? 'btn-success' : 'btn-secondary'; ?>">已驳回</a>
                        <a href="?action=revisions" class="btn <?php echo $action === 'revisions' ? 'btn-success' : 'btn-secondary'; ?>">
                            文章修改
                            <?php if ($pendingRevisions > 0): ?>
                                <span style="background:#e74c3c;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;margin-left:4px;"><?php echo $pendingRevisions; ?></span>
                            <?php endif; ?>
                        </a>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            文章列表
                            <span class="text-muted" style="float:right;font-weight:normal;">共 <?php echo $total; ?> 条记录</span>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($articles)): ?>
                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>标题</th>
                                                <th>作者</th>
                                                <th>标签</th>
                                                <th>状态</th>
                                                <th>提交时间</th>
                                                <th>操作</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($articles as $art): ?>
                                                <tr>
                                                    <td><?php echo $art['id']; ?></td>
                                                    <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                                        <a href="/article.php?id=<?php echo (int)$art['id']; ?>&from_admin=1" style="color:inherit;text-decoration:none;">
                                                            <?php echo h(mb_substr($art['title'], 0, 40)); ?>
                                                        </a>
                                                        <?php if (!empty($art['has_pending_revision'])): ?>
                                                            <span class="status status-pending" style="font-size:0.65rem;margin-left:4px;">有修改</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo h($art['username']); ?></td>
                                                    <td>
                                                        <?php
                                                        $artTags = array_filter(array_map('trim', explode(',', $art['tags'])));
                                                        foreach (array_slice($artTags, 0, 3) as $t): ?>
                                                            <span class="status status-enabled" style="font-size:0.7rem;"><?php echo h($t); ?></span>
                                                        <?php endforeach; ?>
                                                    </td>
                                                    <td>
                                                        <span class="status status-<?php echo $art['status']; ?>">
                                                            <?php echo $art['status'] === 'pending' ? '待审核' : ($art['status'] === 'approved' ? '已通过' : '已驳回'); ?>
                                                        </span>
                                                        <?php if ($art['status'] === 'rejected' && !empty($art['reject_reason'])): ?>
                                                            <div class="info-block info-block-danger" style="margin-top:8px;font-size:12px;line-height:1.5;max-width:260px;white-space:normal;">
                                                                <strong>驳回原因：</strong><?php echo h($art['reject_reason']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo date('Y-m-d H:i', strtotime($art['created_at'])); ?></td>
                                                    <td>
                                                        <div class="action-btns">
                                                            <a href="/article.php?id=<?php echo (int)$art['id']; ?>&from_admin=1" class="btn btn-sm">查看</a>
                                                            <a href="/submit_article.php?edit=<?php echo $art['id']; ?>&from_admin=1" class="btn btn-secondary btn-sm<?php echo pd('articles','edit'); ?>"<?php echo pdAttr('articles','edit'); ?>>编辑</a>
                                                            <?php if ($art['status'] === 'pending'): ?>
                                                                <form method="post" action="?id=<?php echo $art['id']; ?>" style="display:inline;">
                                                                    <?php echo csrf_input('admin_form'); ?>
                                                                    <input type="hidden" name="action" value="approve">
                                                                    <button type="submit" class="btn btn-success btn-sm<?php echo pd('articles','edit'); ?>"<?php echo pdBtnAttr('articles','edit'); ?> onclick="<?php echo hasPermission('articles','edit') ? "return confirm('确认通过？');" : 'return false;'; ?>">通过</button>
                                                                </form>
                                                                <button type="button" class="btn btn-danger btn-sm<?php echo pd('articles','edit'); ?>"<?php echo pdBtnAttr('articles','edit'); ?> onclick="<?php echo hasPermission('articles','edit') ? "openRejectModal('article_review.php?action=reject&id=" . $art['id'] . "')" : ''; ?>">驳回</button>
                                                            <?php endif; ?>
                                                            <form method="post" action="?id=<?php echo $art['id']; ?>" style="display:inline;">
                                                                <?php echo csrf_input('admin_form'); ?>
                                                                <input type="hidden" name="action" value="delete">
                                                                <button type="submit" class="btn btn-danger btn-sm<?php echo pd('articles','delete'); ?>"<?php echo pdBtnAttr('articles','delete'); ?> onclick="<?php echo hasPermission('articles','delete') ? "return confirm('确定删除？');" : 'return false;'; ?>">删除</button>
                                                            </form>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- 分页 -->
                                <?php if ($pagination['totalPages'] > 1): ?>
                                    <div class="pagination" style="margin-top:20px;">
                                        <?php if ($pagination['hasPrev']): ?>
                                            <a href="?<?php echo $status ? 'status=' . h($status) . '&' : ''; ?>page=<?php echo $pagination['page'] - 1; ?>">上一页</a>
                                        <?php endif; ?>
                                        <?php for ($i = max(1, $pagination['page'] - 2); $i <= min($pagination['totalPages'], $pagination['page'] + 2); $i++): ?>
                                            <?php if ($i == $pagination['page']): ?>
                                                <span class="current"><?php echo $i; ?></span>
                                            <?php else: ?>
                                                <a href="?<?php echo $status ? 'status=' . h($status) . '&' : ''; ?>page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                        <?php if ($pagination['hasNext']): ?>
                                            <a href="?<?php echo $status ? 'status=' . h($status) . '&' : ''; ?>page=<?php echo $pagination['page'] + 1; ?>">下一页</a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                            <?php else: ?>
                                <div class="text-center" style="padding:40px;">
                                    <span class="text-muted">暂无数据</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
    <?php renderAdminFooterScripts(); ?>

    <!-- 拒绝理由模态框 -->
    <div id="rejectModal" class="reject-modal-overlay" style="display:none;">
        <div class="reject-modal">
            <div class="reject-modal-header">
                <h3>填写驳回原因</h3>
                <button type="button" class="reject-modal-close" onclick="closeRejectModal()">&times;</button>
            </div>
            <form id="rejectForm" method="post" action="">
                <?php echo csrf_input('admin_form'); ?>
                <div class="reject-modal-body">
                    <label style="display:block;margin-bottom:8px;font-weight:600;color:var(--text-primary);">驳回原因 <span style="color:#e74c3c;">*</span></label>
                    <div class="md-editor-wrap">
                        <div class="md-mode-tabs">
                            <button type="button" class="md-mode-tab active" data-mode="edit" onclick="switchRejectEditorMode('edit')">编写</button>
                            <button type="button" class="md-mode-tab" data-mode="preview" onclick="switchRejectEditorMode('preview')">预览</button>
                        </div>
                        <div class="md-editor-toolbar" id="rejectToolbar">
                            <button type="button" data-action="bold" title="加粗"><b>B</b></button>
                            <button type="button" data-action="italic" title="斜体"><i>I</i></button>
                            <button type="button" data-action="strikethrough" title="删除线"><s>S</s></button>
                            <span class="md-toolbar-separator"></span>
                            <button type="button" data-action="ul" title="无序列表">&#8226;</button>
                            <button type="button" data-action="ol" title="有序列表">1.</button>
                            <button type="button" data-action="quote" title="引用">&gt;</button>
                        </div>
                        <div class="md-editor-body mode-edit" id="rejectEditorBody">
                            <textarea name="reject_reason" id="rejectReasonInput" class="md-editor-textarea" placeholder="请输入驳回原因" required style="min-height:200px;"></textarea>
                            <div id="rejectReasonPreview" class="md-editor-preview-pane markdown-body"></div>
                        </div>
                    </div>
                    <p id="rejectReasonError" style="color:#e74c3c;font-size:0.82rem;margin-top:6px;display:none;">请填写驳回原因</p>
                </div>
                <div class="reject-modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeRejectModal()">取消</button>
                    <button type="submit" class="btn btn-danger">确认驳回</button>
                </div>
            </form>
        </div>
    </div>
    <style>
    .reject-modal-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(4px);}
    .reject-modal{background:var(--card-bg,#1a1a2e);border:1px solid var(--glass-border,rgba(255,255,255,0.1));border-radius:12px;width:90%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,0.3);}
    .reject-modal-header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--glass-border,rgba(255,255,255,0.1));}
    .reject-modal-header h3{margin:0;font-size:1rem;color:var(--text-primary,#fff);}
    .reject-modal-close{background:none;border:none;font-size:1.5rem;color:var(--text-muted,#999);cursor:pointer;padding:0;line-height:1;}
    .reject-modal-close:hover{color:var(--text-primary,#fff);}
    .reject-modal-body{padding:20px;}
    .reject-modal-footer{display:flex;justify-content:flex-end;gap:10px;padding:16px 20px;border-top:1px solid var(--glass-border,rgba(255,255,255,0.1));}
    </style>
    <script src="/assets/js/marked.min.js"></script>
    <script src="/assets/js/reject-reason-editor.js?v=<?php echo ASSETS_VER; ?>"></script>
</body>
</html>

