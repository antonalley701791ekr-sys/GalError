<?php
/**
 * 系统健康检查
 * ------------------------------------------------------------------
 * Web：需超级管理员登录后访问 /admin/health.php（可加 ?format=json）。
 * CLI：php admin/health.php  —— 输出文本报告，供运维/cron 巡检。
 *
 * 覆盖：PHP 运行环境、数据库连接、schema 版本、密钥加载、运行目录可写、
 *       核心业务表（登录/评论/私信/审核相关）、VNDB 外部依赖状态、最近外部依赖错误日志。
 */

$isCli = (PHP_SAPI === 'cli');

require_once __DIR__ . '/../includes/config.php';
if (!$isCli) {
    require_once __DIR__ . '/../includes/auth.php';
    checkLogin();
    requireSuperAdmin();
}

$checks = [];
$add = function (string $name, string $status, string $detail) use (&$checks) {
    // status: ok | warn | error
    $checks[] = ['name' => $name, 'status' => $status, 'detail' => $detail];
};

// 1) PHP 版本
$add('PHP 版本', version_compare(PHP_VERSION, '7.4', '>=') ? 'ok' : 'warn', PHP_VERSION);

// 2) 关键扩展
foreach (['pdo_mysql', 'gd', 'curl', 'fileinfo', 'mbstring', 'openssl', 'json'] as $ext) {
    $add("扩展 $ext", extension_loaded($ext) ? 'ok' : 'error', extension_loaded($ext) ? '已加载' : '缺失');
}

// 3) 数据库连接
$dbOk = false;
try {
    getDB()->query('SELECT 1');
    $dbOk = true;
    $add('数据库连接', 'ok', DB_HOST . '/' . DB_NAME);
} catch (\Throwable $e) {
    $add('数据库连接', 'error', $e->getMessage());
}

// 4) schema 版本（迁移守卫标记 vs 代码常量）
$marker = BASE_PATH . 'data/.schema_version';
$markerVer = is_file($marker) ? trim((string)@file_get_contents($marker)) : '(无标记文件)';
$codeVer = defined('SCHEMA_VERSION') ? SCHEMA_VERSION : '(未定义)';
$add('schema 版本', $markerVer === $codeVer ? 'ok' : 'warn', "标记=$markerVer / 代码=$codeVer");

// 5) 密钥加载（config.secret.php / 环境变量）
$secretFile = __DIR__ . '/../includes/config.secret.php';
$secretLoaded = (defined('DB_PASS') && DB_PASS !== '');
$add('密钥加载', $secretLoaded ? 'ok' : 'error',
    ($secretLoaded ? '已加载' : '数据库密码为空') . (is_file($secretFile) ? '；config.secret.php 存在' : '；无 config.secret.php（可能用环境变量）'));

// 6) 运行目录可写
foreach (['data/', 'data/logs/', 'data/cache/'] as $rel) {
    $abs = BASE_PATH . $rel;
    if (!is_dir($abs)) {
        $add("目录 $rel", 'warn', '不存在（首次写入时会自动创建）');
    } else {
        $add("目录 $rel", is_writable($abs) ? 'ok' : 'error', is_writable($abs) ? '可写' : '不可写');
    }
}

// 7) 核心业务表（登录/评论/私信/审核）
if ($dbOk) {
    $tables = [
        'users' => '登录/用户', 'errors' => '报错/审核', 'error_solutions' => '解决方案/审核',
        'comments' => '评论', 'private_messages' => '私信', 'messages' => '站内信',
    ];
    foreach ($tables as $t => $label) {
        try {
            $exists = getDB()->query("SHOW TABLES LIKE " . getDB()->quote($t))->rowCount() > 0;
            if (!$exists) {
                $add("表 {$t}（{$label}）", 'error', '不存在');
                continue;
            }
            $cnt = (int)getDB()->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            $add("表 {$t}（{$label}）", 'ok', "$cnt 行");
        } catch (\Throwable $e) {
            $add("表 {$t}（{$label}）", 'error', $e->getMessage());
        }
    }
}

