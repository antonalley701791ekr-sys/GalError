<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/view.php';

checkLogin();
requireSuperAdmin();

require_once '../includes/retired_entry.php'; // 一次性入口已下线（任务5）

$pdo = getDB();
$message = '';
$messageType = '';

$stats = [
    'total_errors' => 0,
    'pending_errors' => 0,
    'approved_errors' => 0,
    'rejected_errors' => 0,
    'total_solutions' => 0,
    'pending_solutions' => 0,
    'approved_solutions' => 0,
    'rejected_solutions' => 0,
    'orphan_solutions' => 0,
    'orphan_screenshot_rows' => 0,
    'orphan_revisions' => 0,
    'orphan_revision_screenshot_rows' => 0,
];

$stats['total_errors'] = (int)$pdo->query("SELECT COUNT(*) FROM errors")->fetchColumn();
$stats['pending_errors'] = (int)$pdo->query("SELECT COUNT(*) FROM errors WHERE status = 'pending'")->fetchColumn();
$stats['approved_errors'] = (int)$pdo->query("SELECT COUNT(*) FROM errors WHERE status = 'approved'")->fetchColumn();
$stats['rejected_errors'] = (int)$pdo->query("SELECT COUNT(*) FROM errors WHERE status = 'rejected'")->fetchColumn();
$stats['total_solutions'] = (int)$pdo->query("SELECT COUNT(*) FROM error_solutions")->fetchColumn();
$stats['pending_solutions'] = (int)$pdo->query("SELECT COUNT(*) FROM error_solutions WHERE status = 'pending'")->fetchColumn();
$stats['approved_solutions'] = (int)$pdo->query("SELECT COUNT(*) FROM error_solutions WHERE status = 'approved'")->fetchColumn();
$stats['rejected_solutions'] = (int)$pdo->query("SELECT COUNT(*) FROM error_solutions WHERE status = 'rejected'")->fetchColumn();
$stats['orphan_solutions'] = (int)$pdo->query("SELECT COUNT(*) FROM error_solutions s LEFT JOIN errors e ON e.id = s.error_id WHERE e.id IS NULL")->fetchColumn();
$stats['orphan_screenshot_rows'] = (int)$pdo->query("SELECT COUNT(*) FROM error_solutions s LEFT JOIN errors e ON e.id = s.error_id WHERE e.id IS NULL AND s.solution_screenshots IS NOT NULL AND TRIM(s.solution_screenshots) <> ''")->fetchColumn();
$stats['orphan_revisions'] = (int)$pdo->query("SELECT COUNT(*) FROM error_revisions r LEFT JOIN errors e ON e.id = r.error_id WHERE e.id IS NULL")->fetchColumn();
$stats['orphan_revision_screenshot_rows'] = (int)$pdo->query("SELECT COUNT(*) FROM error_revisions r LEFT JOIN errors e ON e.id = r.error_id WHERE e.id IS NULL AND ((r.new_screenshots IS NOT NULL AND TRIM(r.new_screenshots) <> '') OR (r.new_solution_screenshots IS NOT NULL AND TRIM(r.new_solution_screenshots) <> ''))")->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_validate_request('admin_form')) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'cleanup_orphans') {
            $pdo->beginTransaction();
            $solDel = $pdo->exec("DELETE s FROM error_solutions s LEFT JOIN errors e ON e.id = s.error_id WHERE e.id IS NULL");
            $revDel = $pdo->exec("DELETE r FROM error_revisions r LEFT JOIN errors e ON e.id = r.error_id WHERE e.id IS NULL");
            $pdo->commit();
            $message = '已清理孤儿解决方案 ' . (int)$solDel . ' 条，孤儿修改记录 ' . (int)$revDel . ' 条。';
            $messageType = 'success';
        } elseif ($action === 'recount') {
            $message = '统计已重新计算。';
            $messageType = 'success';
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = '操作失败：' . $e->getMessage();
        $messageType = 'error';
    }
}

$orphanRows = [];
$orphanRowsStmt = $pdo->query("(
    SELECT 'solution' AS source_type, s.id, s.error_id, s.status, s.created_at, LEFT(s.solution, 120) AS content_preview, s.solution_screenshots AS screenshot_preview
    FROM error_solutions s
    LEFT JOIN errors e ON e.id = s.error_id
    WHERE e.id IS NULL
)
UNION ALL
(
    SELECT 'revision' AS source_type, r.id, r.error_id, r.status, r.created_at, LEFT(r.new_data, 120) AS content_preview, CONCAT(IFNULL(r.new_screenshots, ''), CASE WHEN IFNULL(r.new_screenshots, '') <> '' AND IFNULL(r.new_solution_screenshots, '') <> '' THEN ',' ELSE '' END, IFNULL(r.new_solution_screenshots, '')) AS screenshot_preview
    FROM error_revisions r
    LEFT JOIN errors e ON e.id = r.error_id
    WHERE e.id IS NULL
)
ORDER BY created_at DESC
LIMIT 40");
$orphanRows = $orphanRowsStmt->fetchAll();

