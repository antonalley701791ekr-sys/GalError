<?php
require_once '../includes/config.php';
require_once '../includes/image_utils.php';
require_once '../includes/sanitizer.php';
require_once '../includes/auth.php';
require_once '../includes/view.php';

checkLogin();
if (!hasPermission('games', 'view') && !hasPermission('game_review', 'view')) {
    $_SESSION['admin_msg'] = '您没有权限执行此操作';
    header('Location: index.php'); exit;
}

$pdo = getDB();
$message = '';
$messageType = '';
$action = $_POST['action'] ?? ($_GET['action'] ?? '');
$status = $_GET['status'] ?? '';
$viewGame = null;
$editGame = null;
$allGames = [];
$allCategories = [];

$statusText = ['pending' => '待审核', 'approved' => '已通过', 'rejected' => '已拒绝'];

function gameRevisionsHasPending($pdo, $gameId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM game_revisions WHERE game_id = ? AND status = 'pending'");
    $stmt->execute([(int)$gameId]);
    return (int)$stmt->fetchColumn() > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $postAction = (string)$_POST['action'];
    $gameId = intval($_POST['id'] ?? $_GET['id'] ?? 0);
    $revId = intval($_POST['rev_id'] ?? $_GET['rev_id'] ?? 0);
    $rejectReason = trim((string)($_POST['reject_reason'] ?? ''));

    try {
        if ($postAction === 'approve' && $gameId > 0 && $revId <= 0) {
            $stmt = $pdo->prepare("UPDATE games SET status = 'approved', reject_reason = NULL WHERE id = ?");
            $stmt->execute([$gameId]);
            header('Location: /admin/games.php?action=view&id=' . $gameId);
            exit;
        } elseif ($postAction === 'reject' && $gameId > 0 && $revId <= 0) {
            $stmt = $pdo->prepare("UPDATE games SET status = 'rejected', reject_reason = ? WHERE id = ?");
            $stmt->execute([$rejectReason ?: '未通过审核', $gameId]);
            header('Location: /admin/games.php?action=view&id=' . $gameId);
            exit;
        } elseif ($postAction === 'delete' && $gameId > 0 && $revId <= 0) {
            $pdo->prepare("DELETE FROM game_revisions WHERE game_id = ?")->execute([$gameId]);
            $pdo->prepare("DELETE FROM games WHERE id = ?")->execute([$gameId]);
            header('Location: /admin/games.php');
            exit;
        } elseif ($postAction === 'approve' && $revId > 0) {
            $stmt = $pdo->prepare("SELECT r.*, g.* FROM game_revisions r JOIN games g ON r.game_id = g.id WHERE r.id = ? LIMIT 1");
            $stmt->execute([$revId]);
            $rev = $stmt->fetch();
            if ($rev) {
                $newData = json_decode($rev['new_data'] ?? '', true) ?: [];
                $update = $pdo->prepare("UPDATE games SET vndb_id=?, title=?, title_jp=?, romaji=?, aliases=?, developer=?, release_date=?, cover_image=?, vndb_cover_url=?, platforms=?, status='approved', has_pending_revision=0, reject_reason=NULL WHERE id=?");
                $update->execute([
                    $newData['vndb_id'] ?? $rev['vndb_id'] ?? '',
                    $newData['title'] ?? $rev['title'] ?? '',
                    $newData['title_jp'] ?? $rev['title_jp'] ?? '',
                    $newData['romaji'] ?? $rev['romaji'] ?? '',
                    $newData['aliases'] ?? $rev['aliases'] ?? null,
                    $newData['developer'] ?? $rev['developer'] ?? '',
                    $newData['release_date'] ?? $rev['release_date'] ?? null,
                    $newData['cover_image'] ?? $rev['cover_image'] ?? '',
                    $newData['vndb_cover_url'] ?? $rev['vndb_cover_url'] ?? '',
                    $newData['platforms'] ?? $rev['platforms'] ?? '',
                    $rev['game_id'],
                ]);
                $pdo->prepare("UPDATE game_revisions SET status='approved', reject_reason=NULL WHERE id=?")->execute([$revId]);
                $message = '游戏修改已通过并生效';
                $messageType = 'success';
            }
        } elseif ($postAction === 'reject' && $revId > 0) {
            $stmt = $pdo->prepare("SELECT game_id FROM game_revisions WHERE id = ? LIMIT 1");
            $stmt->execute([$revId]);
            $gameIdForRevision = (int)$stmt->fetchColumn();
            $pdo->prepare("UPDATE game_revisions SET status='rejected', reject_reason=? WHERE id=?")->execute([$rejectReason ?: '未通过审核', $revId]);
            if ($gameIdForRevision > 0 && !gameRevisionsHasPending($pdo, $gameIdForRevision)) {
                $pdo->prepare("UPDATE games SET has_pending_revision = 0 WHERE id = ?")->execute([$gameIdForRevision]);
            }
            header('Location: /admin/games.php?action=view&id=' . $gameIdForRevision);
            exit;
        }
    } catch (Throwable $e) {
        $message = '操作失败：' . $e->getMessage();
        $messageType = 'error';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'], $_GET['id'])) {
    $redirectId = intval($_GET['id']);
    if ($_GET['action'] === 'view' && $redirectId > 0) {
        $stmt = $pdo->prepare("SELECT g.*, u.username AS submitter_name FROM games g LEFT JOIN users u ON g.user_id = u.id WHERE g.id = ?");
        $stmt->execute([$redirectId]);
        $viewGame = $stmt->fetch();
    }
    if ($_GET['action'] === 'edit' && $redirectId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM games WHERE id = ?");
        $stmt->execute([$redirectId]);
        $editGame = $stmt->fetch();
    }
}

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;
$where = [];
$params = [];
if ($status && in_array($status, ['pending', 'approved', 'rejected'])) { $where[] = 'status = ?'; $params[] = $status; }
$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) as count FROM games {$whereClause}");
$countStmt->execute($params);
$total = (int)$countStmt->fetch()['count'];
$pagination = paginate($total, $page, $perPage);
$listStmt = $pdo->prepare("SELECT * FROM games {$whereClause} ORDER BY created_at DESC LIMIT {$offset}, {$perPage}");
$listStmt->execute($params);
$games = $listStmt->fetchAll();
foreach ($games as &$gameRow) {
    $coverUrl = getCoverUrl($gameRow, true);
    $gameRow['cover_url'] = $coverUrl ? (preg_match('#^https?://#i', $coverUrl) ? $coverUrl : '/' . ltrim($coverUrl, '/')) : '';
}
unset($gameRow);

