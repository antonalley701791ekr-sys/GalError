<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

checkLogin();
requireSuperAdmin();

require_once '../includes/retired_entry.php'; // 一次性入口已下线（任务5）

$pdo = getDB();

function ensurePrivateMessageSchema(PDO $pdo): void {
    $columns = [];
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM private_messages");
        if ($stmt) {
            foreach ($stmt->fetchAll() as $row) {
                $columns[strtolower($row['Field'])] = true;
            }
        }
    } catch (Throwable $e) {
        return;
    }

    $alter = [];
    if (empty($columns['content_text'])) {
        $alter[] = "ADD COLUMN content_text TEXT NULL AFTER content";
    }
    if (empty($columns['content_images'])) {
        $alter[] = "ADD COLUMN content_images LONGTEXT NULL AFTER content_text";
    }

    if ($alter) {
        $pdo->exec("ALTER TABLE private_messages " . implode(', ', $alter));
    }
}

function decodeLegacyPayload(?string $raw): array {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return ['', []];
    }

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $text = (string)($decoded['text'] ?? '');
        $images = [];
        if (!empty($decoded['images']) && is_array($decoded['images'])) {
            foreach ($decoded['images'] as $img) {
                if (is_string($img) && trim($img) !== '') {
                    $images[] = $img;
                }
            }
        }
        return [$text, array_values(array_unique($images))];
    }

    return [$raw, []];
}

function encodeImages(array $images): ?string {
    if (empty($images)) {
        return null;
    }
    return json_encode(array_values($images), JSON_UNESCAPED_UNICODE);
}

ensurePrivateMessageSchema($pdo);

$total = (int)$pdo->query("SELECT COUNT(*) FROM private_messages")->fetchColumn();
$needsBackfill = (int)$pdo->query("SELECT COUNT(*) FROM private_messages WHERE (content_text IS NULL OR content_text = '' OR content_images IS NULL) AND content IS NOT NULL AND content <> ''")->fetchColumn();

$doMigrate = isset($_POST['start_migrate']);
$dryRun = isset($_POST['dry_run']);
$processed = 0;
$updated = 0;
$unchanged = 0;
$failed = 0;

