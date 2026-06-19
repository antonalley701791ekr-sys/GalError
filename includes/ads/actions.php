<?php
function handleAdsAdd(PDO $pdo, array $input, array $files): array {
    $type = $input['type'] ?? 'text';
    $title = trim($input['title'] ?? '');
    $content = trim($input['content'] ?? '');
    $link = trim($input['link'] ?? '');
    $position = $input['position'] ?? 'header';
    $enabled = isset($input['enabled']) ? 1 : 0;
    $sortOrder = intval($input['sort_order'] ?? 0);
    $image = '';
    if ($title === '') return ['message' => '标题不能为空', 'messageType' => 'error'];
    if ($type === 'image' && isset($files['image']) && ($files['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $result = handleAdImageUpload($files['image']);
        if ($result['success']) $image = $result['path']; else return ['message' => $result['message'], 'messageType' => 'error'];
    }
    $pdo->prepare("INSERT INTO ads (type, title, content, image, link, position, enabled, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")->execute([$type, $title, $content, $image, $link, $position, $enabled, $sortOrder]);
    return ['message' => '广告添加成功', 'messageType' => 'success'];
}

function handleAdsEdit(PDO $pdo, int $id, array $input, array $files): array {
    $type = $input['type'] ?? 'text';
    $title = trim($input['title'] ?? '');
    $content = trim($input['content'] ?? '');
    $link = trim($input['link'] ?? '');
    $position = $input['position'] ?? 'header';
    $enabled = isset($input['enabled']) ? 1 : 0;
    $sortOrder = intval($input['sort_order'] ?? 0);
    if ($title === '') return ['message' => '标题不能为空', 'messageType' => 'error'];
    $imageUpdate = null;
    if ($type === 'image' && isset($files['image']) && ($files['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $result = handleAdImageUpload($files['image']);
        if (!$result['success']) return ['message' => $result['message'], 'messageType' => 'error'];
        $stmt = $pdo->prepare("SELECT image FROM ads WHERE id = ?"); $stmt->execute([$id]); $old = $stmt->fetch(); if ($old && $old['image'] && file_exists(BASE_PATH . $old['image'])) @unlink(BASE_PATH . $old['image']);
        $imageUpdate = $result['path'];
    }
    if ($imageUpdate !== null) $pdo->prepare("UPDATE ads SET type=?, title=?, content=?, image=?, link=?, position=?, enabled=?, sort_order=? WHERE id=?")->execute([$type, $title, $content, $imageUpdate, $link, $position, $enabled, $sortOrder, $id]);
    else $pdo->prepare("UPDATE ads SET type=?, title=?, content=?, link=?, position=?, enabled=?, sort_order=? WHERE id=?")->execute([$type, $title, $content, $link, $position, $enabled, $sortOrder, $id]);
    return ['message' => '广告更新成功', 'messageType' => 'success'];
}

function handleAdsDelete(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare("SELECT image FROM ads WHERE id = ?"); $stmt->execute([$id]); $ad = $stmt->fetch(); if ($ad && $ad['image'] && file_exists(BASE_PATH . $ad['image'])) @unlink(BASE_PATH . $ad['image']);
    $pdo->prepare("DELETE FROM ads WHERE id = ?")->execute([$id]);
    return ['message' => '广告已删除', 'messageType' => 'success'];
}

function handleAdsToggle(PDO $pdo, int $id): array {
    $pdo->prepare("UPDATE ads SET enabled = NOT enabled WHERE id = ?")->execute([$id]);
    return ['message' => '状态已切换', 'messageType' => 'success'];
}
