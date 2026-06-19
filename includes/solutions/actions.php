<?php
function solutionsHandleActions(PDO $pdo, array $input): array {
    $message = '';
    $messageType = '';
    $action = $input['action'] ?? '';
    $solutionId = (int)($input['id'] ?? 0);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action && $solutionId > 0) {
        if (!csrf_validate_request('admin_form')) {
            return ['message' => '请求已过期，请刷新后重试', 'messageType' => 'error'];
        }

        $stmt = $pdo->prepare("SELECT * FROM error_solutions WHERE id = ?");
        $stmt->execute([$solutionId]);
        $solution = $stmt->fetch();
        if (!$solution) {
            return ['message' => '解决方案不存在', 'messageType' => 'error'];
        }

        if ($action === 'approve') {
            $pdo->prepare("UPDATE error_solutions SET status = 'approved', updated_at = NOW() WHERE id = ?")->execute([$solutionId]);
            if (!empty($solution['error_id'])) {
                $pdo->prepare("UPDATE error_solutions SET is_primary = 0 WHERE error_id = ? AND id <> ?")->execute([$solution['error_id'], $solutionId]);
                $pdo->prepare("UPDATE error_solutions SET is_primary = 1 WHERE id = ?")->execute([$solutionId]);
            }
            return ['message' => '解决方案已通过', 'messageType' => 'success', 'redirect' => '/admin/solutions.php'];
        }

        if ($action === 'reject') {
            $rejectReason = trim((string)($input['reject_reason'] ?? ''));
            $pdo->prepare("UPDATE error_solutions SET status = 'rejected', updated_at = NOW() WHERE id = ?")->execute([$solutionId]);
            if ($rejectReason !== '') {
                $pdo->prepare("UPDATE error_solutions SET reject_reason = ? WHERE id = ?")->execute([$rejectReason, $solutionId]);
            }
            return ['message' => '解决方案已拒绝', 'messageType' => 'success', 'redirect' => '/admin/solutions.php'];
        }

        if ($action === 'delete') {
            $pdo->beginTransaction();
            try {
                $errorId = (int)($solution['error_id'] ?? 0);
                $pdo->prepare("DELETE FROM error_revisions WHERE solution_id = ?")->execute([$solutionId]);
                $pdo->prepare("DELETE FROM error_solutions WHERE id = ?")->execute([$solutionId]);
                if ($errorId > 0) {
                    $nextPrimaryStmt = $pdo->prepare("SELECT id FROM error_solutions WHERE error_id = ? ORDER BY is_primary DESC, created_at ASC, id ASC LIMIT 1");
                    $nextPrimaryStmt->execute([$errorId]);
                    $nextPrimaryId = (int)$nextPrimaryStmt->fetchColumn();
                    if ($nextPrimaryId > 0) {
                        $pdo->prepare("UPDATE error_solutions SET is_primary = 1, status = 'approved', updated_at = NOW() WHERE id = ?")->execute([$nextPrimaryId]);
                    }
                }
                $pdo->commit();
                return ['message' => '', 'messageType' => 'success', 'redirect' => '/admin/solutions.php'];
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                return ['message' => '删除失败', 'messageType' => 'error'];
            }
        }

        if ($action === 'primary') {
            if (!empty($solution['error_id'])) {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("UPDATE error_solutions SET is_primary = 0 WHERE error_id = ?")->execute([$solution['error_id']]);
                    $pdo->prepare("UPDATE error_solutions SET is_primary = 1, status = 'approved', updated_at = NOW() WHERE id = ?")->execute([$solutionId]);
                    $pdo->commit();
                    return ['message' => '已设为主方案', 'messageType' => 'success'];
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    return ['message' => '操作失败', 'messageType' => 'error'];
                }
            }
        }

        if ($action === 'delete') {
            if (!empty($solution['error_id'])) {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("DELETE FROM error_revisions WHERE solution_id = ?")->execute([$solutionId]);
                    $pdo->prepare("DELETE FROM error_solutions WHERE id = ?")->execute([$solutionId]);
                    $pdo->prepare("UPDATE error_solutions SET is_primary = 1 WHERE error_id = ? ORDER BY created_at ASC, id ASC LIMIT 1")->execute([$solution['error_id']]);
                    $pdo->commit();
                    return ['message' => '解决方案已删除', 'messageType' => 'success'];
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    return ['message' => '删除失败', 'messageType' => 'error'];
                }
            }
        }
    }

    return ['message' => $message, 'messageType' => $messageType];
}

