<?php
/**
 * 图片处理工具函数
 */

require_once __DIR__ . '/config.php';

/**
 * 处理裁剪后的 base64 图片数据，保存为本地文件
 * @param string $base64Data base64 编码的图片数据（含 data:image/xxx;base64, 前缀）
 * @return array ['success' => bool, 'path' => string, 'message' => string]
 */
function saveBase64ImageToTarget($base64Data, $target = 'covers') {
    if (empty($base64Data)) {
        return ['success' => false, 'message' => '未收到图片数据'];
    }

    $allowedTargets = ['covers', 'avatars', 'private_messages'];
    if (!in_array($target, $allowedTargets, true)) {
        return ['success' => false, 'message' => '无效的图片用途'];
    }

    if (!preg_match('/^data:image\/(jpeg|jpg|png|gif|webp);base64,(.+)$/s', $base64Data, $matches)) {
        return ['success' => false, 'message' => '图片数据格式错误，仅支持 JPEG/PNG/GIF/WEBP'];
    }

    $mimeType = strtolower($matches[1]);
    if ($mimeType === 'jpg') $mimeType = 'jpeg';
    $base64Content = $matches[2];

    $imageData = base64_decode($base64Content, true);
    if ($imageData === false) {
        return ['success' => false, 'message' => '图片数据解码失败'];
    }

    $maxSize = 4 * 1024 * 1024;
    if (strlen($imageData) > $maxSize) {
        return ['success' => false, 'message' => '图片大小超过 4MB 限制'];
    }

    $imageInfo = @getimagesizefromstring($imageData);
    if ($imageInfo === false) {
        return ['success' => false, 'message' => '图片内容验证失败'];
    }

    $extMap = ['jpeg' => 'jpg', 'png' => 'png', 'gif' => 'gif', 'webp' => 'webp'];
    $extension = $extMap[$mimeType] ?? 'jpg';

    $imageDir = UPLOAD_PATH . $target . '/';
    $absImageDir = BASE_PATH . $imageDir;
    if (!is_dir($absImageDir)) {
        mkdir($absImageDir, 0755, true);
    }

    $filename = date('YmdHis') . '_' . uniqid() . '.' . $extension;
    if (file_put_contents($absImageDir . $filename, $imageData) === false) {
        return ['success' => false, 'message' => '图片保存失败'];
    }

    return ['success' => true, 'path' => $imageDir . $filename];
}

function handleCroppedCoverData($base64Data, $target = 'covers') {
    return saveBase64ImageToTarget($base64Data, $target);
}

function handleCroppedAvatarData($base64Data) {
    return saveBase64ImageToTarget($base64Data, 'avatars');
}

/**
 * 判断 cover_image 值是否为远程 URL
 * @param string $coverImage cover_image 字段值
 * @return bool
 */
function isRemoteCoverUrl($coverImage) {
    return !empty($coverImage) && preg_match('/^https?:\/\//', $coverImage);
}

/**
 * 下载远程封面图片到本地 uploads/covers/ 目录
 * @param string $url 远程图片 URL
 * @return array ['success' => bool, 'path' => string, 'message' => string]
 */
function downloadRemoteCover($url) {
    // 验证 URL 协议
    if (!preg_match('/^https?:\/\//i', $url)) {
        return ['success' => false, 'message' => '仅支持 HTTP/HTTPS 协议'];
    }

    // SSRF 防护：解析主机名，禁止内网 IP
    $parsedUrl = parse_url($url);
    $host = $parsedUrl['host'] ?? '';
    if (empty($host)) {
        return ['success' => false, 'message' => '无效的 URL'];
    }

    $ip = gethostbyname($host);
    if ($ip !== false && $ip !== $host) {
        $longIp = ip2long($ip);
        if ($longIp !== false) {
            $privateRanges = [
                ['127.0.0.0', '127.255.255.255'],
                ['10.0.0.0', '10.255.255.255'],
                ['172.16.0.0', '172.31.255.255'],
                ['192.168.0.0', '192.168.255.255'],
                ['169.254.0.0', '169.254.255.255'],
                ['0.0.0.0', '0.255.255.255'],
            ];
            foreach ($privateRanges as $range) {
                $start = ip2long($range[0]);
                $end = ip2long($range[1]);
                if ($longIp >= $start && $longIp <= $end) {
                    return ['success' => false, 'message' => '禁止访问内网地址'];
                }
            }
        }
    }

    // curl 下载
    $maxSize = 5 * 1024 * 1024;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'GalError-ImageProxy/1.0',
        CURLOPT_HTTPHEADER => ['Accept: image/*'],
        CURLOPT_MAXFILESIZE => $maxSize,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        return ['success' => false, 'message' => '下载失败: ' . ($curlError ?: 'HTTP ' . $httpCode)];
    }

    // 验证 Content-Type
    if (!$contentType || !preg_match('/^image\/(jpeg|png|gif|webp)/i', $contentType, $typeMatches)) {
        return ['success' => false, 'message' => '远程资源不是有效的图片'];
    }

    // 验证实际大小
    if (strlen($response) > $maxSize) {
        return ['success' => false, 'message' => '图片大小超过 5MB 限制'];
    }

    // 二次验证图片内容
    $imageInfo = @getimagesizefromstring($response);
    if ($imageInfo === false) {
        return ['success' => false, 'message' => '图片内容验证失败'];
    }

    // 根据 Content-Type 确定扩展名
    $extMap = ['jpeg' => 'jpg', 'png' => 'png', 'gif' => 'gif', 'webp' => 'webp'];
    $extension = $extMap[strtolower($typeMatches[1])] ?? 'jpg';

    // 确保目录存在
    $coverDir = UPLOAD_PATH . 'covers/';
    $absCoverDir = BASE_PATH . $coverDir;
    if (!is_dir($absCoverDir)) {
        mkdir($absCoverDir, 0755, true);
    }

    // 生成唯一文件名并保存
    $filename = date('YmdHis') . '_' . uniqid() . '.' . $extension;

    if (file_put_contents($absCoverDir . $filename, $response) === false) {
        return ['success' => false, 'message' => '图片保存失败'];
    }

    return ['success' => true, 'path' => $coverDir . $filename];
}

/**
 * 获取游戏封面显示 URL，自动检测本地文件是否存在，不存在则回退到 VNDB 封面
 * @param array $game 游戏数据行（需包含 cover_image 和 vndb_cover_url 字段）
 * @param bool $isAdmin 是否在 admin/ 目录下调用（本地路径需加 '../' 前缀）
 * @return string 可直接用于 <img src=""> 的 URL，无封面时返回空字符串
 */
function getCoverUrl($game, $isAdmin = false) {
    $coverImage = $game['cover_image'] ?? '';
    if (empty($coverImage)) {
        return $game['vndb_cover_url'] ?? '';
    }

    // 远程 URL 直接返回
    if (isRemoteCoverUrl($coverImage)) {
        return $coverImage;
    }

    // 本地路径：检查文件是否存在
    if (file_exists(BASE_PATH . $coverImage)) {
        return '/' . $coverImage;
    }

    // 本地文件不存在，回退到 VNDB 封面
    $vndbUrl = $game['vndb_cover_url'] ?? '';
    if (!empty($vndbUrl)) {
        return $vndbUrl;
    }

    return '';
}