// 8) VNDB 外部依赖状态（熔断器）
if (function_exists('vndbCircuitPublicStatus')) {
    try {
        $vndb = vndbCircuitPublicStatus();
        $open = !empty($vndb['open']) || (!empty($vndb['is_open']));
        $add('VNDB 外部依赖', $open ? 'warn' : 'ok', $open ? '熔断中（暂走稳定兜底通道）' : '正常');
    } catch (\Throwable $e) {
        $add('VNDB 外部依赖', 'warn', '状态读取失败：' . $e->getMessage());
    }
}

// 9) 最近外部依赖错误（VNDB / 邮件）
$recentErrors = [];
if (function_exists('galLogTail')) {
    foreach (['vndb', 'mail'] as $ch) {
        foreach (galLogTail($ch, 5) as $line) {
            if (($line['level'] ?? '') === 'error' || ($line['level'] ?? '') === 'warning') {
                $recentErrors[] = ($line['ts'] ?? '') . " [$ch] " . ($line['msg'] ?? ($line['raw'] ?? ''));
            }
        }
    }
}

// 汇总
$overall = 'ok';
foreach ($checks as $c) {
    if ($c['status'] === 'error') { $overall = 'error'; break; }
    if ($c['status'] === 'warn') { $overall = 'warn'; }
}

$payload = [
    'overall'        => $overall,
    'checked_at'     => date('c'),
    'checks'         => $checks,
    'recent_errors'  => $recentErrors,
];

// ── 输出 ──
if ($isCli || (($_GET['format'] ?? '') === 'json')) {
    if (!$isCli) {
        header('Content-Type: application/json; charset=utf-8');
        if ($overall === 'error') { http_response_code(503); }
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    if ($isCli) {
        exit($overall === 'error' ? 1 : 0);
    }
    exit;
}

// HTML（自包含，不依赖后台布局，避免耦合）
$color = ['ok' => '#16a34a', 'warn' => '#d97706', 'error' => '#dc2626'];
$badge = ['ok' => '正常', 'warn' => '注意', 'error' => '异常'];
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>系统健康检查 - <?php echo h(SITE_NAME); ?></title>
<style>
  body{font:15px/1.6 -apple-system,Segoe UI,"Microsoft YaHei",sans-serif;background:#f6f8fa;margin:0;color:#1f2937}
  .wrap{max-width:840px;margin:32px auto;padding:0 16px}
  .top{display:flex;align-items:center;gap:12px;margin-bottom:20px}
  .pill{display:inline-block;padding:6px 14px;border-radius:999px;color:#fff;font-weight:600}
  table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden}
  th,td{padding:10px 14px;text-align:left;border-bottom:1px solid #f0f0f0;font-size:14px}
  th{background:#fafafa}
  .st{font-weight:600}
  .card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px;margin-top:18px}
  pre{background:#f6f8fa;border:1px solid #eee;border-radius:6px;padding:10px;overflow:auto;font-size:13px}
  a{color:#2563eb;text-decoration:none}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <h1 style="margin:0;font-size:22px">系统健康检查</h1>
    <span class="pill" style="background:<?php echo $color[$overall]; ?>"><?php echo $badge[$overall]; ?></span>
    <span style="color:#6b7280;font-size:13px">检查时间 <?php echo h(date('Y-m-d H:i:s')); ?></span>
    <a style="margin-left:auto" href="?format=json">JSON</a>
    <a href="/admin/index.php">返回后台</a>
  </div>
  <table>
    <thead><tr><th style="width:42%">检查项</th><th style="width:12%">状态</th><th>详情</th></tr></thead>
    <tbody>
    <?php foreach ($checks as $c): ?>
      <tr>
        <td><?php echo h($c['name']); ?></td>
        <td class="st" style="color:<?php echo $color[$c['status']]; ?>"><?php echo $badge[$c['status']]; ?></td>
        <td><?php echo h($c['detail']); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div class="card">
    <h3 style="margin:0 0 10px">最近外部依赖错误（VNDB / 邮件）</h3>
    <?php if (empty($recentErrors)): ?>
      <p style="color:#16a34a;margin:0">近期无外部依赖错误记录。</p>
    <?php else: ?>
      <pre><?php echo h(implode("\n", $recentErrors)); ?></pre>
    <?php endif; ?>
    <p style="color:#6b7280;font-size:13px;margin:10px 0 0">日志位置：<code>data/logs/</code>（按 channel-月份 分文件，命令行可 <code>php admin/health.php</code> 巡检）</p>
  </div>
</div>
</body>
</html>