function solutionsMigrateHistory(PDO $pdo): array {
    $stats = ['errors_with_solution' => 0, 'migrated' => 0, 'skipped_existing' => 0, 'updated_primary' => 0];
    $hasOldSolutionColumn = true;
    $hasOldSolutionScreenshotsColumn = true;
    $hasNewSolutionScreenshotsColumn = true;
    try { $pdo->query("SELECT solution FROM errors LIMIT 1"); } catch (Exception $e) { $hasOldSolutionColumn = false; }
    try { $pdo->query("SELECT solution_screenshots FROM errors LIMIT 1"); } catch (Exception $e) { $hasOldSolutionScreenshotsColumn = false; }
    try { $pdo->query("SELECT solution_screenshots FROM error_solutions LIMIT 1"); } catch (Exception $e) { $hasNewSolutionScreenshotsColumn = false; }

    if (!$hasOldSolutionColumn) return ['message' => '当前数据库不存在 errors.solution 字段，无需迁移。', 'messageType' => 'error'] + $stats;

    $pdo->beginTransaction();
    try {
        $selectColumns = $hasOldSolutionScreenshotsColumn ? 'id, user_id, solution, solution_screenshots, status, updated_at, created_at' : 'id, user_id, solution, status, updated_at, created_at';
        $stmt = $pdo->query("SELECT {$selectColumns} FROM errors WHERE solution IS NOT NULL AND TRIM(solution) <> '' ORDER BY id ASC");
        $errors = $stmt->fetchAll();
        $stats['errors_with_solution'] = count($errors);
        foreach ($errors as $row) {
            $errorId = (int)$row['id'];
            $solution = trim((string)$row['solution']);
            if ($solution === '') continue;
            $existStmt = $pdo->prepare("SELECT id, solution, solution_screenshots FROM error_solutions WHERE error_id = ? ORDER BY is_primary DESC, id DESC LIMIT 1");
            $existStmt->execute([$errorId]);
            $existing = $existStmt->fetch();
            $solutionStatus = $row['status'] === 'approved' ? 'approved' : 'pending';
            $screenshots = $hasOldSolutionScreenshotsColumn ? trim((string)($row['solution_screenshots'] ?? '')) : '';
            if ($existing) {
                if (trim((string)($existing['solution'] ?? '')) === '') {
                    $pdo->prepare("UPDATE error_solutions SET user_id = ?, solution = ?, status = ?, is_primary = 1, updated_at = NOW() WHERE id = ?")->execute([(int)($row['user_id'] ?? 0), $solution, $solutionStatus, (int)$existing['id']]);
                    $stats['updated_primary']++;
                } else {
                    $pdo->prepare("UPDATE error_solutions SET is_primary = 1, status = ?, updated_at = NOW() WHERE id = ?")->execute([$solutionStatus, (int)$existing['id']]);
                    $pdo->prepare("UPDATE error_solutions SET is_primary = 0 WHERE error_id = ? AND id <> ?")->execute([$errorId, (int)$existing['id']]);
                    $stats['skipped_existing']++;
                }
                if ($hasNewSolutionScreenshotsColumn && $screenshots !== '') {
                    $pdo->prepare("UPDATE error_solutions SET solution_screenshots = ? WHERE id = ?")->execute([$screenshots, (int)$existing['id']]);
                }
                continue;
            }
            if ($hasNewSolutionScreenshotsColumn) {
                $pdo->prepare("INSERT INTO error_solutions (error_id, user_id, solution, solution_screenshots, status, is_primary, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())")->execute([$errorId, (int)($row['user_id'] ?? 0), $solution, $screenshots, $solutionStatus]);
            } else {
                $pdo->prepare("INSERT INTO error_solutions (error_id, user_id, solution, status, is_primary, created_at, updated_at) VALUES (?, ?, ?, ?, 1, NOW(), NOW())")->execute([$errorId, (int)($row['user_id'] ?? 0), $solution, $solutionStatus]);
            }
            $stats['migrated']++;
        }
        $pdo->commit();
        return ['message' => '迁移完成：扫描 ' . $stats['errors_with_solution'] . ' 条，新增 ' . $stats['migrated'] . ' 条，复用 ' . $stats['skipped_existing'] . ' 条，更新 ' . $stats['updated_primary'] . ' 条。', 'messageType' => 'success'] + $stats;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['message' => '迁移失败：' . $e->getMessage(), 'messageType' => 'error'] + $stats;
    }
}
