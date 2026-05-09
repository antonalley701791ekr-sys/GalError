<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/sensitive_filter.php';

checkLogin();
requirePermission('sensitive_logs', 'view');

$logFile = BASE_PATH . 'data/sensitive_hits.log';
$message = '';
$messageType = '';
$records = [];
$keyword = trim($_GET['keyword'] ?? '');
$domainFilter = trim($_GET['domain'] ?? '');
$limit = max(20, min(200, intval($_GET['limit'] ?? 100)));

function isSensitiveNoiseHitPayloadLocal(array $payload) {
    $matchedWord = trim(mb_strtolower((string)($payload['matched_word'] ?? ''), 'UTF-8'));
    if ($matchedWord === '') {
        return true;
    }
    if (preg_match('/^\d+$/', $matchedWord) === 1) {
        return true;
    }

    static $noiseWords = [
        'www' => true,
        'http' => true,
        'https' => true,
        'html' => true,
        'htm' => true,
        'php' => true,
        'asp' => true,
        'aspx' => true,
        'jsp' => true,
        'com' => true,
        'cn' => true,
        'net' => true,
        'org' => true,
    ];

    return isset($noiseWords[$matchedWord]);
}

$whitelistDomains = array_keys(loadSensitiveUrlWhitelist());
$whitelistWords = array_keys(loadSensitiveWordWhitelist());

if (isset($_POST['cleanup_noise_logs'])) {
    requirePermission('sensitive_logs', 'delete');

    if (!file_exists($logFile)) {
        $message = '当前没有日志文件';
        $messageType = 'error';
    } else {
        $rawLines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($rawLines === false) {
            $message = '读取日志失败，请检查文件权限';
            $messageType = 'error';
        } else {
            $keptLines = [];
            $removedCount = 0;
            foreach ($rawLines as $line) {
                $decoded = json_decode($line, true);
                if (!is_array($decoded)) {
                    $keptLines[] = $line;
                    continue;
                }

                $payload = is_array($decoded['payload'] ?? null) ? $decoded['payload'] : [];
                if (isSensitiveNoiseHitPayloadLocal($payload)) {
                    $removedCount++;
                    continue;
                }

                $keptLines[] = $line;
            }

            $newContent = $keptLines ? implode(PHP_EOL, $keptLines) . PHP_EOL : '';
            if (@file_put_contents($logFile, $newContent, LOCK_EX) !== false) {
                $message = $removedCount > 0 ? "已清理 {$removedCount} 条噪音命中日志" : '没有发现需要清理的噪音命中日志';
                $messageType = 'success';
            } else {
                $message = '清理失败，请检查日志文件写入权限';
                $messageType = 'error';
            }
        }
    }
}

if (isset($_POST['clear_logs'])) {
    requirePermission('sensitive_logs', 'delete');
    if (file_exists($logFile)) {
        if (@file_put_contents($logFile, '') !== false) {
            $message = '日志已清空';
            $messageType = 'success';
        } else {
            $message = '日志清空失败，请检查文件权限';
            $messageType = 'error';
        }
    } else {
        $message = '当前没有日志文件';
        $messageType = 'error';
    }
}

if (isset($_POST['add_selected_domains'])) {
    requirePermission('sensitive_logs', 'add');
    $selectedDomains = normalizeSensitiveWhitelistDomains($_POST['selected_domains'] ?? []);
    if (empty($selectedDomains)) {
        $message = '请先选择要加入白名单的域名';
        $messageType = 'error';
    } else {
        $appendResult = appendSensitiveUrlWhitelist($selectedDomains);
        if ($appendResult['success']) {
            $whitelistDomains = $appendResult['domains'];
            $message = '已将所选域名加入白名单';
            $messageType = 'success';
        } else {
            $message = '加入白名单失败，请检查文件写入权限';
            $messageType = 'error';
        }
    }
}

