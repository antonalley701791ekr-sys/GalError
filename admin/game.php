<?php
require_once 'includes/user_auth.php';
require_once 'includes/image_utils.php';
require_once 'includes/sanitizer.php';
require_once 'includes/view.php';

$pdo = getDB();
$gameId = intval($_GET['id'] ?? 0);

if (!$gameId) {
    header('Location: /');
    exit;
}

// 获取游戏信息（仅已审核通过）
$stmt = $pdo->prepare("SELECT g.*, u.username as submitter_name FROM games g LEFT JOIN users u ON g.user_id = u.id WHERE g.id = ? AND g.status = 'approved'");
$stmt->execute([$gameId]);
$game = $stmt->fetch();

if (!$game) {
    header('Location: /');
    exit;
}

// 获取游戏浏览量
$gameViewCounts = getViewCount('game', $gameId);

// 获取该游戏的已审核修改记录
$stmt = $pdo->prepare("
    SELECT r.*, u.username as submitter_name
    FROM game_revisions r
    LEFT JOIN users u ON r.user_id = u.id
    WHERE r.game_id = ? AND r.status = 'approved'
    ORDER BY r.created_at ASC
");
$stmt->execute([$gameId]);
$gameRevisions = $stmt->fetchAll();

// 获取该游戏的所有已审核报错
$stmt = $pdo->prepare("
    SELECT e.*, c.name as category_name 
    FROM errors e 
    JOIN error_categories c ON e.category_id = c.id 
    WHERE e.game_id = ? AND e.status = 'approved' 
    ORDER BY e.created_at DESC
");
$stmt->execute([$gameId]);
$errors = $stmt->fetchAll();

// 批量获取报错浏览量
$errorIds = array_column($errors, 'id');
$errorViewCounts = getViewCountsBatch('error', $errorIds);

// 批量获取报错评论数
$errorCommentCounts = [];
if (!empty($errorIds)) {
    $placeholders = implode(',', array_fill(0, count($errorIds), '?'));
    $stmt = $pdo->prepare("SELECT content_id, COUNT(*) as cnt FROM comments WHERE content_type = 'error' AND status = 'active' AND content_id IN ($placeholders) GROUP BY content_id");
    $stmt->execute($errorIds);
    foreach ($stmt->fetchAll() as $row) {
        $errorCommentCounts[$row['content_id']] = (int)$row['cnt'];
    }
}
ob_start();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo hs($game['title']); ?> - <?php echo h(SITE_NAME); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER; ?>">
    <link rel="stylesheet" href="/assets/css/user.css?v=<?php echo ASSETS_VER; ?>">
    <?php renderSiteHead(); ?>
</head>
<body class="has-fixed-nav">
    <?php include 'includes/header.php'; ?>

    <?php renderAnnouncement(); ?>

    <!-- 主要内容 -->
    <main class="main">
        <div class="container">
            <!-- 顶部导航 -->
            <div class="mb-20" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <a href="/" class="btn btn-secondary btn-sm">返回首页</a>
                    <?php if (isUserLoggedIn()): ?>
                        <a href="/submit_game?edit_id=<?php echo intval($game['id']); ?>" class="btn btn-secondary btn-sm">编辑游戏信息</a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 游戏信息 -->
            <div class="card">
                <div class="card-header">游戏信息</div>
                <div class="card-body">
                    <div class="game-detail-layout">
                        <?php $coverUrl = getCoverUrl($game); ?>
                        <?php if ($coverUrl): ?>
                            <button type="button" class="game-detail-cover js-image-viewer-trigger" data-viewer-src="<?php echo h($coverUrl); ?>" data-viewer-alt="<?php echo hs($game['title']); ?>" aria-label="查看封面大图">
                                <img src="<?php echo h($coverUrl); ?>" alt="<?php echo hs($game['title']); ?>" class="game-detail-cover-img js-adaptive-cover" loading="lazy">
                            </button>
                        <?php endif; ?>
                        <div class="game-detail-info">
                            <h2 class="game-detail-title"><?php echo hs($game['title']); ?></h2>
                            <?php if ($game['title_jp']): ?>
                                <p class="game-detail-meta"><strong>日文名：</strong><?php echo hs($game['title_jp']); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($game['romaji'])): ?>
                                <p class="game-detail-meta"><strong>罗马音：</strong><?php echo hs($game['romaji']); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($game['aliases'])): ?>
                                <p class="game-detail-meta"><strong>别名：</strong><?php echo hs($game['aliases']); ?></p>
                            <?php endif; ?>
                            <?php if ($game['developer']): ?>
                                <p class="game-detail-meta"><strong>开发商：</strong><?php echo h($game['developer']); ?></p>
                            <?php endif; ?>
                            <?php if ($game['release_date']): ?>
                                <p class="game-detail-meta"><strong>发售日：</strong><?php echo h($game['release_date']); ?></p>
                            <?php endif; ?>
                            <?php if ($game['platforms']): ?>
                                <p class="game-detail-meta"><strong>平台：</strong><?php echo h($game['platforms']); ?></p>
                            <?php endif; ?>
                            <?php if ($game['vndb_id']): ?>
                                <p class="game-detail-meta"><strong>VNDB：</strong><a href="https://vndb.org/<?php echo h($game['vndb_id']); ?>" target="_blank"><?php echo h($game['vndb_id']); ?></a></p>
                            <?php endif; ?>
                            <p class="game-detail-meta"><strong>收录时间：</strong><?php echo date('Y-m-d', strtotime($game['created_at'])); ?></p>
                            <?php if (!empty($game['submitter_name'])): ?>
                                <p class="game-detail-meta"><strong>提交者：</strong><a href="/profile?user_id=<?php echo intval($game['user_id']); ?>" class="detail-author-link"><?php echo h($game['submitter_name']); ?></a></p>
                            <?php endif; ?>
                            <p class="game-detail-meta view-count-detail" title="浏览量">
                                <svg class="view-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                登录浏览：<span id="view-count-user"><?php echo $gameViewCounts['user_views']; ?></span> | 访客浏览：<span id="view-count-guest"><?php echo $gameViewCounts['guest_views']; ?></span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($gameRevisions)): ?>
            <div class="card section-gap">
                <div class="card-header">游戏信息修改记录</div>
                <div class="card-body">
                    <div class="revision-history">
                        <?php foreach ($gameRevisions as $rev): ?>
                            <div class="revision-item">
                                <div class="revision-meta">
                                    <span>修改者：<?php echo h($rev['submitter_name'] ?? '匿名用户'); ?></span>
                                    <span>时间：<?php echo date('Y-m-d H:i', strtotime($rev['created_at'])); ?></span>
                                </div>
                                <?php
                                $oldD = json_decode($rev['old_data'], true) ?: [];
                                $newD = json_decode($rev['new_data'], true) ?: [];
                                $fieldLabels = [
                                    'vndb_id' => 'VNDB',
                                    'title' => '游戏标题',
                                    'title_jp' => '日文名',
                                    'romaji' => '罗马音',
                                    'aliases' => '别名',
                                    'developer' => '开发商',
                                    'release_date' => '发售日',
                                    'platforms' => '平台',
                                ];
                                foreach ($fieldLabels as $field => $label):
                                    $oldVal = (string)($oldD[$field] ?? '');
                                    $newVal = (string)($newD[$field] ?? '');
                                    if ($oldVal !== $newVal):
                                ?>
                                <div class="revision-diff">
                                    <strong><?php echo h($label); ?>：</strong>
                                    <?php if ($oldVal === '' && $newVal !== ''): ?>
                                        <div class="diff-added"><?php echo nl2br(h($newVal)); ?></div>
                                    <?php elseif ($oldVal !== '' && $newVal === ''): ?>
                                        <div class="diff-removed"><?php echo nl2br(h($oldVal)); ?></div>
                                    <?php else: ?>
                                        <div class="diff-removed"><?php echo nl2br(h($oldVal)); ?></div>
                                        <div class="diff-added"><?php echo nl2br(h($newVal)); ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php
                                    endif;
                                endforeach;
                                $oldCover = (string)($oldD['cover_image'] ?? '');
                                $newCover = (string)($newD['cover_image'] ?? '');
                                if ($oldCover !== $newCover):
                                    $oldCoverUrl = getCoverUrl(['cover_image' => $oldCover, 'vndb_cover_url' => $oldD['vndb_cover_url'] ?? ''], true);
                                    $newCoverUrl = getCoverUrl(['cover_image' => $newCover, 'vndb_cover_url' => $newD['vndb_cover_url'] ?? ''], true);
                                ?>
                                <div class="revision-diff">
                                    <strong>封面变化：</strong>
                                    <?php if ($oldCoverUrl): ?>
                                        <div class="diff-removed-imgs"><span>删除：</span><img src="<?php echo h($oldCoverUrl); ?>" alt="旧封面" style="width:80px;height:60px;object-fit:contain;border-radius:4px;opacity:0.65;border:2px solid #e74c3c;background:var(--bg-tertiary);"></div>
                                    <?php endif; ?>
                                    <?php if ($newCoverUrl): ?>
                                        <div class="diff-added-imgs"><span>新增：</span><img src="<?php echo h($newCoverUrl); ?>" alt="新封面" style="width:80px;height:60px;object-fit:contain;border-radius:4px;border:2px solid #27ae60;background:var(--bg-tertiary);"></div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- 报错解决方案（简略卡片） -->
            <div class="section-gap">
                <h2 class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    报错解决方案
                    <?php if (count($errors) > 5): ?>
                        <span style="font-size:0.85rem;font-weight:400;color:var(--text-muted);">共 <?php echo count($errors); ?> 条</span>
                    <?php endif; ?>
                </h2>
                
                <?php if (!empty($errors)): ?>
                    <div class="card-body error-brief-list">
                        <?php foreach (array_slice($errors, 0, 5) as $error): ?>
                            <a class="error-brief-card" href="<?php echo urlError($error['id']); ?>">
                                <div class="error-brief-main">
                                    <h3 class="error-brief-title"><?php echo h($error['title']); ?></h3>
                                    <p class="error-brief-summary">
                                        <?php if (!empty(trim($error['solution_text'] ?? ''))): ?>
                                            <?php echo h(mb_substr($error['solution_text'], 0, 30) . (mb_strlen($error['solution_text']) > 30 ? '...' : '')); ?>
                                        <?php else: ?>
                                            <span class="text-muted">暂无解决方案</span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <div class="error-brief-meta">
                                    <span class="article-tag-sm"><?php echo h($error['category_name']); ?></span>
                                    <span><?php echo date('Y-m-d', strtotime($error['created_at'])); ?></span>
                                    <span class="view-count-inline" title="浏览量">
                                        <svg class="view-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <?php echo $errorViewCounts[$error['id']] ?? 0; ?>
                                    </span>
                                    <span class="comment-count-inline" title="评论数">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                        <?php echo $errorCommentCounts[$error['id']] ?? 0; ?>
                                    </span>
                                </div>
                            </a>
                        <?php endforeach; ?>

                        <?php if (count($errors) > 5): ?>
                            <div class="error-brief-more" id="error-show-more-wrap">
                                <button class="btn btn-secondary" onclick="document.querySelectorAll('.error-brief-hidden').forEach(function(el){el.style.display='';});document.getElementById('error-show-more-wrap').style.display='none';">
                                    查看更多（剩余 <?php echo count($errors) - 5; ?> 条）
                                </button>
                            </div>
                            <?php foreach (array_slice($errors, 5) as $error): ?>
                                <a class="error-brief-card error-brief-hidden" href="<?php echo urlError($error['id']); ?>" style="display:none;">
                                    <div class="error-brief-main">
                                        <h3 class="error-brief-title"><?php echo h($error['title']); ?></h3>
                                        <p class="error-brief-summary">
                                            <?php if (!empty(trim($error['solution']))): ?>
                                                <?php echo h(mb_substr($error['solution'], 0, 30) . (mb_strlen($error['solution']) > 30 ? '...' : '')); ?>
                                            <?php else: ?>
                                                <span class="text-muted">暂无解决方案</span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div class="error-brief-meta">
                                        <span class="article-tag-sm"><?php echo h($error['category_name']); ?></span>
                                        <span><?php echo date('Y-m-d', strtotime($error['created_at'])); ?></span>
                                        <span class="view-count-inline" title="浏览量">
                                            <svg class="view-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            <?php echo $errorViewCounts[$error['id']] ?? 0; ?>
                                        </span>
                                        <span class="comment-count-inline" title="评论数">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                            <?php echo $errorCommentCounts[$error['id']] ?? 0; ?>
                                        </span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="card-body text-center empty-state">
                        <p>该游戏暂无报错解决方案</p>
                        <p>
                            <a href="/submit?game_id=<?php echo $gameId; ?>" class="btn">提交第一个报错解决方案</a>
                        </p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- 操作按钮 -->
            <div class="section-gap text-center">
                <a href="/submit?game_id=<?php echo $gameId; ?>" class="btn">为该游戏提交报错</a>
                <a href="/" class="btn btn-secondary" style="margin-left: 10px;">返回首页</a>
            </div>
        </div>
    </main>

    <?php include 'includes/footer.php'; ?>
    <div id="view-counter-config" data-type="game" data-id="<?php echo $gameId; ?>" data-csrf="<?php echo h(csrf_token('default')); ?>" style="display:none;"></div>
    <script src="/assets/js/view-counter.js"></script>
    <script src="/assets/js/adaptive-cover.js?v=<?php echo ASSETS_VER; ?>"></script>
</body>
</html>
<?php
$pageHtml = ob_get_clean();
view('admin/game.twig', ['page_html' => $pageHtml]);
