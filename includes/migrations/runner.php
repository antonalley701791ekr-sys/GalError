<?php
/**
 * 数据库迁移运行器
 * ------------------------------------------------------------------
 * 背景：历史上这些 ensureXxx / migrateXxx / dropXxx 函数定义在 includes/config.php 中，
 *       且每次任意页面加载都会逐个执行（约 40+ 次 SHOW/ALTER 检查），既重又分散。
 *
 * 现在：所有迁移仍保持“幂等”（内部先 SHOW 再 ALTER），但改由本运行器集中调度，
 *       并通过“版本守卫”避免每次请求都重复检查 —— 仅当 schema 版本变化时才执行一遍。
 *
 * 使用：
 *   - 网页请求：config.php 在加载末尾调用 runSchemaMigrations()（受守卫，稳态下直接跳过）。
 *   - 命令行：  php migrate.php          按守卫执行（已最新则跳过）
 *              php migrate.php --force   忽略守卫，强制重跑全部迁移
 *
 * 新增迁移的步骤：
 *   1) 在 config.php 中编写新的 ensureXxx() 函数（保持幂等）。
 *   2) 把它追加到下方 $migrations 列表末尾。
 *   3) 递增 config.php 中的 SCHEMA_VERSION 常量。
 *
 * 注意：本文件由 config.php 在“所有迁移函数定义完成后”require，
 *       因此这里只定义 runSchemaMigrations()，调用时机由 config.php 控制。
 */

// 迁移函数定义已集中到 schema.php；本运行器负责其调用顺序与版本守卫。
require_once __DIR__ . '/schema.php';

if (!function_exists('runSchemaMigrations')) {
    /**
     * 按既定顺序执行全部数据库迁移（幂等）。
     *
     * @param bool $force true 时忽略版本守卫，强制重跑。
     * @return array ['ran' => bool, 'version' => string, 'count' => int]
     */
    function runSchemaMigrations(bool $force = false): array {
        $version = defined('SCHEMA_VERSION') ? (string) SCHEMA_VERSION : '0';
        $marker  = (defined('BASE_PATH') ? BASE_PATH : __DIR__ . '/../../') . 'data/.schema_version';

        // 版本守卫：标记文件与当前代码版本一致则跳过（稳态下零 DDL/零 SHOW）
        if (!$force && is_file($marker) && trim((string) @file_get_contents($marker)) === $version) {
            return ['ran' => false, 'version' => $version, 'count' => 0];
        }

        // —— 迁移执行顺序与历史上 config.php 内联调用顺序完全一致，不可随意调整 ——
        // （后面的迁移可能依赖前面创建的表/字段）
        $migrations = [
            'ensureAliasesColumn',
            'ensureUsersTable',
            'ensureGamesUserId',
            'ensureErrorsUserId',
            'ensureUsersBannedColumn',
            'ensureUsersLastActivityColumn',
            'ensureErrorRevisionsTable',
            'ensureErrorRevisionsViewedAt',
            'ensureErrorsEngineInfo',
            'ensureErrorsSystemCategory',
            'ensureErrorsAndroidDeviceColumns',
            'ensureErrorRevisionsEngineInfo',
            'ensureErrorsSolutionScreenshots',
            'ensureErrorSolutionsTable',
            'migrateLegacyErrorSolutions',
            'dropLegacyErrorSolutionColumn',
            'ensureRevisionsSolutionScreenshots',
            'ensureErrorsRejectReason',
            'ensureGamesRejectReason',
            'ensureGamesPendingRevisionColumn',
            'ensureGameRevisionsTable',
            'ensureErrorRevisionsRejectReason',
            'ensureArticlesTable',
            'ensureArticleRevisionsTable',
            'ensureArticlesTocColumn',
            'ensureArticlesPendingRevisionColumn',
            'ensureMessagesTable',
            'ensureViewCountsTable',
            'ensureViewLogsTable',
            'ensureAdminLogsTable',
            'ensureTodosTable',
            'ensureDocumentsTable',
            'ensureDiscussionsTable',
            'ensureCommentsTable',
            'ensureCommentsArticleType',
            'ensureCommentsParentId',
            'ensureRateLimitsTable',
            'ensureViewCountsDiscussionType',
            'ensurePrivateMessagesTable',
            'ensureRememberTokensTable',
            'ensureMessagesLinkUrlColumn',
            'ensureAdminGuideMessageLinks',
            'ensureSitePagesTable',
        ];

        $count = 0;
        foreach ($migrations as $fn) {
            if (function_exists($fn)) {
                // 任何迁移抛异常都让其向上冒泡（与历史行为一致）；
                // 此时不写标记文件，下次加载会自动重试。
                $fn();
                $count++;
            }
        }

        // 全部成功后写入版本标记。写失败（如目录不可写）不致命：
        // 退化为“每次加载都执行”，与改造前行为相同，仍然安全。
        @file_put_contents($marker, $version);

        return ['ran' => true, 'version' => $version, 'count' => $count];
    }
}