if (isset($_POST['add_filtered_domains'])) {
    requirePermission('sensitive_logs', 'edit');
    $filteredDomains = normalizeSensitiveWhitelistDomains($_POST['filtered_domains'] ?? []);
    if (empty($filteredDomains)) {
        $message = '当前筛选结果中没有可加入白名单的域名';
        $messageType = 'error';
    } else {
        $appendResult = appendSensitiveUrlWhitelist($filteredDomains);
        if ($appendResult['success']) {
            $whitelistDomains = $appendResult['domains'];
            $message = '已将当前筛选结果中的域名全部加入白名单';
            $messageType = 'success';
        } else {
            $message = '批量加入白名单失败，请检查文件写入权限';
            $messageType = 'error';
        }
    }
}

if (isset($_POST['add_hit_word_whitelist'])) {
    if (!isSuperAdmin()) {
        $message = '仅超级管理员可将命中词加入白名单';
        $messageType = 'error';
    } else {
        $hitWord = trim((string)($_POST['hit_word'] ?? ''));
        $appendWord = appendSensitiveWordWhitelist([$hitWord]);
        if ($hitWord === '') {
            $message = '命中词为空，无法加入白名单';
            $messageType = 'error';
        } elseif ($appendWord['success']) {
            $whitelistWords = $appendWord['words'];
            $message = '已将命中词加入白名单：' . $hitWord;
            $messageType = 'success';
        } else {
            $message = '加入敏感词白名单失败，请检查文件写入权限';
            $messageType = 'error';
        }
    }
}

if (file_exists($logFile)) {
    $rawLines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($rawLines !== false) {
        $rawLines = array_reverse($rawLines);
        foreach ($rawLines as $idx => $line) {
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }

            $payload = $decoded['payload'] ?? [];
            $textPreview = (string)($payload['text_preview'] ?? '');
            $matchedWord = (string)($payload['matched_word'] ?? '');
            $domains = normalizeSensitiveWhitelistDomains($payload['domains'] ?? []);
            $nonWhitelistedDomains = normalizeSensitiveWhitelistDomains($payload['non_whitelisted_domains'] ?? []);

            if ($keyword !== '') {
                $haystack = mb_strtolower($matchedWord . "\n" . $textPreview, 'UTF-8');
                if (mb_stripos($haystack, mb_strtolower($keyword, 'UTF-8'), 0, 'UTF-8') === false) {
                    continue;
                }
            }

            if ($domainFilter !== '') {
                $matched = false;
                foreach ((array)$domains as $domain) {
                    if (mb_stripos((string)$domain, $domainFilter, 0, 'UTF-8') !== false) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    continue;
                }
            }

            $hitUsername = trim((string)($payload['hit_username'] ?? ''));
            if ($hitUsername === '') {
                $hitUsername = '游客';
            }
            $scene = trim((string)($payload['scene'] ?? ''));
            if ($scene === '') {
                $scene = '未知场景';
            }
            $page = trim((string)($payload['page'] ?? ''));
            if ($page === '') {
                $page = (string)($decoded['uri'] ?? '');
            }

            $records[] = [
                'id' => 'log_' . $idx,
                'time' => $decoded['time'] ?? '',
                'hit_username' => $hitUsername,
                'scene' => $scene,
                'page' => $page,
                'ip' => $decoded['ip'] ?? '',
                'uri' => $decoded['uri'] ?? '',
                'method' => $decoded['method'] ?? '',
                'matched_word' => $matchedWord,
                'domains' => $domains,
                'non_whitelisted_domains' => $nonWhitelistedDomains,
                'text_preview' => $textPreview,
            ];

            if (count($records) >= $limit) {
                break;
            }
        }
    }
}

$filteredCandidateDomains = [];
foreach ($records as $record) {
    $filteredCandidateDomains = array_merge($filteredCandidateDomains, $record['non_whitelisted_domains']);
}
$filteredCandidateDomains = normalizeSensitiveWhitelistDomains($filteredCandidateDomains);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>敏感词日志 - <?php echo h(SITE_NAME); ?> 管理后台</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER . '-' . @filemtime(BASE_PATH . '/assets/css/style.css'); ?>">
    <?php renderAdminHeadScript(); ?>
