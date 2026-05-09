<?php
require_once 'includes/user_auth.php';
requireUserLogin();

$pdo = getDB();
$userId = getCurrentUserId();

// 查询会话列表
$sql = "SELECT 
            conv.partner_id,
            u.username AS partner_username,
            u.avatar AS partner_avatar,
            pm.content AS last_message,
            pm.created_at AS last_time,
            COALESCE(unread.cnt, 0) AS unread_count
        FROM (
            SELECT 
                CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END AS partner_id,
                MAX(id) AS last_msg_id
            FROM private_messages
            WHERE sender_id = ? OR receiver_id = ?
            GROUP BY partner_id
        ) AS conv
        JOIN private_messages pm ON pm.id = conv.last_msg_id
        JOIN users u ON u.id = conv.partner_id
        LEFT JOIN (
            SELECT sender_id, COUNT(*) AS cnt
            FROM private_messages
            WHERE receiver_id = ? AND is_read = 0
            GROUP BY sender_id
        ) AS unread ON unread.sender_id = conv.partner_id
        ORDER BY pm.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$userId, $userId, $userId, $userId]);
$conversations = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>消息通知 - <?php echo h(SITE_NAME); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER; ?>">
    <link rel="stylesheet" href="/assets/css/user.css?v=<?php echo ASSETS_VER; ?>">
    <?php renderSiteHead(); ?>
</head>
<body class="has-fixed-nav">
    <?php include 'includes/header.php'; ?>

    <main class="main">
        <div class="container" style="max-width:800px;">
            <div class="mb-20" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
                <h2>消息通知</h2>
            </div>

            <?php if (!empty($conversations)): ?>
                <div class="pm-conversation-list">
                    <?php foreach ($conversations as $conv): ?>
                        <?php
                            $avatarUrl = '';
                            if ($conv['partner_avatar'] && file_exists(BASE_PATH . $conv['partner_avatar'])) {
                                $avatarUrl = '/' . $conv['partner_avatar'];
                            }
                        ?>
                        <a href="<?php echo urlChat((int)$conv['partner_id']); ?>" class="pm-conversation-item">
                            <?php if ($avatarUrl): ?>
                                <img src="<?php echo h($avatarUrl); ?>" class="pm-avatar" alt="">
                            <?php else: ?>
                                <div class="pm-avatar fallback"><?php echo h(mb_substr($conv['partner_username'], 0, 1)); ?></div>
                            <?php endif; ?>

                            <div class="pm-conv-info">
                                <div class="pm-conv-name"><?php echo h($conv['partner_username']); ?></div>
                                <div class="pm-conv-preview"><?php echo h(mb_substr($conv['last_message'], 0, 50, 'UTF-8')); ?></div>
                            </div>

                            <div class="pm-conv-meta">
                                <span class="pm-conv-time"><?php echo date('m-d H:i', strtotime($conv['last_time'])); ?></span>
                                <?php if ((int)$conv['unread_count'] > 0): ?>
                                    <span class="pm-unread-badge"><?php echo (int)$conv['unread_count']; ?></span>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="card-body text-center empty-state">
                        <p>暂无消息</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>
</html>
