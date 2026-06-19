<?php
require_once 'includes/user_auth.php';
require_once 'includes/markdown.php';
require_once 'includes/sensitive_filter.php';
require_once 'includes/auth.php';
require_once 'includes/view.php';

$fromAdmin = isset($_GET['from_admin']) && $_GET['from_admin'] === '1';
if ($fromAdmin) {
    checkLogin();
    requirePermission('articles', 'edit');
} else {
    requireUserLogin();
}

if (!$fromAdmin && checkUserBanned()) {
    header('Location: /');
    exit;
}

$pdo = getDB();
$message = '';
$messageType = '';
$isAdminUser = canCurrentUserBypassModeration() || $fromAdmin;
$actorUserId = $fromAdmin ? intval($_SESSION['admin_id'] ?? 0) : intval(getCurrentUserId());

// 默认标签列表
$defaultTags = ['报错问题', '求助', '解决方案', '游戏攻略', '经验分享', '版本兼容', '汉化问题', '运行崩溃'];

// 编辑模式识别
$editId = intval($_GET['edit'] ?? 0);
$editArticle = null;
$editMode = ''; // 'pending' | 'approved' | 'rejected' | ''

if ($editId > 0) {
    if ($isAdminUser) {
        $stmt = $pdo->prepare("SELECT * FROM articles WHERE id = ?");
        $stmt->execute([$editId]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM articles WHERE id = ? AND user_id = ?");
        $stmt->execute([$editId, getCurrentUserId()]);
    }
    $editArticle = $stmt->fetch();
    if ($editArticle) {
        if ($editArticle['status'] === 'approved' && !empty($editArticle['has_pending_revision'])) {
            // 已有待审核修订，不允许再次编辑
            $editArticle = null;
            $editId = 0;
            $message = '当前有待审核的修改，请等待审核完成后再修改';
            $messageType = 'error';
        } elseif (in_array($editArticle['status'], ['pending', 'approved', 'rejected'])) {
            $editMode = $editArticle['status'];
        } else {
            $editArticle = null;
            $editId = 0;
        }
    } else {
        $editId = 0;
    }
}

// 处理图片上传 (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_image') {
    header('Content-Type: application/json');
    if (!isset($_FILES['image'])) {
        echo json_encode(['success' => false, 'message' => '未选择文件']);
        exit;
    }
    $result = handleArticleImageUpload($_FILES['image']);
    echo json_encode($result);
    exit;
}

