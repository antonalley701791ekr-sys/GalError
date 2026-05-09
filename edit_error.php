<?php
require_once 'includes/user_auth.php';
require_once 'includes/sanitizer.php';

requireUserLogin();

$pdo = getDB();
$isAdminUser = canCurrentUserBypassModeration();

$errorId = intval($_GET['id'] ?? 0);
if (!$errorId) {
    header('Location: /');
    exit;
}

// 获取报错信息（仅已通过审核的报错可编辑）
$stmt = $pdo->prepare("
    SELECT e.*, g.title as game_title, g.vndb_id, c.name as category_name 
    FROM errors e 
    JOIN games g ON e.game_id = g.id 
    JOIN error_categories c ON e.category_id = c.id 
    WHERE e.id = ? AND e.status = 'approved'
");
$stmt->execute([$errorId]);
$error = $stmt->fetch();

if (!$error) {
    header('Location: /');
    exit;
}

// 获取报错分类
$categories = $pdo->query("SELECT * FROM error_categories ORDER BY sort_order ASC")->fetchAll();

// 获取已审核游戏列表
$games = $pdo->query("SELECT * FROM games WHERE status = 'approved' ORDER BY title ASC")->fetchAll();

$message = '';
$messageType = '';

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isUserBanned()) {
        $message = '您的账户已被封禁，无法修改内容';
        $messageType = 'error';
    } else {
    $title = trim($_POST['title'] ?? '');
    $categoryId = intval($_POST['category_id'] ?? 0);
    $phenomenon = trim($_POST['phenomenon'] ?? '');
    $engineInfo = trim($_POST['engine_info'] ?? '');
    $systemInfo = trim($_POST['system_info'] ?? '');
    $patchInfo = trim($_POST['patch_info'] ?? '');
    $solution = trim($_POST['solution'] ?? '');
    $hasSolution = ($_POST['has_solution'] ?? 'no') === 'yes';

    if (!$hasSolution) {
        $solution = '';
    }

    if (!$title || !$categoryId) {
        $message = '请填写所有必填字段';
        $messageType = 'error';
    } elseif ($hasSolution && empty($solution)) {
        $message = '选择了"有解决方案"但未填写解决方案内容';
        $messageType = 'error';
    } else {
        // 构建旧数据快照
        $oldData = json_encode([
            'title' => $error['title'],
            'category_id' => $error['category_id'],
            'phenomenon' => $error['phenomenon'],
            'engine_info' => $error['engine_info'],
            'system_info' => $error['system_info'],
            'patch_info' => $error['patch_info'],
            'solution' => $error['solution'],
        ], JSON_UNESCAPED_UNICODE);

        // 构建新数据快照
        $newData = json_encode([
            'title' => $title,
            'category_id' => $categoryId,
            'phenomenon' => $phenomenon,
            'engine_info' => $engineInfo,
            'system_info' => $systemInfo,
            'patch_info' => $patchInfo,
            'solution' => $solution,
        ], JSON_UNESCAPED_UNICODE);

        // 处理报错截图：保留的旧截图
        $oldScreenshots = $error['screenshots'] ? array_filter(array_map('trim', explode(',', $error['screenshots']))) : [];
        $keepScreenshots = $_POST['keep_screenshots'] ?? [];
        $keepScreenshots = array_filter($keepScreenshots, function($s) use ($oldScreenshots) {
            return in_array(basename($s), $oldScreenshots);
        });
        $keepScreenshots = array_map('basename', $keepScreenshots);

        // 处理新上传的报错截图
        $newUploadedScreenshots = [];
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
                        $newUploadedScreenshots[] = $upload['filename'];
                    }
                }
            }
        }

        $allNewScreenshots = array_merge($keepScreenshots, $newUploadedScreenshots);

        // 处理解决方案截图：保留的旧截图
        $oldSolScreenshots = $error['solution_screenshots'] ? array_filter(array_map('trim', explode(',', $error['solution_screenshots']))) : [];
        $keepSolScreenshots = $_POST['keep_solution_screenshots'] ?? [];
        $keepSolScreenshots = array_filter($keepSolScreenshots, function($s) use ($oldSolScreenshots) {
            return in_array(basename($s), $oldSolScreenshots);
        });
        $keepSolScreenshots = array_map('basename', $keepSolScreenshots);

        // 处理新上传的解决方案截图
        $newUploadedSolScreenshots = [];
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
                        $newUploadedSolScreenshots[] = $upload['filename'];
                    }
                }
            }
        }

        $allNewSolScreenshots = $hasSolution ? array_merge($keepSolScreenshots, $newUploadedSolScreenshots) : [];

        // 检查是否真的有修改
        $oldDecoded = json_decode($oldData, true);
        $newDecoded = json_decode($newData, true);
        $hasTextChange = ($oldDecoded != $newDecoded);
        $hasScreenshotChange = ($oldScreenshots != $allNewScreenshots);
        $hasSolScreenshotChange = ($oldSolScreenshots != $allNewSolScreenshots);

        if (!$hasTextChange && !$hasScreenshotChange && !$hasSolScreenshotChange) {
            $message = '未检测到任何修改';
            $messageType = 'error';
        } else {
            if ($isAdminUser) {
                $stmt = $pdo->prepare("UPDATE errors SET title=?, category_id=?, phenomenon=?, engine_info=?, system_info=?, patch_info=?, solution=?, screenshots=?, solution_screenshots=? WHERE id=?");
                $result = $stmt->execute([
                    $title,
                    $categoryId,
                    $phenomenon,
                    $engineInfo,
                    $systemInfo,
                    $patchInfo,
                    $solution,
                    implode(',', $allNewScreenshots),
                    implode(',', $allNewSolScreenshots),
                    $errorId
                ]);

                if ($result) {
                    $removedSc = array_diff($oldScreenshots, $allNewScreenshots);
                    $removedSolSc = array_diff($oldSolScreenshots, $allNewSolScreenshots);

                    $message = '修改已直接生效';
                    $messageType = 'success';

                    $stmt = $pdo->prepare("
                        SELECT e.*, g.title as game_title, g.vndb_id, c.name as category_name 
                        FROM errors e 
                        JOIN games g ON e.game_id = g.id 
                        JOIN error_categories c ON e.category_id = c.id 
                        WHERE e.id = ? AND e.status = 'approved'
                    ");
                    $stmt->execute([$errorId]);
                    $error = $stmt->fetch();
                    $currentScreenshots = $error['screenshots'] ? array_filter(array_map('trim', explode(',', $error['screenshots']))) : [];
                    $currentSolScreenshots = $error['solution_screenshots'] ? array_filter(array_map('trim', explode(',', $error['solution_screenshots']))) : [];
                } else {
                    $message = '提交失败，请重试';
                    $messageType = 'error';
                }
            } else {
                // 插入修改记录
                $stmt = $pdo->prepare("
                    INSERT INTO error_revisions (error_id, user_id, user_ip, old_data, old_engine_info, new_data, new_engine_info, old_screenshots, new_screenshots, old_solution_screenshots, new_solution_screenshots, status, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
                ");
                $result = $stmt->execute([
                    $errorId,
                    getCurrentUserId(),
                    getClientIP(),
                    $oldData,
                    $error['engine_info'] ?? '',
                    $newData,
                    $engineInfo,
                    implode(',', $oldScreenshots),
                    implode(',', $allNewScreenshots),
                    implode(',', $oldSolScreenshots),
                    implode(',', $allNewSolScreenshots)
                ]);

                if ($result) {
                    $message = '修改已提交，待管理员审核后生效。';
                    $messageType = 'success';
                } else {
                    $message = '提交失败，请重试';
                    $messageType = 'error';
                }
            }
        }
    }
    }
}