if ($doMigrate) {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>私信迁移中</title>';
    echo '<link rel="stylesheet" href="/assets/css/style.css?v=' . ASSETS_VER . '"></head><body class="has-fixed-nav">';
    echo '<div style="max-width: 900px; margin: 40px auto; padding: 20px;">';
    echo '<h2 style="color: var(--accent-purple); margin-bottom: 20px;">私信历史消息结构化迁移</h2>';
    echo '<div style="background: var(--glass-bg); border: 1px solid var(--glass-border); border-radius: 8px; padding: 20px; font-family: monospace; font-size: 14px; color: var(--text-secondary);">';
    flush();

    $stmt = $pdo->query("SELECT id, content, content_text, content_images FROM private_messages ORDER BY id ASC");
    $rows = $stmt ? $stmt->fetchAll() : [];

    foreach ($rows as $index => $row) {
        $processed++;
        $id = (int)$row['id'];
        echo '<div>[' . $processed . '/' . count($rows) . '] ID:' . $id . ' ... ';
        flush();

        try {
            $contentText = (string)($row['content_text'] ?? '');
            $contentImages = $row['content_images'] ?? null;
            $legacyContent = (string)($row['content'] ?? '');

            $images = [];
            if (is_string($contentImages) && trim($contentImages) !== '') {
                $decodedImages = json_decode($contentImages, true);
                if (is_array($decodedImages)) {
                    foreach ($decodedImages as $img) {
                        if (is_string($img) && trim($img) !== '') {
                            $images[] = $img;
                        }
                    }
                }
            }

            $legacyWasStructured = false;
            if ($legacyContent !== '') {
                $decoded = json_decode($legacyContent, true);
                if (is_array($decoded)) {
                    $legacyWasStructured = true;
                    if ($contentText === '') {
                        $contentText = (string)($decoded['text'] ?? '');
                    }
                    if (empty($images) && !empty($decoded['images']) && is_array($decoded['images'])) {
                        foreach ($decoded['images'] as $img) {
                            if (is_string($img) && trim($img) !== '') {
                                $images[] = $img;
                            }
                        }
                    }
                }
            }

            $images = array_values(array_unique($images));
            $newContent = json_encode(['text' => $contentText, 'images' => $images], JSON_UNESCAPED_UNICODE);
            $newImages = encodeImages($images);

            $shouldUpdate = ($row['content_text'] !== $contentText)
                || ((string)$row['content_images'] !== (string)$newImages)
                || ($legacyWasStructured && $row['content'] !== $newContent);

            if ($dryRun) {
                if ($shouldUpdate) {
                    echo '<span class="text-warning">需要更新</span></div>';
                    $updated++;
                } else {
                    echo '<span class="text-success">已是最新</span></div>';
                    $unchanged++;
                }
                flush();
                continue;
            }

            if ($shouldUpdate) {
                $stmtUpd = $pdo->prepare("UPDATE private_messages SET content = ?, content_text = ?, content_images = ? WHERE id = ?");
                $stmtUpd->execute([
                    $newContent,
                    $contentText,
                    $newImages,
                    $id
                ]);
                echo '<span class="text-success">已更新</span></div>';
                $updated++;
            } else {
                echo '<span class="text-success">无需更新</span></div>';
                $unchanged++;
            }
        } catch (Throwable $e) {
            echo '<span class="text-danger">失败: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</span></div>';
            $failed++;
        }
        flush();
    }

    echo '</div>';
    echo '<div style="margin-top: 20px; padding: 15px; background: var(--glass-bg); border-radius: 8px; color: var(--text-primary);">';
    echo '<strong>迁移完成：</strong>处理 <span style="color: var(--accent-purple);">' . $processed . '</span> 条，';
    echo '更新 <span style="color: var(--accent-green);">' . $updated . '</span> 条，';
    echo '无需更新 <span style="color: var(--accent-yellow);">' . $unchanged . '</span> 条，';
    echo '失败 <span style="color: var(--accent-red);">' . $failed . '</span> 条</div>';
    echo '<div style="margin-top: 15px;"><a href="migrate_private_messages.php">返回迁移页面</a> | <a href="../private_chat.php">私信页面</a></div>';
    echo '</div></body></html>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>私信迁移 - <?php echo h(SITE_NAME); ?> 管理后台</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER . '-' . @filemtime(BASE_PATH . '/assets/css/style.css'); ?>">
    <?php renderAdminHeadScript(); ?>
</head>
<body class="has-fixed-nav">
    <?php include '../includes/header.php'; ?>
    <div class="admin-layout">
        <?php renderAdminSidebar('migrate_private_messages.php'); ?>
        <div class="admin-content">
            <main class="admin-main">
                <div class="admin-page-header">
                    <h1>私信结构化迁移工具</h1>
                </div>

                <div class="card">
                    <div class="card-header">历史消息批量迁移</div>
                    <div class="card-body">
                        <p style="margin-bottom: 15px;" class="text-muted">将旧 content 字段中的历史消息批量整理为 content_text / content_images 结构，并同步回填兼容 JSON。</p>
                        <div style="margin-bottom: 20px; padding: 15px; background: var(--glass-bg); border-radius: 8px;">
                            <p>消息总数：<strong style="color: var(--accent-purple);"><?php echo $total; ?></strong></p>
                            <p>待回填条数：<strong style="color: var(--accent-yellow);"><?php echo $needsBackfill; ?></strong></p>
                        </div>

                        <form method="post" onsubmit="return confirm('确定开始迁移私信历史消息？建议先执行一次预览。');" style="display:flex; gap:12px; flex-wrap:wrap;">
                            <?php echo csrf_input('admin_form'); ?>
                            <button type="submit" name="dry_run" class="btn btn-secondary" value="1">预览迁移结果</button>
                            <button type="submit" name="start_migrate" class="btn">开始执行迁移</button>
                        </form>

                        <div style="margin-top: 18px; color: var(--text-muted); font-size: 13px; line-height: 1.7;">
                            <p>说明：</p>
                            <ul style="margin: 8px 0 0 18px;">
                                <li>如果 content 本身就是结构化 JSON，会自动拆出文本和图片列表。</li>
                                <li>如果 content 只是纯文本，会保留为 content_text，图片列表为空。</li>
                                <li>执行后会同时更新兼容字段，避免旧页面读取异常。</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    <?php renderAdminFooterScripts(); ?>
</body>
</html>
