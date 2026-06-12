<?php
/**
 * 全局配置文件 & 核心公共函数库
 * 
 * 本文件是整个系统的核心，所有 PHP 入口文件必须先 define('APP_STARTED', true) 后引入。
 * 
 * 职责:
 * 1. 安全防护：防止直接访问、强制 HTTPS、CORS 同源策略、安全响应头
 * 2. 全局常量：密码策略、加密密钥、数据库配置、登录安全参数
 * 3. 会话管理：Session 初始化、记住我自动登录、Cookie 安全配置
 * 4. 数据库连接：PDO 连接封装（getDB）
 * 5. 认证鉴权：设备端 Token 鉴权（checkAuth）、Web 端会话/Token 鉴权（checkWebAuthOrToken）
 * 6. 用户系统：建表、创建用户、查找用户、登录会话管理
 * 7. 登录安全：IP/用户名锁定、失败计数、验证码
 * 8. CSRF 防护：Token 生成与验证
 * 9. 密码加密：AES-256-CBC 解密（配合前端 Web Crypto API 加密）
 * 
 * 引入方式:
 *   define('APP_STARTED', true);
 *   require_once __DIR__ . '/config.php';
 * 
 * @package SMS-Forward
 */

// 防止直接访问：只有其他 PHP 文件先定义 APP_STARTED 后才能引入本文件
if (!defined('APP_STARTED')) {
    die('Access Denied');
}

// ====== 错误显示（生产环境配置） ======
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php_errors.log');

// ====== 时区设置（防止PHP与数据库时间不一致）======
//date_default_timezone_set('Asia/Shanghai');

// ====== 强制 HTTPS（生产环境）======

/**
 * 检测当前请求是否通过 HTTPS 连接
 * 
 * 检查三个来源：
 * 1. $_SERVER['HTTPS'] — 标准 HTTPS 标识
 * 2. $_SERVER['HTTP_X_FORWARDED_PROTO'] — 反代转发的协议（如 Nginx/CDN）
 * 3. $_SERVER['HTTP_X_FORWARDED_SSL'] — 反代转发的 SSL 标识
 * 
 * @return bool 当前请求是否为 HTTPS
 */
function isHttps() {
    if (isset($_SERVER['HTTPS']) && (strtolower($_SERVER['HTTPS']) === 'on' || $_SERVER['HTTPS'] === '1')) {
        return true;
    }
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && 
        strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    if (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && 
        strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
        return true;
    }
    return false;
}

// ====== 生产环境配置 ======
// 修改说明：
//   - 开发环境（IS_PRODUCTION = false）：允许 HTTP 访问
//   - 生产环境（IS_PRODUCTION = true）：强制 HTTPS 访问，非 HTTPS 自动跳转到 HTTPS
define('IS_PRODUCTION', false); // 部署到生产环境时改为 true
$currentScript = basename($_SERVER['SCRIPT_NAME']);
$needsHttps = in_array($currentScript, ['login.php', 'install.php']);

if (IS_PRODUCTION && !isHttps() && $needsHttps) {
    $redirectUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_SERVER['REQUEST_URI'];
    header('Location: ' . $redirectUrl, true, 301);
    exit;
}

// ====== Gzip 压缩输出（API 响应大多是文本，压缩后只有原来的 10%~20%）======
if (extension_loaded('zlib') && !ob_get_level() && !headers_sent()
    && isset($_SERVER['HTTP_ACCEPT_ENCODING'])
    && stripos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false) {
    ob_start('ob_gzhandler');
}

// ====== CORS 头（仅允许同源请求，防止跨站调用 API）======
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$scheme = isHttps() ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? '';
if ($origin === $scheme . '://' . $host) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, token');

// ====== Web 安全响应头（防点击劫持 / MIME 嗅探 / 信息泄露） ======
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-XSS-Protection: 1; mode=block');
// HSTS：强制浏览器始终使用 HTTPS 访问（有效期 1 年，仅 HTTPS 响应发送）
if (isHttps()) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
// CSP：限制资源加载来源，挡住大部分 XSS payload
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; frame-ancestors 'none'; object-src 'none'; base-uri 'self';");
// 减少信息泄露
header('X-Powered-By:'); // 移除 X-Powered-By 头

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ====== 应用配置 ======
// --- 密码策略 ---
define('PASSWORD_MIN_LENGTH', 8);
define('PASSWORD_REQUIRE_UPPER', true);
define('PASSWORD_REQUIRE_LOWER', true);
define('PASSWORD_REQUIRE_NUMBER', true);
define('PASSWORD_REQUIRE_SPECIAL', false);
define('PASSWORD_MAX_LENGTH', 128);

// --- 加密 ---
// 部署前请修改为随机生成的32字节密钥
define('ENCRYPTION_KEY', 'CHANGE_ME_TO_RANDOM_32BYTES_KEY');

