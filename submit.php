<?php
require_once 'includes/user_auth.php';
require_once 'includes/sanitizer.php';
require_once 'includes/sensitive_filter.php';

requireUserLogin();

$pdo = getDB();
$isAdminUser = canCurrentUserBypassModeration();

// AJAX: VNDB ID 查询游戏
if (isset($_GET['action']) && $_GET['action'] === 'search_vndb') {
    header('Content-Type: application/json; charset=utf-8');
    $vndbId = trim($_POST['vndb_id'] ?? '');
    
    if (empty($vndbId)) {
        echo json_encode(['success' => false, 'message' => '请输入 VNDB ID']);
        exit;
    }
    
    // 搜索已审核通过的游戏
    $stmt = $pdo->prepare("SELECT id, title FROM games WHERE vndb_id = ? AND status = 'approved'");
    $stmt->execute([$vndbId]);
    $existingGame = $stmt->fetch();
    
    if ($existingGame) {
        echo json_encode(['success' => true, 'game_id' => $existingGame['id'], 'message' => '已找到游戏：' . $existingGame['title']]);
        exit;
    }
    
    // 检查是否在审核中
    $stmt = $pdo->prepare("SELECT id FROM games WHERE vndb_id = ? AND status = 'pending'");
    $stmt->execute([$vndbId]);
    $pendingGame = $stmt->fetch();
    
    if ($pendingGame) {
        echo json_encode(['success' => false, 'message' => '该游戏正在等待审核，审核通过后可提交报错']);
    } else {
        echo json_encode(['success' => false, 'message' => '游戏未收录，请先提交游戏', 'show_submit_link' => true]);
    }
    exit;
}

