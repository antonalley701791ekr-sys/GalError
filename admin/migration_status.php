<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/view.php';

checkLogin();
requirePermission('errors', 'view');

$pdo = getDB();

$hasOldSolutionColumn = true;
try {
    $pdo->query("SELECT solution FROM errors LIMIT 1");
} catch (Exception $e) {
    $hasOldSolutionColumn = false;
}

$hasOldSolutionScreenshotsColumn = false;

$hasSolutionScreenshotsColumn = true;
try {
    $pdo->query("SELECT solution_screenshots FROM error_solutions LIMIT 1");
} catch (Exception $e) {
    $hasSolutionScreenshotsColumn = false;
}

$stats = [
    'total_errors' => (int)$pdo->query("SELECT COUNT(*) FROM errors")->fetchColumn(),
    'errors_with_primary_solution' => (int)$pdo->query("SELECT COUNT(DISTINCT e.id) FROM errors e INNER JOIN error_solutions s ON s.error_id = e.id AND s.is_primary = 1 AND s.status = 'approved'")->fetchColumn(),
    'pending_solutions' => (int)$pdo->query("SELECT COUNT(*) FROM error_solutions WHERE status = 'pending'")->fetchColumn(),
    'approved_solutions' => (int)$pdo->query("SELECT COUNT(*) FROM error_solutions WHERE status = 'approved'")->fetchColumn(),
    'rejected_solutions' => (int)$pdo->query("SELECT COUNT(*) FROM error_solutions WHERE status = 'rejected'")->fetchColumn(),
    'old_solution_rows' => $hasOldSolutionColumn ? (int)$pdo->query("SELECT COUNT(*) FROM errors WHERE solution IS NOT NULL AND TRIM(solution) <> ''")->fetchColumn() : 0,
    'old_solution_screenshots_rows' => 0,
    'new_solution_screenshots_rows' => $hasSolutionScreenshotsColumn ? (int)$pdo->query("SELECT COUNT(*) FROM error_solutions WHERE solution_screenshots IS NOT NULL AND TRIM(solution_screenshots) <> ''")->fetchColumn() : 0,
];

$sampleRows = [];
$stmt = $pdo->query("SELECT e.id, e.title, e.status, s.solution AS primary_solution, s.status AS solution_status, s.is_primary
    FROM errors e
    LEFT JOIN error_solutions s ON s.error_id = e.id AND s.is_primary = 1
    ORDER BY e.id DESC
    LIMIT 20");
$sampleRows = $stmt->fetchAll();

ob_start();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>迁移检查 - <?php echo h(SITE_NAME); ?> 管理后台</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER . '-' . @filemtime(BASE_PATH . '/assets/css/style.css'); ?>">
    <?php renderAdminHeadScript(); ?>
</head>
<body class="has-fixed-nav">
<?php include '../includes/header.php'; ?>
<div class="admin-layout">
    <?php renderAdminSidebar('migration_status.php'); ?>
    <div class="admin-content"><main class="admin-main">
        <div class="admin-page-header"><h1>迁移完成检查</h1></div>
        <div class="admin-stats-grid">
            <div class="card stat-card"><div class="card-body"><h3 class="stat-number"><?php echo (int)$stats['total_errors']; ?></h3><p class="stat-label">报错总数</p></div></div>
            <div class="card stat-card"><div class="card-body"><h3 class="stat-number stat-number--green"><?php echo (int)$stats['errors_with_primary_solution']; ?></h3><p class="stat-label">已有主方案的报错</p></div></div>
            <div class="card stat-card"><div class="card-body"><h3 class="stat-number stat-number--yellow"><?php echo (int)$stats['pending_solutions']; ?></h3><p class="stat-label">待审核方案</p></div></div>
            <div class="card stat-card"><div class="card-body"><h3 class="stat-number stat-number--red"><?php echo (int)$stats['rejected_solutions']; ?></h3><p class="stat-label">已拒绝方案</p></div></div>
        </div>

        <div class="card" style="margin-top:20px;">
            <div class="card-header">旧字段兼容检查</div>
            <div class="card-body">
                <?php if ($hasOldSolutionColumn): ?>
                    <div class="admin-alert-warning">数据库仍存在旧字段 `errors.solution`。当前仍在兼容写入，建议确认迁移无误后再决定是否收紧。</div>
                    <p>仍有 <strong><?php echo (int)$stats['old_solution_rows']; ?></strong> 条旧方案内容。</p>
                <?php else: ?>
                    <div class="admin-alert-success">未检测到旧字段 `errors.solution`，说明数据库已进入新结构。</div>
                <?php endif; ?>

                <div class="admin-alert-success" style="margin-top:12px;">旧字段 `errors.solution_screenshots` 已不再参与系统逻辑，可安全清理。</div>
                <p>历史方案截图残留数量：<strong><?php echo (int)$stats['old_solution_screenshots_rows']; ?></strong> 条。</p>

                <?php if ($hasSolutionScreenshotsColumn): ?>
                    <div class="admin-alert-success" style="margin-top:12px;">方案表已包含 `solution_screenshots` 字段，可独立保存方案截图。</div>
                    <p>方案表中有 <strong><?php echo (int)$stats['new_solution_screenshots_rows']; ?></strong> 条截图记录。</p>
                <?php else: ?>
                    <div class="admin-alert-warning" style="margin-top:12px;">方案表尚未包含 `solution_screenshots` 字段，当前会降级为仅保存文字方案。</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card" style="margin-top:20px;">
            <div class="card-header">最近报错样本</div>
            <div class="card-body">
                <div class="table-responsive"><table class="table"><thead><tr><th>ID</th><th>标题</th><th>报错状态</th><th>主方案状态</th><th>主方案内容</th></tr></thead><tbody>
                <?php foreach ($sampleRows as $row): ?>
                    <tr>
                        <td><?php echo (int)$row['id']; ?></td>
                        <td><?php echo h($row['title']); ?></td>
                        <td><?php echo h($row['status']); ?></td>
                        <td><?php echo h($row['solution_status'] ?? '无'); ?></td>
                        <td><?php echo h(mb_substr((string)($row['primary_solution'] ?? ''), 0, 80)); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
            </div>
        </div>
    </main></div>
</div>
<?php renderAdminFooterScripts(); ?>
</body></html>
<?php
$pageHtml = ob_get_clean();
view('admin/migration_status.twig', ['page_html' => $pageHtml]);
