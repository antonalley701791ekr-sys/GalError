<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/markdown.php';

checkLogin();
requirePermission('errors', 'view');

$pdo = getDB();
$message = '';
$messageType = '';

// 处理各种操作
$action = $_GET['action'] ?? '';
$status = $_GET['status'] ?? '';

if ($action === 'approve' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $pdo->prepare("UPDATE errors SET status = 'approved' WHERE id = ?");
    $result = $stmt->execute([$id]);
    
    if ($result) {
        $message = '报错已通过审核';
        $messageType = 'success';
        // 通知提交者
        $stmt2 = $pdo->prepare("SELECT user_id, title FROM errors WHERE id = ?");
        $stmt2->execute([$id]);
        $errorInfo = $stmt2->fetch();
        if ($errorInfo && $errorInfo['user_id']) {
            sendNotification($errorInfo['user_id'], '报错审核通过',
                '您提交的报错「' . $errorInfo['title'] . '」已通过审核，现已在前台展示。');
        }
    } else {
        $message = '操作失败';
        $messageType = 'error';
    }
}

if ($action === 'reject' && isset($_GET['id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission('errors', 'edit');
    $id = intval($_GET['id']);
    $reason = (string)($_POST['reject_reason'] ?? '');
    $reason = str_replace(["\r\n", "\r"], "\n", $reason);
    if (trim($reason) === '') {
        $message = '请填写拒绝理由';
        $messageType = 'error';
    } else {
        $stmt = $pdo->prepare("UPDATE errors SET status = 'rejected', reject_reason = ? WHERE id = ?");
        $result = $stmt->execute([$reason, $id]);
        if ($result) {
            $message = '报错已拒绝';
            $messageType = 'success';
            // 通知提交者
            $stmt2 = $pdo->prepare("SELECT user_id, title FROM errors WHERE id = ?");
            $stmt2->execute([$id]);
            $errorInfo = $stmt2->fetch();
            if ($errorInfo && $errorInfo['user_id']) {
                sendNotification($errorInfo['user_id'], '报错审核未通过',
                    '您提交的报错「' . $errorInfo['title'] . '」未通过审核。' . "\n\n驳回原因：" . $reason . "\n\n如有疑问，请修改后重新提交。");
            }
        } else {
            $message = '操作失败';
            $messageType = 'error';
        }
    }
}

// 审核通过修改记录：将修改应用到报错
if ($action === 'approve_revision' && isset($_GET['rev_id'])) {
    $revId = intval($_GET['rev_id']);
    $stmt = $pdo->prepare("SELECT * FROM error_revisions WHERE id = ? AND status = 'pending'");
    $stmt->execute([$revId]);
    $revision = $stmt->fetch();

    if ($revision) {
        $newData = json_decode($revision['new_data'], true);
        if ($newData) {
            // 应用文字修改到报错
            $stmt = $pdo->prepare("UPDATE errors SET title=?, category_id=?, phenomenon=?, system_info=?, patch_info=?, solution=? WHERE id=?");
            $stmt->execute([
                $newData['title'] ?? '',
                intval($newData['category_id'] ?? 0),
                $newData['phenomenon'] ?? '',
                $newData['system_info'] ?? '',
                $newData['patch_info'] ?? '',
                $newData['solution'] ?? '',
                $revision['error_id']
            ]);

            // 应用截图修改
            if ($revision['new_screenshots'] !== null) {
                $stmt = $pdo->prepare("UPDATE errors SET screenshots=? WHERE id=?");
                $stmt->execute([$revision['new_screenshots'], $revision['error_id']]);
            }

            // 应用解决方案截图修改
            if ($revision['new_solution_screenshots'] !== null) {
                $stmt = $pdo->prepare("UPDATE errors SET solution_screenshots=? WHERE id=?");
                $stmt->execute([$revision['new_solution_screenshots'], $revision['error_id']]);
            }
        }

        // 标记修改记录为已通过
        $stmt = $pdo->prepare("UPDATE error_revisions SET status = 'approved' WHERE id = ?");
        $stmt->execute([$revId]);

        // 通知提交者
        $stmt2 = $pdo->prepare("SELECT e.title, r.user_id FROM errors e JOIN error_revisions r ON r.error_id = e.id WHERE r.id = ?");
        $stmt2->execute([$revId]);
        $revInfo = $stmt2->fetch();
        if ($revInfo && $revInfo['user_id']) {
            sendNotification($revInfo['user_id'], '报错修改审核通过',
                '您对报错「' . $revInfo['title'] . '」的修改已通过审核，新版本已在前台展示。');
        }

        $message = '修改记录已审核通过并应用到报错';
        $messageType = 'success';
    } else {
        $message = '修改记录不存在或已处理';
        $messageType = 'error';
    }
    $action = 'revisions';
}

// 拒绝修改记录
if ($action === 'reject_revision' && isset($_GET['rev_id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission('errors', 'edit');
    $revId = intval($_GET['rev_id']);
    $reason = (string)($_POST['reject_reason'] ?? '');
    $reason = str_replace(["\r\n", "\r"], "\n", $reason);

    if (trim($reason) === '') {
        $message = '请填写拒绝理由';
        $messageType = 'error';
    } else {
        $stmt = $pdo->prepare("UPDATE error_revisions SET status = 'rejected', reject_reason = ? WHERE id = ?");
        $result = $stmt->execute([$reason, $revId]);

        if ($result) {
            // 删除被拒绝修改记录中新上传的截图文件
            $stmt = $pdo->prepare("SELECT old_screenshots, new_screenshots, old_solution_screenshots, new_solution_screenshots FROM error_revisions WHERE id = ?");
            $stmt->execute([$revId]);
            $rev = $stmt->fetch();
            if ($rev) {
                $oldSc = array_filter(array_map('trim', explode(',', $rev['old_screenshots'] ?? '')));
                $newSc = array_filter(array_map('trim', explode(',', $rev['new_screenshots'] ?? '')));
                $addedSc = array_diff($newSc, $oldSc);
                foreach ($addedSc as $sc) {
                    $filePath = BASE_PATH . UPLOAD_PATH . $sc;
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                }
                // 删除新上传的解决方案截图
                $oldSolSc = array_filter(array_map('trim', explode(',', $rev['old_solution_screenshots'] ?? '')));
                $newSolSc = array_filter(array_map('trim', explode(',', $rev['new_solution_screenshots'] ?? '')));
                $addedSolSc = array_diff($newSolSc, $oldSolSc);
                foreach ($addedSolSc as $sc) {
                    $filePath = BASE_PATH . UPLOAD_PATH . $sc;
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                }
            }
            // 通知提交者
            $stmt2 = $pdo->prepare("SELECT e.title, r.user_id FROM errors e JOIN error_revisions r ON r.error_id = e.id WHERE r.id = ?");
            $stmt2->execute([$revId]);
            $revInfo = $stmt2->fetch();
            if ($revInfo && $revInfo['user_id']) {
                sendNotification($revInfo['user_id'], '报错修改审核未通过',
                    '您对报错「' . $revInfo['title'] . '」的修改未通过审核。' . "\n\n驳回原因：" . $reason . "\n\n如有疑问，请修改后重新提交。");
            }
            $message = '修改记录已拒绝';
            $messageType = 'success';
        } else {
            $message = '操作失败';
            $messageType = 'error';
        }
    }
    $action = 'revisions';
}

if ($action === 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    
    // 获取报错信息，删除相关截图
    $stmt = $pdo->prepare("SELECT screenshots, solution_screenshots FROM errors WHERE id = ?");
    $stmt->execute([$id]);
    $error = $stmt->fetch();
    
    if ($error && $error['screenshots']) {
        $screenshots = explode(',', $error['screenshots']);
        foreach ($screenshots as $screenshot) {
            $screenshot = trim($screenshot);
            if ($screenshot && file_exists(BASE_PATH . UPLOAD_PATH . $screenshot)) {
                unlink(BASE_PATH . UPLOAD_PATH . $screenshot);
            }
        }
    }

    if ($error && $error['solution_screenshots']) {
        $solScreenshots = explode(',', $error['solution_screenshots']);
        foreach ($solScreenshots as $screenshot) {
            $screenshot = trim($screenshot);
            if ($screenshot && file_exists(BASE_PATH . UPLOAD_PATH . $screenshot)) {
                unlink(BASE_PATH . UPLOAD_PATH . $screenshot);
            }
        }
    }
    
    // 删除报错记录
    $stmt = $pdo->prepare("DELETE FROM errors WHERE id = ?");
    $result = $stmt->execute([$id]);
    
    if ($result) {
        $message = '报错已删除';
        $messageType = 'success';
    } else {
        $message = '删除失败';
        $messageType = 'error';
    }
}

// 编辑报错处理
if ($action === 'edit' && isset($_POST['edit_error_id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $editId = intval($_POST['edit_error_id']);
    $gameId = intval($_POST['game_id'] ?? 0);
    $categoryId = intval($_POST['category_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $phenomenon = trim($_POST['phenomenon'] ?? '');
    $systemInfo = trim($_POST['system_info'] ?? '');
    $patchInfo = trim($_POST['patch_info'] ?? '');
    $solution = trim($_POST['solution'] ?? '');

    if (empty($title) || empty($solution)) {
        $message = '标题和解决方案不能为空';
        $messageType = 'error';
    } elseif ($gameId <= 0 || $categoryId <= 0) {
        $message = '请选择游戏和报错分类';
        $messageType = 'error';
    } else {
        // 验证 game_id 和 category_id 有效
        $checkGame = $pdo->prepare("SELECT id FROM games WHERE id = ?");
        $checkGame->execute([$gameId]);
        $checkCat = $pdo->prepare("SELECT id FROM error_categories WHERE id = ?");
        $checkCat->execute([$categoryId]);

        if (!$checkGame->fetch()) {
            $message = '选择的游戏不存在';
            $messageType = 'error';
        } elseif (!$checkCat->fetch()) {
            $message = '选择的分类不存在';
            $messageType = 'error';
        } else {
            // 处理报错截图
            $oldStmt = $pdo->prepare("SELECT screenshots, solution_screenshots FROM errors WHERE id = ?");
            $oldStmt->execute([$editId]);
            $oldError = $oldStmt->fetch();
            $oldScreenshots = $oldError && $oldError['screenshots'] ? array_filter(array_map('trim', explode(',', $oldError['screenshots']))) : [];

            // 保留的报错截图
            $keepScreenshots = $_POST['keep_screenshots'] ?? [];
            // 安全过滤：仅保留确实在原记录中的文件名
            $keepScreenshots = array_filter($keepScreenshots, function($s) use ($oldScreenshots) {
                return in_array(basename($s), $oldScreenshots);
            });
            $keepScreenshots = array_map('basename', $keepScreenshots);

            // 取消勾选的旧截图先不做物理删除，避免影响历史修改记录展示
            $toDelete = array_diff($oldScreenshots, $keepScreenshots);

            // 处理新上传的报错截图
            $newScreenshots = [];
            if (isset($_FILES['new_screenshots'])) {
                $files = $_FILES['new_screenshots'];
                for ($i = 0; $i < count($files['name']); $i++) {
                    if ($files['error'][$i] === UPLOAD_ERR_OK) {
                        $tmpFile = [
                            'name' => $files['name'][$i],
                            'type' => $files['type'][$i],
                            'tmp_name' => $files['tmp_name'][$i],
                            'error' => $files['error'][$i],
                            'size' => $files['size'][$i],
                        ];
                        $upload = handleFileUpload($tmpFile);
                        if ($upload['success']) {
                            $newScreenshots[] = $upload['filename'];
                        }
                    }
                }
            }

            $allScreenshots = array_merge($keepScreenshots, $newScreenshots);
            $screenshotsStr = implode(',', $allScreenshots);

            // 处理解决方案截图
            $oldSolScreenshots = $oldError && $oldError['solution_screenshots'] ? array_filter(array_map('trim', explode(',', $oldError['solution_screenshots']))) : [];

            $keepSolScreenshots = $_POST['keep_solution_screenshots'] ?? [];
            $keepSolScreenshots = array_filter($keepSolScreenshots, function($s) use ($oldSolScreenshots) {
                return in_array(basename($s), $oldSolScreenshots);
            });
            $keepSolScreenshots = array_map('basename', $keepSolScreenshots);

            // 取消勾选的旧截图先不做物理删除，避免影响历史修改记录展示
            $toDeleteSol = array_diff($oldSolScreenshots, $keepSolScreenshots);

            $newSolScreenshots = [];
            if (isset($_FILES['new_solution_screenshots'])) {
                $files = $_FILES['new_solution_screenshots'];
                for ($i = 0; $i < count($files['name']); $i++) {
                    if ($files['error'][$i] === UPLOAD_ERR_OK) {
                        $tmpFile = [
                            'name' => $files['name'][$i],
                            'type' => $files['type'][$i],
                            'tmp_name' => $files['tmp_name'][$i],
                            'error' => $files['error'][$i],
                            'size' => $files['size'][$i],
                        ];
                        $upload = handleFileUpload($tmpFile);
                        if ($upload['success']) {
                            $newSolScreenshots[] = $upload['filename'];
                        }
                    }
                }
            }

            $allSolScreenshots = array_merge($keepSolScreenshots, $newSolScreenshots);
            $solScreenshotsStr = implode(',', $allSolScreenshots);

            $stmt = $pdo->prepare("UPDATE errors SET game_id=?, category_id=?, title=?, phenomenon=?, system_info=?, patch_info=?, solution=?, screenshots=?, solution_screenshots=? WHERE id=?");
            $result = $stmt->execute([$gameId, $categoryId, $title, $phenomenon, $systemInfo, $patchInfo, $solution, $screenshotsStr, $solScreenshotsStr, $editId]);

            if ($result) {
                $message = '报错更新成功';
                $messageType = 'success';
                $action = '';
            } else {
                $message = '更新失败';
                $messageType = 'error';
            }
        }
    }
}

// 构建查询条件
$where = [];
$params = [];

if ($status) {
    $where[] = "e.status = ?";
    $params[] = $status;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// 获取报错列表
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$countSql = "
    SELECT COUNT(*) as count 
    FROM errors e 
    {$whereClause}
";
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total = $stmt->fetch()['count'];

$pagination = paginate($total, $page, $perPage);

$sql = "
    SELECT e.*, g.title as game_title, g.vndb_id, c.name as category_name 
    FROM errors e 
    JOIN games g ON e.game_id = g.id 
    JOIN error_categories c ON e.category_id = c.id 
    {$whereClause}
    ORDER BY e.created_at DESC 
    LIMIT {$offset}, {$perPage}
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$errors = $stmt->fetchAll();

// 获取状态统计
$stats = [
    'total' => $pdo->query("SELECT COUNT(*) as count FROM errors")->fetch()['count'],
    'pending' => $pdo->query("SELECT COUNT(*) as count FROM errors WHERE status = 'pending'")->fetch()['count'],
    'approved' => $pdo->query("SELECT COUNT(*) as count FROM errors WHERE status = 'approved'")->fetch()['count'],
    'rejected' => $pdo->query("SELECT COUNT(*) as count FROM errors WHERE status = 'rejected'")->fetch()['count'],
];

// 状态文本映射
$statusText = [
    'pending' => '待审核',
    'approved' => '已通过',
    'rejected' => '已拒绝'
];

// 获取查看详情的报错
$viewError = null;
if ($action === 'view' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("
        SELECT e.*, g.title as game_title, g.vndb_id, c.name as category_name, u.username AS submitter_name
        FROM errors e
        JOIN games g ON e.game_id = g.id
        JOIN error_categories c ON e.category_id = c.id
        LEFT JOIN users u ON e.user_id = u.id
        WHERE e.id = ?
    ");
    $stmt->execute([intval($_GET['id'])]);
    $viewError = $stmt->fetch();
}

// 编辑模式：预查报错数据
$editError = null;
$allGames = [];
$allCategories = [];
if ($action === 'edit' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("
        SELECT e.*, g.title as game_title, g.vndb_id, c.name as category_name 
        FROM errors e 
        JOIN games g ON e.game_id = g.id 
        JOIN error_categories c ON e.category_id = c.id 
        WHERE e.id = ?
    ");
    $stmt->execute([intval($_GET['id'])]);
    $editError = $stmt->fetch();
    if (!$editError) {
        $message = '报错不存在';
        $messageType = 'error';
        $action = '';
    } else {
        // 查询所有已审核游戏列表
        $allGames = $pdo->query("SELECT id, title FROM games WHERE status = 'approved' ORDER BY title ASC")->fetchAll();
        // 查询所有报错分类列表
        $allCategories = $pdo->query("SELECT id, name FROM error_categories ORDER BY sort_order ASC, id ASC")->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>报错管理 - <?php echo h(SITE_NAME); ?> 管理后台</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER . '-' . @filemtime(BASE_PATH . '/assets/css/style.css'); ?>">
    <link rel="stylesheet" href="/assets/css/markdown-editor.css">
    <?php renderAdminHeadScript(); ?>
</head>
<body class="has-fixed-nav">
    <?php include '../includes/header.php'; ?>
    <div class="admin-layout">
        <!-- 侧边栏 -->
        <?php renderAdminSidebar('errors.php'); ?>

        <!-- 主内容区 -->
        <div class="admin-content">
            <!-- 主要内容 -->
            <main class="admin-main">
                <div class="admin-page-header">
                    <h1>报错管理</h1>
                </div>
                <?php if ($message): ?>
                    <div class="admin-alert-<?php echo $messageType; ?>">
                        <?php echo h($message); ?>
                    </div>
                <?php endif; ?>

                <?php if ($action === 'view' && $viewError): ?>
                    <!-- 查看报错详情 -->
                    <div class="card">
                        <div class="card-header">
                            报错详情
                            <span class="status status-<?php echo $viewError['status']; ?>">
                                <?php echo $statusText[$viewError['status']] ?? $viewError['status']; ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                                <div>
                                    <h4 style="color: var(--accent-purple); margin-bottom: 12px;">基本信息</h4>
                                    <p><strong>标题：</strong><?php echo h($viewError['title']); ?></p>
                                    <p><strong>游戏：</strong><?php echo h($viewError['game_title']); ?>
                                        <?php if ($viewError['vndb_id']): ?>
                                            (<a href="https://vndb.org/<?php echo h($viewError['vndb_id']); ?>" target="_blank"><?php echo h($viewError['vndb_id']); ?></a>)
                                        <?php endif; ?>
                                    </p>
                                    <p><strong>分类：</strong><?php echo h($viewError['category_name']); ?></p>
                                    <p><strong>提交时间：</strong><?php echo date('Y-m-d H:i:s', strtotime($viewError['created_at'])); ?></p>
                                    <p><strong>提交者：</strong><?php echo h($viewError['submitter_name'] ?? '匿名用户'); ?></p>
                                </div>
                                <div>
                                    <h4 style="color: var(--accent-purple); margin-bottom: 12px;">操作</h4>
                                    <div class="btn-group">
                                        <?php if ($viewError['status'] === 'pending'): ?>
                                            <a href="?action=approve&id=<?php echo $viewError['id']; ?>" class="btn btn-success<?php echo pd('errors','edit'); ?>"<?php echo pdAttr('errors','edit'); ?>>通过审核</a>
                                            <button type="button" class="btn btn-danger<?php echo pd('errors','edit'); ?>"<?php echo pdBtnAttr('errors','edit'); ?> onclick="<?php echo hasPermission('errors','edit') ? "openRejectModal('?action=reject&id=" . $viewError['id'] . "')" : 'return false;'; ?>">拒绝</button>
                                        <?php endif; ?>
                                        <a href="?action=edit&id=<?php echo $viewError['id']; ?>" class="btn btn-secondary<?php echo pd('errors','edit'); ?>"<?php echo pdAttr('errors','edit'); ?>>编辑</a>
                                        <a href="?action=delete&id=<?php echo $viewError['id']; ?>" class="btn btn-danger<?php echo pd('errors','delete'); ?>"<?php echo pdAttr('errors','delete'); ?> onclick="<?php echo hasPermission('errors','delete') ? "return confirm('确定要删除这个报错吗？')" : 'return false;'; ?>">删除</a>
                                        <a href="errors.php" class="btn btn-secondary">返回列表</a>
                                    </div>
                                </div>
                            </div>

                            <?php if (!empty($viewError['reject_reason'])): ?>
                            <div class="error-section">
                                <h4>拒绝理由</h4>
                                <div class="info-block info-block-danger">
                                    <?php echo nl2br(h($viewError['reject_reason'])); ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($viewError['phenomenon']): ?>
                            <div class="error-section">
                                <h4>问题描述</h4>
                                <div class="info-block">
                                    <?php echo nl2br(h($viewError['phenomenon'])); ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($viewError['system_info']): ?>
                            <div class="error-section">
                                <h4>系统信息</h4>
                                <div class="info-block">
                                    <?php echo nl2br(h($viewError['system_info'])); ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($viewError['patch_info']): ?>
                            <div class="error-section">
                                <h4>汉化补丁信息</h4>
                                <div class="info-block">
                                    <?php echo nl2br(h($viewError['patch_info'])); ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="error-section">
                                <h4>解决方案</h4>
                                <div class="info-block info-block-success">
                                    <?php echo nl2br(h($viewError['solution'])); ?>
                                </div>
                            </div>

                            <?php if ($viewError['screenshots']): ?>
                            <div class="error-section">
                                <h4>报错截图</h4>
                                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                    <?php 
                                    $screenshots = explode(',', $viewError['screenshots']);
                                    foreach ($screenshots as $screenshot):
                                        if (trim($screenshot)):
                                    ?>
                                        <img src="<?php echo h(UPLOAD_URL . trim($screenshot)); ?>" alt="报错截图" 
                                             style="max-width: 200px; max-height: 150px; border-radius: 6px; border: 1px solid var(--glass-border); cursor: pointer;" 
                                             onclick="window.open(this.src)">
                                        <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($viewError['solution_screenshots']): ?>
                            <div class="error-section">
                                <h4>解决方案截图</h4>
                                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                    <?php 
                                    $solScreenshots = explode(',', $viewError['solution_screenshots']);
                                    foreach ($solScreenshots as $screenshot):
                                        if (trim($screenshot)):
                                    ?>
                                        <img src="<?php echo h(UPLOAD_URL . trim($screenshot)); ?>" alt="解决方案截图" 
                                             style="max-width: 200px; max-height: 150px; border-radius: 6px; border: 1px solid var(--glass-border); cursor: pointer;" 
                                             onclick="window.open(this.src)">
                                        <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- 修改记录 -->
                            <?php
                            $revStmt = $pdo->prepare("
                                SELECT r.*, u.username as submitter_name 
                                FROM error_revisions r 
                                LEFT JOIN users u ON r.user_id = u.id 
                                WHERE r.error_id = ? 
                                ORDER BY r.created_at DESC
                            ");
                            $revStmt->execute([$viewError['id']]);
                            $revisions = $revStmt->fetchAll();
                            $revStatusText = ['pending' => '待审核', 'approved' => '已通过', 'rejected' => '已拒绝'];
                            $fieldLabels = [
                                'title' => '报错标题',
                                'phenomenon' => '问题描述',
                                'system_info' => '系统信息',
                                'patch_info' => '汉化补丁',
                                'solution' => '解决方案',
                            ];
                            ?>
                            <?php if (!empty($revisions)): ?>
                            <div class="error-section" style="margin-top: 20px;">
                                <h4>修改记录 (<?php echo count($revisions); ?>)</h4>
                                <?php foreach ($revisions as $rev): ?>
                                <div style="border: 1px solid var(--glass-border); border-radius: 8px; padding: 16px; margin-bottom: 16px; background: var(--glass-bg);">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                                        <div>
                                            <strong>提交者：</strong><?php echo h($rev['submitter_name'] ?? '匿名用户'); ?>
                                            &nbsp;|&nbsp;
                                            <strong>时间：</strong><?php echo date('Y-m-d H:i:s', strtotime($rev['created_at'])); ?>
                                            &nbsp;|&nbsp;
                                            <span class="status status-<?php echo $rev['status']; ?>">
                                                <?php echo $revStatusText[$rev['status']] ?? $rev['status']; ?>
                                            </span>
                                        </div>
                                        <?php if ($rev['status'] === 'pending'): ?>
                                        <div class="btn-group">
                                            <a href="?action=approve_revision&rev_id=<?php echo $rev['id']; ?>" class="btn btn-success<?php echo pd('errors','edit'); ?>" style="font-size: 12px; padding: 4px 10px;"<?php echo pdAttr('errors','edit'); ?> onclick="<?php echo hasPermission('errors','edit') ? "return confirm('确定通过此修改？修改将直接应用到报错记录。')" : 'return false;'; ?>">通过</a>
                                            <button type="button" class="btn btn-danger<?php echo pd('errors','edit'); ?>" style="font-size: 12px; padding: 4px 10px;"<?php echo pdBtnAttr('errors','edit'); ?> onclick="<?php echo hasPermission('errors','edit') ? "openRejectModal('?action=reject_revision&rev_id=" . $rev['id'] . "')" : ''; ?>">拒绝</button>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (!empty($rev['reject_reason'])): ?>
                                    <div style="margin-bottom: 10px;">
                                        <strong style="color: var(--accent-purple);">拒绝理由：</strong>
                                        <div class="info-block info-block-danger">
                                            <?php echo nl2br(h($rev['reject_reason'])); ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <?php
                                    $oldD = json_decode($rev['old_data'], true) ?: [];
                                    $newD = json_decode($rev['new_data'], true) ?: [];
                                    $hasAnyChange = false;
                                    foreach ($fieldLabels as $field => $label):
                                        $oldVal = $oldD[$field] ?? '';
                                        $newVal = $newD[$field] ?? '';
                                        if ($oldVal !== $newVal):
                                            $hasAnyChange = true;
                                    ?>
                                    <div style="margin-bottom: 10px;">
                                        <strong style="color: var(--accent-purple);"><?php echo $label; ?>：</strong>
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
                                        $hasAnyChange = true;
                                    ?>
                                    <div style="margin-bottom: 10px;">
                                        <strong style="color: var(--accent-purple);">报错截图变化：</strong>
                                        <?php if (!empty($removedSc)): ?>
                                            <div style="margin-top: 6px;">
                                                <span class="diff-removed" style="display: inline; padding: 2px 6px;">删除了 <?php echo count($removedSc); ?> 张截图</span>
                                                <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 6px;">
                                                    <?php foreach ($removedSc as $sc): ?>
                                                        <?php $imgPath = BASE_PATH . UPLOAD_PATH . $sc; ?>
                                                        <?php if (file_exists($imgPath)): ?>
                                                            <img src="<?php echo h(UPLOAD_URL . $sc); ?>" alt="历史截图" 
                                                                 style="width:100px;height:75px;object-fit:cover;border-radius:4px;opacity:0.6;border:2px solid #e74c3c;cursor:pointer;"
                                                                 onclick="window.open(this.src)">
                                                        <?php else: ?>
                                                            <span class="diff-removed" style="display:inline-block;padding:4px 8px;font-size:12px;">历史截图文件缺失：<?php echo h($sc); ?></span>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($addedSc)): ?>
                                            <div style="margin-top: 6px;">
                                                <span class="diff-added" style="display: inline; padding: 2px 6px;">新增了 <?php echo count($addedSc); ?> 张截图</span>
                                                <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 6px;">
                                                    <?php foreach ($addedSc as $sc): ?>
                                                        <img src="<?php echo h(UPLOAD_URL . $sc); ?>" alt="新增截图" 
                                                             style="width:100px;height:75px;object-fit:cover;border-radius:4px;border:2px solid #27ae60;cursor:pointer;"
                                                             onclick="window.open(this.src)">
                                                    <?php endforeach; ?>
                                                </div>
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
                                        $hasAnyChange = true;
                                    ?>
                                    <div style="margin-bottom: 10px;">
                                        <strong style="color: var(--accent-purple);">解决方案截图变化：</strong>
                                        <?php if (!empty($removedSolSc)): ?>
                                            <div style="margin-top: 6px;">
                                                <span class="diff-removed" style="display: inline; padding: 2px 6px;">删除了 <?php echo count($removedSolSc); ?> 张截图</span>
                                                <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 6px;">
                                                    <?php foreach ($removedSolSc as $sc): ?>
                                                        <?php $imgPath = BASE_PATH . UPLOAD_PATH . $sc; ?>
                                                        <?php if (file_exists($imgPath)): ?>
                                                            <img src="<?php echo h(UPLOAD_URL . $sc); ?>" alt="历史截图" 
                                                                 style="width:100px;height:75px;object-fit:cover;border-radius:4px;opacity:0.6;border:2px solid #e74c3c;cursor:pointer;"
                                                                 onclick="window.open(this.src)">
                                                        <?php else: ?>
                                                            <span class="diff-removed" style="display:inline-block;padding:4px 8px;font-size:12px;">历史截图文件缺失：<?php echo h($sc); ?></span>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($addedSolSc)): ?>
                                            <div style="margin-top: 6px;">
                                                <span class="diff-added" style="display: inline; padding: 2px 6px;">新增了 <?php echo count($addedSolSc); ?> 张截图</span>
                                                <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 6px;">
                                                    <?php foreach ($addedSolSc as $sc): ?>
                                                        <img src="<?php echo h(UPLOAD_URL . $sc); ?>" alt="新增截图" 
                                                             style="width:100px;height:75px;object-fit:cover;border-radius:4px;border:2px solid #27ae60;cursor:pointer;"
                                                             onclick="window.open(this.src)">
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (!$hasAnyChange): ?>
                                        <p class="text-muted">无文字修改</p>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php elseif ($action === 'edit' && $editError): ?>
                    <!-- 编辑报错表单 -->
                    <div class="card">
                        <div class="card-header">编辑报错</div>
                        <div class="card-body">
                            <form method="post" enctype="multipart/form-data">
                                <input type="hidden" name="edit_error_id" value="<?php echo $editError['id']; ?>">

                                <div class="form-group">
                                    <label class="form-label">游戏 *</label>
                                    <select name="game_id" class="form-input" required>
                                        <option value="">-- 请选择游戏 --</option>
                                        <?php foreach ($allGames as $game): ?>
                                            <option value="<?php echo $game['id']; ?>" <?php echo $game['id'] == $editError['game_id'] ? 'selected' : ''; ?>>
                                                <?php echo h($game['title']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">报错分类 *</label>
                                    <select name="category_id" class="form-input" required>
                                        <option value="">-- 请选择分类 --</option>
                                        <?php foreach ($allCategories as $cat): ?>
                                            <option value="<?php echo $cat['id']; ?>" <?php echo $cat['id'] == $editError['category_id'] ? 'selected' : ''; ?>>
                                                <?php echo h($cat['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">报错标题 *</label>
                                    <input type="text" name="title" class="form-input" required value="<?php echo h($editError['title']); ?>">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">问题描述</label>
                                    <textarea name="phenomenon" class="form-textarea" rows="4"><?php echo h($editError['phenomenon']); ?></textarea>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">系统信息</label>
                                    <textarea name="system_info" class="form-textarea" rows="3"><?php echo h($editError['system_info']); ?></textarea>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">汉化补丁信息</label>
                                    <textarea name="patch_info" class="form-textarea" rows="3"><?php echo h($editError['patch_info']); ?></textarea>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">解决方案 *</label>
                                    <textarea name="solution" class="form-textarea" rows="5" required><?php echo h($editError['solution']); ?></textarea>
                                </div>

                                <?php if ($editError['screenshots']): ?>
                                <div class="form-group">
                                    <label class="form-label">现有报错截图</label>
                                    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                                        <?php
                                        $existingScreenshots = array_filter(array_map('trim', explode(',', $editError['screenshots'])));
                                        foreach ($existingScreenshots as $screenshot):
                                            if ($screenshot):
                                        ?>
                                            <div style="text-align: center;">
                                                <img src="<?php echo h(UPLOAD_URL . $screenshot); ?>" alt="报错截图"
                                                     style="width: 120px; height: 90px; object-fit: cover; border-radius: 4px; border: 1px solid var(--glass-border); display: block; margin-bottom: 4px; cursor: pointer;"
                                                     onclick="window.open(this.src)">
                                                <label style="font-size: 12px; cursor: pointer;">
                                                    <input type="checkbox" name="keep_screenshots[]" value="<?php echo h($screenshot); ?>" checked>
                                                    保留
                                                </label>
                                            </div>
                                        <?php
                                            endif;
                                        endforeach;
                                        ?>
                                    </div>
                                    <small class="text-muted">取消勾选的截图将在提交后被删除</small>
                                </div>
                                <?php endif; ?>

                                <div class="form-group">
                                    <label class="form-label">上传新报错截图</label>
                                    <input type="file" name="new_screenshots[]" class="form-input" multiple accept="image/*">
                                    <small class="text-muted">支持 JPG、PNG、GIF 格式，单个文件最大 2MB</small>
                                </div>

                                <?php if ($editError['solution_screenshots']): ?>
                                <div class="form-group">
                                    <label class="form-label">现有解决方案截图</label>
                                    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                                        <?php
                                        $existingSolScreenshots = array_filter(array_map('trim', explode(',', $editError['solution_screenshots'])));
                                        foreach ($existingSolScreenshots as $screenshot):
                                            if ($screenshot):
                                        ?>
                                            <div style="text-align: center;">
                                                <img src="<?php echo h(UPLOAD_URL . $screenshot); ?>" alt="解决方案截图"
                                                     style="width: 120px; height: 90px; object-fit: cover; border-radius: 4px; border: 1px solid var(--glass-border); display: block; margin-bottom: 4px; cursor: pointer;"
                                                     onclick="window.open(this.src)">
                                                <label style="font-size: 12px; cursor: pointer;">
                                                    <input type="checkbox" name="keep_solution_screenshots[]" value="<?php echo h($screenshot); ?>" checked>
                                                    保留
                                                </label>
                                            </div>
                                        <?php
                                            endif;
                                        endforeach;
                                        ?>
                                    </div>
                                    <small class="text-muted">取消勾选的截图将在提交后被删除</small>
                                </div>
                                <?php endif; ?>

                                <div class="form-group">
                                    <label class="form-label">上传新解决方案截图</label>
                                    <input type="file" name="new_solution_screenshots[]" class="form-input" multiple accept="image/*">
                                    <small class="text-muted">支持 JPG、PNG、GIF 格式，单个文件最大 2MB</small>
                                </div>

                                <div class="form-group">
                                    <div class="btn-group">
                                        <button type="submit" class="btn">更新报错</button>
                                        <a href="?action=view&id=<?php echo $editError['id']; ?>" class="btn btn-secondary">取消</a>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- 状态统计 -->
                    <div class="admin-stats-grid">
                        <div class="card stat-card">
                            <div class="card-body">
                                <h3 class="stat-number stat-number--cyan"><?php echo $stats['total']; ?></h3>
                                <p class="stat-label">总数</p>
                            </div>
                        </div>
                        <div class="card stat-card">
                            <div class="card-body">
                                <h3 class="stat-number stat-number--yellow"><?php echo $stats['pending']; ?></h3>
                                <p class="stat-label">待审核</p>
                            </div>
                        </div>
                        <div class="card stat-card">
                            <div class="card-body">
                                <h3 class="stat-number stat-number--green"><?php echo $stats['approved']; ?></h3>
                                <p class="stat-label">已通过</p>
                            </div>
                        </div>
                        <div class="card stat-card">
                            <div class="card-body">
                                <h3 class="stat-number stat-number--red"><?php echo $stats['rejected']; ?></h3>
                                <p class="stat-label">已拒绝</p>
                            </div>
                        </div>
                    </div>

                    <!-- 筛选标签 -->
                    <div class="btn-group" style="margin-bottom: 20px;">
                        <a href="errors.php" class="btn <?php echo (!$status && $action !== 'revisions') ? 'btn-success' : 'btn-secondary'; ?>">全部</a>
                        <a href="errors.php?status=pending" class="btn <?php echo $status === 'pending' ? 'btn-success' : 'btn-secondary'; ?>">待审核</a>
                        <a href="errors.php?status=approved" class="btn <?php echo $status === 'approved' ? 'btn-success' : 'btn-secondary'; ?>">已通过</a>
                        <a href="errors.php?status=rejected" class="btn <?php echo $status === 'rejected' ? 'btn-success' : 'btn-secondary'; ?>">已拒绝</a>
                        <a href="errors.php?action=revisions" class="btn <?php echo $action === 'revisions' ? 'btn-success' : 'btn-secondary'; ?>">
                            修改记录
                            <?php
                            $pendingRevCount = $pdo->query("SELECT COUNT(*) as c FROM error_revisions WHERE status = 'pending'")->fetch()['c'];
                            if ($pendingRevCount > 0):
                            ?>
                                <span style="background: var(--accent-red); color: var(--bg-primary); border-radius: 10px; padding: 1px 7px; font-size: 11px; margin-left: 4px;"><?php echo $pendingRevCount; ?></span>
                            <?php endif; ?>
                        </a>
                    </div>

                    <?php if ($action === 'revisions'): ?>
                    <!-- 修改记录管理 -->
                    <?php
                    $revStatus = $_GET['rev_status'] ?? '';
                    $revWhere = [];
                    $revParams = [];
                    if ($revStatus) {
                        $revWhere[] = "r.status = ?";
                        $revParams[] = $revStatus;
                    }
                    $revWhereClause = !empty($revWhere) ? 'WHERE ' . implode(' AND ', $revWhere) : '';

                    $revPage = max(1, intval($_GET['rev_page'] ?? 1));
                    $revPerPage = 20;
                    $revOffset = ($revPage - 1) * $revPerPage;

                    $revCountStmt = $pdo->prepare("SELECT COUNT(*) as c FROM error_revisions r $revWhereClause");
                    $revCountStmt->execute($revParams);
                    $revTotal = $revCountStmt->fetch()['c'];
                    $revPagination = paginate($revTotal, $revPage, $revPerPage);

                    $revSql = "
                        SELECT r.*, e.title as error_title, g.title as game_title, u.username as submitter_name 
                        FROM error_revisions r 
                        JOIN errors e ON r.error_id = e.id 
                        JOIN games g ON e.game_id = g.id 
                        LEFT JOIN users u ON r.user_id = u.id 
                        $revWhereClause
                        ORDER BY r.created_at DESC 
                        LIMIT $revOffset, $revPerPage
                    ";
                    $revStmt = $pdo->prepare($revSql);
                    $revStmt->execute($revParams);
                    $allRevisions = $revStmt->fetchAll();
                    $revStatusText = ['pending' => '待审核', 'approved' => '已通过', 'rejected' => '已拒绝'];
                    ?>
                    <div class="btn-group" style="margin-bottom: 16px;">
                        <a href="errors.php?action=revisions" class="btn <?php echo !$revStatus ? 'btn-success' : 'btn-secondary'; ?>" style="font-size: 12px; padding: 4px 10px;">全部</a>
                        <a href="errors.php?action=revisions&rev_status=pending" class="btn <?php echo $revStatus === 'pending' ? 'btn-success' : 'btn-secondary'; ?>" style="font-size: 12px; padding: 4px 10px;">待审核</a>
                        <a href="errors.php?action=revisions&rev_status=approved" class="btn <?php echo $revStatus === 'approved' ? 'btn-success' : 'btn-secondary'; ?>" style="font-size: 12px; padding: 4px 10px;">已通过</a>
                        <a href="errors.php?action=revisions&rev_status=rejected" class="btn <?php echo $revStatus === 'rejected' ? 'btn-success' : 'btn-secondary'; ?>" style="font-size: 12px; padding: 4px 10px;">已拒绝</a>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            修改记录列表
                            <span class="text-muted" style="float: right; font-weight: normal;">共 <?php echo $revTotal; ?> 条记录</span>
                        </div>
                        <div class="card-body">
                            <?php if (empty($allRevisions)): ?>
                                <p class="text-muted" style="text-align: center; padding: 20px;">暂无修改记录</p>
                            <?php else: ?>
                            <?php
                            $fieldLabels = [
                                'title' => '报错标题',
                                'phenomenon' => '问题描述',
                                'system_info' => '系统信息',
                                'patch_info' => '汉化补丁',
                                'solution' => '解决方案',
                            ];
                            foreach ($allRevisions as $rev):
                                $oldD = json_decode($rev['old_data'], true) ?: [];
                                $newD = json_decode($rev['new_data'], true) ?: [];
                            ?>
                            <div style="border: 1px solid var(--glass-border); border-radius: 8px; padding: 16px; margin-bottom: 16px; background: var(--glass-bg);">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 8px; margin-bottom: 12px;">
                                    <div>
                                        <p style="margin: 0 0 4px 0;">
                                            <strong>报错：</strong>
                                            <a href="?action=view&id=<?php echo $rev['error_id']; ?>"><?php echo h($rev['error_title']); ?></a>
                                            &nbsp;|&nbsp;
                                            <strong>游戏：</strong><?php echo h($rev['game_title']); ?>
                                        </p>
                                        <p style="margin: 0;">
                                            <strong>提交者：</strong><?php echo h($rev['submitter_name'] ?? '匿名用户'); ?>
                                            &nbsp;|&nbsp;
                                            <strong>时间：</strong><?php echo date('Y-m-d H:i:s', strtotime($rev['created_at'])); ?>
                                            &nbsp;|&nbsp;
                                            <span class="status status-<?php echo $rev['status']; ?>"><?php echo $revStatusText[$rev['status']] ?? $rev['status']; ?></span>
                                        </p>
                                    </div>
                                    <?php if ($rev['status'] === 'pending'): ?>
                                    <div class="btn-group">
                                        <a href="?action=approve_revision&rev_id=<?php echo $rev['id']; ?>" class="btn btn-success<?php echo pd('errors','edit'); ?>" style="font-size: 12px; padding: 4px 10px;"<?php echo pdAttr('errors','edit'); ?> onclick="<?php echo hasPermission('errors','edit') ? "return confirm('确定通过此修改？修改将直接应用到报错记录。')" : 'return false;'; ?>">通过</a>
                                        <button type="button" class="btn btn-danger<?php echo pd('errors','edit'); ?>" style="font-size: 12px; padding: 4px 10px;"<?php echo pdBtnAttr('errors','edit'); ?> onclick="<?php echo hasPermission('errors','edit') ? "openRejectModal('?action=reject_revision&rev_id=" . $rev['id'] . "')" : ''; ?>">拒绝</button>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($rev['reject_reason'])): ?>
                                <div style="margin-bottom: 8px;">
                                    <strong style="color: var(--accent-purple);">拒绝理由：</strong>
                                    <div class="info-block info-block-danger">
                                        <?php echo nl2br(h($rev['reject_reason'])); ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- 修改详情 diff -->
                                <?php
                                foreach ($fieldLabels as $field => $label):
                                    $oldVal = $oldD[$field] ?? '';
                                    $newVal = $newD[$field] ?? '';
                                    if ($oldVal !== $newVal):
                                ?>
                                <div style="margin-bottom: 8px;">
                                    <strong style="color: var(--accent-purple);"><?php echo $label; ?>：</strong>
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
                                <div style="margin-bottom: 8px;">
                                    <strong style="color: var(--accent-purple);">报错截图变化：</strong>
                                    <?php if (!empty($removedSc)): ?>
                                        <div style="margin-top: 4px;">
                                            <span class="diff-removed" style="display: inline; padding: 2px 6px;">删除了 <?php echo count($removedSc); ?> 张截图</span>
                                            <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-top: 4px;">
                                                <?php foreach ($removedSc as $sc): ?>
                                                    <img src="<?php echo h(UPLOAD_URL . $sc); ?>" alt="已删除截图" style="width:80px;height:60px;object-fit:cover;border-radius:4px;opacity:0.6;border:2px solid #e74c3c;cursor:pointer;" onclick="window.open(this.src)">
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($addedSc)): ?>
                                        <div style="margin-top: 4px;">
                                            <span class="diff-added" style="display: inline; padding: 2px 6px;">新增了 <?php echo count($addedSc); ?> 张截图</span>
                                            <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-top: 4px;">
                                                <?php foreach ($addedSc as $sc): ?>
                                                    <img src="<?php echo h(UPLOAD_URL . $sc); ?>" alt="新增截图" style="width:80px;height:60px;object-fit:cover;border-radius:4px;border:2px solid #27ae60;cursor:pointer;" onclick="window.open(this.src)">
                                                <?php endforeach; ?>
                                            </div>
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
                                <div style="margin-bottom: 8px;">
                                    <strong style="color: var(--accent-purple);">解决方案截图变化：</strong>
                                    <?php if (!empty($removedSolSc)): ?>
                                        <div style="margin-top: 4px;">
                                            <span class="diff-removed" style="display: inline; padding: 2px 6px;">删除了 <?php echo count($removedSolSc); ?> 张截图</span>
                                            <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-top: 4px;">
                                                <?php foreach ($removedSolSc as $sc): ?>
                                                    <img src="<?php echo h(UPLOAD_URL . $sc); ?>" alt="已删除截图" style="width:80px;height:60px;object-fit:cover;border-radius:4px;opacity:0.6;border:2px solid #e74c3c;cursor:pointer;" onclick="window.open(this.src)">
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($addedSolSc)): ?>
                                        <div style="margin-top: 4px;">
                                            <span class="diff-added" style="display: inline; padding: 2px 6px;">新增了 <?php echo count($addedSolSc); ?> 张截图</span>
                                            <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-top: 4px;">
                                                <?php foreach ($addedSolSc as $sc): ?>
                                                    <img src="<?php echo h(UPLOAD_URL . $sc); ?>" alt="新增截图" style="width:80px;height:60px;object-fit:cover;border-radius:4px;border:2px solid #27ae60;cursor:pointer;" onclick="window.open(this.src)">
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>

                            <!-- 分页 -->
                            <?php if ($revPagination['totalPages'] > 1): ?>
                                <div class="pagination">
                                    <?php if ($revPagination['hasPrev']): ?>
                                        <a href="?action=revisions&rev_status=<?php echo $revStatus; ?>&rev_page=<?php echo $revPagination['page'] - 1; ?>">上一页</a>
                                    <?php endif; ?>
                                    <?php for ($i = max(1, $revPagination['page'] - 2); $i <= min($revPagination['totalPages'], $revPagination['page'] + 2); $i++): ?>
                                        <?php if ($i == $revPagination['page']): ?>
                                            <span class="current"><?php echo $i; ?></span>
                                        <?php else: ?>
                                            <a href="?action=revisions&rev_status=<?php echo $revStatus; ?>&rev_page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                    <?php if ($revPagination['hasNext']): ?>
                                        <a href="?action=revisions&rev_status=<?php echo $revStatus; ?>&rev_page=<?php echo $revPagination['page'] + 1; ?>">下一页</a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php else: ?>
                    <!-- 报错列表 -->
                    <div class="card">
                        <div class="card-header">
                            报错列表
                            <span class="text-muted" style="float: right; font-weight: normal;">
                                共 <?php echo $total; ?> 条记录
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>标题</th>
                                            <th>游戏</th>
                                            <th>分类</th>
                                            <th>状态</th>
                                            <th>提交时间</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($errors as $error): ?>
                                            <tr>
                                                <td>
                                                    <a href="?action=view&id=<?php echo $error['id']; ?>" style="color: inherit; text-decoration: none;">
                                                        <?php echo h(mb_substr($error['title'], 0, 30) . (mb_strlen($error['title']) > 30 ? '...' : '')); ?>
                                                    </a>
                                                </td>
                                                <td>
                                                    <?php echo h($error['game_title']); ?>
                                                    <?php if ($error['vndb_id']): ?>
                                                        <br><small class="text-muted"><?php echo h($error['vndb_id']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo h($error['category_name']); ?></td>
                                                <td>
                                                    <span class="status status-<?php echo $error['status']; ?>">
                                                        <?php echo $statusText[$error['status']] ?? $error['status']; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('Y-m-d H:i', strtotime($error['created_at'])); ?></td>
                                                <td>
                                                    <a href="?action=view&id=<?php echo $error['id']; ?>" class="btn" style="font-size: 12px; padding: 4px 8px;">查看</a>
                                                    <a href="?action=edit&id=<?php echo $error['id']; ?>" class="btn btn-secondary<?php echo pd('errors','edit'); ?>" style="font-size: 12px; padding: 4px 8px;"<?php echo pdAttr('errors','edit'); ?>>编辑</a>
                                                    <?php if ($error['status'] === 'pending'): ?>
                                                        <a href="?action=approve&id=<?php echo $error['id']; ?>" class="btn btn-success<?php echo pd('errors','edit'); ?>" style="font-size: 12px; padding: 4px 8px;"<?php echo pdAttr('errors','edit'); ?>>通过</a>
                                                        <button type="button" class="btn btn-danger<?php echo pd('errors','edit'); ?>" style="font-size: 12px; padding: 4px 8px;"<?php echo pdBtnAttr('errors','edit'); ?> onclick="<?php echo hasPermission('errors','edit') ? "openRejectModal('?action=reject&id=" . $error['id'] . "')" : ''; ?>">拒绝</button>
                                                    <?php endif; ?>
                                                    <a href="?action=delete&id=<?php echo $error['id']; ?>" class="btn btn-danger<?php echo pd('errors','delete'); ?>" style="font-size: 12px; padding: 4px 8px;"<?php echo pdAttr('errors','delete'); ?> onclick="<?php echo hasPermission('errors','delete') ? "return confirm('确定要删除这个报错吗？')" : 'return false;'; ?>">删除</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- 分页 -->
                            <?php if ($pagination['totalPages'] > 1): ?>
                                <div class="pagination">
                                    <?php if ($pagination['hasPrev']): ?>
                                        <a href="?status=<?php echo $status; ?>&page=<?php echo $pagination['page'] - 1; ?>">上一页</a>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = max(1, $pagination['page'] - 2); $i <= min($pagination['totalPages'], $pagination['page'] + 2); $i++): ?>
                                        <?php if ($i == $pagination['page']): ?>
                                            <span class="current"><?php echo $i; ?></span>
                                        <?php else: ?>
                                            <a href="?status=<?php echo $status; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                    
                                    <?php if ($pagination['hasNext']): ?>
                                        <a href="?status=<?php echo $status; ?>&page=<?php echo $pagination['page'] + 1; ?>">下一页</a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </main>
        </div>
    </div>
    <?php renderAdminFooterScripts(); ?>

    <!-- 拒绝理由模态框 -->
    <div id="rejectModal" class="reject-modal-overlay" style="display:none;">
        <div class="reject-modal">
            <div class="reject-modal-header">
                <h3>填写拒绝理由</h3>
                <button type="button" class="reject-modal-close" onclick="closeRejectModal()">&times;</button>
            </div>
            <form id="rejectForm" method="post" action="">
                <div class="reject-modal-body">
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
                            <textarea name="reject_reason" id="rejectReasonInput" class="md-editor-textarea" placeholder="请输入拒绝理由" required style="min-height:200px;"></textarea>
                            <div id="rejectReasonPreview" class="md-editor-preview-pane markdown-body"></div>
                        </div>
                    </div>
                    <p id="rejectReasonError" style="color:#e74c3c;font-size:0.82rem;margin-top:6px;display:none;">请填写拒绝理由</p>
                </div>
                <div class="reject-modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeRejectModal()">取消</button>
                    <button type="submit" class="btn btn-danger">确认拒绝</button>
                </div>
            </form>
        </div>
    </div>
    <style>
    .reject-modal-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(4px);}
[data-theme="light"] .reject-modal-overlay{background:rgba(15,17,23,0.24);}
    .reject-modal{background:var(--bg-secondary);border:1px solid var(--glass-border,rgba(255,255,255,0.1));border-radius:12px;width:90%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,0.3);}
    .reject-modal-header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--glass-border,rgba(255,255,255,0.1));}
    .reject-modal-header h3{margin:0;font-size:1rem;color:var(--text-primary,#fff);}
    .reject-modal-close{background:none;border:none;font-size:1.5rem;color:var(--text-muted,#999);cursor:pointer;padding:0;line-height:1;}
    .reject-modal-close:hover{color:var(--text-primary,#fff);}
    .reject-modal-body{padding:20px;}
    .reject-modal-footer{display:flex;justify-content:flex-end;gap:10px;padding:16px 20px;border-top:1px solid var(--glass-border,rgba(255,255,255,0.1));}

    .table th:nth-child(4),
    .table td:nth-child(4) {
        white-space: nowrap;
    }

    .table td:nth-child(4) .status {
        display: inline-flex;
        align-items: center;
        white-space: nowrap;
        word-break: keep-all;
        line-height: 1.2;
    }
    </style>
    <script src="/assets/js/marked.min.js"></script>
    <script src="/assets/js/reject-reason-editor.js?v=<?php echo ASSETS_VER . '-' . @filemtime(__DIR__ . '/../assets/js/reject-reason-editor.js'); ?>"></script>
</body>