// 处理提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    if (!csrf_validate_request('submit_article_form')) {
        $message = '请求已过期，请刷新后重试';
        $messageType = 'error';
    } else {
    $title = trim($_POST['title'] ?? '');
    $content = $_POST['content'] ?? '';
    $tags = $_POST['tags'] ?? [];
    $customTag = trim($_POST['custom_tag'] ?? '');
    $tocConfigJson = $_POST['toc_config'] ?? '';

    // 处理自定义标签
    if ($customTag) {
        $customTags = array_map('trim', explode(',', $customTag));
        $customTags = array_filter($customTags);
        $tags = array_merge($tags, $customTags);
    }

    // 去重并限制最多5个
    $tags = array_unique($tags);
    $tags = array_slice($tags, 0, 5);
    $tagsStr = implode(',', $tags);

    // 处理 TOC 配置：以 PHP 端提取的标题为准，合并用户选择的可见性
    $headings = extract_headings_from_markdown($content);
    $userTocConfig = json_decode($tocConfigJson, true);
    $userVisibility = [];
    if (is_array($userTocConfig)) {
        foreach ($userTocConfig as $item) {
            if (isset($item['id'])) {
                $userVisibility[$item['id']] = !empty($item['visible']);
            }
        }
    }
    $finalTocConfig = [];
    foreach ($headings as $h) {
        $finalTocConfig[] = [
            'level'   => $h['level'],
            'text'    => $h['text'],
            'id'      => $h['id'],
            'visible' => isset($userVisibility[$h['id']]) ? $userVisibility[$h['id']] : true,
        ];
    }
    $finalTocJson = json_encode($finalTocConfig, JSON_UNESCAPED_UNICODE);

    // 验证
    $errors = [];
    if (empty($title)) {
        $errors[] = '请输入文章标题';
    }
    if (mb_strlen($title) > 200) {
        $errors[] = '标题不能超过200个字符';
    }
    if (empty(trim(strip_tags($content)))) {
        $errors[] = '请输入文章内容';
    }

    // 敏感词检查（标题 + 内容）
    if (empty($errors)) {
        $titleCheck = containsSensitiveWord($title, [
            'scene' => '文章投稿',
            'page' => '/submit_article',
        ]);
        if ($titleCheck['found']) {
            $errors[] = '标题包含违规内容，请修改后重新提交';
        }
        $contentCheck = containsSensitiveWord(strip_tags($content), [
            'scene' => '文章投稿',
            'page' => '/submit_article',
        ]);
        if ($contentCheck['found']) {
            $errors[] = '内容包含违规内容，请修改后重新提交';
        }
    }

    if (empty($errors)) {
        $submitStatus = getCurrentUserModerationStatus();
        if ($editId > 0 && $editArticle && $editMode === 'approved') {
            // 已审核文章：创建修订记录
            $hasChange = ($title !== $editArticle['title'])
                      || ($content !== $editArticle['content'])
                      || ($tagsStr !== $editArticle['tags'])
                      || ($finalTocJson !== ($editArticle['toc_config'] ?? ''));

            if (!$hasChange) {
                $message = '未检测到任何修改';
                $messageType = 'error';
            } else {
                if ($isAdminUser) {
                    $stmt = $pdo->prepare("INSERT INTO article_revisions (article_id, user_id, old_title, new_title, old_content, new_content, old_tags, new_tags, old_toc_config, new_toc_config, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved')");
                    $revResult = $stmt->execute([
                        $editId,
                        $actorUserId,
                        $editArticle['title'],
                        $title,
                        $editArticle['content'],
                        $content,
                        $editArticle['tags'],
                        $tagsStr,
                        $editArticle['toc_config'] ?? '',
                        $finalTocJson,
                    ]);

                    if ($revResult) {
                        if ($isAdminUser) {
                            $stmt = $pdo->prepare("UPDATE articles SET title = ?, content = ?, tags = ?, toc_config = ?, updated_at = NOW(), has_pending_revision = 0 WHERE id = ?");
                            $result = $stmt->execute([$title, $content, $tagsStr, $finalTocJson, $editId]);
                        } else {
                            $stmt = $pdo->prepare("UPDATE articles SET title = ?, content = ?, tags = ?, toc_config = ?, updated_at = NOW(), has_pending_revision = 0 WHERE id = ? AND user_id = ?");
                            $result = $stmt->execute([$title, $content, $tagsStr, $finalTocJson, $editId, getCurrentUserId()]);
                        }
                    } else {
                        $result = false;
                    }

                    if ($result) {
                        setcookie('clear_markdown_draft', 'article_draft_edit_' . $editId, time() + 300, '/');
                        sendMentionNotifications(getCurrentUserId(), $_SESSION['user_username'] ?? '', $content, '文章', $title, urlArticle($editId));
                        $message = '修改已直接生效';
                        $messageType = 'success';
                    } else {
                        $message = '提交失败，请稍后重试';
                        $messageType = 'error';
                    }
                } else {
                    $stmt = $pdo->prepare("INSERT INTO article_revisions (article_id, user_id, old_title, new_title, old_content, new_content, old_tags, new_tags, old_toc_config, new_toc_config, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
                    $result = $stmt->execute([
                        $editId,
                        $actorUserId,
                        $editArticle['title'],
                        $title,
                        $editArticle['content'],
                        $content,
                        $editArticle['tags'],
                        $tagsStr,
                        $editArticle['toc_config'] ?? '',
                        $finalTocJson,
                    ]);

                    if ($result) {
                        $pdo->prepare("UPDATE articles SET has_pending_revision = 1 WHERE id = ?")->execute([$editId]);
                        setcookie('clear_markdown_draft', 'article_draft_edit_' . $editId, time() + 300, '/');
                        if ($submitStatus === 'approved') {
                            sendMentionNotifications(getCurrentUserId(), $_SESSION['user_username'] ?? '', $content, '文章', $title, urlArticle($editId));
                        }
                        $message = '修改已提交，待管理员审核后生效。审核期间将显示原版本内容。';
                        $messageType = 'success';
                    } else {
                        $message = '提交失败，请稍后重试';
                        $messageType = 'error';
                    }
                }
            }
        } elseif ($editId > 0 && $editArticle && ($editMode === 'pending' || $editMode === 'rejected')) {
            // 待审核/已驳回文章：直接更新
            $stmt = $pdo->prepare("UPDATE articles SET title = ?, content = ?, tags = ?, toc_config = ?, status = ?, reject_reason = NULL, updated_at = NOW() WHERE id = ?");
            if ($isAdminUser) {
                $result = $stmt->execute([$title, $content, $tagsStr, $finalTocJson, $submitStatus, $editId]);
            } else {
                $stmt = $pdo->prepare("UPDATE articles SET title = ?, content = ?, tags = ?, toc_config = ?, status = ?, reject_reason = NULL, updated_at = NOW() WHERE id = ? AND user_id = ?");
                $result = $stmt->execute([$title, $content, $tagsStr, $finalTocJson, $submitStatus, $editId, getCurrentUserId()]);
            }

            if ($result) {
                setcookie('clear_markdown_draft', 'article_draft_edit_' . $editId, time() + 300, '/');
                if ($submitStatus === 'approved') {
                    sendMentionNotifications(getCurrentUserId(), $_SESSION['user_username'] ?? '', $content, '文章', $title, urlArticle($editId));
                }
                $message = $isAdminUser ? '文章已更新并直接发布' : '文章已更新，等待管理员审核';
                $messageType = 'success';
            } else {
                $message = '提交失败，请稍后重试';
                $messageType = 'error';
            }
        } else {
            // 新建文章
            $stmt = $pdo->prepare("INSERT INTO articles (user_id, title, content, tags, toc_config, status) VALUES (?, ?, ?, ?, ?, ?)");
            $result = $stmt->execute([getCurrentUserId(), $title, $content, $tagsStr, $finalTocJson, $submitStatus]);

            if ($result) {
                $newArticleId = (int)$pdo->lastInsertId();
                setcookie('clear_markdown_draft', 'article_draft_new', time() + 300, '/');
                if ($submitStatus === 'approved') {
                    sendMentionNotifications(getCurrentUserId(), $_SESSION['user_username'] ?? '', $content, '文章', $title, urlArticle($newArticleId));
                }
                $message = $isAdminUser ? '提交成功，文章已直接发布' : '提交成功，等待管理员审核';
                $messageType = 'success';
                $title = '';
                $content = '';
                $tags = [];
            } else {
                $message = '提交失败，请稍后重试';
                $messageType = 'error';
            }
        }
    } else {
        $message = implode('；', $errors);
        $messageType = 'error';
    }
    }
}

