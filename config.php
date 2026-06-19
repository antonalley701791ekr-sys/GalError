<?php
session_start();

// 注意：本文件疑似已无任何引用的遗留文件（旧 mysqli 实现）。
// 凭据已改为从私有配置读取，避免明文泄露；确认无引用后可整文件删除。
$__secret = is_file(__DIR__ . '/includes/config.secret.php')
    ? (require __DIR__ . '/includes/config.secret.php') : [];
if (!is_array($__secret)) { $__secret = []; }

$dbhost = (getenv('DB_HOST') ?: ($__secret['DB_HOST'] ?? 'localhost'));
$dbuser = (getenv('DB_USER') ?: ($__secret['DB_USER'] ?? ''));
$dbpass = (getenv('DB_PASS') ?: ($__secret['DB_PASS'] ?? ''));
$dbname = (getenv('DB_NAME') ?: ($__secret['DB_NAME'] ?? ''));

$conn = mysqli_connect($dbhost, $dbuser, $dbpass, $dbname);
mysqli_set_charset($conn, 'utf8mb4');

if (!$conn) die("数据库连接失败");

function isAdminLogin() {
    return isset($_SESSION['admin']);
}

function checkAdmin() {
    if (!isAdminLogin()) {
        header("Location: login.php");
        exit;
    }
}

// VNDB 自动抓取
function fetchVNDB($vndb_id) {
    $id = trim(str_replace('v', '', $vndb_id));
    $url = "https://vndb.org/v$id";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    $html = curl_exec($ch);
    curl_close($ch);
    
    $data = [
        'title' => '',
        'title_jp' => '',
        'developer' => '',
        'release_date' => '',
        'cover' => '',
        'platform' => 'PC'
    ];
    
    // 标题
    if (preg_match('/<h1 itemprop="name">(.*?)<\/h1>/', $html, $m)) {
        $data['title'] = trim(strip_tags($m[1]));
    }
    
    // 封面
    if (preg_match('/<img itemprop="image" src="(.*?)"/', $html, $m)) {
        $data['cover'] = 'https:' . $m[1];
    }
    
    // 开发商
    if (preg_match('/Developer.*?<a.*?>(.*?)<\/a>/s', $html, $m)) {
        $data['developer'] = trim($m[1]);
    }
    
    // 发售日
    if (preg_match('/Released.*?(\d{4}-\d{2}-\d{2})/', $html, $m)) {
        $data['release_date'] = $m[1];
    }
    
    return $data;
}
?>