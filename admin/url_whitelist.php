<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/sensitive_filter.php';

checkLogin();
requirePermission('url_whitelist', 'view');

$whitelistFile = SENSITIVE_URL_WHITELIST_FILE;
$message = '';
$messageType = '';

function readWhitelistDomains($file) {
    if (!file_exists($file)) {
        return [];
    }
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return [];
    }

    $domains = [];
    foreach ($lines as $line) {
        $domain = trim(mb_strtolower($line, 'UTF-8'));
        if ($domain !== '') {
            $domains[] = $domain;
        }
    }

    $domains = array_values(array_unique($domains));
    sort($domains, SORT_NATURAL | SORT_FLAG_CASE);
    return $domains;
}

function normalizeWhitelistInput($text) {
    $text = str_replace(["\r\n", "\r", ',', ';', '，', '；'], "\n", (string)$text);
    $lines = explode("\n", $text);
    $domains = [];

    foreach ($lines as $line) {
        $domain = trim(mb_strtolower($line, 'UTF-8'));
        if ($domain === '') {
            continue;
        }
        $domain = preg_replace('#^https?://#i', '', $domain);
        $domain = preg_replace('#/.*$#', '', $domain);
        $domain = trim($domain, ". \t\n\r\0\x0B");
        if ($domain === '') {
            continue;
        }
        if (!preg_match('/^[a-z0-9.-]+$/i', $domain)) {
            continue;
        }
        $domains[] = $domain;
    }

    $domains = array_values(array_unique($domains));
    sort($domains, SORT_NATURAL | SORT_FLAG_CASE);
    return $domains;
}

$currentDomains = readWhitelistDomains($whitelistFile);
$formValue = implode("\n", $currentDomains);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission('url_whitelist', 'edit');
    $domains = normalizeWhitelistInput($_POST['domains'] ?? '');
    $content = $domains ? implode(PHP_EOL, $domains) . PHP_EOL : '';

    if (@file_put_contents($whitelistFile, $content, LOCK_EX) !== false) {
        $currentDomains = $domains;
        $formValue = implode("\n", $currentDomains);
        $message = 'URL 白名单已保存';
        $messageType = 'success';
    } else {
        $message = '保存失败，请检查文件写入权限';
        $messageType = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>链接白名单 - <?php echo h(SITE_NAME); ?> 管理后台</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER . '-' . @filemtime(BASE_PATH . '/assets/css/style.css'); ?>">
    <?php renderAdminHeadScript(); ?>
</head>
<body class="has-fixed-nav">
    <?php include '../includes/header.php'; ?>
    <div class="admin-layout">
        <?php renderAdminSidebar('url_whitelist.php'); ?>

        <div class="admin-content">
            <main class="admin-main">
                <div class="admin-page-header" style="display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap;">
                    <div>
                        <h1>链接白名单</h1>
                        <p class="text-muted" style="margin-top:8px;">管理允许自动放行的域名，减少正常网址误伤。</p>
                    </div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <a href="sensitive_logs.php" class="btn btn-secondary">查看命中日志</a>
                    </div>
                </div>

                <?php if ($message): ?>
                    <div class="admin-alert-<?php echo $messageType; ?>">
                        <?php echo h($message); ?>
                    </div>
                <?php endif; ?>

                <div class="card" style="margin-bottom:20px;">
                    <div class="card-header">编辑白名单</div>
                    <div class="card-body">
                        <?php if (!hasPermission('url_whitelist', 'edit')): ?>
                            <div class="admin-alert-warning" style="margin-bottom: 20px;">
                                您没有编辑权限，当前为只读模式。
                            </div>
                        <?php endif; ?>

                        <form method="post">
                            <div class="form-group">
                                <label class="form-label">域名列表</label>
                                <textarea name="domains" class="form-textarea" rows="18" placeholder="每行一个域名，例如 github.com" <?php echo hasPermission('url_whitelist', 'edit') ? '' : 'readonly'; ?>><?php echo h($formValue); ?></textarea>
                                <p class="form-hint">支持每行一个域名，也支持粘贴带协议的网址。保存时会自动转为小写、去重并清理路径。</p>
                            </div>
                            <?php if (hasPermission('url_whitelist', 'edit')): ?>
                                <div class="form-group" style="margin-bottom:0;display:flex;gap:10px;flex-wrap:wrap;">
                                    <button type="submit" class="btn">保存白名单</button>
                                    <a href="url_whitelist.php" class="btn btn-secondary">重新加载</a>
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">当前域名（<?php echo count($currentDomains); ?>）</div>
                    <div class="card-body">
                        <?php if (empty($currentDomains)): ?>
                            <div class="text-center" style="padding:40px;">
                                <span class="text-muted">当前没有白名单域名</span>
                            </div>
                        <?php else: ?>
                            <div style="display:flex;flex-wrap:wrap;gap:10px;">
                                <?php foreach ($currentDomains as $domain): ?>
                                    <span style="display:inline-flex;align-items:center;padding:8px 12px;border-radius:999px;background:var(--glass-bg);border:1px solid var(--glass-border);font-size:13px;word-break:break-all;">
                                        <?php echo h($domain); ?>
                                    </span>
                                <?php endforeach; ?>
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

