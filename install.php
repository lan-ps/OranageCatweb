<?php
/**
 * 系统初始化安装脚本
 * 
 * 功能说明:
 * - 自动创建系统所需的所有数据库表（不存在时创建）
 * - 检测是否已存在管理员账号
 * - 若不存在用户，显示创建管理员账号的表单
 * - 若已存在用户，显示完成提示并引导至登录页
 * 
 * 数据库表创建清单:
 * 1. users              - 用户表（存储账号信息）
 * 2. remember_tokens    - 记住我令牌表（自动登录功能）
 * 3. forward_records    - 转发记录表（存储短信/来电记录）
 * 4. login_failures     - 登录失败记录表（用于登录安全）
 * 5. login_locks        - 登录锁定表（账号/IP封禁）
 * 
 * 使用方法:
 * 1. 确保数据库配置正确（config.php 中的 DB_* 常量）
 * 2. 通过浏览器访问 install.php
 * 3. 按照提示创建第一个管理员账号
 * 4. 安装完成后，强烈建议删除或重命名此文件
 * 
 * 安全注意事项:
 * - 安装完成后务必删除此文件，防止被恶意利用
 * - 管理员密码应符合安全策略（至少8位，包含大小写字母和数字）
 * - 生产环境建议使用 HTTPS 访问
 * 
 * 访问地址: GET/POST /install.php
 */
define('APP_STARTED', true);
require_once __DIR__ . '/config.php';

// 设置响应内容类型
header('Content-Type: text/html; charset=utf-8');

// 初始化消息变量
$message = '';
$messageType = ''; // ok: 成功消息, err: 错误消息

try {
    // 确保所有必需的数据库表存在（不存在则创建）
    ensureUsersTable();           // 创建用户表
    ensureRememberTokensTable();  // 创建记住我令牌表
    ensureForwardRecordsTable();  // 创建转发记录表
    ensureLoginFailuresTable();   // 创建登录失败记录表
    ensureLoginLocksTable();      // 创建登录锁定表

    // 处理表单提交（创建管理员账号）
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // 检查是否已存在用户（防止重复创建）
        if (hasAnyUser()) {
            $message = '已存在用户账号，禁止重复创建。若忘记密码请用 phpMyAdmin / SQL 重置。';
            $messageType = 'err';
        } else {
            // 获取表单数据
            $u = trim($_POST['username'] ?? '');
            $p = $_POST['password'] ?? '';
            $p2 = $_POST['password2'] ?? '';

            // 验证表单数据
            if ($u === '' || $p === '') {
                $message = '账号和密码不能为空';
                $messageType = 'err';
            } elseif (strlen($u) > 64) {
                $message = '账号长度不能超过 64 个字符';
                $messageType = 'err';
            } elseif (strlen($p) < PASSWORD_MIN_LENGTH) {
                $message = '密码至少 ' . PASSWORD_MIN_LENGTH . ' 位';
                $messageType = 'err';
            } elseif ($p !== $p2) {
                $message = '两次输入的密码不一致';
                $messageType = 'err';
            } else {
                // 创建管理员账号（密码会自动进行 bcrypt 哈希）
                $id = createUser($u, $p);
                $message = '✔ 管理员账号创建成功（ID: ' . htmlspecialchars($id) . '），请前往登录';
                $messageType = 'ok';
            }
        }
    }
} catch (Throwable $e) {
    // 捕获所有异常（数据库连接失败、SQL错误等）
    $message = '错误：' . $e->getMessage();
    $messageType = 'err';
}

// 检查系统是否已初始化（是否存在用户）
$hasUser = false;
try { 
    $hasUser = hasAnyUser(); 
} catch (Throwable $e) {
    // 忽略数据库查询异常
} ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>初始化安装</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;background:#f0f2f5;margin:0;padding:40px 16px;}
.box{max-width:480px;margin:0 auto;background:#fff;border-radius:10px;padding:28px 32px;box-shadow:0 2px 12px rgba(0,0,0,0.06);}
h1{font-size:20px;margin:0 0 18px;color:#1a1a2e;}
.sub{font-size:13px;color:#999;margin-bottom:18px;}
.row{margin-bottom:14px;}
.row label{display:block;font-size:13px;color:#555;margin-bottom:6px;}
.row input{width:100%;padding:10px 12px;border:1px solid #d9d9d9;border-radius:6px;font-size:14px;outline:none;box-sizing:border-box;}
.row input:focus{border-color:#1890ff;}
.btn{display:inline-block;width:100%;padding:11px;background:#1890ff;color:#fff;border:none;border-radius:6px;font-size:15px;cursor:pointer;text-align:center;text-decoration:none;box-sizing:border-box;}
.btn:hover{background:#1678db;}
.ok{color:#389e0d;background:#f6ffed;border:1px solid #b7eb8f;padding:10px 14px;border-radius:6px;margin:10px 0;font-size:13px;}
.err{color:#cf1322;background:#fff1f0;border:1px solid #ffa39e;padding:10px 14px;border-radius:6px;margin:10px 0;font-size:13px;}
.tip{font-size:12px;color:#aaa;margin-top:18px;text-align:center;}
</style>
</head>
<body>
<div class="box">
<h1>短信转发系统 - 初始化</h1>

<?php if ($message): ?>
    <div class="<?= $messageType === 'ok' ? 'ok' : 'err' ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<?php if (!$hasUser): ?>
    <div class="sub">首次使用，请创建管理员账号</div>
    <form method="post" autocomplete="off">
        <div class="row">
            <label for="username">管理员账号</label>
            <input type="text" id="username" name="username" required autofocus maxlength="64">
        </div>
        <div class="row">
            <label for="password">密码（至少 <?= PASSWORD_MIN_LENGTH ?> 位）</label>
            <input type="password" id="password" name="password" required minlength="<?= PASSWORD_MIN_LENGTH ?>">
        </div>
        <div class="row">
            <label for="password2">再次输入密码</label>
            <input type="password" id="password2" name="password2" required minlength="<?= PASSWORD_MIN_LENGTH ?>">
        </div>
        <button type="submit" class="btn">创建并启用</button>
    </form>
    <div class="tip">此账号将拥有系统全部权限，密码请妥善保管</div>
<?php else: ?>
    <div class="sub">系统已初始化完成</div>
    <a class="btn" href="login.php">前往登录</a>
<?php endif; ?>
</div>
</body>
</html>