// 表单回显
$formTitle = $editArticle['title'] ?? ($_POST['title'] ?? '');
$formContent = $editArticle['content'] ?? ($_POST['content'] ?? '');
$formTagsRaw = $editArticle ? explode(',', (string)$editArticle['tags']) : ($_POST['tags'] ?? []);
$formTags = array_values(array_unique(array_filter(array_map('trim', $formTagsRaw))));
$formTocConfig = $editArticle['toc_config'] ?? ($_POST['toc_config'] ?? '');
$extraTags = array_values(array_filter($formTags, function($tag) use ($defaultTags) {
    return !in_array($tag, $defaultTags, true);
}));

// 页面标题
$pageTitle = '提交文章';
if ($editId && $editArticle) {
    $pageTitle = $editMode === 'approved' ? '编辑已发布文章' : '编辑文章';
}

$draftKey = 'article_draft_' . ($editId ? 'edit_' . intval($editId) : 'new');
$editNotice = '';
if ($editMode === 'approved') {
    $editNotice = $isAdminUser ? '您正在编辑已发布的文章，保存后将直接生效。' : '您正在编辑已发布的文章。修改提交后需要管理员重新审核，审核期间前台将继续显示原版本内容。';
}
$submitText = $editMode === 'approved' ? '提交修改' : ($editId ? '更新文章' : '提交文章');
if ($editId && $editArticle) {
    $backUrl = $fromAdmin ? '/article.php?id=' . intval($editId) . '&from_admin=1' : urlArticle($editId);
    $backText = '返回文章';
} else {
    $backUrl = $fromAdmin ? '/admin/article_review.php' : '/';
    $backText = $fromAdmin ? '返回后台' : '返回首页';
}

ob_start(); renderSiteHead(); $siteHeadHtml = ob_get_clean();
ob_start(); include __DIR__ . '/includes/header.php'; $headerHtml = ob_get_clean();
ob_start(); include __DIR__ . '/includes/footer.php'; $footerHtml = ob_get_clean();
$adminSidebarHtml = '';
$adminFooterScriptsHtml = '';
if ($fromAdmin) {
    ob_start(); renderAdminSidebar('article_review.php'); $adminSidebarHtml = ob_get_clean();
    ob_start(); renderAdminFooterScripts(); $adminFooterScriptsHtml = ob_get_clean();
}

