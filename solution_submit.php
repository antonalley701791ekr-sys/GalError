<?php
require_once 'includes/user_auth.php';
require_once 'includes/sanitizer.php';
require_once 'includes/sensitive_filter.php';
require_once 'includes/view.php';

requireUserLogin();

$pdo = getDB();
$errorId = intval($_GET['error_id'] ?? $_POST['error_id'] ?? 0);
$solutionId = intval($_GET['solution_id'] ?? $_POST['solution_id'] ?? 0);
if ($errorId <= 0) {
    header('Location: /');
    exit;
}

$stmt = $pdo->prepare("
    SELECT e.*, g.title AS game_title, c.name AS category_name
    FROM errors e
    JOIN games g ON e.game_id = g.id
    JOIN error_categories c ON e.category_id = c.id
    WHERE e.id = ? AND e.status = 'approved'
    LIMIT 1
");
$stmt->execute([$errorId]);
$error = $stmt->fetch();
if (!$error) {
    header('Location: /');
    exit;
}

$solutionsStmt = $pdo->prepare("\n    SELECT id, error_id, solution_no, user_id, solution, solution_screenshots, status, is_primary, created_at, updated_at\n    FROM error_solutions\n    WHERE error_id = ?\n    ORDER BY created_at ASC, id ASC\n");
$solutionsStmt->execute([$errorId]);
$solutions = $solutionsStmt->fetchAll();
$primarySolutionRow = null;
foreach ($solutions as $row) {
    if (!empty($row['is_primary'])) {
        $primarySolutionRow = $row;
        break;
    }
}
$currentSolutionRow = null;
if ($solutionId > 0) {
    $currentStmt = $pdo->prepare("\n        SELECT id, error_id, solution_no, user_id, solution, solution_screenshots, status, is_primary, created_at, updated_at\n        FROM error_solutions\n        WHERE id = ? AND error_id = ?\n        LIMIT 1\n    ");
    $currentStmt->execute([$solutionId, $errorId]);
    $currentSolutionRow = $currentStmt->fetch() ?: null;
    if (!$currentSolutionRow) {
        foreach ($solutions as $row) {
            if ((int)$row['id'] === $solutionId) {
                $currentSolutionRow = $row;
                break;
            }
        }
    }
}
$editMode = $solutionId > 0;
$currentSolutionText = $editMode ? (string)($currentSolutionRow['solution'] ?? '') : '';
$currentSolutionScreenshots = $editMode ? array_filter(array_map('trim', explode(',', (string)($currentSolutionRow['solution_screenshots'] ?? '')))) : [];

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate_request('solution_submit_form')) {
        $message = '请求已过期，请刷新后重试';
        $messageType = 'error';
    } elseif (isUserBanned()) {
        $message = '您的账户已被封禁，无法提交内容';
        $messageType = 'error';
    } else {
        $solution = trim($_POST['solution'] ?? '');
        $submitAsNew = ($_POST['submit_as_new'] ?? '0') === '1';
        if ($editMode) {
            $submitAsNew = false;
        } else {
            $submitAsNew = true;
        }

        if ($solution === '') {
            $message = '请填写解决方案内容';
            $messageType = 'error';
        } else {
            $check = containsSensitiveWord($solution, ['scene' => '解决方案提交', 'page' => '/solution_submit']);
            if (!empty($check['found'])) {
                $message = '解决方案包含违规内容，请修改后重新提交';
                $messageType = 'error';
            }
        }

        if ($messageType !== 'error') {
            $solutionScreenshots = [];
            $keepSolutionScreenshots = array_map('trim', (array)($_POST['keep_solution_screenshots'] ?? []));
            if (isset($_FILES['solution_screenshots']) && is_array($_FILES['solution_screenshots']['name'])) {
                foreach ($_FILES['solution_screenshots']['name'] as $key => $name) {
                    if ($_FILES['solution_screenshots']['error'][$key] === UPLOAD_ERR_OK && $name) {
                        $file = [
                            'name' => $name,
                            'type' => $_FILES['solution_screenshots']['type'][$key],
                            'tmp_name' => $_FILES['solution_screenshots']['tmp_name'][$key],
                            'error' => $_FILES['solution_screenshots']['error'][$key],
                            'size' => $_FILES['solution_screenshots']['size'][$key],
                        ];
                        $upload = handleFileUpload($file);
                        if ($upload['success']) {
                            $solutionScreenshots[] = trim((string)$upload['filename']);
                        }
                    }
                }
            }
            $solutionScreenshots = array_values(array_filter(array_map('trim', $solutionScreenshots)));
            $solutionScreenshotsCsv = implode(',', $solutionScreenshots);

            $currentUserId = getCurrentUserId();
            $status = canCurrentUserBypassModeration() ? 'approved' : 'pending';

            $pdo->beginTransaction();
            try {
                $isEditMode = (!empty($currentSolutionRow) && !$submitAsNew);
                $oldSolutionRow = null;
                if ($isEditMode) {
                    $oldStmt = $pdo->prepare("SELECT id, error_id, solution_no, solution, solution_screenshots, status, is_primary, created_at, updated_at FROM error_solutions WHERE id = ? FOR UPDATE");
                    $oldStmt->execute([$currentSolutionRow['id']]);
                    $oldSolutionRow = $oldStmt->fetch();
                    $pdo->prepare("UPDATE error_solutions SET solution = ?, status = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$solution, $status, $currentSolutionRow['id']]);
                    $targetSolutionId = (int)$currentSolutionRow['id'];
                } else {
                    $nextNoStmt = $pdo->prepare("SELECT COALESCE(MAX(solution_no), 0) + 1 FROM error_solutions WHERE error_id = ?");
                    $nextNoStmt->execute([$errorId]);
                    $nextSolutionNo = (int)$nextNoStmt->fetchColumn();
                    $pdo->prepare("INSERT INTO error_solutions (error_id, solution_no, user_id, solution, solution_screenshots, status, is_primary) VALUES (?, ?, ?, ?, ?, ?, 0)")
                        ->execute([$errorId, $nextSolutionNo, $currentUserId, $solution, $solutionScreenshotsCsv, $status]);
                    $targetSolutionId = (int)$pdo->lastInsertId();
                }

                if ($isEditMode) {
                    $existingShotsStmt = $pdo->prepare("SELECT solution_screenshots FROM error_solutions WHERE id = ?");
                    $existingShotsStmt->execute([$targetSolutionId]);
                    $existingShots = array_values(array_filter(array_map('trim', explode(',', (string)$existingShotsStmt->fetchColumn()))));
                    $keptShots = array_values(array_intersect($existingShots, $keepSolutionScreenshots));
                    $mergedShots = array_values(array_unique(array_merge($keptShots, $solutionScreenshots)));
                    $pdo->prepare("UPDATE error_solutions SET solution_screenshots = ? WHERE id = ?")
                        ->execute([implode(',', $mergedShots), $targetSolutionId]);
                }

                if ($submitAsNew) {
                    $pdo->prepare("UPDATE error_solutions SET is_primary = 0 WHERE error_id = ? AND id <> ?")
                        ->execute([$errorId, $targetSolutionId]);
                    $pdo->prepare("UPDATE error_solutions SET is_primary = 1 WHERE id = ?")
                        ->execute([$targetSolutionId]);
                } else {
                    $pdo->prepare("UPDATE error_solutions SET is_primary = 1 WHERE id = ?")
                        ->execute([$targetSolutionId]);
                    $pdo->prepare("UPDATE error_solutions SET is_primary = 0 WHERE error_id = ? AND id <> ?")
                        ->execute([$errorId, $targetSolutionId]);
                }

                if ($isEditMode && $oldSolutionRow) {
                    $newSolutionStmt = $pdo->prepare("SELECT id, solution_no, solution, solution_screenshots, status, is_primary, created_at, updated_at FROM error_solutions WHERE id = ?");
                    $newSolutionStmt->execute([$targetSolutionId]);
                    $newSolutionRow = $newSolutionStmt->fetch() ?: [];
                    $revOldData = json_encode(['solution' => (string)($oldSolutionRow['solution'] ?? '')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $revNewData = json_encode(['solution' => (string)($newSolutionRow['solution'] ?? '')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $revOldShots = (string)($oldSolutionRow['solution_screenshots'] ?? '');
                    $revNewShots = (string)($newSolutionRow['solution_screenshots'] ?? '');
                    $revStatus = ($status === 'approved') ? 'approved' : 'pending';
                    $revStmt = $pdo->prepare("INSERT INTO error_revisions (error_id, solution_id, user_id, old_data, new_data, old_solution_screenshots, new_solution_screenshots, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $revStmt->execute([$errorId, $targetSolutionId, $currentUserId, $revOldData, $revNewData, $revOldShots, $revNewShots, $revStatus]);
                }

                $pdo->commit();
                header('Location: /error_detail.php?id=' . $errorId);
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = '提交失败，请重试';
                $messageType = 'error';
            }
        }
    }
}

ob_start();
renderSiteHead();
$siteHeadHtml = ob_get_clean();
ob_start();
include __DIR__ . '/includes/header.php';
$headerHtml = ob_get_clean();
ob_start();
renderAnnouncement();
$announcementHtml = ob_get_clean();
ob_start();
include __DIR__ . '/includes/footer.php';
$footerHtml = ob_get_clean();

ob_start();
?>
<script>
(function() {
    var textarea = document.getElementById('solution_textarea');
    var submitAsNew = document.getElementById('submit_as_new');
    var showNewTip = function() {
        var wrap = document.getElementById('new_solution_tip');
        if (wrap) wrap.style.display = submitAsNew && submitAsNew.checked ? '' : 'none';
    };
    if (submitAsNew) {
        submitAsNew.addEventListener('change', showNewTip);
        showNewTip();
    }
})();
</script>
<?php
$pageScriptsHtml = ob_get_clean();

view('front/solution_submit.twig', [
    'message' => $message,
    'message_type' => $messageType,
    'error' => [
        'id' => (int)$error['id'],
        'title' => (string)$error['title'],
        'game_title' => (string)$error['game_title'],
        'category_name' => (string)$error['category_name'],
        'solution_text' => $currentSolutionText,
        'solution_screenshots' => $currentSolutionScreenshots,
    ],
    'edit_mode' => $editMode,
    'upload_url' => UPLOAD_URL,
    'csrf_html' => csrf_input('solution_submit_form'),
    'site_head_html' => $siteHeadHtml,
    'header_html' => $headerHtml,
    'announcement_html' => $announcementHtml,
    'footer_html' => $footerHtml,
    'page_scripts_html' => $pageScriptsHtml,
]);
