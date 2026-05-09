<?php
require_once '../includes/config.php';
require_once '../includes/image_utils.php';
require_once '../includes/sanitizer.php';
require_once '../includes/auth.php';

checkLogin();
if (!hasPermission('games', 'view') && !hasPermission('game_review', 'view')) {
    $_SESSION['admin_msg'] = '您没有权限执行此操作';
    header('Location: index.php'); exit;
}

$pdo = getDB();
$message = '';
$messageType = '';

// 处理各种操作
$action = $_GET['action'] ?? '';
$status = $_GET['status'] ?? '';

// AJAX: 裁剪图片上传
if ($action === 'upload_cropped' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = handleCroppedCoverData($_POST['image_data'] ?? '');
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// 编辑游戏处理
if ($action === 'edit' && isset($_POST['edit_game_id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $editId = intval($_POST['edit_game_id']);
    $vndbId = trim($_POST['vndb_id'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $titleJp = trim($_POST['title_jp'] ?? '');
    $romaji = trim($_POST['romaji'] ?? '');
    $aliases = trim($_POST['aliases'] ?? '');
    $developer = trim($_POST['developer'] ?? '');
    $releaseDate = trim($_POST['release_date'] ?? '');
    $coverUrl = trim($_POST['cover_url'] ?? '');
    $coverVndbUrl = trim($_POST['cover_vndb_url'] ?? '');
    $coverType = trim($_POST['cover_type'] ?? 'keep');
    $platforms = trim($_POST['platforms'] ?? '');

    if (empty($title)) {
        $message = '游戏标题不能为空';
        $messageType = 'error';
    } else {
        // 获取旧记录
        $oldStmt = $pdo->prepare("SELECT cover_image, vndb_cover_url FROM games WHERE id = ?");
        $oldStmt->execute([$editId]);
        $oldGame = $oldStmt->fetch();

        if (!$oldGame) {
            $message = '游戏不存在';
            $messageType = 'error';
        } else {
            $newCoverImage = null; // null 表示不更新封面
            $newVndbCoverUrl = null;
            $updateCover = false;

            if ($coverType !== 'keep') {
                $updateCover = true;
                $newCoverImage = '';
                $croppedPath = trim($_POST['cropped_cover_path'] ?? '');

                if (!empty($croppedPath) && strpos($croppedPath, UPLOAD_PATH . 'covers/') === 0
                    && !preg_match('/\.\./', $croppedPath) && file_exists(BASE_PATH . $croppedPath)) {
                    $newCoverImage = $croppedPath;
                } elseif ($coverType === 'upload' && isset($_FILES['cover_file']) && $_FILES['cover_file']['error'] === UPLOAD_ERR_OK) {
                    $upload = handleCoverUpload($_FILES['cover_file']);
                    if ($upload['success']) {
                        $newCoverImage = $upload['path'];
                    } else {
                        $message = $upload['message'];
                        $messageType = 'error';
                    }
                } elseif ($coverType === 'url' && !empty($coverUrl)) {
                    $newCoverImage = $coverUrl;
                } elseif ($coverType === 'vndb' && !empty($coverVndbUrl)) {
                    if (!empty($vndbId)) {
                        $vndbCheck = fetchVNDBInfo($vndbId);
                        if ($vndbCheck['success'] && isset($vndbCheck['data']['cover_sexual']) && $vndbCheck['data']['cover_sexual'] >= 1.3) {
                            $message = 'VNDB 封面含有 R18 内容（sexual=' . round($vndbCheck['data']['cover_sexual'], 1) . '），禁止使用。';
                            $messageType = 'error';
                        } else {
                            $newCoverImage = $coverVndbUrl;
                            $newVndbCoverUrl = $coverVndbUrl;
                        }
                    } else {
                        $newCoverImage = $coverVndbUrl;
                        $newVndbCoverUrl = $coverVndbUrl;
                    }
                }
            }

            if ($messageType !== 'error') {
                if ($updateCover) {
                    // 删除旧的本地封面文件
                    if ($oldGame['cover_image'] && strpos($oldGame['cover_image'], UPLOAD_PATH) === 0) {
                        $oldFilePath = BASE_PATH . $oldGame['cover_image'];
                        if (file_exists($oldFilePath)) {
                            unlink($oldFilePath);
                        }
                    }
                    $stmt = $pdo->prepare("UPDATE games SET vndb_id=?, title=?, title_jp=?, romaji=?, aliases=?, developer=?, release_date=?, cover_image=?, vndb_cover_url=?, platforms=? WHERE id=?");
                    $stmt->execute([$vndbId, $title, $titleJp, $romaji, $aliases ?: null, $developer, $releaseDate ?: null, $newCoverImage, $newVndbCoverUrl, $platforms, $editId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE games SET vndb_id=?, title=?, title_jp=?, romaji=?, aliases=?, developer=?, release_date=?, platforms=? WHERE id=?");
                    $stmt->execute([$vndbId, $title, $titleJp, $romaji, $aliases ?: null, $developer, $releaseDate ?: null, $platforms, $editId]);
                }

                $message = '游戏更新成功';
                $messageType = 'success';

                // 尝试将远程封面下载到本地
                if ($updateCover && isRemoteCoverUrl($newCoverImage)) {
                    $dlResult = downloadRemoteCover($newCoverImage);
                    if ($dlResult['success']) {
                        $pdo->prepare("UPDATE games SET cover_image = ? WHERE id = ?")->execute([$dlResult['path'], $editId]);
                    } else {
                        if (empty($newVndbCoverUrl)) {
                            $pdo->prepare("UPDATE games SET vndb_cover_url = ? WHERE id = ?")->execute([$newCoverImage, $editId]);
                        }
                        $message .= '（封面下载失败，使用原始链接）';
                    }
                }

                $action = '';
            }
        }
    }
}

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $vndbId = trim($_POST['vndb_id'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $titleJp = trim($_POST['title_jp'] ?? '');
    $romaji = trim($_POST['romaji'] ?? '');
    $aliases = trim($_POST['aliases'] ?? '');
    $developer = trim($_POST['developer'] ?? '');
    $releaseDate = trim($_POST['release_date'] ?? '');
    $coverUrl = trim($_POST['cover_url'] ?? '');
    $coverVndbUrl = trim($_POST['cover_vndb_url'] ?? '');
    $coverType = trim($_POST['cover_type'] ?? 'vndb');
    $platforms = trim($_POST['platforms'] ?? '');
    
    if (empty($title)) {
        $message = '游戏标题不能为空';
        $messageType = 'error';
    } else {
        // 处理封面图片（裁剪后的路径优先）
        $coverImage = '';
        $croppedPath = trim($_POST['cropped_cover_path'] ?? '');

        if (!empty($croppedPath) && strpos($croppedPath, UPLOAD_PATH . 'covers/') === 0
            && !preg_match('/\.\./', $croppedPath) && file_exists(BASE_PATH . $croppedPath)) {
            $coverImage = $croppedPath;
        } elseif ($coverType === 'upload' && isset($_FILES['cover_file']) && $_FILES['cover_file']['error'] === UPLOAD_ERR_OK) {
            $upload = handleCoverUpload($_FILES['cover_file']);
            if ($upload['success']) {
                $coverImage = $upload['path'];
            } else {
                $message = $upload['message'];
                $messageType = 'error';
            }
        } elseif ($coverType === 'url' && !empty($coverUrl)) {
            $coverImage = $coverUrl;
        } elseif ($coverType === 'vndb' && !empty($coverVndbUrl)) {
            // 服务端二次验证 VNDB 封面 R18 内容
            if (!empty($vndbId)) {
                $vndbCheck = fetchVNDBInfo($vndbId);
                if ($vndbCheck['success'] && isset($vndbCheck['data']['cover_sexual']) && $vndbCheck['data']['cover_sexual'] >= 1.3) {
                    $message = 'VNDB 封面含有 R18 内容（sexual=' . round($vndbCheck['data']['cover_sexual'], 1) . '），禁止使用。请选择其他方式提供健全封面。';
                    $messageType = 'error';
                } else {
                    $coverImage = $coverVndbUrl;
                }
            } else {
                $coverImage = $coverVndbUrl;
            }
        }

        if ($messageType !== 'error') {
            // 确定 vndb_cover_url（VNDB 封面备份）
            $vndbCoverUrlValue = null;
            if ($coverType === 'vndb' && !empty($coverVndbUrl)) {
                $vndbCoverUrlValue = $coverVndbUrl;
            }

            $stmt = $pdo->prepare("
                INSERT INTO games (vndb_id, title, title_jp, romaji, aliases, developer, release_date, cover_image, vndb_cover_url, platforms, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved')
            ");
            $result = $stmt->execute([$vndbId, $title, $titleJp, $romaji, $aliases ?: null, $developer, $releaseDate ?: null, $coverImage, $vndbCoverUrlValue, $platforms]);
            
            if ($result) {
                $message = '游戏添加成功';
                $messageType = 'success';
                // 尝试将远程封面下载到本地
                if (isRemoteCoverUrl($coverImage)) {
                    $newGameId = $pdo->lastInsertId();
                    $dlResult = downloadRemoteCover($coverImage);
                    if ($dlResult['success']) {
                        $pdo->prepare("UPDATE games SET cover_image = ? WHERE id = ?")->execute([$dlResult['path'], $newGameId]);
                    } else {
                        // 下载失败时确保 vndb_cover_url 有值可回退
                        if (empty($vndbCoverUrlValue)) {
                            $pdo->prepare("UPDATE games SET vndb_cover_url = ? WHERE id = ?")->execute([$coverImage, $newGameId]);
                        }
                        $message .= '（封面下载失败，使用原始链接）';
                    }
                }
            } else {
                $message = '游戏添加失败';
                $messageType = 'error';
            }
        }
    }
}

if ($action === 'delete' && isset($_GET['id'])) {
    $gameId = intval($_GET['id']);
    
    // 检查是否有关联的报错
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM errors WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $errorCount = $stmt->fetch()['count'];
    
    if ($errorCount > 0) {
        $message = '该游戏下还有报错记录，无法删除';
        $messageType = 'error';
    } else {
        // 删除本地封面文件
        $stmt = $pdo->prepare("SELECT cover_image FROM games WHERE id = ?");
        $stmt->execute([$gameId]);
        $game = $stmt->fetch();
        if ($game && $game['cover_image'] && strpos($game['cover_image'], UPLOAD_PATH) === 0) {
            $filePath = BASE_PATH . $game['cover_image'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }

        $stmt = $pdo->prepare("DELETE FROM games WHERE id = ?");
        $result = $stmt->execute([$gameId]);
        
        if ($result) {
            $message = '游戏删除成功';
            $messageType = 'success';
        } else {
            $message = '游戏删除失败';
            $messageType = 'error';
        }
    }
}

// 审核通过
if ($action === 'approve' && isset($_GET['id'])) {
    requirePermission('game_review', 'edit');
    $id = intval($_GET['id']);
    $stmt = $pdo->prepare("UPDATE games SET status = 'approved' WHERE id = ?");
    $result = $stmt->execute([$id]);
    if ($result) {
        $message = '游戏已通过审核';
        $messageType = 'success';
        // 通知提交者
        $stmt2 = $pdo->prepare("SELECT user_id, title FROM games WHERE id = ?");
        $stmt2->execute([$id]);
        $gameInfo = $stmt2->fetch();
        if ($gameInfo && $gameInfo['user_id']) {
            sendNotification($gameInfo['user_id'], '游戏审核通过',
                '您提交的游戏「' . $gameInfo['title'] . '」已通过审核，现已在前台展示。');
        }
        // 尝试将远程封面下载到本地
        $stmt2 = $pdo->prepare("SELECT cover_image, vndb_cover_url FROM games WHERE id = ?");
        $stmt2->execute([$id]);
        $gameRow = $stmt2->fetch();
        if ($gameRow && isRemoteCoverUrl($gameRow['cover_image'])) {
            if (empty($gameRow['vndb_cover_url'])) {
                $pdo->prepare("UPDATE games SET vndb_cover_url = ? WHERE id = ?")->execute([$gameRow['cover_image'], $id]);
            }
            $dlResult = downloadRemoteCover($gameRow['cover_image']);
            if ($dlResult['success']) {
                $pdo->prepare("UPDATE games SET cover_image = ? WHERE id = ?")->execute([$dlResult['path'], $id]);
            } else {
                $message .= '（封面下载失败，使用原始链接）';
            }
        }
    } else {
        $message = '操作失败';
        $messageType = 'error';
    }
    $action = '';
}

// 审核拒绝
if ($action === 'reject' && isset($_GET['id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission('game_review', 'edit');
    $id = intval($_GET['id']);
    $reason = trim($_POST['reject_reason'] ?? '');
    if (empty($reason)) {
        $message = '请填写拒绝理由';
        $messageType = 'error';
    } else {
        $stmt = $pdo->prepare("UPDATE games SET status = 'rejected', reject_reason = ? WHERE id = ?");
        $result = $stmt->execute([$reason, $id]);
        if ($result) {
            $message = '游戏已拒绝';
            $messageType = 'success';
            // 通知提交者
            $stmt2 = $pdo->prepare("SELECT user_id, title FROM games WHERE id = ?");
            $stmt2->execute([$id]);
            $gameInfo = $stmt2->fetch();
            if ($gameInfo && $gameInfo['user_id']) {
                sendNotification($gameInfo['user_id'], '游戏审核未通过',
                    '您提交的游戏「' . $gameInfo['title'] . '」未通过审核。' . "\n\n驳回原因：" . $reason . "\n\n如有疑问，请修改后重新提交。");
            }
        } else {
            $message = '操作失败';
            $messageType = 'error';
        }
    }
    $action = '';
}

// 审核通过游戏修改记录
if ($action === 'approve_revision' && isset($_GET['rev_id'])) {
    requirePermission('game_review', 'edit');
    $revId = intval($_GET['rev_id']);
    $stmt = $pdo->prepare("SELECT * FROM game_revisions WHERE id = ? AND status = 'pending'");
    $stmt->execute([$revId]);
    $rev = $stmt->fetch();
    if (!$rev) {
        $message = '修改记录不存在或已处理';
        $messageType = 'error';
    } else {
        $newData = json_decode($rev['new_data'], true) ?: [];
        $stmt = $pdo->prepare("UPDATE games SET vndb_id=?, title=?, title_jp=?, romaji=?, aliases=?, developer=?, release_date=?, cover_image=?, vndb_cover_url=?, platforms=?, has_pending_revision=0 WHERE id=?");
        $result = $stmt->execute([
            $newData['vndb_id'] ?? '',
            $newData['title'] ?? '',
            $newData['title_jp'] ?? '',
            $newData['romaji'] ?? '',
            ($newData['aliases'] ?? '') ?: null,
            $newData['developer'] ?? '',
            ($newData['release_date'] ?? '') ?: null,
            $newData['cover_image'] ?? '',
            ($newData['vndb_cover_url'] ?? '') ?: null,
            $newData['platforms'] ?? '',
            (int)$rev['game_id']
        ]);
        if ($result) {
            $pdo->prepare("UPDATE game_revisions SET status = 'approved' WHERE id = ?")->execute([$revId]);
            $message = '游戏修改已通过并应用';
            $messageType = 'success';
            if (!empty($rev['user_id'])) {
                sendNotification($rev['user_id'], '游戏修改审核通过', '您提交的游戏信息修改已通过审核并生效。');
            }
        } else {
            $message = '操作失败';
            $messageType = 'error';
        }
    }
    $action = '';
}

// 审核拒绝游戏修改记录
if ($action === 'reject_revision' && isset($_GET['rev_id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission('game_review', 'edit');
    $revId = intval($_GET['rev_id']);
    $reason = trim($_POST['reject_reason'] ?? '');
    if ($reason === '') {
        $message = '请填写拒绝理由';
        $messageType = 'error';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM game_revisions WHERE id = ? AND status = 'pending'");
        $stmt->execute([$revId]);
        $rev = $stmt->fetch();
        if (!$rev) {
            $message = '修改记录不存在或已处理';
            $messageType = 'error';
        } else {
            $pdo->prepare("UPDATE game_revisions SET status = 'rejected', reject_reason = ? WHERE id = ?")->execute([$reason, $revId]);
            $pendingStmt = $pdo->prepare("SELECT COUNT(*) FROM game_revisions WHERE game_id = ? AND status = 'pending'");
            $pendingStmt->execute([(int)$rev['game_id']]);
            if ((int)$pendingStmt->fetchColumn() === 0) {
                $pdo->prepare("UPDATE games SET has_pending_revision = 0 WHERE id = ?")->execute([(int)$rev['game_id']]);
            }
            $message = '游戏修改已拒绝';
            $messageType = 'success';
            if (!empty($rev['user_id'])) {
                sendNotification($rev['user_id'], '游戏修改审核未通过', '您提交的游戏信息修改未通过审核。' . "\n\n驳回原因：" . $reason);
            }
        }
    }
    $action = '';
}

if ($action === 'fetch_vndb' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $vndbId = trim($_POST['vndb_id'] ?? '');
    if ($vndbId) {
        $vndbResult = fetchVNDBInfo($vndbId);
        header('Content-Type: application/json');
        echo json_encode($vndbResult);
        exit;
    }
}

// 编辑模式：预查游戏数据
$editGame = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM games WHERE id = ?");
    $stmt->execute([intval($_GET['id'])]);
    $editGame = $stmt->fetch();
    if (!$editGame) {
        $message = '游戏不存在';
        $messageType = 'error';
        $action = '';
    }
}

// 查看详情
$viewGame = null;
if ($action === 'view' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT g.*, u.username AS submitter_name FROM games g LEFT JOIN users u ON g.user_id = u.id WHERE g.id = ?");
    $stmt->execute([intval($_GET['id'])]);
    $viewGame = $stmt->fetch();
}

$statusText = [
    'pending' => '待审核',
    'approved' => '已通过',
    'rejected' => '已拒绝'
];

$pendingGameRevisions = $pdo->query("SELECT r.*, g.title AS game_title, u.username AS submitter_name FROM game_revisions r JOIN games g ON r.game_id = g.id LEFT JOIN users u ON r.user_id = u.id WHERE r.status = 'pending' ORDER BY r.created_at DESC")->fetchAll();
$pendingGameRevisionCount = count($pendingGameRevisions);

// 统计数据
$gameStats = [
    'total'    => $pdo->query("SELECT COUNT(*) FROM games")->fetchColumn(),
    'pending'  => $pdo->query("SELECT COUNT(*) FROM games WHERE status='pending'")->fetchColumn(),
    'approved' => $pdo->query("SELECT COUNT(*) FROM games WHERE status='approved'")->fetchColumn(),
    'rejected' => $pdo->query("SELECT COUNT(*) FROM games WHERE status='rejected'")->fetchColumn(),
];

// 获取游戏列表（动态 status 筛选）
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($status && in_array($status, ['pending', 'approved', 'rejected'])) {
    $where[] = "status = ?";
    $params[] = $status;
}
$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) as count FROM games {$whereClause}");
$countStmt->execute($params);
$total = $countStmt->fetch()['count'];
$pagination = paginate($total, $page, $perPage);

$listStmt = $pdo->prepare("SELECT * FROM games {$whereClause} ORDER BY created_at DESC LIMIT {$offset}, {$perPage}");
$listStmt->execute($params);
$games = $listStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>游戏管理 - <?php echo h(SITE_NAME); ?> 管理后台</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER . '-' . @filemtime(BASE_PATH . '/assets/css/style.css'); ?>">
    <link rel="stylesheet" href="/assets/css/markdown-editor.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">
    <?php renderAdminHeadScript(); ?>
</head>
<body class="has-fixed-nav">
    <?php include '../includes/header.php'; ?>
    <div class="admin-layout">
        <!-- 侧边栏 -->
        <?php renderAdminSidebar('games.php'); ?>

        <!-- 主内容区 -->
        <div class="admin-content">
            <!-- 主要内容 -->
            <main class="admin-main">
                <div class="admin-page-header">
                    <h1>游戏管理</h1>
                    <a href="?action=add" class="btn<?php echo pd('games','add'); ?>"<?php echo pdAttr('games','add'); ?>>添加游戏</a>
                </div>
                <?php if ($message): ?>
                    <div class="admin-alert-<?php echo $messageType; ?>">
                        <?php echo h($message); ?>
                    </div>
                <?php endif; ?>

                <?php if ($action === 'add' || ($action === 'edit' && $editGame)): ?>
                    <!-- 添加/编辑游戏表单 -->
                    <div class="card">
                        <div class="card-header"><?php echo $action === 'edit' ? '编辑游戏' : '添加游戏'; ?></div>
                        <div class="card-body">
                            <form method="post" enctype="multipart/form-data">
                                <?php if ($action === 'edit' && $editGame): ?>
                                    <input type="hidden" name="edit_game_id" value="<?php echo $editGame['id']; ?>">
                                <?php endif; ?>

                                <div class="form-group">
                                    <label class="form-label">VNDB ID（可选）</label>
                                    <div style="display: flex; gap: 10px;">
                                        <input type="text" name="vndb_id" class="form-input" placeholder="例如：v12345" id="vndb_id" value="<?php echo h($editGame['vndb_id'] ?? ''); ?>">
                                        <button type="button" class="btn btn-secondary" onclick="fetchVNDBData()">获取信息</button>
                                    </div>
                                    <small class="text-muted">输入VNDB ID可自动获取游戏信息</small>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">游戏标题 *</label>
                                    <input type="text" name="title" class="form-input" id="title" required value="<?php echo h($editGame['title'] ?? ''); ?>">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">日文名</label>
                                    <input type="text" name="title_jp" class="form-input" id="title_jp" value="<?php echo h($editGame['title_jp'] ?? ''); ?>">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">罗马音</label>
                                    <input type="text" name="romaji" class="form-input" id="romaji" value="<?php echo h($editGame['romaji'] ?? ''); ?>">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">别名</label>
                                    <input type="text" name="aliases" class="form-input" id="aliases" value="<?php echo h($editGame['aliases'] ?? ''); ?>">
                                    <small class="text-muted">多个别名用逗号分隔</small>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">开发商</label>
                                    <input type="text" name="developer" class="form-input" id="developer" value="<?php echo h($editGame['developer'] ?? ''); ?>">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">发售日</label>
                                    <input type="date" name="release_date" class="form-input" id="release_date" value="<?php echo h($editGame['release_date'] ?? ''); ?>">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">封面图片</label>
                                    <div class="alert-error" style="margin-bottom: 12px; font-size: 13px; line-height: 1.6;">
                                        <strong>R18 封面禁止使用！</strong>无论使用哪种方式提供封面，均禁止包含色情、裸露等不适内容。
                                    </div>

                                    <?php if ($action === 'edit' && $editGame): ?>
                                        <?php $editCoverUrl = getCoverUrl($editGame, true); ?>
                                        <?php if ($editCoverUrl): ?>
                                            <div style="margin-bottom: 12px; display: flex; align-items: flex-start; gap: 12px;">
                                                <img src="<?php echo h($editCoverUrl); ?>" alt="当前封面" style="width: 80px; height: 112px; object-fit: cover; border-radius: 4px; border: 1px solid var(--glass-border);">
                                                <span class="text-muted" style="font-size: 13px;">当前封面</span>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <div style="margin-bottom: 10px;">
                                        <?php if ($action === 'edit' && $editGame): ?>
                                            <label style="margin-right: 15px; font-weight: normal; cursor: pointer;">
                                                <input type="radio" name="cover_type" value="keep" checked onchange="toggleAdminCoverInput()"> 保留当前封面
                                            </label>
                                        <?php endif; ?>
                                        <label style="margin-right: 15px; font-weight: normal; cursor: pointer;">
                                            <input type="radio" name="cover_type" value="vndb" <?php echo $action !== 'edit' ? 'checked' : ''; ?> onchange="toggleAdminCoverInput()"> 使用 VNDB 封面
                                        </label>
                                        <label style="margin-right: 15px; font-weight: normal; cursor: pointer;">
                                            <input type="radio" name="cover_type" value="url" onchange="toggleAdminCoverInput()"> 输入图片 URL
                                        </label>
                                        <label style="font-weight: normal; cursor: pointer;">
                                            <input type="radio" name="cover_type" value="upload" onchange="toggleAdminCoverInput()"> 上传本地图片
                                        </label>
                                    </div>
                                    <!-- 各封面类型的提示信息 -->
                                    <div id="admin_cover_tip" class="cover-tip"></div>
                                    <!-- 裁剪后路径隐藏字段 -->
                                    <input type="hidden" name="cropped_cover_path" id="admin_cropped_cover_path" value="">
                                    <div class="cover-crop-mode" id="admin_cover_crop_mode" style="display: <?php echo $action === 'edit' ? 'none' : ''; ?>;">
                                        <span class="text-muted">裁剪方向：</span>
                                        <label class="radio-label"><input type="radio" name="admin_cover_crop_orientation" value="portrait" checked onchange="setAdminCoverCropOrientation('portrait')"> 竖屏封面</label>
                                        <label class="radio-label"><input type="radio" name="admin_cover_crop_orientation" value="landscape" onchange="setAdminCoverCropOrientation('landscape')"> 横屏封面</label>
                                    </div>
                                    <div id="admin_cover_vndb_wrap" style="display: <?php echo $action === 'edit' ? 'none' : ''; ?>;">
                                        <input type="hidden" name="cover_vndb_url" id="cover_vndb_url" value="">
                                        <div id="admin_vndb_cover_preview" class="text-muted">点击上方"获取信息"后将自动获取 VNDB 封面，R18 封面会被自动过滤</div>
                                        <div id="admin_vndb_crop_btn_wrap" style="display: none; margin-top: 8px;">
                                            <button type="button" class="btn-crop" onclick="adminOpenCropperVndb()">裁剪封面</button>
                                        </div>
                                    </div>
                                    <div id="admin_cover_url_wrap" style="display: none;">
                                        <div style="display: flex; gap: 10px; margin-bottom: 8px;">
                                            <input type="url" name="cover_url" class="form-input" id="cover_url" placeholder="输入图片链接地址">
                                            <button type="button" class="btn-crop" onclick="adminLoadAndCropUrl()">加载并裁剪</button>
                                        </div>
                                        <div id="admin_url_preview" style="display: none;"></div>
                                    </div>
                                    <div id="admin_cover_file_wrap" style="display: none;">
                                        <input type="file" name="cover_file" class="form-input" id="cover_file" accept="image/jpeg,image/png" onchange="adminOnFileSelected(this)">
                                        <div id="admin_file_crop_btn_wrap" style="display: none; margin-top: 8px;">
                                            <button type="button" class="btn-crop" onclick="adminOpenCropperFile()">裁剪封面</button>
                                        </div>
                                    </div>
                                    <!-- 裁剪状态 -->
                                    <div id="admin_crop_status" class="crop-status" style="display: none;"></div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">平台</label>
                                    <input type="text" name="platforms" class="form-input" id="platforms" placeholder="例如：Windows, Linux" value="<?php echo h($editGame['platforms'] ?? ''); ?>">
                                </div>

                                <div class="form-group">
                                    <div class="btn-group">
                                        <button type="submit" class="btn"><?php echo $action === 'edit' ? '更新游戏' : '添加游戏'; ?></button>
                                        <a href="games.php" class="btn btn-secondary">取消</a>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php elseif ($action === 'view' && $viewGame): ?>
                    <!-- 查看游戏详情 -->
                    <div class="card">
                        <div class="card-header">
                            游戏详情
                            <span class="status status-<?php echo $viewGame['status']; ?>">
                                <?php echo $statusText[$viewGame['status']] ?? $viewGame['status']; ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 20px;">
                                <?php $viewCoverUrl = getCoverUrl($viewGame, true); ?>
                                <?php if ($viewCoverUrl): ?>
                                    <div style="flex-shrink: 0;">
                                        <img src="<?php echo h($viewCoverUrl); ?>" alt="封面" style="width: 200px; height: 280px; object-fit: cover; border-radius: 6px; border: 1px solid var(--glass-border);">
                                    </div>
                                <?php endif; ?>
                                <div style="flex: 1; min-width: 300px;">
                                    <p style="margin-bottom: 10px;"><strong>VNDB ID：</strong>
                                        <?php if ($viewGame['vndb_id']): ?>
                                            <a href="https://vndb.org/<?php echo h($viewGame['vndb_id']); ?>" target="_blank"><?php echo h($viewGame['vndb_id']); ?></a>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </p>
                                    <p style="margin-bottom: 10px;"><strong>游戏标题：</strong><?php echo h($viewGame['title']); ?></p>
                                    <?php if ($viewGame['title_jp']): ?>
                                        <p style="margin-bottom: 10px;"><strong>日文原名：</strong><?php echo h($viewGame['title_jp']); ?></p>
                                    <?php endif; ?>
                                    <?php if ($viewGame['romaji']): ?>
                                        <p style="margin-bottom: 10px;"><strong>罗马音：</strong><?php echo h($viewGame['romaji']); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($viewGame['aliases'])): ?>
                                        <p style="margin-bottom: 10px;"><strong>别名：</strong><?php echo h($viewGame['aliases']); ?></p>
                                    <?php endif; ?>
                                    <?php if ($viewGame['developer']): ?>
                                        <p style="margin-bottom: 10px;"><strong>开发商：</strong><?php echo h($viewGame['developer']); ?></p>
                                    <?php endif; ?>
                                    <?php if ($viewGame['release_date']): ?>
                                        <p style="margin-bottom: 10px;"><strong>发售日：</strong><?php echo h($viewGame['release_date']); ?></p>
                                    <?php endif; ?>
                                    <?php if ($viewGame['platforms']): ?>
                                        <p style="margin-bottom: 10px;"><strong>平台：</strong><?php echo h($viewGame['platforms']); ?></p>
                                    <?php endif; ?>
                                    <p style="margin-bottom: 10px;"><strong>提交者：</strong><?php echo h($viewGame['submitter_name'] ?? '匿名用户'); ?></p>
                                    <p style="margin-bottom: 10px;"><strong>提交时间：</strong><?php echo date('Y-m-d H:i:s', strtotime($viewGame['created_at'])); ?></p>
                                </div>
                            </div>

                            <div class="btn-group">
                                <?php if ($viewGame['status'] === 'pending'): ?>
                                    <a href="?action=approve&id=<?php echo $viewGame['id']; ?>" class="btn btn-success<?php echo pd('game_review','edit'); ?>"<?php echo pdAttr('game_review','edit'); ?>>通过审核</a>
                                    <button type="button" class="btn btn-danger<?php echo pd('game_review','edit'); ?>"<?php echo pdBtnAttr('game_review','edit'); ?> onclick="<?php echo hasPermission('game_review','edit') ? "openRejectModal('?action=reject&id=" . $viewGame['id'] . "')" : ''; ?>">拒绝</button>
                                <?php endif; ?>
                                <a href="?action=edit&id=<?php echo $viewGame['id']; ?>" class="btn btn-secondary<?php echo pd('games','edit'); ?>"<?php echo pdAttr('games','edit'); ?>>编辑</a>
                                <a href="?action=delete&id=<?php echo $viewGame['id']; ?>" class="btn btn-danger<?php echo pd('games','delete'); ?>"<?php echo pdAttr('games','delete'); ?> onclick="<?php echo hasPermission('games','delete') ? "return confirm('确定要删除这个游戏吗？')" : 'return false;'; ?>">删除</a>
                                <a href="games.php<?php echo $status ? '?status=' . h($status) : ''; ?>" class="btn btn-secondary">返回列表</a>
                            </div>

                            <?php if (!empty($viewGame['reject_reason'])): ?>
                                <div class="info-block info-block-danger" style="margin-top:16px;">
                                    <strong>拒绝理由：</strong><?php echo h($viewGame['reject_reason']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- 统计卡片 -->
                    <div class="admin-stats-grid">
                        <div class="card stat-card"><div class="card-body">
                            <h3 class="stat-number stat-number--cyan"><?php echo $gameStats['total']; ?></h3>
                            <p class="stat-label">总数</p>
                        </div></div>
                        <div class="card stat-card"><div class="card-body">
                            <h3 class="stat-number stat-number--yellow"><?php echo $gameStats['pending']; ?></h3>
                            <p class="stat-label">待审核</p>
                        </div></div>
                        <div class="card stat-card"><div class="card-body">
                            <h3 class="stat-number stat-number--green"><?php echo $gameStats['approved']; ?></h3>
                            <p class="stat-label">已通过</p>
                        </div></div>
                        <div class="card stat-card"><div class="card-body">
                            <h3 class="stat-number stat-number--red"><?php echo $gameStats['rejected']; ?></h3>
                            <p class="stat-label">已拒绝</p>
                        </div></div>
                        <div class="card stat-card"><div class="card-body">
                            <h3 class="stat-number stat-number--yellow"><?php echo $pendingGameRevisionCount; ?></h3>
                            <p class="stat-label">待审修改</p>
                        </div></div>
                    </div>

                    <!-- 筛选标签 -->
                    <div class="btn-group" style="margin-bottom:20px;">
                        <a href="games.php" class="btn <?php echo !$status ? 'btn-success' : 'btn-secondary'; ?>">全部</a>
                        <a href="games.php?status=pending" class="btn <?php echo $status === 'pending' ? 'btn-success' : 'btn-secondary'; ?>">
                            待审核
                            <?php if ($gameStats['pending'] > 0): ?>
                                <span style="background:#e74c3c;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;margin-left:4px;"><?php echo $gameStats['pending']; ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="games.php?status=approved" class="btn <?php echo $status === 'approved' ? 'btn-success' : 'btn-secondary'; ?>">已通过</a>
                        <a href="games.php?status=rejected" class="btn <?php echo $status === 'rejected' ? 'btn-success' : 'btn-secondary'; ?>">已拒绝</a>
                    </div>

                    <!-- 游戏列表 -->
                    <?php if (!empty($pendingGameRevisions)): ?>
                    <div class="card" style="margin-bottom:20px;">
                        <div class="card-header">待审核游戏修改</div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table">
                                    <thead><tr><th>游戏</th><th>提交者</th><th>提交时间</th><th>变更摘要</th><th>操作</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($pendingGameRevisions as $rev): ?>
                                        <?php
                                        $oldD = json_decode($rev['old_data'], true) ?: [];
                                        $newD = json_decode($rev['new_data'], true) ?: [];
                                        $changed = [];
                                        foreach (['title'=>'标题','title_jp'=>'日文名','romaji'=>'罗马音','aliases'=>'别名','developer'=>'开发商','release_date'=>'发售日','platforms'=>'平台','cover_image'=>'封面'] as $field => $label) {
                                            if (($oldD[$field] ?? '') !== ($newD[$field] ?? '')) $changed[] = $label;
                                        }
                                        ?>
                                        <tr>
                                            <td><a href="<?php echo urlGame((int)$rev['game_id']); ?>" target="_blank"><?php echo h($rev['game_title']); ?></a></td>
                                            <td><?php echo h($rev['submitter_name'] ?? '匿名用户'); ?></td>
                                            <td><?php echo date('Y-m-d H:i', strtotime($rev['created_at'])); ?></td>
                                            <td><?php echo h(implode('、', $changed) ?: '无'); ?></td>
                                            <td>
                                                <a href="?action=approve_revision&rev_id=<?php echo $rev['id']; ?>" class="btn btn-success<?php echo pd('game_review','edit'); ?>" style="font-size:12px;padding:4px 8px;"<?php echo pdAttr('game_review','edit'); ?> onclick="<?php echo hasPermission('game_review','edit') ? "return confirm('确定通过此游戏修改？修改将直接应用。')" : 'return false;'; ?>">通过</a>
                                                <button type="button" class="btn btn-danger<?php echo pd('game_review','edit'); ?>" style="font-size:12px;padding:4px 8px;"<?php echo pdBtnAttr('game_review','edit'); ?> onclick="<?php echo hasPermission('game_review','edit') ? "openRejectModal('?action=reject_revision&rev_id=" . $rev['id'] . "')" : ''; ?>">拒绝</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- 游戏列表 -->
                    <div class="card">
                        <div class="card-header">
                            游戏列表
                            <span class="text-muted" style="float: right; font-weight: normal;">
                                共 <?php echo $total; ?> 条记录
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>封面</th>
                                            <th>游戏信息</th>
                                            <th>VNDB ID</th>
                                            <th>状态</th>
                                            <th>提交时间</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($games)): ?>
                                            <tr><td colspan="6" class="text-center text-muted" style="padding: 40px;">暂无数据</td></tr>
                                        <?php endif; ?>
                                        <?php foreach ($games as $game): ?>
                                            <tr>
                                                <td>
                                                    <?php $coverUrl = getCoverUrl($game, true); ?>
                                                    <?php if ($coverUrl): ?>
                                                        <img src="<?php echo h($coverUrl); ?>" alt="<?php echo hs($game['title']); ?>" style="width: 50px; height: 70px; object-fit: cover; border-radius: 4px;">
                                                    <?php else: ?>
                                                        <div style="width: 50px; height: 70px; background: var(--glass-bg); border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 10px;" class="text-muted">无</div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <strong><?php echo hs($game['title']); ?></strong>
                                                    <?php if ($game['title_jp']): ?>
                                                        <br><small class="text-muted"><?php echo h($game['title_jp']); ?></small>
                                                    <?php endif; ?>
                                                    <?php if ($game['developer']): ?>
                                                        <br><small class="text-muted"><?php echo h($game['developer']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($game['vndb_id']): ?>
                                                        <a href="https://vndb.org/<?php echo h($game['vndb_id']); ?>" target="_blank">
                                                            <?php echo h($game['vndb_id']); ?>
                                                        </a>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="status status-<?php echo $game['status']; ?>">
                                                        <?php echo $statusText[$game['status']] ?? $game['status']; ?>
                                                    </span>
                                                    <?php if (!empty($game['has_pending_revision'])): ?>
                                                        <br><small class="text-muted">有待审修改</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo date('Y-m-d H:i', strtotime($game['created_at'])); ?></td>
                                                <td>
                            <a href="?action=view&id=<?php echo $game['id']; ?><?php echo $status ? '&status=' . h($status) : ''; ?>" class="btn" style="font-size: 12px; padding: 4px 8px;">查看</a>
                                                    <a href="?action=edit&id=<?php echo $game['id']; ?>" class="btn btn-secondary<?php echo pd('games','edit'); ?>" style="font-size: 12px; padding: 4px 8px;"<?php echo pdAttr('games','edit'); ?>>编辑</a>
                                                    <?php if ($game['status'] === 'pending'): ?>
                                                        <a href="?action=approve&id=<?php echo $game['id']; ?>" class="btn btn-success<?php echo pd('game_review','edit'); ?>" style="font-size: 12px; padding: 4px 8px;"<?php echo pdAttr('game_review','edit'); ?>>通过</a>
                                                        <button type="button" class="btn btn-danger<?php echo pd('game_review','edit'); ?>" style="font-size: 12px; padding: 4px 8px;"<?php echo pdBtnAttr('game_review','edit'); ?> onclick="<?php echo hasPermission('game_review','edit') ? "openRejectModal('?action=reject&id=" . $game['id'] . "')" : ''; ?>">拒绝</button>
                                                    <?php endif; ?>
                                                    <a href="?action=delete&id=<?php echo $game['id']; ?>" class="btn btn-danger<?php echo pd('games','delete'); ?>" style="font-size: 12px; padding: 4px 8px;"<?php echo pdAttr('games','delete'); ?> onclick="<?php echo hasPermission('games','delete') ? "return confirm('确定要删除这个游戏吗？')" : 'return false;'; ?>">删除</a>
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
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"></script>
    <script src="/assets/js/cover-cropper.js?v=<?php echo ASSETS_VER; ?>"></script>
    <script>
        // 初始化裁剪组件
        var adminCropper = new CoverCropper({
            uploadUrl: '?action=upload_cropped',
            proxyUrl: '/image_proxy?url=',
            croppedPathInput: 'admin_cropped_cover_path',
            cropStatusId: 'admin_crop_status',
            aspectRatio: 5 / 7,
            outputWidth: 500,
            title: '裁剪游戏封面',
            confirmText: '确认封面裁剪',
            outputQuality: 0.9,
            onCropped: function(path, base64Data) {
                var statusEl = document.getElementById('admin_crop_status');
                statusEl.style.display = '';
                statusEl.innerHTML = '<img class="crop-preview-thumb" src="' + base64Data + '"> 封面已裁剪';
            }
        });

        var adminCurrentVndbCoverUrl = '';
        var adminCurrentSelectedFile = null;
        var adminCurrentCoverOrientation = 'portrait';

        function setAdminCoverCropOrientation(orientation) {
            adminCurrentCoverOrientation = orientation === 'landscape' ? 'landscape' : 'portrait';
            if (adminCurrentCoverOrientation === 'landscape') {
                adminCropper.setAspectRatio(16 / 9, 960, 540);
            } else {
                adminCropper.setAspectRatio(5 / 7, 500, 700);
            }
            adminCropper.reset();
        }

        setAdminCoverCropOrientation('portrait');

        var adminCoverTips = {
            keep: '将保留当前封面不做更改。若需更换封面，请选择其他选项。',
            vndb: '使用 VNDB 封面：系统将自动获取封面图片，R18 内容自动过滤。获取后可选择竖屏或横屏裁剪。',
            url: '输入图片 URL：输入链接后点击“加载并裁剪”，可选择竖屏或横屏裁剪。禁止 R18 内容。',
            upload: '上传本地图片：支持 JPG/PNG，最大 2MB。选择文件后可选择竖屏或横屏裁剪。禁止 R18 内容。'
        };

        function updateAdminCoverTip(type) {
            var tipDiv = document.getElementById('admin_cover_tip');
            if (tipDiv) {
                tipDiv.textContent = adminCoverTips[type] || '';
                tipDiv.style.display = adminCoverTips[type] ? '' : 'none';
            }
        }

        // 页面加载时显示初始提示
        var isEditMode = !!document.querySelector('input[name="edit_game_id"]');
        updateAdminCoverTip(isEditMode ? 'keep' : 'vndb');

        // VNDB 封面裁剪
        function adminOpenCropperVndb() {
            if (adminCurrentVndbCoverUrl) {
                adminCropper.open(adminCurrentVndbCoverUrl, 'vndb');
            }
        }

        // URL 封面加载并裁剪
        function adminLoadAndCropUrl() {
            var url = document.getElementById('cover_url').value.trim();
            if (!url) {
                alert('请先输入图片链接地址');
                return;
            }
            adminCropper.open(url, 'url');
        }

        // 本地文件选择
        function adminOnFileSelected(input) {
            if (input.files && input.files[0]) {
                adminCurrentSelectedFile = input.files[0];
                document.getElementById('admin_file_crop_btn_wrap').style.display = '';
                adminCropper.reset();
            } else {
                adminCurrentSelectedFile = null;
                document.getElementById('admin_file_crop_btn_wrap').style.display = 'none';
            }
        }

        // 本地文件裁剪
        function adminOpenCropperFile() {
            if (adminCurrentSelectedFile) {
                adminCropper.open(adminCurrentSelectedFile, 'file');
            }
        }

        function toggleAdminCoverInput() {
            var type = document.querySelector('input[name="cover_type"]:checked').value;
            document.getElementById('admin_cover_vndb_wrap').style.display = type === 'vndb' ? '' : 'none';
            document.getElementById('admin_cover_url_wrap').style.display = type === 'url' ? '' : 'none';
            document.getElementById('admin_cover_file_wrap').style.display = type === 'upload' ? '' : 'none';
            var cropMode = document.getElementById('admin_cover_crop_mode');
            if (cropMode) cropMode.style.display = type === 'keep' ? 'none' : '';
            if (type !== 'upload') {
                var coverFileEl = document.getElementById('cover_file');
                if (coverFileEl) {
                    coverFileEl.value = '';
                }
                adminCurrentSelectedFile = null;
                var fileCropBtn = document.getElementById('admin_file_crop_btn_wrap');
                if (fileCropBtn) fileCropBtn.style.display = 'none';
            }
            if (type !== 'url') {
                var coverUrlEl = document.getElementById('cover_url');
                if (coverUrlEl) coverUrlEl.value = '';
                var urlPreview = document.getElementById('admin_url_preview');
                if (urlPreview) urlPreview.style.display = 'none';
            }
            updateAdminCoverTip(type);
            // 切换封面类型时清空裁剪状态（keep除外）
            if (type !== 'keep') {
                adminCropper.reset();
            }
        }

        function fetchVNDBData() {
            var vndbId = document.getElementById('vndb_id').value.trim();
            if (!vndbId) {
                alert('请输入VNDB ID');
                return;
            }

            // 重置裁剪状态
            adminCropper.reset();
            adminCurrentVndbCoverUrl = '';
            document.getElementById('admin_vndb_crop_btn_wrap').style.display = 'none';

            fetch('?action=fetch_vndb', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'vndb_id=' + encodeURIComponent(vndbId)
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    // 标题优先级：中文 → 日文 → 英文/罗马音
                    var displayTitle = data.data.title_zh || data.data.title_jp || data.data.title || '';
                    document.getElementById('title').value = displayTitle;
                    document.getElementById('title_jp').value = data.data.title_jp || '';
                    document.getElementById('romaji').value = data.data.romaji || '';
                    document.getElementById('aliases').value = data.data.aliases || '';
                    document.getElementById('developer').value = data.data.developer || '';
                    document.getElementById('release_date').value = data.data.release_date || '';
                    document.getElementById('platforms').value = data.data.platforms || '';

                    // 处理 VNDB 封面
                    var coverUrl = data.data.cover_url || '';
                    var coverSexual = data.data.cover_sexual || 0;
                    var previewDiv = document.getElementById('admin_vndb_cover_preview');

                    if (coverUrl && coverSexual >= 1.3) {
                        // R18 封面，拦截
                        document.getElementById('cover_vndb_url').value = '';
                        adminCurrentVndbCoverUrl = '';
                        document.getElementById('admin_vndb_crop_btn_wrap').style.display = 'none';
                        previewDiv.innerHTML = '<div class="alert-error" style="margin-top: 0;">'
                            + 'VNDB 封面含有不适内容（sexual=' + coverSexual.toFixed(1) + '），已自动过滤。请使用其他方式提供健全封面。</div>';
                    } else if (coverUrl) {
                        document.getElementById('cover_vndb_url').value = coverUrl;
                        adminCurrentVndbCoverUrl = coverUrl;
                        document.getElementById('admin_vndb_crop_btn_wrap').style.display = '';
                        previewDiv.innerHTML = '<div style="display: flex; align-items: flex-start; gap: 12px;">'
                            + '<img src="' + coverUrl + '" style="width: 100px; height: 140px; object-fit: cover; border-radius: 4px; border: 1px solid var(--glass-border);">'
                            + '<span style="color: var(--accent-green);">已获取 VNDB 封面（可点击下方按钮裁剪）</span></div>';
                    } else {
                        document.getElementById('cover_vndb_url').value = '';
                        adminCurrentVndbCoverUrl = '';
                        document.getElementById('admin_vndb_crop_btn_wrap').style.display = 'none';
                        previewDiv.innerHTML = '<span class="text-muted">该游戏在 VNDB 上无封面，请手动输入 URL 或上传图片</span>';
                    }

                    alert('游戏信息获取成功');
                } else {
                    alert('获取失败：' + data.message);
                }
            })
            .catch(function(error) {
                alert('请求失败：' + error.message);
            });
        }
    </script>
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
                    <label style="display:block;margin-bottom:8px;font-weight:600;color:var(--text-primary);">拒绝理由 <span style="color:#e74c3c;">*</span></label>
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

