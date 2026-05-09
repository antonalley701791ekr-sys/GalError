<?php
require_once '../includes/config.php';
require_once '../includes/image_utils.php';
require_once '../includes/auth.php';

checkLogin();
requireSuperAdmin();

$pdo = getDB();

// 查询所有远程封面的游戏
$stmt = $pdo->query("SELECT id, title, cover_image FROM games WHERE cover_image LIKE 'http%' ORDER BY id ASC");
$remoteGames = $stmt->fetchAll();
$totalCount = count($remoteGames);

// 查询本地封面丢失但有 vndb_cover_url 可回退的游戏
$stmt2 = $pdo->query("SELECT id, title, cover_image, vndb_cover_url FROM games WHERE cover_image != '' AND cover_image IS NOT NULL AND cover_image NOT LIKE 'http%' AND vndb_cover_url IS NOT NULL AND vndb_cover_url != '' ORDER BY id ASC");
$localGames = $stmt2->fetchAll();
$missingGames = [];
foreach ($localGames as $game) {
    if (!file_exists(BASE_PATH . $game['cover_image'])) {
        $missingGames[] = $game;
    }
}
$missingCount = count($missingGames);

// 执行远程封面迁移
$doMigrate = isset($_POST['start_migrate']);
$successCount = 0;
$failCount = 0;

if ($doMigrate && $totalCount > 0) {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>封面迁移中</title>';
    echo '<link rel="stylesheet" href="/assets/css/style.css?v=' . ASSETS_VER . '"></head><body class="has-fixed-nav">';
    echo '<div style="max-width: 800px; margin: 40px auto; padding: 20px;">';
    echo '<h2 style="color: var(--accent-purple); margin-bottom: 20px;">封面迁移进度</h2>';
    echo '<div style="background: var(--glass-bg); border: 1px solid var(--glass-border); border-radius: 8px; padding: 20px; font-family: monospace; font-size: 14px; color: var(--text-secondary);">';
    flush();

    foreach ($remoteGames as $index => $game) {
        $num = $index + 1;

        $check = $pdo->prepare("SELECT cover_image FROM games WHERE id = ?");
        $check->execute([$game['id']]);
        $current = $check->fetch();
        if (!$current || !isRemoteCoverUrl($current['cover_image'])) {
            echo "<div>[{$num}/{$totalCount}] ID:{$game['id']} - 已是本地路径，跳过</div>";
            flush();
            continue;
        }

        echo "<div>[{$num}/{$totalCount}] ID:{$game['id']} " . htmlspecialchars(mb_substr($game['title'], 0, 30)) . " ... ";
        flush();

        // 迁移前保存 vndb_cover_url 作为回退
        $checkVndb = $pdo->prepare("SELECT vndb_cover_url FROM games WHERE id = ?");
        $checkVndb->execute([$game['id']]);
        $vndbRow = $checkVndb->fetch();
        if (empty($vndbRow['vndb_cover_url']) && preg_match('/vndb\.org/i', $current['cover_image'])) {
            $pdo->prepare("UPDATE games SET vndb_cover_url = ? WHERE id = ?")->execute([$current['cover_image'], $game['id']]);
        }

        $dlResult = downloadRemoteCover($current['cover_image']);
        if ($dlResult['success']) {
            $pdo->prepare("UPDATE games SET cover_image = ? WHERE id = ?")->execute([$dlResult['path'], $game['id']]);
            echo '<span class="text-success">成功</span></div>';
            $successCount++;
        } else {
            echo '<span class="text-danger">失败: ' . htmlspecialchars($dlResult['message']) . '</span></div>';
            $failCount++;
        }
        flush();

        usleep(500000);
    }

    echo '</div>';
    echo '<div style="margin-top: 20px; padding: 15px; background: var(--glass-bg); border-radius: 8px; color: var(--text-primary);">';
    echo "<strong>迁移完成：</strong>成功 <span style=\"color: var(--accent-green);\">{$successCount}</span> 个，";
    echo "失败 <span style=\"color: var(--accent-red);\">{$failCount}</span> 个，";
    echo "总计 {$totalCount} 个</div>";
    echo '<div style="margin-top: 15px;"><a href="migrate_covers.php">返回迁移页面</a> | <a href="games.php">游戏管理</a></div>';
    echo '</div></body></html>';
    exit;
}

// 执行丢失封面重新下载
$doRecover = isset($_POST['start_recover']);
$recoverSuccess = 0;
$recoverFail = 0;