$gameStats = [
    'total' => (int)$pdo->query("SELECT COUNT(*) FROM games")->fetchColumn(),
    'pending' => (int)$pdo->query("SELECT COUNT(*) FROM games WHERE status='pending'")->fetchColumn(),
    'approved' => (int)$pdo->query("SELECT COUNT(*) FROM games WHERE status='approved'")->fetchColumn(),
    'rejected' => (int)$pdo->query("SELECT COUNT(*) FROM games WHERE status='rejected'")->fetchColumn(),
];

$pendingGameRevisions = $pdo->query("SELECT r.*, g.*, g.title AS game_title, u.username AS submitter_name FROM game_revisions r JOIN games g ON r.game_id = g.id LEFT JOIN users u ON r.user_id = u.id WHERE r.status = 'pending' ORDER BY r.created_at DESC")->fetchAll();
foreach ($pendingGameRevisions as &$revision) {
    $coverUrl = getCoverUrl($revision, true);
    $revision['cover_url'] = $coverUrl ? (preg_match('#^https?://#i', $coverUrl) ? $coverUrl : '/' . ltrim($coverUrl, '/')) : '';
}
unset($revision);
$pendingGameRevisionCount = count($pendingGameRevisions);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'view' && !empty($_GET['id'])) {
    $gameId = (int)$_GET['id'];
    if ($gameId > 0) {
        header('Location: /game.php?id=' . $gameId . '&from_admin=1');
        exit;
    }
}
if ($action === 'edit' && !$editGame && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM games WHERE id = ?");
    $stmt->execute([intval($_GET['id'])]);
    $editGame = $stmt->fetch();
}
if ($action === 'edit' || $action === 'add') {
    $allGames = $pdo->query("SELECT id, title FROM games WHERE status = 'approved' ORDER BY title ASC")->fetchAll();
    $allCategories = $pdo->query("SELECT id, name FROM error_categories ORDER BY sort_order ASC, id ASC")->fetchAll();
}

view('admin/games.twig', [
    'page_title' => '游戏管理',
    'admin_css_mtime' => @filemtime(BASE_PATH . '/assets/css/style.css') ?: time(),
    'message' => $message,
    'message_type' => $messageType,
    'action' => $action,
    'status' => $status,
    'statusText' => $statusText,
    'games' => $games,
    'total' => $total,
    'pagination' => $pagination,
    'gameStats' => $gameStats,
    'pendingGameRevisions' => $pendingGameRevisions,
    'pendingGameRevisionCount' => $pendingGameRevisionCount,
    'viewGame' => $viewGame,
    'editGame' => $editGame,
    'allGames' => $allGames,
    'allCategories' => $allCategories,
]);