ob_start();
?>
<style>
.tag-selector { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
.tag-chip { display: inline-flex; align-items: center; gap: 4px; padding: 6px 14px; background: var(--glass-bg); border: 1px solid var(--glass-border); border-radius: var(--radius-pill); color: var(--text-secondary); cursor: pointer; font-size: 0.85rem; transition: all var(--transition); user-select: none; }
.tag-chip:hover { border-color: var(--accent-purple); color: var(--accent-purple); }
.tag-chip.selected { background: rgba(167,139,250,0.15); border-color: var(--accent-purple); color: var(--accent-purple); font-weight: 600; }
.tag-chip input[type="checkbox"] { display: none; }
.custom-tag-input { display: flex; gap: 8px; align-items: center; }
.custom-tag-input input { flex: 1; }
.tag-count { font-size: 0.82rem; color: var(--text-muted); margin-top: 8px; }
.tag-count .count-num { color: var(--accent-purple); font-weight: 600; }
</style>
<?php
$inlineStyleHtml = ob_get_clean();

ob_start();
?>
<script src="/assets/js/marked.min.js"></script>
<script>window.MARKDOWN_DRAFT_KEY = <?php echo json_encode($draftKey); ?>;</script>
<script src="/assets/js/markdown-editor.js?v=<?php echo ASSETS_VER; ?>"></script>
<script src="/assets/js/markdown-draft.js?v=<?php echo ASSETS_VER; ?>"></script>
<script>
MarkdownEditor.init({ textareaId: 'md-editor', previewId: 'md-preview', imageUploadUrl: '/submit_article', draftKey: <?php echo json_encode($draftKey); ?> });
(function() {
    var textarea = document.getElementById('md-editor');
    var tocList = document.getElementById('tocSelectorList');
    var tocInput = document.getElementById('tocConfigInput');
    var tocState = {};
    var debounceTimer = null;
    try { var saved = JSON.parse(tocInput.value || '[]'); if (Array.isArray(saved)) saved.forEach(function(item) { if (item.id) tocState[item.id] = item.visible !== false; }); } catch(e) {}
    function extractHeadings(text) {
        var headings = [], index = 0, lines = text.split('\n');
        for (var i = 0; i < lines.length; i++) {
            var line = lines[i];
            var m = line.match(/^(#{1,6})\s+(.+)$/);
            if (m) { headings.push({ level: m[1].length, text: m[2].replace(/#+\s*$/, '').trim(), id: 'heading-' + index }); index++; continue; }
            if (i + 1 < lines.length && line.trim() !== '') {
                var nextLine = lines[i + 1];
                if (/^=+\s*$/.test(nextLine)) { headings.push({ level: 1, text: line.trim(), id: 'heading-' + index }); index++; i++; }
                else if (/^-{2,}\s*$/.test(nextLine)) { headings.push({ level: 2, text: line.trim(), id: 'heading-' + index }); index++; i++; }
            }
        }
        return headings;
    }
    function escapeHtml(str) { var d = document.createElement('div'); d.textContent = str; return d.innerHTML; }
    function updateTocPanel() {
        var headings = extractHeadings(textarea.value);
        if (!headings.length) { tocList.innerHTML = '<div class="toc-selector-empty">请先在上方编辑器中输入包含标题的内容</div>'; tocInput.value = '[]'; return; }
        var html = '', tocConfig = [];
        headings.forEach(function(h) {
            var checked = tocState[h.id] !== false;
            tocConfig.push({ level: h.level, text: h.text, id: h.id, visible: checked });
            var indent = (h.level - 1) * 16;
            html += '<div class="toc-selector-item" style="padding-left:' + (8 + indent) + 'px;"><input type="checkbox" data-id="' + h.id + '"' + (checked ? ' checked' : '') + '><span class="toc-item-level">H' + h.level + '</span><span class="toc-item-text">' + escapeHtml(h.text) + '</span></div>';
        });
        tocList.innerHTML = html;
        tocInput.value = JSON.stringify(tocConfig);
    }
    tocList.addEventListener('change', function(e) { if (e.target.type !== 'checkbox') return; var id = e.target.getAttribute('data-id'); tocState[id] = e.target.checked; try { var config = JSON.parse(tocInput.value || '[]'); config.forEach(function(item) { if (item.id === id) item.visible = e.target.checked; }); tocInput.value = JSON.stringify(config); } catch(ex) {} });
    textarea.addEventListener('input', function() { clearTimeout(debounceTimer); debounceTimer = setTimeout(updateTocPanel, 500); });
    updateTocPanel();
})();
(function() {
    var tagSelector = document.getElementById('tagSelector');
    var tagCountEl = document.getElementById('tagCount');
    var customTagInput = document.getElementById('customTagInput');
    var addCustomTagBtn = document.getElementById('addCustomTagBtn');
    function getCheckedTags() { return Array.prototype.slice.call(tagSelector.querySelectorAll('input[type="checkbox"]:checked')); }
    function updateTagCount() { tagCountEl.textContent = getCheckedTags().length; }
    function syncChipSelectedState(checkbox) { var chip = checkbox.closest('.tag-chip'); if (chip) chip.classList.toggle('selected', checkbox.checked); }
    function hasTagValue(tagText) { var value = tagText.trim(); if (!value) return false; var allTags = tagSelector.querySelectorAll('input[type="checkbox"][name="tags[]"]'); for (var i = 0; i < allTags.length; i++) if ((allTags[i].value || '').trim() === value) return true; return false; }
    function createCustomTagChip(tagText) { var label = document.createElement('label'); label.className = 'tag-chip selected'; label.setAttribute('data-custom-tag', '1'); var input = document.createElement('input'); input.type = 'checkbox'; input.name = 'tags[]'; input.value = tagText; input.checked = true; label.appendChild(input); label.appendChild(document.createTextNode(tagText)); tagSelector.appendChild(label); }
    function addCustomTagsFromInput() { var raw = (customTagInput.value || '').trim(); if (!raw) return; var candidates = raw.split(/[，,]/).map(function(item) { return item.trim(); }).filter(Boolean); var checkedBefore = getCheckedTags().length; var addedCount = 0; for (var i = 0; i < candidates.length; i++) { var tag = candidates[i]; if (hasTagValue(tag)) continue; if (checkedBefore + addedCount >= 5) break; createCustomTagChip(tag); addedCount++; } customTagInput.value = ''; updateTagCount(); if (addedCount === 0 && checkedBefore >= 5) alert('最多选择5个标签'); }
    tagSelector.addEventListener('change', function(e) { if (e.target.type !== 'checkbox') return; var checkbox = e.target; if (getCheckedTags().length > 5) { checkbox.checked = false; alert('最多选择5个标签'); return; } syncChipSelectedState(checkbox); updateTagCount(); });
    addCustomTagBtn.addEventListener('click', addCustomTagsFromInput);
    customTagInput.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); addCustomTagsFromInput(); } });
    updateTagCount();
})();
document.getElementById('articleForm').addEventListener('submit', function(e) {
    var textarea = document.getElementById('md-editor');
    document.getElementById('contentInput').value = textarea.value;
    if (!confirm(<?php echo json_encode(($editId && $editArticle) ? '确认提交本次修改吗？提交后将进入审核或直接生效。' : '确认提交新文章吗？提交后将进入审核或直接发布。'); ?>)) { e.preventDefault(); return; }
    if (!textarea.value.trim()) { e.preventDefault(); alert('请输入文章内容'); return; }
    var checked = document.querySelectorAll('#tagSelector input[type="checkbox"]:checked').length;
    var customTag = document.querySelector('input[name="custom_tag"]').value.trim();
    var customCount = customTag ? customTag.split(',').filter(function(t){ return t.trim(); }).length : 0;
    if (checked + customCount > 5) { e.preventDefault(); alert('标签总数不能超过5个'); }
});
</script>
<?php
$pageScriptsHtml = ob_get_clean();

view('front/submit_article.twig', [
    'from_admin' => $fromAdmin,
    'page_title' => $pageTitle,
    'message' => $message,
    'message_type' => $messageType,
    'form_title' => $formTitle,
    'form_content' => $formContent,
    'form_toc_config' => $formTocConfig,
    'form_tags' => $formTags,
    'default_tags' => $defaultTags,
    'extra_tags' => $extraTags,
    'edit_notice' => $editNotice,
    'submit_text' => $submitText,
    'back_url' => $backUrl,
    'back_text' => $backText,
    'csrf_html' => csrf_input('submit_article_form'),
    'site_head_html' => $siteHeadHtml,
    'header_html' => $headerHtml,
    'footer_html' => $footerHtml,
    'admin_sidebar_html' => $adminSidebarHtml,
    'admin_footer_scripts_html' => $adminFooterScriptsHtml,
    'inline_style_html' => $inlineStyleHtml,
    'page_scripts_html' => $pageScriptsHtml,
]);
exit;
