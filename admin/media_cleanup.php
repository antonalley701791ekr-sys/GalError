<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

checkLogin();
requireSuperAdmin();

$pdo = getDB();
$message = '';
$messageType = 'success';

function parseCsvList($csv) {
    if ($csv === null || $csv === '') return [];
    $arr = array_filter(array_map('trim', explode(',', (string)$csv)));
    return array_values(array_unique($arr));
}

function collectArticleImageRefsFromMarkdown($markdown) {
    $markdown = (string)$markdown;
    if ($markdown === '') return [];

    $refs = [];
    if (preg_match_all('/!\[[^\]]*\]\(([^)]+)\)/u', $markdown, $m)) {
        foreach ($m[1] as $raw) {
            $url = trim((string)$raw);
            if (strpos($url, '/data/articles/') === 0) {
                $refs[] = basename($url);
            } elseif (strpos($url, 'data/articles/') === 0) {
                $refs[] = basename($url);
            }
        }
    }

    return array_values(array_unique($refs));
}

function buildReferencedSet($pdo) {
    $set = [];

    // errors 当前截图
    $rows = $pdo->query("SELECT screenshots, solution_screenshots FROM errors")->fetchAll();
    foreach ($rows as $r) {
        foreach (parseCsvList($r['screenshots'] ?? '') as $f) $set['data/' . $f] = true;
        foreach (parseCsvList($r['solution_screenshots'] ?? '') as $f) $set['data/' . $f] = true;
    }

    // error revisions 历史截图
    $rows = $pdo->query("SELECT old_screenshots, new_screenshots, old_solution_screenshots, new_solution_screenshots FROM error_revisions")->fetchAll();
    foreach ($rows as $r) {
        foreach (parseCsvList($r['old_screenshots'] ?? '') as $f) $set['data/' . $f] = true;
        foreach (parseCsvList($r['new_screenshots'] ?? '') as $f) $set['data/' . $f] = true;
        foreach (parseCsvList($r['old_solution_screenshots'] ?? '') as $f) $set['data/' . $f] = true;
        foreach (parseCsvList($r['new_solution_screenshots'] ?? '') as $f) $set['data/' . $f] = true;
    }

    // articles 当前正文图片
    $rows = $pdo->query("SELECT content FROM articles")->fetchAll();
    foreach ($rows as $r) {
        foreach (collectArticleImageRefsFromMarkdown($r['content'] ?? '') as $f) {
            $set['data/articles/' . $f] = true;
        }
    }

    // article revisions 历史正文图片
    $rows = $pdo->query("SELECT old_content, new_content FROM article_revisions")->fetchAll();
    foreach ($rows as $r) {
        foreach (collectArticleImageRefsFromMarkdown($r['old_content'] ?? '') as $f) $set['data/articles/' . $f] = true;
        foreach (collectArticleImageRefsFromMarkdown($r['new_content'] ?? '') as $f) $set['data/articles/' . $f] = true;
    }

    return $set;
}

function scanFiles($basePath, $subDir) {
    $dir = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . trim($subDir, '/\\');
    if (!is_dir($dir)) return [];

    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','svg'], true)) continue;
        $abs = $file->getPathname();
        $rel = str_replace(['\\','//'], '/', substr($abs, strlen(rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR)));
        $files[] = $rel;
    }
    return $files;
}

$doDelete = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_orphans');
$referenced = buildReferencedSet($pdo);

$scanTargets = ['data', 'data/articles'];
$allFiles = [];
foreach ($scanTargets as $target) {
    foreach (scanFiles(BASE_PATH, $target) as $f) {
        $allFiles[$f] = true;
    }
}
$allFiles = array_keys($allFiles);
sort($allFiles);

$orphans = [];
foreach ($allFiles as $f) {
    if (!isset($referenced[$f])) {
        $orphans[] = $f;
    }
}

$deletedCount = 0;
if ($doDelete) {
    foreach ($orphans as $f) {
        $abs = BASE_PATH . str_replace('/', DIRECTORY_SEPARATOR, $f);
        if (is_file($abs) && @unlink($abs)) {
            $deletedCount++;
        }
    }
    $message = '清理完成：共删除 ' . $deletedCount . ' 个孤儿图片文件。';

    // 删除后刷新结果
    $allFiles = [];
    foreach ($scanTargets as $target) {
        foreach (scanFiles(BASE_PATH, $target) as $f) {
            $allFiles[$f] = true;
        }
    }
    $allFiles = array_keys($allFiles);
    sort($allFiles);
    $orphans = [];
    foreach ($allFiles as $f) {
        if (!isset($referenced[$f])) {
            $orphans[] = $f;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>图片清理 - <?php echo h(SITE_NAME); ?> 管理后台</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER . '-' . @filemtime(BASE_PATH . '/assets/css/style.css'); ?>">
    <?php renderAdminHeadScript(); ?>
</head>
<body class="has-fixed-nav">
<?php include '../includes/header.php'; ?>
<div class="admin-layout">
    <?php renderAdminSidebar('media_cleanup.php'); ?>
    <div class="admin-content">
        <main class="admin-main">
            <div class="admin-page-header"><h1>图片清理（孤儿文件）</h1></div>

            <?php if ($message): ?>
                <div class="admin-alert-<?php echo $messageType; ?>"><?php echo h($message); ?></div>
            <?php endif; ?>

            <div class="card" style="margin-bottom:16px;">
                <div class="card-header">扫描结果</div>
                <div class="card-body">
                    <p>已引用图片：<strong><?php echo count($referenced); ?></strong> 张</p>
                    <p>磁盘图片总数：<strong><?php echo count($allFiles); ?></strong> 张</p>
                    <p>孤儿图片：<strong style="color:#e67e22;"><?php echo count($orphans); ?></strong> 张</p>
                    <form method="post" onsubmit="return confirm('确定删除当前扫描到的孤儿图片吗？此操作不可恢复。');">
                        <input type="hidden" name="action" value="delete_orphans">
                        <button type="submit" class="btn btn-danger" <?php echo empty($orphans) ? 'disabled' : ''; ?>>删除孤儿图片</button>
                        <a href="media_cleanup.php" class="btn btn-secondary">重新扫描</a>
                    </form>
                    <p class="text-muted" style="margin-top:10px;">说明：仅会删除未被 errors / error_revisions / articles / article_revisions 引用的图片。</p>
                </div>
            </div>

            <div class="card">
                <div class="card-header">孤儿图片列表（前 500 条）</div>
                <div class="card-body">
                    <?php if (empty($orphans)): ?>
                        <p class="text-muted">当前未发现孤儿图片。</p>
                    <?php else: ?>
                        <ul style="margin:0;padding-left:20px;max-height:420px;overflow:auto;">
                            <?php foreach (array_slice($orphans, 0, 500) as $f): ?>
                                <li style="margin-bottom:4px;"><?php echo h($f); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if (count($orphans) > 500): ?>
                            <p class="text-muted" style="margin-top:10px;">仅展示前 500 条，请按“删除孤儿图片”或导出后分批处理。</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>
</body>
</html>

