<?php
function handleCategoriesAdd(PDO $pdo, array $input): array {
    $name = trim($input['name'] ?? '');
    $description = trim($input['description'] ?? '');
    $sortOrder = intval($input['sort_order'] ?? 0);
    if ($name === '') return ['message' => '分类名称不能为空', 'messageType' => 'error'];
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM error_categories WHERE name = ?"); $stmt->execute([$name]); if ((int)($stmt->fetch()['count'] ?? 0) > 0) return ['message' => '分类名称已存在', 'messageType' => 'error'];
    $pdo->prepare("INSERT INTO error_categories (name, description, sort_order) VALUES (?, ?, ?)")->execute([$name, $description, $sortOrder]);
    return ['message' => '分类添加成功', 'messageType' => 'success'];
}
function handleCategoriesEdit(PDO $pdo, int $id, array $input): array {
    $name = trim($input['name'] ?? ''); $description = trim($input['description'] ?? ''); $sortOrder = intval($input['sort_order'] ?? 0);
    if ($name === '') return ['message' => '分类名称不能为空', 'messageType' => 'error'];
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM error_categories WHERE name = ? AND id != ?"); $stmt->execute([$name, $id]); if ((int)($stmt->fetch()['count'] ?? 0) > 0) return ['message' => '分类名称已存在', 'messageType' => 'error'];
    $pdo->prepare("UPDATE error_categories SET name = ?, description = ?, sort_order = ? WHERE id = ?")->execute([$name, $description, $sortOrder, $id]);
    return ['message' => '分类更新成功', 'messageType' => 'success'];
}
function handleCategoriesDelete(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM errors WHERE category_id = ?"); $stmt->execute([$id]); if ((int)($stmt->fetch()['count'] ?? 0) > 0) return ['message' => '该分类下还有报错记录，无法删除', 'messageType' => 'error'];
    $pdo->prepare("DELETE FROM error_categories WHERE id = ?")->execute([$id]);
    return ['message' => '分类删除成功', 'messageType' => 'success'];
}
