<?php
require_once 'includes/user_auth.php';
require_once 'includes/markdown.php';

$pdo = getDB();
$id = intval($_GET['id'] ?? 0);

if (!$id) {
    header('Location: /');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ? AND enabled = 1");
$stmt->execute([$id]);
$doc = $stmt->fetch();

if (!$doc) {
    header('Location: /');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($doc['title']); ?> - <?php echo h(getSiteSetting('site_name', SITE_NAME)); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER; ?>">
    <link rel="stylesheet" href="/assets/css/user.css?v=<?php echo ASSETS_VER; ?>">
    <link rel="stylesheet" href="/assets/css/markdown-editor.css">
    <?php renderSiteHead(); ?>
</head>
<body class="has-fixed-nav">
    <?php include 'includes/header.php'; ?>

    <main class="main">
        <div class="container" style="max-width: 900px;">
            <!-- 面包屑 -->
            <div class="doc-detail-breadcrumb">
                <a href="/">首页</a> &gt; <span><?php echo h(mb_substr($doc['title'], 0, 40)); ?></span>
            </div>

            <div class="card">
                <div class="card-body">
                    <div class="doc-detail-header">
                        <h1 class="doc-detail-title"><?php echo h($doc['title']); ?></h1>
                        <?php if ($doc['description']): ?>
                            <p class="doc-detail-desc"><?php echo h($doc['description']); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="doc-detail-content markdown-body">
                        <?php echo md_to_html($doc['content']); ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>
</html>
