<?php
function handlePagesUploadImage(array $file): array {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) return ['success' => false, 'message' => '未选择文件'];
    $result = handleDocImageUpload($file);
    if ($result['success']) $result['url'] = '/' . $result['path'];
    return $result;
}

function handlePagesSavePage(PDO $pdo, string $slug, string $content, array $pageMeta): array {
    if (!isset($pageMeta[$slug])) return ['success' => false, 'message' => '页面不存在'];
    $stmt = $pdo->prepare('UPDATE site_pages SET content = ? WHERE slug = ?');
    $stmt->execute([$content, $slug]);
    return ['success' => true, 'message' => '"' . $pageMeta[$slug]['title'] . '" 保存成功'];
}
