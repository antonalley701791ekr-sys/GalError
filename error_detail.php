<?php
require_once 'includes/user_auth.php';
require_once 'includes/auth.php';
require_once 'includes/sanitizer.php';
require_once 'includes/markdown.php';
require_once 'includes/view.php';

$fromAdmin = isset($_GET['from_admin']) && $_GET['from_admin'] === '1';
if ($fromAdmin) {
    checkLogin();
    requirePermission('errors', 'view');
}

$pdo = getDB();
$id = intval($_GET['id'] ?? 0);

if (!$id) {
    header('Location: ' . ($fromAdmin ? '/admin/errors.php' : '/'));
    exit;
}

if ($fromAdmin) {
    $stmt = $pdo->prepare("
        SELECT e.id, e.game_id, e.category_id, e.user_id, e.title, e.phenomenon, e.engine_info, e.system_category, e.system_info,
               e.android_cpu, e.android_model, e.android_version, e.patch_info, e.screenshots,
               e.user_ip, e.status, e.reject_reason, e.created_at, e.updated_at,
               g.title as game_title, g.id as game_id, c.name as category_name, u.username as submitter_name,
               es.user_id AS solution_user_id, su.username AS solution_author_name,
               es.solution AS solution_text,
               es.solution_screenshots AS solution_screenshots,
               es.created_at AS solution_created_at
        FROM errors e
        JOIN games g ON e.game_id = g.id
        JOIN error_categories c ON e.category_id = c.id
        LEFT JOIN users u ON e.user_id = u.id
        LEFT JOIN error_solutions es ON es.error_id = e.id AND es.is_primary = 1 AND es.status = 'approved'
        LEFT JOIN users su ON su.id = es.user_id
        WHERE e.id = ?
    ");
} else {
    $stmt = $pdo->prepare("
        SELECT e.id, e.game_id, e.category_id, e.user_id, e.title, e.phenomenon, e.engine_info, e.system_category, e.system_info,
               e.android_cpu, e.android_model, e.android_version, e.patch_info, e.screenshots,
               e.user_ip, e.status, e.reject_reason, e.created_at, e.updated_at,
               g.title as game_title, g.id as game_id, c.name as category_name, u.username as submitter_name,
               es.user_id AS solution_user_id, su.username AS solution_author_name,
               es.solution AS solution_text,
               es.solution_screenshots AS solution_screenshots,
               es.created_at AS solution_created_at
        FROM errors e
        JOIN games g ON e.game_id = g.id
        JOIN error_categories c ON e.category_id = c.id
        LEFT JOIN users u ON e.user_id = u.id
        LEFT JOIN error_solutions es ON es.error_id = e.id AND es.is_primary = 1 AND es.status = 'approved'
        LEFT JOIN users su ON su.id = es.user_id
        WHERE e.id = ? AND e.status = 'approved'
    ");
}
$stmt->execute([$id]);
$error = $stmt->fetch();

if (!$error) {
    header('Location: ' . ($fromAdmin ? '/admin/errors.php' : '/'));
    exit;
}

$viewCounts = getViewCount('error', $id);

$stmt = $pdo->prepare("
    SELECT r.*, u.username as submitter_name
    FROM error_revisions r
    LEFT JOIN users u ON r.user_id = u.id
    WHERE r.error_id = ? AND r.status = 'approved'
    ORDER BY r.created_at ASC
");
$stmt->execute([$id]);
$revisions = $stmt->fetchAll();
$revisionBySolutionId = [];
foreach ($revisions as $rev) {
    $solutionIdKey = (int)($rev['solution_id'] ?? 0);
    if ($solutionIdKey > 0) {
        $revisionBySolutionId[$solutionIdKey] = $rev;
    }
}

$stmt = $pdo->prepare("
    SELECT e.id, e.title, e.created_at, c.name as category_name,
           u.username AS solution_author_name, es.created_at AS solution_created_at,
           es.solution AS solution_text
    FROM errors e
    JOIN error_categories c ON e.category_id = c.id
    LEFT JOIN error_solutions es ON es.error_id = e.id AND es.is_primary = 1 AND es.status = 'approved'
    LEFT JOIN users u ON u.id = es.user_id
    WHERE e.game_id = ? AND e.id != ? AND e.status = 'approved'
    ORDER BY e.created_at DESC
    LIMIT 5
");
$stmt->execute([$error['game_id'], $id]);
$relatedErrors = $stmt->fetchAll();
$relatedViews = getViewCountsBatch('error', array_column($relatedErrors, 'id'));
$solutionSubmitUrl = '/solution_submit.php?error_id=' . $id;
$hasPrimarySolution = !empty(trim((string)($error['solution_text'] ?? '')));

$solutionsStmt = $pdo->prepare("\n    SELECT es.id, es.error_id, es.user_id, es.solution, es.solution_screenshots, es.status, es.is_primary, es.created_at, es.updated_at, u.username AS solution_author_name\n    FROM error_solutions es\n    LEFT JOIN users u ON u.id = es.user_id\n    WHERE es.error_id = ?\n      AND (es.status = 'approved' OR ? = 1 OR es.user_id = ?)\n    ORDER BY es.is_primary DESC, es.created_at ASC, es.id ASC\n");
$solutionsStmt->execute([$id, $fromAdmin ? 1 : 0, (int)getCurrentUserId()]);
$errorSolutions = $solutionsStmt->fetchAll();
$solutionIndexMap = [];
foreach ($errorSolutions as $idx => $solutionRow) {
    if (!empty($solutionRow['id'])) {
        $solutionIndexMap[(int)$solutionRow['id']] = $idx + 1;
    }
}
$solutionNumberLabels = ['一','二','三','四','五','六','七','八','九','十','十一','十二','十三','十四','十五','十六','十七','十八','十九','二十'];
$categoryNameById = [];
$categoryStmt = $pdo->query("SELECT id, name FROM error_categories");
foreach ($categoryStmt->fetchAll() as $categoryRow) {
    $categoryNameById[(string)$categoryRow['id']] = (string)$categoryRow['name'];
}

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

$systemCategoryLabels = [
    'windows' => 'Windows',
    'android_emulator' => '安卓模拟器',
    'console_handheld' => '主机掌机',
    'mobile_native' => '手机原生',
    'win_handheld' => 'Win掌机',
    'cloud_streaming' => '云/串流',
    'other' => '其他',
];

function renderTextWithAutoLinks($text) {
    $text = (string)$text;
    if ($text === '') return '';
    $pattern = '~(https?://[^\s<]+|www\.[^\s<]+)~iu';
    $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $html = '';
    foreach ($parts as $idx => $part) {
        if ($idx % 2 === 0) {
            $html .= h($part);
            continue;
        }
        $urlPart = $part;
        $trail = '';
        while ($urlPart !== '' && preg_match('/[\.,，。!！\?？;；:：\)）\]\}]+$/u', $urlPart)) {
            $trail = mb_substr($urlPart, -1, 1, 'UTF-8') . $trail;
            $urlPart = mb_substr($urlPart, 0, mb_strlen($urlPart, 'UTF-8') - 1, 'UTF-8');
        }
        if ($urlPart === '') {
            $html .= h($part);
            continue;
        }
        $href = preg_match('~^https?://~i', $urlPart) ? $urlPart : ('https://' . $urlPart);
        $html .= '<a href="' . h($href) . '" target="_blank" rel="noopener noreferrer nofollow">' . h($urlPart) . '</a>' . h($trail);
    }
    return nl2br($html);
}

$inlineStyleHtml = renderTwig('front/partials/error_detail_styles.twig', []);

ob_start(); renderSiteHead(); $siteHeadHtml = ob_get_clean();
ob_start(); include __DIR__ . '/includes/header.php'; $headerHtml = ob_get_clean();
if (trim((string)$headerHtml) === '') {
    $headerHtml = renderTwig('partials/header.twig', [
        'csrf_token_json' => json_encode(csrf_token('default'), JSON_UNESCAPED_UNICODE),
        'api_code_message_map_json' => json_encode([
            'method_not_allowed' => '请求方式不正确，请刷新页面后重试',
            'bad_origin' => '请求来源异常，请在本站页面内重试',
            'csrf_failed' => '请求已过期，请刷新页面后重试',
            'unauthorized' => '请先登录后再操作',
            'forbidden' => '您没有权限执行此操作',
            'invalid_params' => '提交参数有误，请检查后重试',
            'invalid_id' => '目标内容不存在或已失效',
            'invalid_fingerprint' => '请求标识无效，请重试',
            'rate_limited' => '操作过于频繁，请稍后再试',
            'db_error' => '服务器繁忙，请稍后重试',
            'unknown_action' => '未知操作请求，请刷新后重试',
            'captcha_rate_limited' => '验证码请求过于频繁，请稍后再试',
            'captcha_verify_failed' => '验证码校验失败，请重新验证',
        ], JSON_UNESCAPED_UNICODE),
        'site_logo' => getSiteSetting('site_logo', ''),
        'site_name' => getSiteSetting('site_name', SITE_NAME),
        'submit_article_url' => isUserLoggedIn() ? '/submit_article' : '/login?redirect=' . urlencode('/submit_article'),
        'submit_discussion_url' => isUserLoggedIn() ? '/submit_discussion' : '/login?redirect=' . urlencode('/submit_discussion'),
        'is_logged_in' => isUserLoggedIn(),
        'has_unread' => false,
        'unread_msg_count' => 0,
        'unread_pm_count' => 0,
        'avatar_url' => isUserLoggedIn() ? getUserAvatarUrl() : '',
        'user_initial' => isUserLoggedIn() ? getUserInitial() : '',
        'username' => isUserLoggedIn() ? (string)($_SESSION['user_username'] ?? '') : '',
        'user_role' => isUserLoggedIn() ? (string)($_SESSION['user_role'] ?? '') : '',
        'role_label' => isUserLoggedIn() ? getRoleLabel((string)($_SESSION['user_role'] ?? '')) : '',
        'is_admin' => isAdmin(),
        'site_footer_scripts_html' => '',
        'footer_text' => getSiteSetting('footer_text', ''),
        'assets_ver' => ASSETS_VER,
        'user_card_js_ver' => ASSETS_VER . '-' . (@filemtime(BASE_PATH . 'assets/js/user-card.js') ?: time()),
    ]);
}
ob_start(); include __DIR__ . '/includes/footer.php'; $footerHtml = ob_get_clean();
$adminSidebarHtml = '';
$adminFooterScriptsHtml = '';
if ($fromAdmin) {
    ob_start(); renderAdminSidebar('errors.php'); $adminSidebarHtml = ob_get_clean();
    ob_start(); renderAdminFooterScripts(); $adminFooterScriptsHtml = ob_get_clean();
}

ob_start();
?>
<div class="article-detail-content">
    <?php if ($fromAdmin && $error['status'] === 'rejected' && !empty(trim((string)$error['reject_reason']))): ?>
    <div class="error-detail-section" style="margin-top:16px;"><h3>拒绝理由</h3><p style="color:#ef4444;"><?php echo nl2br(h($error['reject_reason'])); ?></p></div>
    <?php endif; ?>
    <?php if ($error['phenomenon']): ?>
    <div class="error-detail-section">
        <h3>问题描述</h3>
        <div class="error-detail-text"><?php echo renderTextWithAutoLinks($error['phenomenon']); ?></div>
        <?php if ($error['screenshots']): ?>
        <div class="error-subsection">
            <h4>报错截图</h4>
            <div class="screenshot-list">
                <?php foreach (explode(',', $error['screenshots']) as $screenshot): if (trim($screenshot)): ?>
                    <img src="<?php echo h(UPLOAD_URL . trim($screenshot)); ?>" alt="报错截图" class="screenshot-thumb js-image-viewer-trigger" data-viewer-src="<?php echo h(UPLOAD_URL . trim($screenshot)); ?>" data-viewer-alt="报错截图">
                <?php endif; endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if (!empty($error['system_category']) || $error['system_info']): ?><div class="error-detail-section"><h3>系统分类</h3><p><?php echo (!empty($error['system_category']) && isset($systemCategoryLabels[$error['system_category']])) ? h($systemCategoryLabels[$error['system_category']]) : '<span class="text-muted">未标注</span>'; ?></p></div><?php endif; ?>
    <?php if (($error['system_category'] ?? '') === 'android_emulator' && (!empty($error['android_cpu']) || !empty($error['android_model']) || !empty($error['android_version']))): ?>
    <div class="error-detail-section"><h3>安卓模拟器设备信息</h3><p><?php if (!empty($error['android_cpu'])): ?><strong>手机处理器：</strong><?php echo h($error['android_cpu']); ?><br><?php endif; ?><?php if (!empty($error['android_model'])): ?><strong>手机机型：</strong><?php echo h($error['android_model']); ?><br><?php endif; ?><?php if (!empty($error['android_version'])): ?><strong>安卓版本：</strong><?php echo h($error['android_version']); ?><?php endif; ?></p></div>
    <?php endif; ?>
    <?php if ($error['system_info']): ?><div class="error-detail-section"><h3>系统信息</h3><p><?php echo nl2br(h($error['system_info'])); ?></p></div><?php endif; ?>
    <?php if ($error['patch_info']): ?><div class="error-detail-section"><h3>汉化补丁</h3><p><?php echo nl2br(h($error['patch_info'])); ?></p></div><?php endif; ?>
    <div class="error-detail-section">
        <h3>解决方案</h3>
        <?php if (!empty($errorSolutions)): ?>
            <div class="text-muted" style="margin: 2px 0 12px;">共 <?php echo count($errorSolutions); ?> 条解决方案，按发布时间排序。</div>
            <?php foreach ($errorSolutions as $idx => $solutionRow): ?>
                <div class="error-subsection" style="margin-top: 14px;">
                    <h4 style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
                        <span>方案 <?php echo !empty($solutionRow['solution_no']) ? ($solutionNumberLabels[(int)$solutionRow['solution_no'] - 1] ?? (string)$solutionRow['solution_no']) : ($solutionNumberLabels[$idx] ?? (string)($idx + 1)); ?></span>
                        <a class="btn btn-secondary btn-sm" href="/solution_submit.php?error_id=<?php echo (int)$error['id']; ?>&solution_id=<?php echo (int)$solutionRow['id']; ?>">编辑此方案</a>
                    </h4>
                    <?php if (!empty($solutionRow['solution_author_name'])): ?>
                        <div class="text-muted" style="margin:0 0 8px;">提交者：<a class="js-user-card" data-user-id="<?php echo (int)($solutionRow['user_id'] ?? 0); ?>" data-username="<?php echo h($solutionRow['solution_author_name']); ?>" href="/profile?user_id=<?php echo (int)($solutionRow['user_id'] ?? 0); ?>"><?php echo h($solutionRow['solution_author_name']); ?></a><?php if (!empty($solutionRow['created_at'])): ?> · 提交于 <?php echo date('Y-m-d H:i', strtotime($solutionRow['created_at'])); ?><?php endif; ?></div>
                    <?php endif; ?>
                    <?php $solutionRevision = $revisionBySolutionId[(int)$solutionRow['id']] ?? null; if ($solutionRevision): ?>
                        <div class="text-muted" style="margin:0 0 8px;">最新修改者：<a class="js-user-card" data-user-id="<?php echo (int)($solutionRevision['user_id'] ?? 0); ?>" data-username="<?php echo h($solutionRevision['submitter_name'] ?? '匿名用户'); ?>" href="/profile?user_id=<?php echo (int)($solutionRevision['user_id'] ?? 0); ?>"><?php echo h($solutionRevision['submitter_name'] ?? '匿名用户'); ?></a><?php if (!empty($solutionRevision['created_at'])): ?> · 最新修改时间：<?php echo h(date('Y-m-d H:i', strtotime($solutionRevision['created_at']))); ?><?php endif; ?></div>
                    <?php endif; ?>
                    <div class="error-detail-text"><?php echo renderTextWithAutoLinks($solutionRow['solution'] ?? ''); ?></div>
                    <?php
                        $solutionShotList = array_values(array_filter(array_map('trim', explode(',', (string)($solutionRow['solution_screenshots'] ?? '')))));
                        if (!empty($solutionShotList)):
                    ?>
                        <div class="error-subsection" style="margin-top:12px;">
                            <h4>解决方案截图</h4>
                            <div class="screenshot-list">
                                <?php foreach ($solutionShotList as $screenshot): ?>
                                    <img src="<?php echo h(UPLOAD_URL . ltrim($screenshot, '/')); ?>" alt="解决方案截图" class="screenshot-thumb js-image-viewer-trigger" data-viewer-src="<?php echo h(UPLOAD_URL . ltrim($screenshot, '/')); ?>" data-viewer-alt="解决方案截图">
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="text-muted">暂无解决方案，等待补充</p>
        <?php endif; ?>
    </div>
</div>
<?php
$detailSectionsHtml = ob_get_clean();

$revisions = array_map(static function ($rev) use ($solutionIndexMap, $solutionNumberLabels, $categoryNameById) {
    $solutionLabel = '';
    if (!empty($rev['solution_id']) && isset($solutionIndexMap[(int)$rev['solution_id']])) {
        $solutionNo = (int)$solutionIndexMap[(int)$rev['solution_id']];
        $solutionNoLabel = $solutionNumberLabels[$solutionNo - 1] ?? (string)$solutionNo;
        $solutionLabel = '方案' . $solutionNoLabel;
    }

    $oldD = json_decode($rev['old_data'], true) ?: [];
    $newD = json_decode($rev['new_data'], true) ?: [];
    $fieldLabels = ['title' => '报错标题', 'category_id' => '报错分类', 'phenomenon' => '问题描述', 'system_category' => '系统分类', 'system_info' => '系统信息', 'patch_info' => '汉化补丁', 'solution' => $solutionLabel !== '' ? $solutionLabel : '解决方案'];
    $diffs = [];
    foreach ($fieldLabels as $field => $label) {
        $oldVal = $oldD[$field] ?? '';
        $newVal = $newD[$field] ?? '';
        if ($oldVal !== $newVal) {
            if ($field === 'category_id') {
                $oldVal = $categoryNameById[(string)$oldVal] ?? (string)$oldVal;
                $newVal = $newD['category_name'] ?? ($categoryNameById[(string)$newVal] ?? (string)$newVal);
            }
            $mode = (!empty($oldVal) && empty($newVal)) ? 'removed' : ((!empty($newVal) && empty($oldVal)) ? 'added' : 'changed');
            $diffs[] = [
                'label' => $label,
                'mode' => $mode,
                'old_html' => nl2br(h((string)$oldVal)),
                'new_html' => nl2br(h((string)$newVal)),
            ];
        }
    }

    $oldScreenshots = array_values(array_filter(array_map('trim', explode(',', (string)($rev['old_screenshots'] ?? '')))));
    $newScreenshots = array_values(array_filter(array_map('trim', explode(',', (string)($rev['new_screenshots'] ?? '')))));
    $oldSolScreenshots = array_values(array_filter(array_map('trim', explode(',', (string)($rev['old_solution_screenshots'] ?? '')))));
    $newSolScreenshots = array_values(array_filter(array_map('trim', explode(',', (string)($rev['new_solution_screenshots'] ?? '')))));

    return [
        'submitter_name' => (string)($rev['submitter_name'] ?? '匿名用户'),
        'created_at' => !empty($rev['created_at']) ? date('Y-m-d H:i', strtotime($rev['created_at'])) : '',
        'review_status_label' => ($rev['status'] ?? '') === 'pending' ? '待审核' : (($rev['status'] ?? '') === 'approved' ? '已通过' : ''),
        'diffs' => $diffs,
        'screenshot_diff' => ((($oldDecoded['category_id'] ?? null) != ($newDecoded['category_id'] ?? null)) && count($oldScreenshots) === count($newScreenshots) && implode(',', $oldScreenshots) === implode(',', $newScreenshots)) ? null : [
            'old' => array_map(static fn($s) => UPLOAD_URL . $s, $oldScreenshots),
            'new' => array_map(static fn($s) => UPLOAD_URL . $s, $newScreenshots),
        ],
        'solution_label' => $solutionLabel !== '' ? $solutionLabel : '解决方案',
        'solution_screenshot_diff' => [
            'old' => array_map(static fn($s) => UPLOAD_URL . $s, $oldSolScreenshots),
            'new' => array_map(static fn($s) => UPLOAD_URL . $s, $newSolScreenshots),
        ],
    ];
}, $revisions);

$revisionsHtml = '';

ob_start();
if (!empty($relatedErrors)):
?>
<section class="mb-20" style="margin-top:24px;"><h2 class="card-header" style="display:flex;justify-content:space-between;align-items:center;">该游戏的其他报错<a href="<?php echo urlGame($error['game_id']); ?>" class="btn btn-secondary btn-sm" style="font-size:0.8rem;">查看全部</a></h2><div class="card-body">
<?php foreach ($relatedErrors as $rel): ?>
    <a class="error-card" href="<?php echo urlError($rel['id']); ?>"><h3 class="error-title"><?php echo h($rel['title']); ?></h3><div class="error-meta"><span class="article-tag-sm"><?php echo h($rel['category_name']); ?></span><span>时间：<?php echo date('Y-m-d', strtotime($rel['created_at'])); ?></span><span class="view-count-inline" title="浏览量"><?php echo $relatedViews[$rel['id']] ?? 0; ?></span></div><div class="error-content"><div class="error-section"><h4>解决方案：</h4><?php if (!empty(trim($rel['solution_text'] ?? ''))): ?><p><?php echo h(mb_substr($rel['solution_text'], 0, 80) . (mb_strlen($rel['solution_text']) > 80 ? '...' : '')); ?></p><?php else: ?><p class="text-muted">暂无解决方案</p><?php endif; ?></div></div></a>
<?php endforeach; ?>
</div></section>
<?php endif; $relatedHtml = ob_get_clean();

$comments = array_map(static function ($comment) use ($id, $commentPerPage) {
    $avatarUrl = (!empty($comment['avatar']) && file_exists(BASE_PATH . $comment['avatar'])) ? '/' . $comment['avatar'] : '';
    $parentUserId = (int)($comment['parent_user_id'] ?? 0);
    $parentContent = trim((string)($comment['parent_content'] ?? ''));
    return [
        'id' => (int)$comment['id'],
        'content' => (string)$comment['content'],
        'content_html' => md_to_html($comment['content']),
        'username' => (string)$comment['username'],
        'user_id' => (int)$comment['user_id'],
        'avatar_url' => $avatarUrl,
        'created_at' => date('Y-m-d H:i', strtotime($comment['created_at'])),
        'parent_id' => (int)($comment['parent_id'] ?? 0),
        'parent_username' => (string)($comment['parent_username'] ?? ''),
        'parent_user_id' => $parentUserId,
        'reply_quote_url' => $comment['parent_id'] ? h(buildCommentTargetUrl('error', $id, (int)$comment['parent_id'], $commentPerPage)) : '',
        'parent_content_excerpt' => $comment['parent_id'] ? nl2br(h(mb_strimwidth($parentContent, 0, 240, '…', 'UTF-8'))) : '',
        'can_reply' => isUserLoggedIn(),
        'can_edit' => isUserLoggedIn() && (int)getCurrentUserId() === (int)$comment['user_id'],
        'can_delete' => isAdmin() || (isUserLoggedIn() && (int)getCurrentUserId() === (int)$comment['user_id']),
    ];
}, $errorComments);

$commentsHtml = '';

$errorVm = [
    'id' => (int)$error['id'],
    'title' => (string)$error['title'],
    'title_short' => mb_substr((string)$error['title'], 0, 30),
    'game_title' => (string)$error['game_title'],
    'game_title_short' => mb_substr((string)$error['game_title'], 0, 20),
    'game_url' => urlGame($error['game_id']),
    'game_id' => (int)$error['game_id'],
    'category_id' => (int)$error['category_id'],
    'category_name' => (string)$error['category_name'],
    'submitter_name' => (string)($error['submitter_name'] ?? ''),
    'user_id' => (int)($error['user_id'] ?? 0),
    'solution_user_id' => (int)($error['solution_user_id'] ?? 0),
    'solution_author_name' => '',
    'solution_created_at' => (string)($error['solution_created_at'] ?? ''),
    'created_text' => date('Y-m-d H:i', strtotime($error['created_at'])),
    'view_user' => (int)($viewCounts['user_views'] ?? 0),
    'view_guest' => (int)($viewCounts['guest_views'] ?? 0),
];

$pageScriptsHtml = renderTwig('front/partials/error_detail_scripts.twig', [
    'error_id' => $id,
    'assets_ver' => ASSETS_VER,
    'comment_modal_mtime' => @filemtime(BASE_PATH . '/assets/js/comment-modal.js') ?: time(),
]);

view('front/error_detail.twig', [
    'from_admin' => $fromAdmin,
    'error' => $errorVm,
    'inline_style_html' => $inlineStyleHtml,
    'site_head_html' => $siteHeadHtml,
    'header_html' => $headerHtml,
    'admin_sidebar_html' => $adminSidebarHtml,
    'admin_footer_scripts_html' => $adminFooterScriptsHtml,
    'footer_html' => $footerHtml,
    'error_has_phenomenon' => !empty($error['phenomenon']),
    'phenomenon_html' => renderTextWithAutoLinks($error['phenomenon'] ?? ''),
    'screenshot_urls' => array_values(array_map(static function ($s) {
        $s = trim((string)$s);
        return ['url' => UPLOAD_URL . $s];
    }, array_filter(array_map('trim', explode(',', (string)($error['screenshots'] ?? '')))))),
    'error_has_system_section' => !empty($error['system_category']) || !empty($error['system_info']),
    'system_category_label_html' => (!empty($error['system_category']) && isset($systemCategoryLabels[$error['system_category']])) ? h($systemCategoryLabels[$error['system_category']]) : '<span class="text-muted">未标注</span>',
    'error_is_android_emulator' => (($error['system_category'] ?? '') === 'android_emulator'),
    'android_device_rows' => array_values(array_filter([
        !empty($error['android_cpu']) ? ['label' => '手机处理器', 'value' => h($error['android_cpu'])] : null,
        !empty($error['android_model']) ? ['label' => '手机机型', 'value' => h($error['android_model'])] : null,
        !empty($error['android_version']) ? ['label' => '安卓版本', 'value' => h($error['android_version'])] : null,
    ])),
    'error_has_system_info' => !empty($error['system_info']),
    'system_info_html' => nl2br(h($error['system_info'] ?? '')),
    'error_has_patch_info' => !empty($error['patch_info']),
    'patch_info_html' => nl2br(h($error['patch_info'] ?? '')),
    'solutions' => array_map(static function ($solutionRow, $idx) use ($error, $revisionBySolutionId, $solutionNumberLabels) {
        $solutionRevision = $revisionBySolutionId[(int)$solutionRow['id']] ?? null;
        $solutionShotList = array_values(array_filter(array_map('trim', explode(',', (string)($solutionRow['solution_screenshots'] ?? '')))));
        return [
            'id' => (int)$solutionRow['id'],
            'label' => !empty($solutionRow['solution_no']) ? ($solutionNumberLabels[(int)$solutionRow['solution_no'] - 1] ?? (string)$solutionRow['solution_no']) : ($solutionNumberLabels[$idx] ?? (string)($idx + 1)),
            'user_id' => (int)($solutionRow['user_id'] ?? 0),
            'author_name' => (string)($solutionRow['solution_author_name'] ?? ''),
            'created_at' => !empty($solutionRow['created_at']) ? date('Y-m-d H:i', strtotime($solutionRow['created_at'])) : '',
            'revision_user_id' => (int)($solutionRevision['user_id'] ?? 0),
            'revision_author_name' => (string)($solutionRevision['submitter_name'] ?? ''),
            'revision_created_at' => !empty($solutionRevision['created_at'] ?? '') ? date('Y-m-d H:i', strtotime($solutionRevision['created_at'])) : '',
            'content_html' => renderTextWithAutoLinks($solutionRow['solution'] ?? ''),
            'screenshot_urls' => array_values(array_map(static fn($s) => ['url' => UPLOAD_URL . ltrim($s, '/')], $solutionShotList)),
            'screenshot_count' => count($solutionShotList),
        ];
    }, $errorSolutions, array_keys($errorSolutions ?: [])),
    'error_has_solutions' => !empty($errorSolutions),
    'revisions' => $revisions,
    'game_url' => urlGame($error['game_id']),
    'related_errors' => !empty($relatedErrors) ? array_values(array_map(static function ($rel) use ($relatedViews) {
        $solutionText = trim((string)($rel['solution_text'] ?? ''));
        $summary = $solutionText !== '' ? mb_substr($solutionText, 0, 80) . (mb_strlen($solutionText) > 80 ? '...' : '') : '';
        return [
            'url' => urlError((int)$rel['id']),
            'title' => (string)$rel['title'],
            'category_name' => (string)$rel['category_name'],
            'created_at' => date('Y-m-d', strtotime((string)($rel['created_at'] ?? 'now'))),
            'view_count' => (int)($relatedViews[(int)$rel['id']] ?? 0),
            'solution_summary' => $summary,
        ];
    }, $relatedErrors)) : [],
    'related_html' => $relatedHtml,
    'comments' => $comments,
    'comment_count' => $errorCommentCount,
    'comment_pagination' => $commentPagination,
    'comment_error_id' => $id,
    'comment_request_uri' => $_SERVER['REQUEST_URI'] ?? ('/error_detail.php?id=' . $id),
    'comment_is_logged_in' => isUserLoggedIn(),
    'page_scripts_html' => $pageScriptsHtml,
    'is_logged_in' => isUserLoggedIn(),
    'can_edit' => isUserLoggedIn() || $fromAdmin,
    'error_edit_url' => $fromAdmin
        ? '/edit_error.php?id=' . (int)$error['id'] . '&from_admin=1'
        : (isUserLoggedIn() ? '/edit_error.php?id=' . (int)$error['id'] : ''),
    'back_url' => $fromAdmin ? '/admin/errors.php' : urlGame($error['game_id']),
    'back_text' => $fromAdmin ? '返回列表' : '返回游戏页',
    'solution_submit_url' => $solutionSubmitUrl,
]);
