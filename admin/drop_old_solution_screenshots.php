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

function hasTableColumn(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

$hasOldColumn = hasTableColumn($pdo, 'errors', 'solution_screenshots');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_validate_request('admin_form')) {
    try {
        if ($hasOldColumn) {
            $pdo->exec("ALTER TABLE `errors` DROP COLUMN `solution_screenshots`");
            $message = '已删除旧字段 `errors.solution_screenshots`。';
            $messageType = 'success';
            $hasOldColumn = false;
        } else {
            $message = '旧字段 `errors.solution_screenshots` 已不存在，无需删除。';
            $messageType = 'success';
        }
    } catch (Exception $e) {
        $message = '删除失败：' . $e->getMessage();
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
    <title>删除旧方案截图字段 - <?php echo h(SITE_NAME); ?> 管理后台</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER . '-' . @filemtime(BASE_PATH . '/assets/css/style.css'); ?>">
    <?php renderAdminHeadScript(); ?>
</head>
<body class="has-fixed-nav">
<?php include '../includes/header.php'; ?>
<div class="admin-layout">
    <?php renderAdminSidebar('drop_old_solution_screenshots.php'); ?>
    <div class="admin-content"><main class="admin-main">
        <div class="admin-page-header"><h1>删除旧方案截图字段</h1></div>
        <?php if ($message): ?><div class="admin-alert-<?php echo $messageType; ?>"><?php echo h($message); ?></div><?php endif; ?>
        <div class="card"><div class="card-header">说明</div><div class="card-body">
            <p class="text-muted">如果你已经确认所有方案截图都迁移到了 `error_solutions.solution_screenshots`，可以在这里删除旧字段 `errors.solution_screenshots`。</p>
            <p>当前状态：<?php echo $hasOldColumn ? '<span class="text-warning">旧字段仍存在</span>' : '<span class="text-success">旧字段已不存在</span>'; ?></p>
            <form method="post" onsubmit="return confirm('确定删除旧字段 errors.solution_screenshots 吗？建议先备份数据库。');">
                <?php echo csrf_input('admin_form'); ?>
                <button type="submit" class="btn btn-danger" <?php echo $hasOldColumn ? '' : 'disabled'; ?>>删除旧字段</button>
            </form>
        </div></div>
    </main></div>
</div>
<?php renderAdminFooterScripts(); ?>
</body></html>
<?php
$pageHtml = ob_get_clean();
view('admin/drop_old_solution_screenshots.twig', ['page_html' => $pageHtml]);
