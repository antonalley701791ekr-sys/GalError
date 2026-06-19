<?php
require_once 'includes/user_auth.php';
require_once 'includes/view.php';
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

foreach ($conversations as &$conv) {
    $avatarUrl = '';
    if (!empty($conv['partner_avatar']) && file_exists(BASE_PATH . $conv['partner_avatar'])) {
        $avatarUrl = '/' . $conv['partner_avatar'];
    }

    $conv['avatar_url'] = $avatarUrl;
    $conv['avatar_fallback'] = mb_substr((string)$conv['partner_username'], 0, 1);
    $conv['last_message_preview'] = mb_substr((string)$conv['last_message'], 0, 50, 'UTF-8');
    $conv['last_time_text'] = !empty($conv['last_time']) ? date('m-d H:i', strtotime($conv['last_time'])) : '';
    $conv['chat_url'] = urlChat((int)$conv['partner_id']);
    $conv['unread_count'] = (int)($conv['unread_count'] ?? 0);
}
unset($conv);

ob_start();
renderSiteHead();
$siteHeadHtml = ob_get_clean();

ob_start();
include 'includes/header.php';
$headerHtml = ob_get_clean();

ob_start();
include 'includes/footer.php';
$footerHtml = ob_get_clean();

view('front/private_messages.twig', [
    'conversations' => $conversations,
    'site_head_html' => $siteHeadHtml,
    'header_html' => $headerHtml,
    'footer_html' => $footerHtml,
]);
