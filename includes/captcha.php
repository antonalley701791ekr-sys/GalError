<?php
/**
 * 滑块验证码核心模块
 * 使用 PHP GD 库生成滑块拼图，Session 管理验证状态
 */

// ===== 配置常量 =====
define('CAPTCHA_IMG_WIDTH', 320);
define('CAPTCHA_IMG_HEIGHT', 160);
define('CAPTCHA_PIECE_SIZE', 42);
define('CAPTCHA_PIECE_RADIUS', 8);
define('CAPTCHA_TOLERANCE', 5);          // X 坐标容差 (px)
define('CAPTCHA_CHALLENGE_TTL', 120);    // Challenge 有效期 (秒)
define('CAPTCHA_VERIFIED_TTL', 86400);   // 验证通过有效期 (秒) = 24h
define('CAPTCHA_FAIL_DELAY_THRESHOLD', 5);
define('CAPTCHA_FAIL_LOCK_THRESHOLD', 10);
define('CAPTCHA_LOCK_DURATION', 30);     // 锁定时长 (秒)

/**
 * 判断当前请求是否需要显示验证码
 */
function needsCaptchaVerification() {
    return needsCaptchaVerificationForAuth();
}

/**
 * 判断认证环节（登录/注册）是否需要显示验证码
 */
function needsCaptchaVerificationForAuth() {
    // GD 扩展不可用 → 优雅降级
    if (!extension_loaded('gd')) {
        return false;
    }
    // 已登录用户免验证
    if (function_exists('isUserLoggedIn') && isUserLoggedIn()) {
        return false;
    }
    // 已通过验证
    if (isCaptchaVerified()) {
        return false;
    }
    return true;
}

/**
 * 检查 session 中是否已通过验证（带过期检查）
 */
function isCaptchaVerified() {
    if (!empty($_SESSION['captcha_verified']) && !empty($_SESSION['captcha_verified_at'])) {
        if (time() - $_SESSION['captcha_verified_at'] < CAPTCHA_VERIFIED_TTL) {
            return true;
        }
        // 已过期，清除
        unset($_SESSION['captcha_verified'], $_SESSION['captcha_verified_at']);
    }
    return false;
}

/**
 * 生成验证码 Challenge
 * 返回数组: [bg => base64, piece => base64, token => string, y => int]
 */