$message = '';
$messageType = '';

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isUserBanned()) {
        $message = '您的账户已被封禁，无法提交内容';
        $messageType = 'error';
    } else {
    $gameId = intval($_POST['game_id'] ?? 0);
    $categoryId = intval($_POST['category_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $phenomenon = trim($_POST['phenomenon'] ?? '');
    $engineInfo = trim($_POST['engine_info'] ?? '');
    $systemInfo = trim($_POST['system_info'] ?? '');
    $patchInfo = trim($_POST['patch_info'] ?? '');
    $solution = trim($_POST['solution'] ?? '');
    $hasSolution = ($_POST['has_solution'] ?? 'no') === 'yes';
    
    // 无解决方案时清空 solution
    if (!$hasSolution) {
        $solution = '';
    }
    
    // 验证必填字段
    if (!$gameId || !$categoryId || !$title || empty($phenomenon) || empty($systemInfo)) {
        $message = '请填写所有必填字段';
        $messageType = 'error';
    } elseif ($hasSolution && empty($solution)) {
        $message = '选择了"有解决方案"但未填写解决方案内容';
        $messageType = 'error';
    } else {
        $sensitiveFields = [
            '标题' => $title,
            '错误现象' => $phenomenon,
            '游戏引擎信息' => $engineInfo,
            '系统信息' => $systemInfo,
            '汉化补丁' => $patchInfo,
        ];

        if ($hasSolution) {
            $sensitiveFields['解决方案'] = $solution;
        }

        foreach ($sensitiveFields as $label => $value) {
            if ($value === '') {
                continue;
            }
            $sensitiveCheck = containsSensitiveWord($value, [
                'scene' => '报错投稿',
                'page' => '/submit',
            ]);
            if ($sensitiveCheck['found']) {
                $message = $label . '包含违规内容，请修改后重新提交';
                $messageType = 'error';
                break;
            }
        }
    }

    if ($messageType !== 'error') {
        // 处理报错截图上传
        $screenshots = [];
        if (isset($_FILES['screenshots']) && is_array($_FILES['screenshots']['name'])) {
            foreach ($_FILES['screenshots']['name'] as $key => $name) {
                if ($_FILES['screenshots']['error'][$key] === UPLOAD_ERR_OK && $name) {
                    $file = [
                        'name' => $name,
                        'type' => $_FILES['screenshots']['type'][$key],
                        'tmp_name' => $_FILES['screenshots']['tmp_name'][$key],
                        'error' => $_FILES['screenshots']['error'][$key],
                        'size' => $_FILES['screenshots']['size'][$key]
                    ];
                    
                    $upload = handleFileUpload($file);
                    if ($upload['success']) {
                        $screenshots[] = $upload['filename'];
                    }
                }
            }
        }

        // 处理解决方案截图上传
        $solutionScreenshots = [];
        if ($hasSolution && isset($_FILES['solution_screenshots']) && is_array($_FILES['solution_screenshots']['name'])) {
            foreach ($_FILES['solution_screenshots']['name'] as $key => $name) {
                if ($_FILES['solution_screenshots']['error'][$key] === UPLOAD_ERR_OK && $name) {
                    $file = [
                        'name' => $name,
                        'type' => $_FILES['solution_screenshots']['type'][$key],
                        'tmp_name' => $_FILES['solution_screenshots']['tmp_name'][$key],
                        'error' => $_FILES['solution_screenshots']['error'][$key],
                        'size' => $_FILES['solution_screenshots']['size'][$key]
                    ];
                    
                    $upload = handleFileUpload($file);
                    if ($upload['success']) {
                        $solutionScreenshots[] = $upload['filename'];
                    }
                }
            }
        }
        
        // 插入报错记录
        $submitStatus = getCurrentUserModerationStatus();
        $stmt = $pdo->prepare("
            INSERT INTO errors (game_id, category_id, title, phenomenon, engine_info, system_info, patch_info, solution, screenshots, solution_screenshots, user_ip, user_id, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $result = $stmt->execute([
            $gameId, $categoryId, $title, $phenomenon, $engineInfo, $systemInfo, $patchInfo, $solution,
            implode(',', $screenshots), implode(',', $solutionScreenshots), getClientIP(), getCurrentUserId(), $submitStatus
        ]);
        
        if ($result) {
            $message = $isAdminUser ? '提交成功！报错已直接发布。' : '提交成功！您的报错将在管理员审核后显示。';
            $messageType = 'success';
            // 清空表单
            $_POST = [];
        } else {
            $message = '提交失败，请重试';
            $messageType = 'error';
        }
    }
    }
}

// 获取游戏列表（仅已审核通过的游戏）
$games = $pdo->query("SELECT * FROM games WHERE status = 'approved' ORDER BY title ASC")->fetchAll();

// 获取报错分类
$categories = $pdo->query("SELECT * FROM error_categories ORDER BY sort_order ASC")->fetchAll();

// 获取预选游戏ID
$preselectedGameId = intval($_GET['game_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>提交报错 - <?php echo h(SITE_NAME); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER; ?>">
    <link rel="stylesheet" href="/assets/css/user.css?v=<?php echo ASSETS_VER; ?>">
    <?php renderSiteHead(); ?>
</head>
<body class="has-fixed-nav">
    <?php include __DIR__ . '/includes/header.php'; ?>

    <?php renderAnnouncement(); ?>

    <!-- 主要内容 -->
    <main class="main">
        <div class="container">
            <div class="card">
                <div class="card-header">提交 Galgame 报错解决方案</div>
                <div class="card-body">
                    <?php if ($message): ?>
                        <div class="alert-<?php echo $messageType; ?>">
                            <?php echo h($message); ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" enctype="multipart/form-data" onsubmit="return confirm('确认提交报错吗？提交后将进入审核或直接发布。');">
                        <!-- VNDB ID 查询 -->
                        <div class="form-group">
                            <label class="form-label">VNDB ID 查询（可选）</label>
                            <div class="inline-field-group">
                                <input type="text" id="vndb_id_input" class="form-input" placeholder="例如：v12345">
                                <button type="button" id="vndbSearchBtn" class="btn btn-secondary" onclick="searchGameByVndb()">查询游戏</button>
                            </div>
                            <div id="vndbSearchResult"></div>
                        </div>

                        <!-- 游戏选择 -->
                        <div class="form-group">
                            <label class="form-label">选择游戏 *</label>
                            <select name="game_id" id="game_select" class="form-select" required>
                                <option value="">请选择游戏</option>
                                <?php foreach ($games as $game): ?>
                                    <option value="<?php echo $game['id']; ?>" <?php echo ($preselectedGameId == $game['id']) ? 'selected' : ''; ?>>
                                        <?php echo hs($game['title']); ?>
                                        <?php if ($game['vndb_id']): ?>(<?php echo h($game['vndb_id']); ?>)<?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 报错分类 -->
                        <div class="form-group">
                            <label class="form-label">报错分类 *</label>
                            <select name="category_id" class="form-select" required>
                                <option value="">请选择分类</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>" <?php echo (isset($_POST['category_id']) && $_POST['category_id'] == $category['id']) ? 'selected' : ''; ?>>
                                        <?php echo h($category['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 报错标题 -->
                        <div class="form-group">
                            <label class="form-label">报错标题 *</label>
                            <input type="text" name="title" class="form-input" placeholder="例如：启动闪退、0xc000007b、字体乱码等" required value="<?php echo h($_POST['title'] ?? ''); ?>">
                        </div>

                        <!-- 错误现象 -->
                        <div class="form-group">
                            <label class="form-label">错误现象 *</label>
                            <textarea name="phenomenon" class="form-textarea" rows="4" placeholder="详细描述错误现象、弹窗内容、出现时机等" required><?php echo h($_POST['phenomenon'] ?? ''); ?></textarea>
                        </div>

                        <!-- 游戏引擎信息 -->
                        <div class="form-group">
                            <label class="form-label">游戏引擎信息</label>
                            <input type="text" name="engine_info" class="form-input" placeholder="例如：Kirikiri2（KRKR2）、BGI、Artemis" value="<?php echo h($_POST['engine_info'] ?? ''); ?>">
                        </div>

                        <!-- 系统信息 -->
                        <div class="form-group">
                            <label class="form-label">系统信息 *</label>
                            <input type="text" name="system_info" class="form-input" placeholder="例如：Windows 10 64位 / Windows 11" required value="<?php echo h($_POST['system_info'] ?? ''); ?>">
                        </div>

                        <!-- 汉化补丁信息 -->
                        <div class="form-group">
                            <label class="form-label">汉化补丁</label>
                            <input type="text" name="patch_info" class="form-input" placeholder="汉化组名称、版本号等" value="<?php echo h($_POST['patch_info'] ?? ''); ?>">
                        </div>

                        <!-- 报错截图上传 -->
                        <div class="form-group">
                            <label class="form-label">报错截图（可选，最多5张）</label>
                            <input type="file" name="screenshots[]" class="form-input" accept="image/*" multiple>
                            <small class="text-muted">支持 JPG、PNG、GIF 格式，单个文件最大 2MB</small>
                        </div>

                        <!-- 是否有解决方案 -->
                        <div class="form-group">
                            <label class="form-label">是否有解决方案</label>
                            <div class="radio-group">
                                <label class="radio-label">
                                    <input type="radio" name="has_solution" value="no" <?php echo (($_POST['has_solution'] ?? 'no') === 'no') ? 'checked' : ''; ?> onchange="toggleSolution()"> 否
                                </label>
                                <label class="radio-label">
                                    <input type="radio" name="has_solution" value="yes" <?php echo (($_POST['has_solution'] ?? 'no') === 'yes') ? 'checked' : ''; ?> onchange="toggleSolution()"> 是
                                </label>
                            </div>
                            <small class="text-muted">选"否"可先提交报错，其他用户可在详情页补充解决方案</small>
                        </div>

                        <!-- 解决方案 -->
                        <div class="form-group">
                            <label class="form-label">解决方案</label>
                            <textarea name="solution" id="solution_textarea" class="form-textarea" rows="6" placeholder="详细描述解决步骤和方法" <?php echo (($_POST['has_solution'] ?? 'no') !== 'yes') ? 'disabled' : ''; ?>><?php echo h($_POST['solution'] ?? ''); ?></textarea>
                        </div>

                        <!-- 解决方案截图上传 -->
                        <div class="form-group" id="solution_screenshots_group" style="<?php echo (($_POST['has_solution'] ?? 'no') !== 'yes') ? 'display:none;' : ''; ?>">
                            <label class="form-label">解决方案截图（可选，最多5张）</label>
                            <input type="file" name="solution_screenshots[]" class="form-input" accept="image/*" multiple>
                            <small class="text-muted">支持 JPG、PNG、GIF 格式，单个文件最大 2MB</small>
                        </div>

                        <!-- 提交按钮 -->
                        <div class="form-group">
                            <div class="btn-group">
                                <button type="submit" name="submit" class="btn">提交报错</button>
                                <a href="/" class="btn btn-secondary">返回首页</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/includes/footer.php'; ?>
    <script>
    function toggleSolution() {
        var hasSolution = document.querySelector('input[name="has_solution"]:checked').value === 'yes';
        var textarea = document.getElementById('solution_textarea');
        var solutionScreenshotsGroup = document.getElementById('solution_screenshots_group');
        textarea.disabled = !hasSolution;
        if (!hasSolution) {
            textarea.value = '';
        }
        solutionScreenshotsGroup.style.display = hasSolution ? '' : 'none';
    }

    function searchGameByVndb() {
        var vndbInput = document.getElementById('vndb_id_input');
        var btn = document.getElementById('vndbSearchBtn');
        var resultDiv = document.getElementById('vndbSearchResult');
        var gameSelect = document.getElementById('game_select');
        var vndbId = vndbInput.value.trim();

        if (!vndbId) {
            resultDiv.innerHTML = '<div class="alert-error" style="margin-top: 10px;">请输入 VNDB ID</div>';
            return;
        }

        btn.disabled = true;
        btn.textContent = '查询中...';
        resultDiv.innerHTML = '';

        var formData = new FormData();
        formData.append('vndb_id', vndbId);

        fetch('/submit?action=search_vndb', {
            method: 'POST',
            body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success && data.game_id) {
                // 自动选中对应游戏
                var found = false;
                for (var i = 0; i < gameSelect.options.length; i++) {
                    if (gameSelect.options[i].value == data.game_id) {
                        gameSelect.selectedIndex = i;
                        found = true;
                        break;
                    }
                }
                resultDiv.innerHTML = '<div class="alert-success" style="margin-top: 10px;">' + escapeHtml(data.message) + (found ? '' : '（下拉框中未找到）') + '</div>';
            } else {
                var html = '<div class="alert-error" style="margin-top: 10px;">' + escapeHtml(data.message);
                if (data.show_submit_link) {
                    html += '<br><a href="/submit_game">去提交游戏</a>';
                }
                html += '</div>';
                resultDiv.innerHTML = html;
            }
        })
        .catch(function() {
            resultDiv.innerHTML = '<div class="alert-error" style="margin-top: 10px;">查询失败，请重试</div>';
        })
        .finally(function() {
            btn.disabled = false;
            btn.textContent = '查询游戏';
        });
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // 回车键触发查询
    document.getElementById('vndb_id_input').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchGameByVndb();
        }
    });
    </script>
</body>
</html>