ob_start();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>统计修正 - <?php echo h(SITE_NAME); ?> 管理后台</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER . '-' . @filemtime(BASE_PATH . '/assets/css/style.css'); ?>">
    <?php renderAdminHeadScript(); ?>
</head>
<body class="has-fixed-nav">
<?php include '../includes/header.php'; ?>
<div class="admin-layout">
    <?php renderAdminSidebar('solution_stats_fix.php'); ?>
    <div class="admin-content"><main class="admin-main">
        <div class="admin-page-header"><h1>所有内容统计修正</h1></div>
        <?php if ($message): ?><div class="admin-alert-<?php echo $messageType; ?>"><?php echo h($message); ?></div><?php endif; ?>
        <div class="admin-stats-grid">
            <div class="card stat-card"><div class="card-body"><h3 class="stat-number stat-number--cyan"><?php echo (int)$stats['total_errors']; ?></h3><p class="stat-label">报错总数</p></div></div>
            <div class="card stat-card"><div class="card-body"><h3 class="stat-number stat-number--yellow"><?php echo (int)$stats['pending_errors']; ?></h3><p class="stat-label">待审核报错</p></div></div>
            <div class="card stat-card"><div class="card-body"><h3 class="stat-number stat-number--green"><?php echo (int)$stats['approved_errors']; ?></h3><p class="stat-label">已通过报错</p></div></div>
            <div class="card stat-card"><div class="card-body"><h3 class="stat-number stat-number--red"><?php echo (int)$stats['rejected_errors']; ?></h3><p class="stat-label">已拒绝报错</p></div></div>
            <div class="card stat-card"><div class="card-body"><h3 class="stat-number stat-number--cyan"><?php echo (int)$stats['total_solutions']; ?></h3><p class="stat-label">方案总数</p></div></div>
            <div class="card stat-card"><div class="card-body"><h3 class="stat-number stat-number--yellow"><?php echo (int)$stats['pending_solutions']; ?></h3><p class="stat-label">待审核方案</p></div></div>
            <div class="card stat-card"><div class="card-body"><h3 class="stat-number stat-number--green"><?php echo (int)$stats['approved_solutions']; ?></h3><p class="stat-label">已通过方案</p></div></div>
            <div class="card stat-card"><div class="card-body"><h3 class="stat-number stat-number--red"><?php echo (int)$stats['rejected_solutions']; ?></h3><p class="stat-label">已拒绝方案</p></div></div>
        </div>

        <div class="card" style="margin-top:20px;">
            <div class="card-header">孤儿数据检查</div>
            <div class="card-body">
                <p>孤儿解决方案：<strong><?php echo (int)$stats['orphan_solutions']; ?></strong> 条</p>
                <p>含截图的孤儿解决方案：<strong><?php echo (int)$stats['orphan_screenshot_rows']; ?></strong> 条</p>
                <p>孤儿修改记录：<strong><?php echo (int)$stats['orphan_revisions']; ?></strong> 条</p>
                <p>含截图的孤儿修改记录：<strong><?php echo (int)$stats['orphan_revision_screenshot_rows']; ?></strong> 条</p>
                <form method="post" style="display:inline-block; margin-right:10px;">
                    <?php echo csrf_input('admin_form'); ?>
                    <input type="hidden" name="action" value="recount">
                    <button type="submit" class="btn btn-secondary">重新计算统计</button>
                </form>
                <form method="post" style="display:inline-block;" onsubmit="return confirm('确定清理所有孤儿解决方案和修改记录吗？建议先备份数据库。');">
                    <?php echo csrf_input('admin_form'); ?>
                    <input type="hidden" name="action" value="cleanup_orphans">
                    <button type="submit" class="btn btn-danger" <?php echo ($stats['orphan_solutions'] > 0 || $stats['orphan_revisions'] > 0) ? '' : 'disabled'; ?>>清理孤儿数据</button>
                </form>
            </div>
        </div>

        <div class="card" style="margin-top:20px;">
            <div class="card-header">最近 40 条孤儿列表</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>类型</th>
                                <th>ID</th>
                                <th>关联报错ID</th>
                                <th>状态</th>
                                <th>内容预览</th>
                                <th>截图预览</th>
                                <th>时间</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($orphanRows as $row): ?>
                            <tr>
                                <td><?php echo $row['source_type'] === 'solution' ? '解决方案' : '修改记录'; ?></td>
                                <td><?php echo (int)$row['id']; ?></td>
                                <td><?php echo (int)$row['error_id']; ?></td>
                                <td><?php echo h($row['status']); ?></td>
                                <td><?php echo h(mb_substr((string)$row['content_preview'], 0, 80)); ?></td>
                                <td><?php echo h(mb_substr((string)$row['screenshot_preview'], 0, 80)); ?></td>
                                <td><?php echo h($row['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main></div>
</div>
<?php renderAdminFooterScripts(); ?>
</body>
</html>
<?php
$pageHtml = ob_get_clean();
view('admin/solution_stats_fix.twig', ['page_html' => $pageHtml]);