function generateCaptchaChallenge() {
    // 频率限制检查
    if (!empty($_SESSION['captcha_lock_until']) && time() < $_SESSION['captcha_lock_until']) {
        return ['error' => '请求过于频繁，请 ' . ($_SESSION['captcha_lock_until'] - time()) . ' 秒后再试'];
    }

    // 连续失败检查（不使用 sleep 阻塞 worker）
    $failCount = $_SESSION['captcha_fail_count'] ?? 0;
    if ($failCount >= CAPTCHA_FAIL_DELAY_THRESHOLD && $failCount < CAPTCHA_FAIL_LOCK_THRESHOLD) {
        // 返回提示而非阻塞，让客户端延迟重试
        return ['error' => '验证失败次数较多，请稍后再试', 'retry_after' => 2];
    }

    // 生成随机颜色参数
    $bgColors = _generateRandomColors();

    // 创建背景画布
    $bgImg = imagecreatetruecolor(CAPTCHA_IMG_WIDTH, CAPTCHA_IMG_HEIGHT);
    imagealphablending($bgImg, true);
    imagesavealpha($bgImg, true);

    // 绘制渐变背景
    _drawGradientBackground($bgImg, $bgColors);

    // 绘制干扰元素
    _drawNoise($bgImg);

    // 计算拼图块位置
    $pieceX = mt_rand(CAPTCHA_PIECE_SIZE + 40, CAPTCHA_IMG_WIDTH - CAPTCHA_PIECE_SIZE - 20);
    $pieceY = mt_rand(20, CAPTCHA_IMG_HEIGHT - CAPTCHA_PIECE_SIZE - 20);

    // 创建拼图块画布 (宽度含右侧凸起，高度等于拼图主体)
    $totalPieceW = CAPTCHA_PIECE_SIZE + CAPTCHA_PIECE_RADIUS;
    $totalPieceH = CAPTCHA_PIECE_SIZE;
    $pieceImg = imagecreatetruecolor($totalPieceW, $totalPieceH);
    imagealphablending($pieceImg, true);
    imagesavealpha($pieceImg, true);
    // 填充透明背景
    $transparent = imagecolorallocatealpha($pieceImg, 0, 0, 0, 127);
    imagefill($pieceImg, 0, 0, $transparent);

    // 创建拼图形状 mask 并提取像素
    _extractPuzzlePiece($bgImg, $pieceImg, $pieceX, $pieceY, $totalPieceW, $totalPieceH);

    // 在背景图上绘制缺口
    _drawPuzzleHole($bgImg, $pieceX, $pieceY);

    // 转换为 base64
    ob_start();
    imagepng($bgImg);
    $bgData = base64_encode(ob_get_clean());

    ob_start();
    imagepng($pieceImg);
    $pieceData = base64_encode(ob_get_clean());

    imagedestroy($bgImg);
    imagedestroy($pieceImg);

    // 生成 token
    $token = bin2hex(random_bytes(16));

    // 存入 session
    $_SESSION['captcha_x'] = $pieceX;
    $_SESSION['captcha_token'] = $token;
    $_SESSION['captcha_time'] = time();

    return [
        'success' => true,
        'bg' => 'data:image/png;base64,' . $bgData,
        'piece' => 'data:image/png;base64,' . $pieceData,
        'token' => $token,
        'y' => $pieceY,
        'imgWidth' => CAPTCHA_IMG_WIDTH,
        'pieceWidth' => $totalPieceW
    ];
}

/**
 * 验证用户提交的答案
 */
function verifyCaptchaAnswer($token, $x) {
    // 检查是否有 challenge
    if (!isset($_SESSION['captcha_token']) || !isset($_SESSION['captcha_x']) || !isset($_SESSION['captcha_time'])) {
        return ['success' => false, 'message' => '验证已过期，请刷新重试'];
    }

    // 检查锁定
    if (!empty($_SESSION['captcha_lock_until']) && time() < $_SESSION['captcha_lock_until']) {
        return ['success' => false, 'message' => '请求过于频繁，请稍后再试'];
    }

    // 检查 token 一致性
    if (!hash_equals($_SESSION['captcha_token'], $token)) {
        return ['success' => false, 'message' => '验证令牌无效，请刷新重试'];
    }

    // 检查 challenge 是否过期
    if (time() - $_SESSION['captcha_time'] > CAPTCHA_CHALLENGE_TTL) {
        _clearChallengeData();
        return ['success' => false, 'message' => '验证已过期，请重新获取'];
    }

    // 验证 X 坐标
    $correctX = $_SESSION['captcha_x'];
    $diff = abs((int)$x - $correctX);

    if ($diff <= CAPTCHA_TOLERANCE) {
        // 验证通过
        $_SESSION['captcha_verified'] = true;
        $_SESSION['captcha_verified_at'] = time();
        $_SESSION['captcha_fail_count'] = 0;
        unset($_SESSION['captcha_lock_until']);
        _clearChallengeData();
        return ['success' => true, 'message' => '验证通过'];
    }

    // 验证失败
    $failCount = ($_SESSION['captcha_fail_count'] ?? 0) + 1;
    $_SESSION['captcha_fail_count'] = $failCount;

    if ($failCount >= CAPTCHA_FAIL_LOCK_THRESHOLD) {
        $_SESSION['captcha_lock_until'] = time() + CAPTCHA_LOCK_DURATION;
        _clearChallengeData();
        return ['success' => false, 'message' => '失败次数过多，请 ' . CAPTCHA_LOCK_DURATION . ' 秒后再试'];
    }

    _clearChallengeData();
    return ['success' => false, 'message' => '验证失败，请重试'];
}

