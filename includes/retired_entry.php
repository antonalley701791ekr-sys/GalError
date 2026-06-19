<?php
/**
 * 一次性运维/迁移入口的统一“已下线”守卫。
 * ------------------------------------------------------------------
 * 用法：在入口文件“鉴权之后、执行迁移逻辑之前” require 本文件即可，
 *       它会输出提示并 exit，使其后的迁移代码不再执行（代码本身保留，便于审计/恢复）。
 *
 * 背景：数据库结构迁移已并入正式迁移机制（includes/migrations/runner.php），
 *       请改用命令行 `php migrate.php`。
 *
 * 特殊恢复场景（如导入旧备份后需重跑某个一次性脚本）：
 *       临时移除该入口文件中“require ... retired_entry.php”这一行即可恢复其原有功能。
 */

http_response_code(410); // Gone
if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}
echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
   . '<meta name="viewport" content="width=device-width, initial-scale=1.0"><title>入口已下线</title></head>'
   . '<body style="font:16px/1.7 -apple-system,Segoe UI,Microsoft YaHei,sans-serif;background:#f6f8fa;margin:0">'
   . '<div style="max-width:640px;margin:72px auto;padding:28px;background:#fff;border:1px solid #e5e7eb;border-radius:10px">'
   . '<h2 style="margin:0 0 12px">该运维入口已下线</h2>'
   . '<p style="color:#444">一次性数据库迁移已并入正式迁移机制，请改用命令行执行：</p>'
   . '<pre style="background:#f6f8fa;border:1px solid #eee;padding:12px 14px;border-radius:6px;overflow:auto">'
   . "php migrate.php          # 按版本守卫执行\nphp migrate.php --force   # 强制重跑全部迁移</pre>"
   . '<p style="margin-top:18px"><a href="/admin/index.php" style="color:#2563eb;text-decoration:none">← 返回后台首页</a></p>'
   . '</div></body></html>';
exit;