// --- 认证与会话 ---
define('AUTH_TOKEN', '75gU0YzKgN');
define('SESSION_COOKIE_NAME', 'FWD_SYS_SID');
define('REMEMBER_ME_DURATION', 604800); // 记住我有效期 7 天（秒）

// --- 登录安全 ---
define('LOGIN_LOCK_THRESHOLD', 3);     // 账号失败3次锁定
define('LOGIN_ATTEMPT_WINDOW', 300);   // 账号失败计数窗口5分钟（秒）
define('LOGIN_LOCKOUT_DURATION', 300); // 账号锁定持续时间5分钟（秒）
define('IP_LOCK_THRESHOLD', 5);        // IP失败5次封禁
define('IP_ATTEMPT_WINDOW', 600);      // IP失败计数窗口10分钟（秒）
define('IP_LOCKOUT_DURATION', 600);    // IP封禁持续时间10分钟（秒）

// --- 数据库 ---
// 部署前请修改为你的数据库连接信息
define('DB_HOST', 'localhost');
define('DB_NAME', 'sms_forward');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

// ====== 会话（用于 Web 端登录） ======
if (session_status() === PHP_SESSION_NONE) {
    // 使用与项目隔离的会话名，避免与同站点其他应用冲突
    session_name(SESSION_COOKIE_NAME);

    // session 默认浏览器关闭即失效（lifetime=0）
    // 只有"数据库中存在有效 remember_tokens 记录"时，才延长 session 有效期
    // （不在此处直接延长，留到 getDB() 之后做严格校验）
    $lifetime = 0;

    // 安全 Cookie 配置：HTTPS 下强制 secure，防止会话劫持
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'domain'   => '',
        'secure'   => isHttps(), // HTTPS 环境下仅通过 HTTPS 发送 Cookie
        'httponly' => true,      // 禁止 JavaScript 访问，防 XSS
        'samesite' => 'Lax',     // Lax 比 Strict 友好：允许顶层 GET 导航带 cookie
    ]);
    session_start();

    // 自动登录逻辑延后到 getDB() 定义之后执行（依赖数据库常量）
    // 见文件底部 session 自动登录块
}

// ====== 数据库连接 ======

/**
 * 获取 PDO 数据库连接实例
 * 
 * 每次调用返回新的 PDO 连接，使用 utf8mb4 字符集。
 * 连接失败时返回 HTTP 500 并终止执行。
 * 
 * @return PDO 数据库连接
 */
function getDB() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
        // 设置 MySQL 会话时区与 PHP 一致（防止 NOW()/CURRENT_TIMESTAMP 与 date() 不一致）
        //$pdo->exec("SET time_zone = '+08:00'");
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['code' => 500, 'msg' => '数据库连接失败']);
        exit;
    }
}

