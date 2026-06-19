<?php
/**
 * 统一日志工具
 * ------------------------------------------------------------------
 * 目标：让关键链路（尤其外部依赖 VNDB / Resend）出问题时能查到“具体原因”，
 *       并能区分“本地错误”和“外部依赖错误”（约定 ctx.dep = 'external' 表示外部依赖）。
 *
 * 写入：data/logs/{channel}-YYYYMM.log，每行一条 JSON。按月分文件 = 天然轮转；
 *       单文件超过 5MB 时滚动为 .1 备份。
 *
 * 原则：记录日志本身永不抛异常、永不影响主流程（全部 @ 抑制 + try/catch 兜底）。
 *
 * 用法：
 *   galLog('vndb', 'error', 'VNDB 请求失败', ['vndb_id' => 'v123', 'dep' => 'external']);
 *   galLogError('mail', 'Resend 发送失败', ['http' => 429, 'dep' => 'external']);
 */

if (!function_exists('galLogDir')) {
    function galLogDir(): string {
        $base = defined('BASE_PATH') ? BASE_PATH : (__DIR__ . '/../');
        $dir = $base . 'data/logs/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }
}

if (!function_exists('galLog')) {
    function galLog(string $channel, string $level, string $message, array $context = []): void {
        try {
            $channel = preg_replace('/[^A-Za-z0-9_\-]/', '', $channel);
            if ($channel === '') {
                $channel = 'app';
            }
            $dir = galLogDir();
            $file = $dir . $channel . '-' . date('Ym') . '.log';

            // 大小滚动：超过 5MB 备份为 .1（仅保留一份备份，避免占满磁盘）
            if (is_file($file) && @filesize($file) > 5 * 1024 * 1024) {
                @rename($file, $file . '.1');
            }

            $entry = [
                'ts'      => date('c'),
                'level'   => $level,
                'channel' => $channel,
                'msg'     => $message,
            ];
            if (!empty($context)) {
                $entry['ctx'] = $context;
            }
            if (function_exists('getClientIP')) {
                $ip = getClientIP();
                if ($ip !== '') {
                    $entry['ip'] = $ip;
                }
            }

            @file_put_contents(
                $file,
                json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
                FILE_APPEND | LOCK_EX
            );
        } catch (\Throwable $e) {
            // 记录日志失败绝不影响主流程
        }
    }
}

if (!function_exists('galLogError')) {
    function galLogError(string $channel, string $message, array $context = []): void {
        galLog($channel, 'error', $message, $context);
    }
}

if (!function_exists('galLogTail')) {
    /**
     * 读取某 channel 当月日志的最后 $lines 行（供健康检查页展示），返回已解析的数组（新→旧）。
     */
    function galLogTail(string $channel, int $lines = 20): array {
        $channel = preg_replace('/[^A-Za-z0-9_\-]/', '', $channel);
        $file = galLogDir() . $channel . '-' . date('Ym') . '.log';
        if (!is_file($file)) {
            return [];
        }
        $all = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($all)) {
            return [];
        }
        $slice = array_slice($all, -$lines);
        $out = [];
        foreach (array_reverse($slice) as $line) {
            $decoded = json_decode($line, true);
            $out[] = is_array($decoded) ? $decoded : ['raw' => $line];
        }
        return $out;
    }
}