</head>
<body class="has-fixed-nav">
    <?php include '../includes/header.php'; ?>
    <div class="admin-layout">
        <?php renderAdminSidebar('sensitive_logs.php'); ?>

        <div class="admin-content">
            <main class="admin-main">
                <div class="admin-page-header" style="display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap;">
                    <div>
                        <h1>敏感词命中日志</h1>
                        <p class="text-muted" style="margin-top:8px;">用于排查误伤、补充白名单和观察异常提交。</p>
                    </div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                        <a href="url_whitelist.php" class="btn btn-secondary">管理链接白名单</a>
                        <div class="text-muted">当前显示 <?php echo count($records); ?> 条</div>
                    </div>
                </div>

                <?php if ($message): ?>
                    <div class="admin-alert-<?php echo $messageType; ?>">
                        <?php echo h($message); ?>
                    </div>
                <?php endif; ?>

                <div class="card" style="margin-bottom:20px;">
                    <div class="card-header">筛选与操作</div>
                    <div class="card-body">
                        <form method="get" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;align-items:end;">
                            <div class="form-group" style="margin:0;">
                                <label class="form-label">关键词</label>
                                <input type="text" name="keyword" class="form-input" placeholder="匹配词或文本片段" value="<?php echo h($keyword); ?>">
                            </div>
                            <div class="form-group" style="margin:0;">
                                <label class="form-label">域名</label>
                                <input type="text" name="domain" class="form-input" placeholder="如 github.com" value="<?php echo h($domainFilter); ?>">
                            </div>
                            <div class="form-group" style="margin:0;">
                                <label class="form-label">显示条数</label>
                                <select name="limit" class="form-select">
                                    <?php foreach ([20, 50, 100, 200] as $option): ?>
                                        <option value="<?php echo $option; ?>" <?php echo $limit === $option ? 'selected' : ''; ?>><?php echo $option; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" style="margin:0;display:flex;gap:10px;flex-wrap:wrap;">
                                <button type="submit" class="btn">筛选</button>
                                <a href="sensitive_logs.php" class="btn btn-secondary">重置</a>
                            </div>
                        </form>

                        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
                            <?php if (hasPermission('sensitive_logs', 'delete')): ?>
                                <form method="post" onsubmit="return confirm('确定要清理历史噪音命中日志吗？这会删除命中词为纯数字或明显网址噪音词的旧记录。');">
                                    <button type="submit" name="cleanup_noise_logs" value="1" class="btn btn-secondary">清理噪音日志</button>
                                </form>

                                <form method="post" onsubmit="return confirm('确定要清空敏感词日志吗？此操作不可恢复。');">
                                    <button type="submit" name="clear_logs" value="1" class="btn btn-danger">清空日志</button>
                                </form>
                            <?php endif; ?>

                            <?php if (hasPermission('sensitive_logs', 'edit') && !empty($filteredCandidateDomains)): ?>
                                <form method="post">
                                    <?php foreach ($filteredCandidateDomains as $domain): ?>
                                        <input type="hidden" name="filtered_domains[]" value="<?php echo h($domain); ?>">
                                    <?php endforeach; ?>
                                    <button type="submit" name="add_filtered_domains" value="1" class="btn">将当前筛选结果中的域名全部加入白名单</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <form method="post">
                    <input type="hidden" name="hit_word" value="">
                    <div class="card">
                        <div class="card-header" style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;">
                            <span>日志列表</span>
                            <?php if (hasPermission('sensitive_logs', 'add')): ?>
                                <button type="submit" name="add_selected_domains" value="1" class="btn">将勾选域名加入白名单</button>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <?php if (empty($records)): ?>
                                <div class="text-center" style="padding:40px;">
                                    <span class="text-muted">暂无符合条件的日志记录</span>
                                </div>
                            <?php else: ?>
                                <div style="display:flex;flex-direction:column;gap:16px;">
                                    <?php foreach ($records as $record): ?>
                                        <div style="border:1px solid var(--glass-border);border-radius:12px;padding:16px;background:var(--glass-bg);">
                                            <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
                                                <div>
                                                    <div style="font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                                                        <span>命中词：<?php echo h($record['matched_word'] ?: '未知'); ?></span>
                                                        <?php if (isSuperAdmin()): ?>
                                                            <?php $isWordWhitelisted = in_array(mb_strtolower((string)$record['matched_word'], 'UTF-8'), $whitelistWords, true); ?>
                                                            <?php if (!$isWordWhitelisted && $record['matched_word'] !== ''): ?>
                                                                <button type="submit" name="add_hit_word_whitelist" value="1" class="btn btn-secondary btn-sm js-add-hit-word" data-hit-word="<?php echo h($record['matched_word']); ?>">加入敏感词白名单</button>
                                                            <?php else: ?>
                                                                <span class="text-muted" style="font-size:12px;">已在敏感词白名单</span>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="text-muted" style="font-size:13px;margin-top:6px;">
                                                        <?php echo h($record['time'] ?: '-'); ?> · <?php echo h($record['method'] ?: '-'); ?> · 用户：<?php echo h($record['hit_username'] ?: '游客'); ?>
                                                    </div>
                                                </div>
                                                <div class="text-muted" style="font-size:13px;word-break:break-all;max-width:520px;">
                                                    场景：<?php echo h($record['scene'] ?: '未知场景'); ?><br>
                                                    页面：<?php echo h($record['page'] ?: '-'); ?>
                                                </div>
                                            </div>

                                            <div class="admin-stats-grid">
                                                <div>
                                                    <div style="font-size:13px;font-weight:600;margin-bottom:6px;">提取到的域名</div>
                                                    <?php if ($record['domains']): ?>
                                                        <div style="display:flex;flex-wrap:wrap;gap:8px;">
                                                            <?php foreach ($record['domains'] as $domain): ?>
                                                                <label style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;background:var(--glass-bg);border:1px solid var(--glass-border);font-size:13px;word-break:break-all;">
                                                                    <?php if (hasPermission('sensitive_logs', 'add') && in_array($domain, $record['non_whitelisted_domains'], true)): ?>
                                                                        <input type="checkbox" name="selected_domains[]" value="<?php echo h($domain); ?>">
                                                                    <?php endif; ?>
                                                                    <span><?php echo h($domain); ?></span>
                                                                    <?php if (in_array($domain, $whitelistDomains, true)): ?>
                                                                        <span class="text-muted">已在白名单</span>
                                                                    <?php endif; ?>
                                                                </label>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="text-muted" style="font-size:13px;">无</div>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <div style="font-size:13px;font-weight:600;margin-bottom:6px;">非白名单域名</div>
                                                    <div class="text-muted" style="font-size:13px;word-break:break-all;">
                                                        <?php echo $record['non_whitelisted_domains'] ? h(implode(', ', $record['non_whitelisted_domains'])) : '无'; ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <div>
                                                <div style="font-size:13px;font-weight:600;margin-bottom:6px;">文本预览</div>
                                                <div style="white-space:pre-wrap;word-break:break-word;line-height:1.7;background:rgba(0,0,0,0.12);border-radius:10px;padding:12px;font-size:14px;">
                                                    <?php echo h($record['text_preview'] ?: ''); ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </main>
        </div>
    </div>
    <script>
    (function() {
        var hitWordInput = document.querySelector('input[name="hit_word"]');
        if (!hitWordInput) return;
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.js-add-hit-word');
            if (!btn) return;
            hitWordInput.value = btn.getAttribute('data-hit-word') || '';
        });
    })();
    </script>
    <?php renderAdminFooterScripts(); ?>
</body>
</html>