// ====== 记住我 Cookie 自动登录（必须在 session_start 之后、且 DB_* 常量已定义）======
// 仅在尚未登录 + 携带 remember_me cookie 时尝试
if (session_status() === PHP_SESSION_ACTIVE
    && empty($_SESSION['user_id'])
    && !empty($_COOKIE['remember_me'])) {

    // 先做严格校验：cookie 必须在数据库 remember_tokens 中有有效记录
    $check = verifyRememberMeCookie();

    if ($check['valid']) {
        // 拉取用户信息并建立 session
        $db = getDB();
        $stmt = $db->prepare("SELECT id, username, is_active FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$check['user_id']]);
        $user = $stmt->fetch();

        if ($user && $user['is_active']) {
            // 建立 session
            session_regenerate_id(true);
            $_SESSION['user_id']  = intval($user['id']);
            $_SESSION['username'] = $user['username'];
            $_SESSION['login_at'] = date('Y-m-d H:i:s');
            $_SESSION['by_remember_me'] = true;  // 标记：本次登录来自 cookie
            rotateCsrfToken();

            // 注意：session 仍保持 lifetime=0（关闭浏览器即失效）
            // 下次访问如果浏览器已关 session 没了，只要 remember_me cookie 还在
            // 且数据库记录没被删，就会自动重建 session —— 实现 7 天免登录

            // 轮换 token（防 token 重放）
            $db->prepare("DELETE FROM remember_tokens WHERE selector = ?")
                ->execute([$check['selector']]);
            setRememberMeCookie($user['id']);
        } else {
            // 用户被禁用/不存在：清除对应 token 和 cookie
            $db = getDB();
            $db->prepare("DELETE FROM remember_tokens WHERE selector = ?")
                ->execute([$check['selector']]);
            setcookie('remember_me', '', time() - 3600, '/', '', isHttps(), true);
        }
    } else {
        // 验证失败：cookie 是伪造的、token 已过期或已被吊销
        // 立即清除该 cookie，避免下次再触发校验
        setcookie('remember_me', '', time() - 3600, '/', '', isHttps(), true);
    }
}

// ====== 设备端 Token 鉴权（仅用于 receive.php 等设备上报接口） ======

/**
 * 设备端 Token 鉴权
 * 
 * 从 HTTP Header、GET 参数或 POST 参数中获取 token，与 AUTH_TOKEN 常量比对。
 * 验证失败时返回 HTTP 401 并终止执行。
 * 
 * Token 来源优先级：
 * 1. HTTP Header: Token
 * 2. GET 参数: token
 * 3. POST 参数: token
 * 
 * @return void 验证通过时无返回值，失败时终止脚本
 */
function checkAuth() {
    $token = null;
    if (isset($_SERVER['HTTP_TOKEN'])) {
        $token = $_SERVER['HTTP_TOKEN'];
    } elseif (isset($_GET['token'])) {
        $token = $_GET['token'];
    } elseif (isset($_POST['token'])) {
        $token = $_POST['token'];
    }
    if ($token !== AUTH_TOKEN) {
        http_response_code(401);
        echo json_encode(['code' => 401, 'msg' => '未授权访问']);
        exit;
    }
}

// ====== Web 端会话鉴权（用于 list/detail 等读取接口） ======
// 既支持 Cookie 会话（浏览器登录后自动带），也兼容旧版 Token 调用方式（设备/脚本）

/**
 * Web 端会话或 Token 鉴权
 * 
 * 支持两种认证方式：
 * 1. Cookie 会话：检查 $_SESSION['user_id'] 是否存在
 * 2. Token 认证：从 HTTP Header、GET 参数或 POST 参数获取 token，与 AUTH_TOKEN 比对
 * 
 * 验证失败时返回 HTTP 401 并终止执行。
 * 
 * @return void 验证通过时无返回值，失败时终止脚本
 */
function checkWebAuthOrToken() {
    if (!empty($_SESSION['user_id'])) {
        return; // 已登录
    }
    // 兜底：允许带正确 Token 的脚本访问
    $token = null;
    if (isset($_SERVER['HTTP_TOKEN'])) {
        $token = $_SERVER['HTTP_TOKEN'];
    } elseif (isset($_GET['token'])) {
        $token = $_GET['token'];
    } elseif (isset($_POST['token'])) {
        $token = $_POST['token'];
    }
    if ($token === AUTH_TOKEN) {
        return;
    }
    http_response_code(401);
    echo json_encode(['code' => 401, 'msg' => '请先登录']);
    exit;
}

// ====== 用户系统辅助函数 ======

/**
 * 确保 users 表存在（不存在则创建）
 * 
 * 表结构：
 * - id: 自增主键
 * - username: 用户名（唯一）
 * - password_hash: bcrypt 哈希密码
 * - is_active: 是否启用（1=启用，0=禁用）
 * - created_at: 创建时间
 * - last_login_at: 最后登录时间
 * 
 * @return void
 */
function ensureUsersTable() {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(64) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_login_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * 确保 forward_records 表存在（不存在则创建）
 * 
 * 存储短信和来电转发记录。
 * 索引：device_name, type, event_time, phone_number
 * 
 * @return void
 */
function ensureForwardRecordsTable() {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS forward_records (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        device_name VARCHAR(128) NOT NULL,
        type ENUM('sms', 'call') NOT NULL,
        phone_number VARCHAR(32) NOT NULL,
        content TEXT,
        location VARCHAR(128) DEFAULT NULL,
        event_time DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_device (device_name),
        INDEX idx_type (type),
        INDEX idx_event_time (event_time),
        INDEX idx_phone (phone_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * 确保 login_failures 表存在（不存在则创建）
 * 
 * 记录登录失败尝试，用于统计失败次数。
 * 索引：ip_address, username, failed_at
 * 
 * @return void
 */
function ensureLoginFailuresTable() {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS login_failures (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ip_address VARCHAR(64) NOT NULL,
        username VARCHAR(64) NOT NULL,
        failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ip (ip_address),
        INDEX idx_username (username),
        INDEX idx_failed_at (failed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * 确保 login_locks 表存在（不存在则创建）
 * 
 * 存储账号和IP的锁定信息，防止暴力破解。
 * 唯一索引：(lock_type, lock_key)
 * 
 * @return void
 */
function ensureLoginLocksTable() {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS login_locks (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        lock_type ENUM('username', 'ip') NOT NULL,
        lock_key VARCHAR(128) NOT NULL,
        locked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        unlock_at DATETIME NOT NULL,
        UNIQUE INDEX idx_type_key (lock_type, lock_key),
        INDEX idx_unlock_at (unlock_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * 确保 remember_tokens 表存在（不存在则创建）
 * 
 * 存储"记住我"功能的令牌，实现自动登录。
 * 索引：user_id, expires_at
 * 
 * @return void
 */
function ensureRememberTokensTable() {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS remember_tokens (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        selector VARCHAR(255) NOT NULL UNIQUE,
        token_hash VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id),
        INDEX idx_expires_at (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * 检查是否已存在任何用户
 * 
 * @return bool true=至少有一个用户, false=无用户
 */
function hasAnyUser() {
    $db = getDB();
    $stmt = $db->query("SELECT COUNT(*) AS c FROM users");
    return intval($stmt->fetch()['c']) > 0;
}

/**
 * AES-256-CBC 解密
 * 
 * 用于解密前端通过 Web Crypto API 加密的密码。
 * 数据格式：Base64(IV + 密文)，密钥不足32字节时用0填充。
 * 
 * @param string $encryptedData Base64编码的加密数据
 * @return string|null 解密后的明文，失败返回null
 */
function aesDecrypt($encryptedData) {
    if (empty($encryptedData)) return null;

    try {
        $data = base64_decode($encryptedData, true);
        if ($data === false) return null;

        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        if (strlen($data) < $ivLength) return null;

        $iv = substr($data, 0, $ivLength);
        $ciphertext = substr($data, $ivLength);

        // 密钥处理：与前端保持一致，不足32字节用0填充
        $keyBytes = str_pad(ENCRYPTION_KEY, 32, "\0", STR_PAD_RIGHT);

        $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $keyBytes, OPENSSL_RAW_DATA, $iv);
        return $decrypted !== false ? $decrypted : null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * 密码强度验证
 * 
 * 根据 PASSWORD_* 系列常量定义的规则，检查密码是否满足安全要求。
 * 包括：长度、大小写、数字、特殊字符、常见弱密码黑名单。
 * 
 * @param string $password 待验证的明文密码
 * @return array 错误信息数组，空数组表示通过
 */
function validatePasswordStrength($password) {
    $errors = [];
    
    if (!is_string($password)) {
        $errors[] = '密码必须是字符串';
        return $errors;
    }
    
    $len = strlen($password);
    
    if ($len < PASSWORD_MIN_LENGTH) {
        $errors[] = '密码长度至少需要 ' . PASSWORD_MIN_LENGTH . ' 个字符';
    }
    
    if ($len > PASSWORD_MAX_LENGTH) {
        $errors[] = '密码长度不能超过 ' . PASSWORD_MAX_LENGTH . ' 个字符';
    }
    
    if (PASSWORD_REQUIRE_UPPER && !preg_match('/[A-Z]/', $password)) {
        $errors[] = '密码必须包含至少一个大写字母';
    }
    
    if (PASSWORD_REQUIRE_LOWER && !preg_match('/[a-z]/', $password)) {
        $errors[] = '密码必须包含至少一个小写字母';
    }
    
    if (PASSWORD_REQUIRE_NUMBER && !preg_match('/[0-9]/', $password)) {
        $errors[] = '密码必须包含至少一个数字';
    }
    
    if (PASSWORD_REQUIRE_SPECIAL && !preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/', $password)) {
        $errors[] = '密码必须包含至少一个特殊字符';
    }
    
    // 检查常见弱密码
    $commonPasswords = [
        '123456', '12345678', 'password', 'qwerty', 'abc123', 'monkey',
        '123123', '654321', 'superman', 'qazwsx', 'master', '123qwe'
    ];
    if (in_array(strtolower($password), $commonPasswords)) {
        $errors[] = '密码过于简单，请使用更复杂的密码';
    }
    
    return $errors;
}

/**
 * 创建新用户
 * 
 * 先验证密码强度，通过后使用 bcrypt 哈希存储。
 * 
 * @param string $username 用户名
 * @param string $password 明文密码
 * @return int 新用户ID
 * @throws Exception 密码不符合安全要求时抛出
 */
function createUser($username, $password) {
    // 验证密码强度
    $pwdErrors = validatePasswordStrength($password);
    if (!empty($pwdErrors)) {
        throw new Exception('密码不符合安全要求: ' . implode(', ', $pwdErrors));
    }
    
    $db = getDB();
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $db->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
    $stmt->execute([$username, $hash]);
    return $db->lastInsertId();
}

/**
 * 根据用户名查找用户
 * 
 * 用户名不区分大小写，查询时自动转为小写。
 * 
 * @param string $username 用户名
 * @return array|null 用户记录数组，不存在返回null
 */
function findUserByUsername($username) {
    if (empty($username)) return null;
    $username = strtolower(trim($username));
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * 验证"记住我"Cookie是否有效
 * 
 * 从Cookie中提取selector和token，查询数据库验证：
 * 1. selector是否存在
 * 2. token是否过期
 * 3. token哈希是否匹配
 * 
 * @return array ['valid' => bool, 'user_id' => int|null, 'selector' => string|null]
 */
function verifyRememberMeCookie() {
    ensureRememberTokensTable();

    $cookie = $_COOKIE['remember_me'] ?? '';
    if (empty($cookie) || strpos($cookie, '|') === false) {
        return ['valid' => false, 'user_id' => null, 'selector' => null];
    }
    list($selector, $token) = explode('|', $cookie, 2);
    if ($selector === '' || $token === '') {
        return ['valid' => false, 'user_id' => null, 'selector' => null];
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT user_id, token_hash, expires_at FROM remember_tokens
        WHERE selector = ? LIMIT 1");
    $stmt->execute([$selector]);
    $row = $stmt->fetch();
    if (!$row) return ['valid' => false, 'user_id' => null, 'selector' => null];

    // 检查过期
    if (strtotime($row['expires_at']) < time()) {
        // 清理过期 token
        $db->prepare("DELETE FROM remember_tokens WHERE selector = ?")->execute([$selector]);
        return ['valid' => false, 'user_id' => null, 'selector' => null];
    }

    // 验证 token 哈希
    if (!password_verify($token, $row['token_hash'])) {
        return ['valid' => false, 'user_id' => null, 'selector' => null];
    }

    return ['valid' => true, 'user_id' => intval($row['user_id']), 'selector' => $selector];
}

/**
 * 生成并设置"记住我"Cookie
 * 
 * 生成安全的 selector + token，将 token 哈希存入数据库，
 * Cookie 格式为 "selector|token"。
 * 采用 selector/token 分离方案：selector 明文用于快速查询，
 * token 用 bcrypt 哈希存储，防止数据库泄露后直接伪造 Cookie。
 * 
 * @param int $userId 用户ID
 * @return void
 */
function setRememberMeCookie($userId) {
    ensureRememberTokensTable();

    // 生成安全的 selector 和 token
    $selector = bin2hex(random_bytes(16));
    $token = bin2hex(random_bytes(32));
    $tokenHash = password_hash($token, PASSWORD_BCRYPT);
    
    // 计算过期时间
    $expiresAt = date('Y-m-d H:i:s', time() + REMEMBER_ME_DURATION);
    
    // 存储到数据库
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at) 
        VALUES (?, ?, ?, ?)");
    $stmt->execute([$userId, $selector, $tokenHash, $expiresAt]);
    
    // 设置 Cookie：selector|token
    $cookieValue = $selector . '|' . $token;
    setcookie('remember_me', $cookieValue, [
        'expires'   => time() + REMEMBER_ME_DURATION,
        'path'      => '/',
        'secure'    => isHttps(),
        'httponly'  => true,
        'samesite'  => 'Strict'
    ]);
}

/**
 * 清除"记住我"Cookie
 * 
 * 从数据库中删除对应的 remember_tokens 记录，
 * 并将浏览器中的 remember_me Cookie 设为过期。
 * 
 * @return void
 */
function clearRememberMeCookie() {
    // 从数据库删除
    $rememberMeCookie = $_COOKIE['remember_me'] ?? '';
    if (!empty($rememberMeCookie) && strpos($rememberMeCookie, '|') !== false) {
        list($selector, $token) = explode('|', $rememberMeCookie, 2);
        $db = getDB();
        $stmt = $db->prepare("DELETE FROM remember_tokens WHERE selector = ?");
        $stmt->execute([$selector]);
    }
    
    // 清除 Cookie
    setcookie('remember_me', '', [
        'expires'   => time() - 3600,
        'path'      => '/',
        'secure'    => isHttps(),
        'httponly'  => true,
        'samesite'  => 'Strict'
    ]);
}

/**
 * 登录成功后建立会话
 * 
 * 执行以下操作：
 * 1. 重新生成会话ID（防会话固定攻击）
 * 2. 写入用户信息到 $_SESSION
 * 3. 重新生成 CSRF token
 * 4. 清理旧的 remember_tokens（防旧设备继续自动登录）
 * 5. 根据"记住我"选项设置或清除 Cookie
 * 6. 更新 last_login_at
 * 
 * @param array $user 用户记录（含 id, username 等）
 * @param bool $rememberMe 是否勾选"记住我"
 * @return void
 */
function loginUserSession($user, $rememberMe = false) {
    // 重新生成会话 ID，防止会话固定
    session_regenerate_id(true);
    $_SESSION['user_id']  = intval($user['id']);
    $_SESSION['username'] = $user['username'];
    $_SESSION['login_at'] = date('Y-m-d H:i:s');

    // 重新生成 CSRF token，防止登录前 token 被滥用
    rotateCsrfToken();

    // 登录时清理该用户所有旧的 remember_tokens 记录
    // 防止旧设备/旧 cookie 仍能自动登录
    $db = getDB();
    $db->prepare("DELETE FROM remember_tokens WHERE user_id = ?")
        ->execute([$user['id']]);

    // 仅当用户勾选"记住我"时，才写入新的 remember_tokens + cookie
    if ($rememberMe) {
        setRememberMeCookie($user['id']);
    } else {
        // 不勾选记住我：主动清除浏览器上的旧 remember_me cookie
        setcookie('remember_me', '', [
            'expires'   => time() - 3600,
            'path'      => '/',
            'secure'    => isHttps(),
            'httponly'  => true,
            'samesite'  => 'Lax'
        ]);
    }

    $stmt = $db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
    $stmt->execute([$user['id']]);
}

/**
 * 获取当前登录用户信息
 * 
 * 从 $_SESSION 中获取 user_id，查询数据库返回用户信息。
 * 
 * @return array|null 用户记录（id, username, last_login_at），未登录返回null
 */
function currentUser() {
    if (empty($_SESSION['user_id'])) return null;
    $db = getDB();
    $stmt = $db->prepare("SELECT id, username, last_login_at FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * 强制要求 Web 登录
 * 
 * 检查 $_SESSION['user_id'] 是否存在，未登录则重定向到登录页。
 * 用于 index.php 等需要登录才能访问的页面。
 * 
 * @return void 已登录时无返回值，未登录时重定向到 login.php
 */
function requireWebLogin() {
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

/**
 * 用户退出登录
 * 
 * 执行完整的退出流程：
 * 1. 清除"记住我"Cookie 及数据库中的对应 token
 * 2. 清空 $_SESSION 数据
 * 3. 删除会话 Cookie
 * 4. 销毁会话
 * 
 * @return void
 */
function logoutUser() {
    // 清除记住我 Cookie
    clearRememberMeCookie();
    
    // 清除会话
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/**
 * 获取客户端真实 IP 地址
 * 
 * 仅在可信反代（127.0.0.1 / ::1）时才信任 X-Forwarded-For。
 * 自动处理 IPv6 映射地址（::ffff:x.x.x.x → x.x.x.x）。
 * 
 * @return string 客户端 IP 地址
 */
function clientIp() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // 仅当请求来自可信反代时，才采用 X-Forwarded-For 中的客户端 IP
    $trustedProxies = ['127.0.0.1', '::1'];
    if (in_array($ip, $trustedProxies, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $forwarded = trim($parts[0]);
        if (filter_var($forwarded, FILTER_VALIDATE_IP)) {
            $ip = $forwarded;
        }
    }
    // IPv6 压缩处理
    if (strpos($ip, '::ffff:') === 0) {
        $ip = substr($ip, 7);
    }
    return $ip;
}

// ====== 登录锁定（基于 login_locks 表） ======

/**
 * 检查指定IP是否存在登录失败记录
 * 
 * 在 IP_ATTEMPT_WINDOW 时间窗口内查询 login_failures 表。
 * 用于判断是否需要显示验证码。
 * 
 * @param string $ip 客户端IP地址
 * @return bool true=有失败记录, false=无失败记录
 */
function hasIpFailedAttempt($ip) {
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM login_failures
        WHERE ip_address = ? AND failed_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $stmt->execute([$ip, IP_ATTEMPT_WINDOW]);
    return intval($stmt->fetch()['cnt']) > 0;
}

/**
 * 检查IP是否被锁定
 * 
 * 查询 login_locks 表，检查该IP是否有未过期的锁定记录。
 * 
 * @return bool true=被锁定, false=未锁定
 */
function isIpLocked() {
    ensureLoginLocksTable();
    $ip = clientIp();
    $db = getDB();
    $stmt = $db->prepare("SELECT unlock_at FROM login_locks
        WHERE lock_type = 'ip' AND lock_key = ? AND unlock_at > NOW() LIMIT 1");
    $stmt->execute([$ip]);
    return $stmt->fetch() !== false;
}

/**
 * 检查用户名是否被锁定
 * 
 * 查询 login_locks 表，检查该用户名是否有未过期的锁定记录。
 * 
 * @param string $username 用户名
 * @return bool true=被锁定, false=未锁定
 */
function isUsernameLocked($username) {
    if (empty($username)) return false;
    ensureLoginLocksTable();
    $username = strtolower(trim($username));
    $db = getDB();
    $stmt = $db->prepare("SELECT unlock_at FROM login_locks
        WHERE lock_type = 'username' AND lock_key = ? AND unlock_at > NOW() LIMIT 1");
    $stmt->execute([$username]);
    return $stmt->fetch() !== false;
}

/**
 * 获取IP锁定剩余时间
 * 
 * @return int 剩余分钟数，未锁定时返回0
 */
function ipLockoutRemainingMinutes() {
    if (!isIpLocked()) return 0;
    $ip = clientIp();
    $db = getDB();
    $stmt = $db->prepare("SELECT unlock_at FROM login_locks
        WHERE lock_type = 'ip' AND lock_key = ? AND unlock_at > NOW() LIMIT 1");
    $stmt->execute([$ip]);
    $row = $stmt->fetch();
    if (!$row) return 0;
    $remaining = strtotime($row['unlock_at']) - time();
    return max(1, intval(ceil($remaining / 60)));
}

/**
 * 获取用户名锁定剩余时间
 * 
 * @param string $username 用户名
 * @return int 剩余分钟数，未锁定时返回0
 */
function usernameLockoutRemainingMinutes($username) {
    if (empty($username)) return 0;
    if (!isUsernameLocked($username)) return 0;
    $username = strtolower(trim($username));
    $db = getDB();
    $stmt = $db->prepare("SELECT unlock_at FROM login_locks
        WHERE lock_type = 'username' AND lock_key = ? AND unlock_at > NOW() LIMIT 1");
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if (!$row) return 0;
    $remaining = strtotime($row['unlock_at']) - time();
    return max(1, intval(ceil($remaining / 60)));
}

/**
 * 记录登录失败尝试
 * 
 * 将失败记录写入 login_failures 表，并检查是否达到锁定阈值：
 * - 用户名维度：达到 LOGIN_LOCK_THRESHOLD 次后锁定
 * - IP维度：达到 IP_LOCK_THRESHOLD 次后封禁
 * 
 * @param string $ip 客户端IP地址
 * @param string $username 用户名
 * @param bool $recordUsername 是否记录用户名维度（用户存在时为true，防止预锁定不存在的账号）
 * @return void
 */
function recordFailedAttempt($ip, $username, $recordUsername = true) {
    $username = strtolower(trim($username));
    ensureLoginLocksTable();
    $db = getDB();

    // 记录失败日志
    $db->prepare("INSERT INTO login_failures (ip_address, username) VALUES (?, ?)")
        ->execute([$ip, $username]);

    // 检查用户名是否达到锁定阈值（仅当该用户确实存在时）
    if ($recordUsername) {
        $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM login_failures
            WHERE username = ? AND failed_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)");
        $stmt->execute([$username, LOGIN_ATTEMPT_WINDOW]);
        if (intval($stmt->fetch()['cnt']) >= LOGIN_LOCK_THRESHOLD) {
            $unlockAt = date('Y-m-d H:i:s', time() + LOGIN_LOCKOUT_DURATION);
            $db->prepare("INSERT INTO login_locks (lock_type, lock_key, unlock_at)
                VALUES ('username', ?, ?)
                ON DUPLICATE KEY UPDATE unlock_at = VALUES(unlock_at)")
                ->execute([$username, $unlockAt]);
        }
    }

    // 检查 IP 是否达到封禁阈值（始终检查）
    $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM login_failures
        WHERE ip_address = ? AND failed_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $stmt->execute([$ip, IP_ATTEMPT_WINDOW]);
    if (intval($stmt->fetch()['cnt']) >= IP_LOCK_THRESHOLD) {
        $unlockAt = date('Y-m-d H:i:s', time() + IP_LOCKOUT_DURATION);
        $db->prepare("INSERT INTO login_locks (lock_type, lock_key, unlock_at)
            VALUES ('ip', ?, ?)
            ON DUPLICATE KEY UPDATE unlock_at = VALUES(unlock_at)")
            ->execute([$ip, $unlockAt]);
    }
}

/**
 * 清理登录失败记录和锁定
 * 
 * 登录成功时调用，删除该IP和用户名的所有失败记录及锁定。
 * 
 * @param string $ip 客户端IP地址
 * @param string $username 用户名
 * @return void
 */
function clearFailedAttempts($ip, $username) {
    $username = strtolower(trim($username));
    ensureLoginLocksTable();
    $db = getDB();
    $db->prepare("DELETE FROM login_failures WHERE ip_address = ? OR username = ?")
        ->execute([$ip, $username]);
    $db->prepare("DELETE FROM login_locks WHERE (lock_type = 'ip' AND lock_key = ?)
        OR (lock_type = 'username' AND lock_key = ?)")
        ->execute([$ip, $username]);
}

/**
 * 获取或生成 CSRF Token
 * 
 * 如果 Session 中没有 CSRF Token，则自动生成一个。
 * 
 * @return string CSRF Token（64位十六进制字符串）
 */
function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * 重新生成 CSRF Token
 * 
 * 强制生成新的 CSRF Token，用于登录成功后防止重放攻击。
 * 
 * @return string 新的 CSRF Token
 */
function rotateCsrfToken() {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

/**
 * 验证 CSRF Token
 * 
 * 使用 hash_equals 进行时间安全的比较，防止时序攻击。
 * 
 * @param string $token 待验证的 CSRF Token
 * @return bool true=验证通过, false=验证失败
 */
function verifyCsrfToken($token) {
    return !empty($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * 生成验证码
 * 
 * 生成4位随机验证码，避开易混淆字符（0/O/1/I/L）。
 * 验证码存储在 Session 中，有效期为 CAPTCHA_TTL 秒。
 * 
 * @return string 4位验证码
 */
function generateCaptcha() {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 4; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    $_SESSION['captcha_code'] = $code;
    $_SESSION['captcha_time'] = time();
    return $code;
}

// 验证码有效期（秒）
if (!defined('CAPTCHA_TTL')) {
    define('CAPTCHA_TTL', 300);
}

/**
 * 验证码验证
 * 
 * 验证用户输入的验证码是否正确。验证码不区分大小写，且一次性使用。
 * 验证成功后自动清除 Session 中的验证码，防止重复使用。
 * 
 * @param string $input 用户输入的验证码
 * @return bool 验证结果：true=正确，false=错误或已过期
 */
function verifyCaptcha($input) {
    if (empty($_SESSION['captcha_code']) || empty($_SESSION['captcha_time'])) return false;
    if (time() - $_SESSION['captcha_time'] > CAPTCHA_TTL) {
        unset($_SESSION['captcha_code'], $_SESSION['captcha_time']);
        return false;
    }
    $code = $_SESSION['captcha_code'];
    unset($_SESSION['captcha_code'], $_SESSION['captcha_time']);
    return is_string($input) && strtolower(trim($input)) === strtolower($code);
}

/**
 * 登录验证
 * 
 * 综合验证登录请求，包括：
 * 1. 检查 IP 是否被封禁
 * 2. 检查用户名是否被锁定
 * 3. 验证密码是否正确
 * 4. 记录失败尝试并检查是否达到锁定阈值
 * 
 * 安全特性：
 * - 用户不存在时只记录 IP 维度，防止攻击者预锁定不存在的账号
 * - 账号被禁用时不记录失败，直接拒绝
 * - 登录成功后自动清理失败记录
 * 
 * @param string $username 用户名
 * @param string $password 明文密码
 * @return array 验证结果：['ok' => bool, 'reason' => string, 'user' => array|null]
 *               reason 可能的值：'ip_locked', 'username_locked', 'account_disabled', 'invalid'
 */
function attemptLogin($username, $password) {
    $ip = clientIp();
    $uname = strtolower(trim($username));

    // 先检查IP是否被封禁（优先于密码验证）
    if (isIpLocked()) {
        return ['ok' => false, 'reason' => 'ip_locked'];
    }

    // 检查账号是否被锁定
    if (isUsernameLocked($uname)) {
        return ['ok' => false, 'reason' => 'username_locked'];
    }

    // 验证密码
    $user = findUserByUsername($username);

    if (!$user) {
        // 用户不存在：只记录 IP 维度（防止攻击者预锁定不存在的账号）
        recordFailedAttempt($ip, $uname, false);
    } elseif (!$user['is_active']) {
        // 账号被禁用：不记录失败，直接拒绝
        return ['ok' => false, 'reason' => 'account_disabled'];
    } elseif (!password_verify($password, $user['password_hash'])) {
        // 密码错误：记录 IP + 用户名维度
        recordFailedAttempt($ip, $uname, true);
    } else {
        // 登录成功：清理失败记录
        clearFailedAttempts($ip, $uname);
        return ['ok' => true, 'user' => $user];
    }

    // 检查账号是否达到锁定阈值
    if (isUsernameLocked($uname)) {
        return ['ok' => false, 'reason' => 'username_locked'];
    }

    // 检查IP是否达到封禁阈值
    if (isIpLocked()) {
        return ['ok' => false, 'reason' => 'ip_locked'];
    }

    return ['ok' => false, 'reason' => 'invalid'];
}

/**
 * 获取设备列表
 * 
 * 从 forward_records 表中查询所有不重复的设备名称，按字母顺序排序。
 * 用于前端筛选器的设备下拉菜单。
 * 
 * @param PDO $db 数据库连接实例
 * @return array 设备名称数组，按字母顺序排序
 */
function getDeviceList($db) {
    $stmt = $db->query("SELECT DISTINCT device_name FROM forward_records WHERE device_name != '' ORDER BY device_name");
    $devices = [];
    while ($row = $stmt->fetch()) {
        $devices[] = $row['device_name'];
    }
    return $devices;
}
