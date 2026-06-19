<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/view.php';

checkLogin();
requirePermission('errors', 'edit');

require_once '../includes/retired_entry.php'; // 一次性入口已下线（任务5）

$pdo = getDB();
$message = '';
$messageType = '';

function hasTableColumn(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

$hasNewColumn = hasTableColumn($pdo, 'error_solutions', 'solution_screenshots');
$hasOldColumn = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_validate_request('admin_form')) {
    try {
        $hasNewColumn = hasTableColumn($pdo, 'error_solutions', 'solution_screenshots');
        if (!$hasNewColumn) {
            $pdo->exec("ALTER TABLE `error_solutions` ADD COLUMN `solution_screenshots` TEXT NULL AFTER `solution`");
            $hasNewColumn = hasTableColumn($pdo, 'error_solutions', 'solution_screenshots');
        }

        if ($hasOldColumn) {
            $stmt = $pdo->query("SELECT e.id, e.solution_screenshots FROM errors e WHERE e.solution_screenshots IS NOT NULL AND TRIM(e.solution_screenshots) <> ''");
            $rows = $stmt->fetchAll();
            $migrated = 0;
            foreach ($rows as $row) {
                $errorId = (int)$row['id'];
                $screenshots = (string)$row['solution_screenshots'];
                $solStmt = $pdo->prepare("SELECT id FROM error_solutions WHERE error_id = ? ORDER BY is_primary DESC, id DESC LIMIT 1");
                $solStmt->execute([$errorId]);
                $solutionRow = $solStmt->fetch();
                if ($solutionRow) {
                    $pdo->prepare("UPDATE error_solutions SET solution_screenshots = ? WHERE id = ?")
                        ->execute([$screenshots, (int)$solutionRow['id']]);
                } else {
                    $pdo->prepare("INSERT INTO error_solutions (error_id, user_id, solution, solution_screenshots, status, is_primary, created_at, updated_at) VALUES (?, 0, '', ?, 'pending', 1, NOW(), NOW())")
                        ->execute([$errorId, $screenshots]);
                }
                $migrated++;
            }
            $message = '方案截图字段已补齐，并迁移了 ' . $migrated . ' 条旧截图数据。';
        } else {
            $message = '方案表截图字段已补齐。旧字段 `errors.solution_screenshots` 不存在，无需迁移。';
        }

        $messageType = 'success';
    } catch (Exception $e) {
        $message = '操作失败：' . $e->getMessage();
        $messageType = 'error';
    }
}

ob_start();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>补齐方案截图字段 - <?php echo h(SITE_NAME); ?> 管理后台</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER . '-' . @filemtime(BASE_PATH . '/assets/css/style.css'); ?>">
    <?php renderAdminHeadScript(); ?>
</head>
<body class="has-fixed-nav">
<?php include '../includes/header.php'; ?>
<div class="admin-layout">
    <?php renderAdminSidebar('ensure_solution_screenshots.php'); ?>
    <div class="admin-content"><main class="admin-main">
        <div class="admin-page-header"><h1>补齐方案截图字段</h1></div>
        <?php if ($message): ?><div class="admin-alert-<?php echo $messageType; ?>"><?php echo h($message); ?></div><?php endif; ?>
        <div class="card"><div class="card-header">说明</div><div class="card-body">
            <p class="text-muted">此工具用于把 `solution_screenshots` 完整迁移到 `error_solutions`。如果新字段不存在，会先自动添加，再把旧截图数据迁入新表。</p>
            <ul>
                <li>新字段：`error_solutions.solution_screenshots`</li>
                <li>旧字段：`errors.solution_screenshots`</li>
            </ul>
            <form method="post" onsubmit="return confirm('确定开始补齐并迁移方案截图字段吗？建议先备份数据库。');">
                <?php echo csrf_input('admin_form'); ?>
                <button type="submit" class="btn btn-warning">开始补齐并迁移</button>
            </form>
        </div></div>
        <div class="card" style="margin-top:20px;">
            <div class="card-header">字段状态</div>
            <div class="card-body">
                <p>方案表字段：<?php echo $hasNewColumn ? '<span class="text-success">已存在</span>' : '<span class="text-danger">缺失</span>'; ?></p>
                <p>旧字段：<?php echo $hasOldColumn ? '<span class="text-warning">仍存在</span>' : '<span class="text-success">已移除</span>'; ?></p>
            </div>
        </div>
    </main></div>
</div>
<?php renderAdminFooterScripts(); ?>
</body></html>
<?php
$pageHtml = ob_get_clean();
view('admin/ensure_solution_screenshots.twig', ['page_html' => $pageHtml]);
