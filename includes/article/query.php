<?php
function loadArticleQueryContext(PDO $pdo, int $id, array $input = []): array {
    if (!$id) return ['article' => null];
    $sql = 'SELECT a.*, u.username, u.avatar FROM articles a JOIN users u ON a.user_id = u.id WHERE a.id = ?';
    if (!isAdmin()) $sql .= " AND a.status = 'approved'";
    $stmt = $pdo->prepare($sql); $stmt->execute([$id]); $article = $stmt->fetch(); if (!$article) return ['article' => null];
    $tags = array_filter(explode(',', $article['tags']));
    $viewCounts = getViewCount('article', $id);
    $commentPage = max(1, intval($input['comment_page'] ?? 1));
    $commentPerPage = getCommentPerPage();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE content_type = 'article' AND content_id = ? AND status = 'active'"); $stmt->execute([$id]); $articleCommentCount = (int)$stmt->fetchColumn();
    $commentPagination = paginate($articleCommentCount, $commentPage, $commentPerPage);
    $stmt = $pdo->prepare("SELECT c.*, u.username, u.avatar, c.parent_id, pc.user_id as parent_user_id, pu.username as parent_username, pc.content as parent_content FROM comments c JOIN users u ON c.user_id = u.id LEFT JOIN comments pc ON c.parent_id = pc.id LEFT JOIN users pu ON pc.user_id = pu.id WHERE c.content_type = 'article' AND c.content_id = ? AND c.status = 'active' ORDER BY c.created_at ASC, c.id ASC LIMIT {$commentPagination['offset']}, {$commentPerPage}"); $stmt->execute([$id]); $articleComments = $stmt->fetchAll();
    foreach ($articleComments as &$comment) {
        $comment['content_html'] = md_to_html($comment['content'] ?? '');
        $comment['parent_quote_url'] = !empty($comment['parent_id']) ? '#comment-' . (int)$comment['parent_id'] : null;
    }
    unset($comment);
    $tocItems = []; $tocConfig = json_decode($article['toc_config'] ?? '', true); if (is_array($tocConfig) && !empty($tocConfig)) { foreach ($tocConfig as $item) if (!empty($item['visible'])) $tocItems[] = $item; } else { $tocItems = extract_headings_from_markdown($article['content']); }
    $hasToc = !empty($tocItems);
    if ($hasToc) { $minLevel = PHP_INT_MAX; foreach ($tocItems as $item) $minLevel = min($minLevel, intval($item['level'])); $counters = []; foreach ($tocItems as &$item) { $depth = intval($item['level']) - $minLevel; if (!isset($counters[$depth])) $counters[$depth] = 0; $counters[$depth]++; foreach (array_keys($counters) as $d) if ($d > $depth) unset($counters[$d]); $parts = []; for ($i=0;$i<=$depth;$i++) $parts[] = isset($counters[$i]) ? $counters[$i] : 1; $item['number'] = implode('.', $parts); } unset($item); }
    return compact('article', 'tags', 'viewCounts', 'commentPage', 'commentPerPage', 'articleCommentCount', 'commentPagination', 'articleComments', 'tocItems', 'hasToc');
}