// 当前报错截图列表
$currentScreenshots = $error['screenshots'] ? array_filter(array_map('trim', explode(',', $error['screenshots']))) : [];
// 当前解决方案截图列表
$currentSolScreenshots = $error['solution_screenshots'] ? array_filter(array_map('trim', explode(',', $error['solution_screenshots']))) : [];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>编辑报错 - <?php echo h(SITE_NAME); ?></title>
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
                <div class="card-header">编辑报错 - <?php echo hs($error['game_title']); ?></div>
                <div class="card-body">
                    <?php if ($message): ?>
                        <div class="alert-<?php echo $messageType; ?>">
                            <?php echo h($message); ?>
                            <?php if ($messageType === 'success'): ?>
                                <br><a href="<?php echo urlError($error['id']); ?>">返回报错详情</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" enctype="multipart/form-data" onsubmit="return confirm('确认提交本次修改吗？提交后将进入审核或直接生效。');">
                        <!-- 游戏信息（只读展示） -->
                        <div class="form-group">
                            <label class="form-label">所属游戏</label>
                            <input type="text" class="form-input" value="<?php echo hs($error['game_title']); ?><?php if ($error['vndb_id']): ?> (<?php echo h($error['vndb_id']); ?>)<?php endif; ?>" disabled>
                        </div>

                        <!-- 报错分类 -->
                        <div class="form-group">
                            <label class="form-label">报错分类 *</label>
                            <select name="category_id" class="form-select" required>
                                <option value="">请选择分类</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>" <?php echo ($category['id'] == $error['category_id']) ? 'selected' : ''; ?>>
                                        <?php echo h($category['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 报错标题 -->
                        <div class="form-group">
                            <label class="form-label">报错标题 *</label>
                            <input type="text" name="title" class="form-input" placeholder="例如：启动闪退、0xc000007b、字体乱码等" required value="<?php echo h($error['title']); ?>">
                        </div>

                        <!-- 错误现象 -->
                        <div class="form-group">
                            <label class="form-label">错误现象</label>
                            <textarea name="phenomenon" class="form-textarea" rows="4" placeholder="详细描述错误现象、弹窗内容、出现时机等"><?php echo h($error['phenomenon']); ?></textarea>
                        </div>

                        <!-- 游戏引擎信息 -->
                        <div class="form-group">
                            <label class="form-label">游戏引擎信息</label>
                            <input type="text" name="engine_info" class="form-input" placeholder="例如：Kirikiri2（KRKR2）、BGI、Artemis" value="<?php echo h($error['engine_info'] ?? ''); ?>">
                        </div>

                        <!-- 系统信息 -->
                        <div class="form-group">
                            <label class="form-label">系统信息</label>
                            <input type="text" name="system_info" class="form-input" placeholder="例如：Windows 10 64位 / Windows 11" value="<?php echo h($error['system_info']); ?>">
                        </div>

                        <!-- 汉化补丁信息 -->
                        <div class="form-group">
                            <label class="form-label">汉化补丁</label>
                            <input type="text" name="patch_info" class="form-input" placeholder="汉化组名称、版本号等" value="<?php echo h($error['patch_info']); ?>">
                        </div>

                        <!-- 是否有解决方案 -->
                        <div class="form-group">
                            <label class="form-label">是否有解决方案</label>
                            <div class="radio-group">
                                <label class="radio-label">
                                    <input type="radio" name="has_solution" value="no" <?php echo empty(trim($error['solution'])) ? 'checked' : ''; ?> onchange="toggleSolution()"> 否
                                </label>
                                <label class="radio-label">
                                    <input type="radio" name="has_solution" value="yes" <?php echo !empty(trim($error['solution'])) ? 'checked' : ''; ?> onchange="toggleSolution()"> 是
                                </label>
                            </div>
                        </div>

                        <!-- 解决方案 -->
                        <div class="form-group">
                            <label class="form-label">解决方案</label>
                            <textarea name="solution" id="solution_textarea" class="form-textarea" rows="6" placeholder="详细描述解决步骤和方法" <?php echo empty(trim($error['solution'])) ? 'disabled' : ''; ?>><?php echo h($error['solution']); ?></textarea>
                        </div>

                        <!-- 解决方案截图区域 -->
                        <div id="solution_screenshots_group" style="<?php echo empty(trim($error['solution'])) ? 'display:none;' : ''; ?>">
                        <?php if (!empty($currentSolScreenshots)): ?>
                        <div class="form-group">
                            <label class="form-label">现有解决方案截图</label>
                            <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                                <?php foreach ($currentSolScreenshots as $screenshot): ?>
                                    <div style="text-align: center;">
                                        <img src="<?php echo h(UPLOAD_URL . $screenshot); ?>" alt="解决方案截图"
                                             style="width: 120px; height: 90px; object-fit: cover; border-radius: 4px; border: 1px solid var(--glass-border); display: block; margin-bottom: 4px; cursor: pointer;"
                                             onclick="window.open(this.src)">
                                        <label style="font-size: 12px; cursor: pointer;">
                                            <input type="checkbox" name="keep_solution_screenshots[]" value="<?php echo h($screenshot); ?>" checked>
                                            保留
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <small class="text-muted">取消勾选的截图将在审核通过后被移除</small>
                        </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label class="form-label">上传新解决方案截图（可选，最多5张）</label>
                            <input type="file" name="solution_screenshots[]" class="form-input" accept="image/*" multiple>
                            <small class="text-muted">支持 JPG、PNG、GIF 格式，单个文件最大 2MB</small>
                        </div>
                        </div>

                        <!-- 现有报错截图 -->
                        <?php if (!empty($currentScreenshots)): ?>
                        <div class="form-group">
                            <label class="form-label">现有报错截图</label>
                            <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                                <?php foreach ($currentScreenshots as $screenshot): ?>
                                    <div style="text-align: center;">
                                        <img src="<?php echo h(UPLOAD_URL . $screenshot); ?>" alt="报错截图"
                                             style="width: 120px; height: 90px; object-fit: cover; border-radius: 4px; border: 1px solid var(--glass-border); display: block; margin-bottom: 4px; cursor: pointer;"
                                             onclick="window.open(this.src)">
                                        <label style="font-size: 12px; cursor: pointer;">
                                            <input type="checkbox" name="keep_screenshots[]" value="<?php echo h($screenshot); ?>" checked>
                                            保留
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <small class="text-muted">取消勾选的截图将在审核通过后被移除</small>
                        </div>
                        <?php endif; ?>

                        <!-- 上传新报错截图 -->
                        <div class="form-group">
                            <label class="form-label">上传新报错截图（可选，最多5张）</label>
                            <input type="file" name="screenshots[]" class="form-input" accept="image/*" multiple>
                            <small class="text-muted">支持 JPG、PNG、GIF 格式，单个文件最大 2MB</small>
                        </div>

                        <!-- 提交按钮 -->
                        <div class="form-group">
                            <div class="btn-group">
                                <button type="submit" class="btn">提交修改</button>
                                <a href="<?php echo urlGame($error['game_id']); ?>" class="btn btn-secondary">取消</a>
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
    </script>
</body>
</html>