// ===== 内部辅助函数 =====

function _clearChallengeData() {
    unset($_SESSION['captcha_x'], $_SESSION['captcha_token'], $_SESSION['captcha_time']);
}

function _generateRandomColors() {
    return [
        [mt_rand(40, 100), mt_rand(50, 120), mt_rand(100, 180)],
        [mt_rand(60, 130), mt_rand(80, 150), mt_rand(120, 200)],
        [mt_rand(80, 160), mt_rand(60, 140), mt_rand(100, 190)]
    ];
}

function _drawGradientBackground($img, $colors) {
    $w = imagesx($img);
    $h = imagesy($img);

    for ($x = 0; $x < $w; $x++) {
        $ratio = $x / $w;
        if ($ratio < 0.5) {
            $r2 = $ratio * 2;
            $r = (int)($colors[0][0] + ($colors[1][0] - $colors[0][0]) * $r2);
            $g = (int)($colors[0][1] + ($colors[1][1] - $colors[0][1]) * $r2);
            $b = (int)($colors[0][2] + ($colors[1][2] - $colors[0][2]) * $r2);
        } else {
            $r2 = ($ratio - 0.5) * 2;
            $r = (int)($colors[1][0] + ($colors[2][0] - $colors[1][0]) * $r2);
            $g = (int)($colors[1][1] + ($colors[2][1] - $colors[1][1]) * $r2);
            $b = (int)($colors[1][2] + ($colors[2][2] - $colors[1][2]) * $r2);
        }
        $color = imagecolorallocate($img, max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
        imageline($img, $x, 0, $x, $h - 1, $color);
    }
}

function _drawNoise($img) {
    $w = imagesx($img);
    $h = imagesy($img);

    // 随机线段
    for ($i = 0; $i < 8; $i++) {
        $color = imagecolorallocatealpha($img, mt_rand(100, 255), mt_rand(100, 255), mt_rand(100, 255), mt_rand(50, 100));
        imageline($img, mt_rand(0, $w), mt_rand(0, $h), mt_rand(0, $w), mt_rand(0, $h), $color);
    }

    // 随机圆
    for ($i = 0; $i < 15; $i++) {
        $color = imagecolorallocatealpha($img, mt_rand(80, 220), mt_rand(80, 220), mt_rand(80, 220), mt_rand(40, 100));
        $cx = mt_rand(0, $w);
        $cy = mt_rand(0, $h);
        $size = mt_rand(10, 60);
        imageellipse($img, $cx, $cy, $size, $size, $color);
    }

    // 随机矩形
    for ($i = 0; $i < 6; $i++) {
        $color = imagecolorallocatealpha($img, mt_rand(60, 200), mt_rand(60, 200), mt_rand(60, 200), mt_rand(60, 110));
        $x1 = mt_rand(0, $w);
        $y1 = mt_rand(0, $h);
        imagerectangle($img, $x1, $y1, $x1 + mt_rand(10, 50), $y1 + mt_rand(10, 40), $color);
    }

    // 随机点
    for ($i = 0; $i < 200; $i++) {
        $color = imagecolorallocatealpha($img, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), mt_rand(30, 100));
        imagesetpixel($img, mt_rand(0, $w - 1), mt_rand(0, $h - 1), $color);
    }
}

/**
 * 判断点 (px, py) 是否在拼图形状内（矩形 + 右侧凸起半圆）
 */
function _isInsidePuzzleShape($px, $py, $pieceSize, $radius) {
    // 主矩形区域
    $inMain = ($px >= 0 && $px < $pieceSize && $py >= 0 && $py < $pieceSize);

    // 右侧凸起 (半圆，圆心在右边缘中点)
    $bumpRX = $pieceSize;
    $bumpRY = $pieceSize / 2;
    $distR = sqrt(pow($px - $bumpRX, 2) + pow($py - $bumpRY, 2));
    $inRightBump = ($distR <= $radius);

    return $inMain || $inRightBump;
}

