<?php
function handleDocumentsUploadImage(array $file): array {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) return ['success' => false, 'message' => '未选择文件'];
    $result = handleDocImageUpload($file);
    if ($result['success']) $result['url'] = '/' . $result['path'];
    return $result;
}

function handleDocumentsAdd(PDO $pdo, array $input, array $files): array {
    $title = trim($input['title'] ?? '');
    if ($title === '') return ['message' => '标题不能为空', 'messageType' => 'error'];
    $description = trim($input['description'] ?? ''); $content = trim($input['content'] ?? ''); $link = trim($input['link'] ?? ''); $enabled = isset($input['enabled']) ? 1 : 0; $sortOrder = intval($input['sort_order'] ?? 0); $image = '';
    if (isset($files['image']) && ($files['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) { $r = handleDocImageUpload($files['image']); if ($r['success']) $image = $r['path']; else return ['message' => $r['message'], 'messageType' => 'error']; }
    $pdo->prepare('INSERT INTO documents (title, description, content, image, link, enabled, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$title, $description, $content, $image, $link, $enabled, $sortOrder]);
    return ['message' => '文档添加成功', 'messageType' => 'success'];
}

function handleDocumentsEdit(PDO $pdo, int $id, array $input, array $files): array {
    $title = trim($input['title'] ?? '');
    if ($title === '') return ['message' => '标题不能为空', 'messageType' => 'error'];
    $description = trim($input['description'] ?? ''); $content = trim($input['content'] ?? ''); $link = trim($input['link'] ?? ''); $enabled = isset($input['enabled']) ? 1 : 0; $sortOrder = intval($input['sort_order'] ?? 0); $imageUpdate = null;
    if (isset($files['image']) && ($files['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) { $r = handleDocImageUpload($files['image']); if (!$r['success']) return ['message' => $r['message'], 'messageType' => 'error']; $stmt = $pdo->prepare('SELECT image FROM documents WHERE id = ?'); $stmt->execute([$id]); $old = $stmt->fetch(); if ($old && $old['image'] && file_exists(BASE_PATH . $old['image'])) @unlink(BASE_PATH . $old['image']); $imageUpdate = $r['path']; }
    if ($imageUpdate !== null) { $pdo->prepare('UPDATE documents SET title=?, description=?, content=?, image=?, link=?, enabled=?, sort_order=? WHERE id=?')->execute([$title, $description, $content, $imageUpdate, $link, $enabled, $sortOrder, $id]); }
    else { $pdo->prepare('UPDATE documents SET title=?, description=?, content=?, link=?, enabled=?, sort_order=? WHERE id=?')->execute([$title, $description, $content, $link, $enabled, $sortOrder, $id]); }
    return ['message' => '文档更新成功', 'messageType' => 'success'];
}

function handleDocumentsDelete(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare('SELECT image FROM documents WHERE id = ?'); $stmt->execute([$id]); $doc = $stmt->fetch(); if ($doc && $doc['image'] && file_exists(BASE_PATH . $doc['image'])) @unlink(BASE_PATH . $doc['image']);
    $pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$id]);
    return ['message' => '文档已删除', 'messageType' => 'success'];
}

function handleDocumentsToggle(PDO $pdo, int $id): array {
    $pdo->prepare('UPDATE documents SET enabled = NOT enabled WHERE id = ?')->execute([$id]);
    return ['message' => '状态已切换', 'messageType' => 'success'];
}
