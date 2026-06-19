<?php
/**
 * 数据库迁移命令行入口
 * ------------------------------------------------------------------
 * 用法（在项目根目录）：
 *   php migrate.php           按版本守卫执行（schema 已最新则跳过）
 *   php migrate.php --force    忽略守卫，强制重跑全部迁移
 *
 * 仅允许命令行执行；通过 Web 访问会被拒绝（并已在 nginx 中 deny）。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("此脚本仅允许通过命令行执行。\n");
}

require __DIR__ . '/includes/config.php';

$force = in_array('--force', $argv, true);

if ($force) {
    fwrite(STDOUT, "[migrate] 强制重跑全部迁移...\n");
} else {
    fwrite(STDOUT, "[migrate] 按版本守卫执行迁移（已最新则跳过）...\n");
}

try {
    $result = runSchemaMigrations($force);
    if ($result['ran']) {
        fwrite(STDOUT, "[migrate] 已执行 {$result['count']} 项迁移，schema 版本: {$result['version']}\n");
    } else {
        fwrite(STDOUT, "[migrate] schema 已是最新（{$result['version']}），无需执行。\n");
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "[migrate] 迁移失败：" . $e->getMessage() . "\n");
    fwrite(STDERR, "[migrate] 标记文件未更新，修复后可重试。\n");
    exit(1);
}
