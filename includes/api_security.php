<?php
/**
 * API 通用安全能力：Basic Auth + IP 限频
 */

if (!function_exists('api_security_json')) {
    function api_security_json(int $status, array $payload): void {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!defined('API_BASIC_AUTH_MODE')) {
    define('API_BASIC_AUTH_MODE', 'off'); // off | all | selected
}
if (!defined('API_BASIC_AUTH_ENDPOINTS')) {
    define('API_BASIC_AUTH_ENDPOINTS', ''); // 逗号分隔，如 comment,private_msg
}
if (!defined('API_BASIC_AUTH_USER')) {
    define('API_BASIC_AUTH_USER', '');
}
if (!defined('API_BASIC_AUTH_PASS')) {
    define('API_BASIC_AUTH_PASS', '');
}
if (!defined('API_RATE_LIMIT_ENABLED')) {
    define('API_RATE_LIMIT_ENABLED', true);
}
if (!defined('API_RATE_LIMIT_MAX')) {
    define('API_RATE_LIMIT_MAX', 120);
}
if (!defined('API_RATE_LIMIT_WINDOW')) {
    define('API_RATE_LIMIT_WINDOW', 60);
}

if (!function_exists('api_security_client_ip')) {
    function api_security_client_ip(): string {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
            $_SERVER['HTTP_X_REAL_IP'] ?? '',
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        ];

        foreach ($candidates as $raw) {
            $raw = trim((string)$raw);
            if ($raw === '') {
                continue;
            }
            if (strpos($raw, ',') !== false) {
                $parts = explode(',', $raw);
                $raw = trim((string)$parts[0]);
            }
            if (filter_var($raw, FILTER_VALIDATE_IP)) {
                return $raw;
            }
        }

        return '0.0.0.0';
    }
}

if (!function_exists('api_security_read_basic_auth')) {
    function api_security_read_basic_auth(): array {
        $user = (string)($_SERVER['PHP_AUTH_USER'] ?? '');
        $pass = (string)($_SERVER['PHP_AUTH_PW'] ?? '');

        if ($user !== '' || $pass !== '') {
            return [$user, $pass];
        }

        $authHeader = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($authHeader !== '' && stripos($authHeader, 'basic ') === 0) {
            $encoded = trim(substr($authHeader, 6));
            $decoded = base64_decode($encoded, true);
            if (is_string($decoded) && strpos($decoded, ':') !== false) {
                [$user, $pass] = explode(':', $decoded, 2);
                return [(string)$user, (string)$pass];
            }
        }

        return ['', ''];
    }
}

if (!function_exists('api_should_require_basic_auth')) {
    function api_should_require_basic_auth(string $endpoint = ''): bool {
        $mode = strtolower(trim((string)API_BASIC_AUTH_MODE));
        if ($mode === 'off' || $mode === '') {
            return false;
        }
        if ($mode === 'all') {
            return true;
        }
        if ($mode !== 'selected') {
            return false;
        }

        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return false;
        }

        $raw = trim((string)API_BASIC_AUTH_ENDPOINTS);
        if ($raw === '') {
            return false;
        }

        $items = array_map('trim', explode(',', $raw));
        foreach ($items as $item) {
            if ($item !== '' && strcasecmp($item, $endpoint) === 0) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('api_require_basic_auth')) {
    function api_require_basic_auth(string $endpoint = ''): void {
        if (!api_should_require_basic_auth($endpoint)) {
            return;
        }

        $expectedUser = (string)API_BASIC_AUTH_USER;
        $expectedPass = (string)API_BASIC_AUTH_PASS;
        if ($expectedUser === '' || $expectedPass === '') {
            api_security_json(500, [
                'success' => false,
                'code' => 'basic_auth_misconfigured',
                'message' => 'Basic Auth 配置无效',
            ]);
        }

        [$user, $pass] = api_security_read_basic_auth();
        $ok = hash_equals($expectedUser, $user) && hash_equals($expectedPass, $pass);
        if (!$ok) {
            if (!headers_sent()) {
                header('WWW-Authenticate: Basic realm="API"');
            }
            api_security_json(401, [
                'success' => false,
                'code' => 'basic_auth_required',
                'message' => '需要 Basic Auth 认证',
            ]);
        }
    }
}

if (!function_exists('api_enforce_rate_limit')) {
    function api_enforce_rate_limit(string $bucket, ?int $max = null, ?int $window = null): void {
        if (!API_RATE_LIMIT_ENABLED) {
            return;
        }

        $max = $max ?? (int)API_RATE_LIMIT_MAX;
        $window = $window ?? (int)API_RATE_LIMIT_WINDOW;
        if ($max <= 0 || $window <= 0) {
            return;
        }

        $dir = BASE_PATH . UPLOAD_PATH . 'cache/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir . 'api_rate_limit.json';

        $ip = api_security_client_ip();
        $now = time();
        $key = hash('sha256', $bucket . '|' . $ip);

        $fp = @fopen($file, 'c+');
        if (!$fp) {
            return;
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                fclose($fp);
                return;
            }

            $content = stream_get_contents($fp);
            $data = json_decode((string)$content, true);
            if (!is_array($data)) {
                $data = [];
            }

            foreach ($data as $k => $row) {
                $ts = (int)($row['ts'] ?? 0);
                if ($ts <= 0 || ($now - $ts) > $window) {
                    unset($data[$k]);
                }
            }

            $row = $data[$key] ?? ['count' => 0, 'ts' => $now];
            $ts = (int)($row['ts'] ?? $now);
            $count = (int)($row['count'] ?? 0);

            if (($now - $ts) > $window) {
                $ts = $now;
                $count = 0;
            }

            $count++;
            $data[$key] = ['count' => $count, 'ts' => $ts];

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);

            if ($count > $max) {
                api_security_json(429, [
                    'success' => false,
                    'code' => 'rate_limited',
                    'message' => '请求过于频繁，请稍后再试',
                ]);
            }
        } catch (Throwable $e) {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }
}