/**
 * 从背景图提取拼图块像素
 */
function _extractPuzzlePiece($bgImg, $pieceImg, $pieceX, $pieceY, $totalW, $totalH) {
    $bgW = imagesx($bgImg);
    $bgH = imagesy($bgImg);

    for ($dx = 0; $dx < $totalW; $dx++) {
        for ($dy = 0; $dy < $totalH; $dy++) {
            if (_isInsidePuzzleShape($dx, $dy, CAPTCHA_PIECE_SIZE, CAPTCHA_PIECE_RADIUS)) {
                $srcX = $pieceX + $dx;
                $srcY = $pieceY + $dy;
                if ($srcX >= 0 && $srcX < $bgW && $srcY >= 0 && $srcY < $bgH) {
                    $rgb = imagecolorat($bgImg, $srcX, $srcY);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;
                    $color = imagecolorallocate($pieceImg, $r, $g, $b);
                    imagesetpixel($pieceImg, $dx, $dy, $color);
                }
            }
        }
    }

    // 绘制拼图块边缘（白色半透明轮廓）
    for ($dx = 0; $dx < $totalW; $dx++) {
        for ($dy = 0; $dy < $totalH; $dy++) {
            if (_isInsidePuzzleShape($dx, $dy, CAPTCHA_PIECE_SIZE, CAPTCHA_PIECE_RADIUS)) {
                // 检查是否为边缘像素
                $isEdge = false;
                for ($ox = -1; $ox <= 1; $ox++) {
                    for ($oy = -1; $oy <= 1; $oy++) {
                        if (!_isInsidePuzzleShape($dx + $ox, $dy + $oy, CAPTCHA_PIECE_SIZE, CAPTCHA_PIECE_RADIUS)) {
                            $isEdge = true;
                            break 2;
                        }
                    }
                }
                if ($isEdge) {
                    $edgeColor = imagecolorallocatealpha($pieceImg, 255, 255, 255, 40);
                    imagesetpixel($pieceImg, $dx, $dy, $edgeColor);
                }
            }
        }
    }
}

/**
 * 在背景图上绘制拼图缺口
 */
function _drawPuzzleHole($bgImg, $pieceX, $pieceY) {
    $bgW = imagesx($bgImg);
    $bgH = imagesy($bgImg);
    $totalW = CAPTCHA_PIECE_SIZE + CAPTCHA_PIECE_RADIUS;
    $totalH = CAPTCHA_PIECE_SIZE;

    // 半透明深色遮罩
    $holeColor = imagecolorallocatealpha($bgImg, 0, 0, 0, 60);
    $edgeColor = imagecolorallocatealpha($bgImg, 255, 255, 255, 80);

    for ($dx = 0; $dx < $totalW; $dx++) {
        for ($dy = 0; $dy < $totalH; $dy++) {
            if (_isInsidePuzzleShape($dx, $dy, CAPTCHA_PIECE_SIZE, CAPTCHA_PIECE_RADIUS)) {
                $srcX = $pieceX + $dx;
                $srcY = $pieceY + $dy;
                if ($srcX >= 0 && $srcX < $bgW && $srcY >= 0 && $srcY < $bgH) {
                    // 检查是否为边缘
                    $isEdge = false;
                    for ($ox = -1; $ox <= 1; $ox++) {
                        for ($oy = -1; $oy <= 1; $oy++) {
                            if (!_isInsidePuzzleShape($dx + $ox, $dy + $oy, CAPTCHA_PIECE_SIZE, CAPTCHA_PIECE_RADIUS)) {
                                $isEdge = true;
                                break 2;
                            }
                        }
                    }
                    if ($isEdge) {
                        imagesetpixel($bgImg, $srcX, $srcY, $edgeColor);
                    } else {
                        imagesetpixel($bgImg, $srcX, $srcY, $holeColor);
                    }
                }
            }
        }
    }
}