if ($doRecover && $missingCount > 0) {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>封面恢复中</title>';
    echo '<link rel="stylesheet" href="/assets/css/style.css?v=' . ASSETS_VER . '"></head><body class="has-fixed-nav">';
    echo '<div style="max-width: 800px; margin: 40px auto; padding: 20px;">';
    echo '<h2 style="color: var(--accent-yellow); margin-bottom: 20px;">封面恢复进度</h2>';
    echo '<div style="background: var(--glass-bg); border: 1px solid var(--glass-border); border-radius: 8px; padding: 20px; font-family: monospace; font-size: 14px; color: var(--text-secondary);">';
    flush();

    foreach ($missingGames as $index => $game) {
        $num = $index + 1;

        // 再次确认本地文件确实不存在
        if (file_exists(BASE_PATH . $game['cover_image'])) {
            echo "<div>[{$num}/{$missingCount}] ID:{$game['id']} - 文件已存在，跳过</div>";
            flush();
            continue;
        }

        echo "<div>[{$num}/{$missingCount}] ID:{$game['id']} " . htmlspecialchars(mb_substr($game['title'], 0, 30)) . " ... ";
        flush();

        $dlResult = downloadRemoteCover($game['vndb_cover_url']);
        if ($dlResult['success']) {
            $pdo->prepare("UPDATE games SET cover_image = ? WHERE id = ?")->execute([$dlResult['path'], $game['id']]);
            echo '<span style="color: var(--accent-green);">恢复成功</span></div>';
            $recoverSuccess++;
        } else {
            echo '<span style="color: var(--accent-red);">失败: ' . htmlspecialchars($dlResult['message']) . '</span></div>';
            $recoverFail++;
        }
        flush();

        usleep(500000);
    }

    echo '</div>';
    echo '<div style="margin-top: 20px; padding: 15px; background: var(--glass-bg); border-radius: 8px; color: var(--text-primary);">';
    echo "<strong>恢复完成：</strong>成功 <span style=\"color: var(--accent-green);\">{$recoverSuccess}</span> 个，";
    echo "失败 <span style=\"color: var(--accent-red);\">{$recoverFail}</span> 个，";
    echo "总计 {$missingCount} 个</div>";
    echo '<div style="margin-top: 15px;"><a href="migrate_covers.php">返回迁移页面</a> | <a href="games.php">游戏管理</a></div>';
    echo '</div></body></html>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>封面迁移 - <?php echo h(SITE_NAME); ?> 管理后台</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER . '-' . @filemtime(BASE_PATH . '/assets/css/style.css'); ?>">
    <?php renderAdminHeadScript(); ?>
</head>
<body class="has-fixed-nav">
    <?php include '../includes/header.php'; ?>
    <div class="admin-layout">
        <!-- 侧边栏 -->
        <?php renderAdminSidebar('migrate_covers.php'); ?>

        <!-- 主内容区 -->
        <div class="admin-content">
            <main class="admin-main">
                <div class="admin-page-header">
                    <h1>封面迁移工具</h1>
                </div>
                <!-- 远程封面本地化 -->
                <div class="card">
                    <div class="card-header">远程封面本地化</div>
                    <div class="card-body">
                        <p style="margin-bottom: 15px;" class="text-muted">
                            将数据库中存储的远程封面 URL（VNDB CDN 等）下载到本地服务器，加速前台页面加载。
                        </p>

                        <?php if ($totalCount > 0): ?>
                            <div style="margin-bottom: 20px; padding: 15px; background: var(--glass-bg); border-radius: 8px;">
                                <p>发现 <strong style="color: var(--accent-purple);"><?php echo $totalCount; ?></strong> 个游戏使用远程封面 URL，可以迁移到本地。</p>
                            </div>

                            <table class="admin-table" style="margin-bottom: 20px;">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>游戏名称</th>
                                        <th>当前封面 URL</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($remoteGames as $game): ?>
                                    <tr>
                                        <td><?php echo $game['id']; ?></td>
                                        <td><?php echo h(mb_substr($game['title'], 0, 40)); ?></td>
                                        <td style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 12px;" class="text-muted">
                                            <?php echo h($game['cover_image']); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <form method="post" onsubmit="return confirm('确定开始迁移？过程中请勿关闭页面。');">
                                <button type="submit" name="start_migrate" class="btn">开始迁移（预计 <?php echo ceil($totalCount * 1.5); ?> 秒）</button>
                            </form>
                        <?php else: ?>
                            <div class="text-center text-muted" style="padding: 30px;">
                                <p style="font-size: 16px;">所有游戏封面均已本地化，无需迁移。</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 丢失封面恢复 -->
                <div class="card" style="margin-top: 20px;">
                    <div class="card-header">丢失封面恢复</div>
                    <div class="card-body">
                        <p style="margin-bottom: 15px;" class="text-muted">
                            自动检测本地封面文件是否存在，若丢失则从 VNDB 原始 URL 重新下载恢复。
                        </p>

                        <?php if ($missingCount > 0): ?>
                            <div style="margin-bottom: 20px; padding: 15px; background: var(--glass-bg); border-radius: 8px;">
                                <p>发现 <strong style="color: var(--accent-yellow);"><?php echo $missingCount; ?></strong> 个游戏的本地封面文件丢失，可从 VNDB 重新下载。</p>
                            </div>

                            <table class="admin-table" style="margin-bottom: 20px;">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>游戏名称</th>
                                        <th>丢失的本地路径</th>
                                        <th>VNDB 回退 URL</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($missingGames as $game): ?>
                                    <tr>
                                        <td><?php echo $game['id']; ?></td>
                                        <td><?php echo h(mb_substr($game['title'], 0, 30)); ?></td>
                                        <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 12px; color: var(--accent-red);">
                                            <?php echo h($game['cover_image']); ?>
                                        </td>
                                        <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 12px;" class="text-muted">
                                            <?php echo h($game['vndb_cover_url']); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <form method="post" onsubmit="return confirm('确定开始恢复丢失的封面？过程中请勿关闭页面。');">
                                <button type="submit" name="start_recover" class="btn btn-warning">开始恢复（预计 <?php echo ceil($missingCount * 1.5); ?> 秒）</button>
                            </form>
                        <?php else: ?>
                            <div class="text-center text-muted" style="padding: 30px;">
                                <p style="font-size: 16px;">所有本地封面文件完好，无需恢复。</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>
    <?php renderAdminFooterScripts(); ?>
</body>
</html>

