<?php
/**
 * 用户登录页面
 * 
 * 功能说明:
 * - 提供用户登录表单（账号 + 密码）
 * - 支持"记住我"功能（7天免登录）
 * - 密码通过 AES-256-CBC 加密传输（前端 Web Crypto API 加密）
 * - 登录失败后显示验证码（防止暴力破解）
 * - 账号/IP 锁定机制（防暴力破解）
 * 
 * 访问方式: GET/POST /login.php
 * 
 * 认证要求:
 * - 无需登录（已登录用户自动跳转到 index.php）
 * 
 * 安全特性:
 * - 生产环境强制 HTTPS 访问
 * - CSRF Token 验证（防跨站请求伪造）
 * - 密码前端加密传输（防中间人窃取）
 * - 验证码机制（登录失败后必填）
 * - 账号锁定：失败3次锁定5分钟
 * - IP封禁：失败5次封禁10分钟
 * 
 * 表单字段:
 * - username: 用户名（2-32字符，支持中文、字母、数字、下划线）
 * - password_encrypted: AES加密后的密码（前端加密后传输）
 * - csrf: CSRF令牌
 * - captcha: 验证码（条件必填）
 * - remember_me: 记住我选项
 * 
 * 依赖文件:
 * - config.php: 全局配置和公共函数
 * - captcha.php: 验证码图片生成
 */
define('APP_STARTED', true);
require_once __DIR__ . '/config.php';

// 已登录用户自动跳转到主页
if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$username = '';

// 一次密码错误就显示验证码（通过数据库 login_failures 表中当前IP的失败记录判断）
$showCap = hasIpFailedAttempt(clientIp());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 非 HTTPS 直接拒绝（生产环境，复用 config.php 中的 isHttps() 函数）
    if (IS_PRODUCTION && !isHttps()) {
        $error = '⚠ 请使用 HTTPS 访问本站，当前连接不安全';
    } else {
        $username = trim($_POST['username'] ?? '');
        $passwordEncrypted = $_POST['password_encrypted'] ?? '';
        $password = $passwordEncrypted !== '' ? (aesDecrypt($passwordEncrypted) ?? '') : '';
        $csrf = $_POST['csrf'] ?? '';
        $captcha = trim($_POST['captcha'] ?? '');
        $rememberMe = isset($_POST['remember_me']) && $_POST['remember_me'] === 'on';

        // 检查CSRF
        if (!verifyCsrfToken($csrf)) {
            $error = '会话已过期，请刷新页面重试';
        } elseif ($username === '' || $password === '') {
            $error = '请输入账号和密码';
        } elseif (!preg_match('/^[\x{4e00}-\x{9fa5}a-zA-Z0-9_@.\-]{2,32}$/u', $username)) {
            $error = '账号格式不正确';
        } else {
            // 如果当前IP已有失败记录，则必须先通过验证码
            if (hasIpFailedAttempt(clientIp()) && !verifyCaptcha($captcha)) {
                $error = '验证码错误';
            } else {
                // 调用登录验证
                $result = attemptLogin($username, $password);

                if ($result['ok']) {
                    // 登录成功
                    loginUserSession($result['user'], $rememberMe);
                    header('Location: index.php');
                    exit;
                }

                // 登录失败，根据原因显示不同提示
                if ($result['reason'] === 'username_locked') {
                    $mins = usernameLockoutRemainingMinutes($username);
                    $error = " 当前账号登录失败过多，已临时锁定 {$mins} 分钟";
                } elseif ($result['reason'] === 'ip_locked') {
                    $mins = ipLockoutRemainingMinutes();
                    $error = "⚠ 当前IP登录失败过于频繁，已临时封禁 {$mins} 分钟";
                } elseif ($result['reason'] === 'account_disabled') {
                    $error = "⚠ 该账号已被禁用，请联系管理员";
                } else {
                    $error = '账号或密码错误';
                }
            }
        }

        // 登录失败：重新查询是否需要显示验证码（attemptLogin 已记录了新的失败）
        $showCap = hasIpFailedAttempt(clientIp());
    }
}

$csrfToken = csrfToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<meta name="theme-color" content="#eef2f6">
<title>登录 - 橘猫客户端</title>
<link rel="icon" href="favicon.ico" />
<style>
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{height:100%}
body{
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;
    background:linear-gradient(160deg,#e8ecf1 0%,#eef2f6 40%,#f1f5f9 100%);
    color:#1e293b;
    min-height:100vh;min-height:100dvh;
    display:flex;align-items:center;justify-content:center;
    padding:16px;
    padding-top:max(16px,env(safe-area-inset-top));
    padding-bottom:max(16px,env(safe-area-inset-bottom));
    position:relative;
}

/* 光晕 */
.glow{position:fixed;border-radius:50%;pointer-events:none;filter:blur(100px);z-index:0}
.glow-1{
    width:550px;height:550px;
    background:radial-gradient(circle,rgba(59,130,246,0.13) 0%,transparent 70%);
    top:-180px;left:-120px;
    animation:float1 10s ease-in-out infinite;
}
.glow-2{
    width:450px;height:450px;
    background:radial-gradient(circle,rgba(14,165,233,0.1) 0%,transparent 70%);
    bottom:-120px;right:-100px;
    animation:float2 12s ease-in-out infinite reverse;
}
.glow-3{
    width:380px;height:380px;
    background:radial-gradient(circle,rgba(245,158,11,0.06) 0%,transparent 70%);
    top:50%;left:50%;transform:translate(-50%,-50%);
    animation:pulse 7s ease-in-out infinite;
}
@keyframes float1{
    0%,100%{transform:translate(0,0) scale(1)}
    50%{transform:translate(40px,30px) scale(1.15)}
}
@keyframes float2{
    0%,100%{transform:translate(0,0) scale(1)}
    50%{transform:translate(-30px,-30px) scale(1.1)}
}
@keyframes pulse{
    0%,100%{transform:translate(-50%,-50%) scale(1);opacity:0.6}
    50%{transform:translate(-50%,-50%) scale(1.3);opacity:1}
}

/* 水波纹 */
#rippleCanvas{position:fixed;inset:0;pointer-events:none;z-index:1}

/* 卡片 */
.login-box{
    position:relative;z-index:2;
    background:rgba(255,255,255,0.85);
    backdrop-filter:blur(20px);
    -webkit-backdrop-filter:blur(20px);
    border:1px solid rgba(0,0,0,0.06);
    width:100%;max-width:420px;
    border-radius:24px;
    padding:44px 36px 40px;
    box-shadow:0 0 0 1px rgba(0,0,0,0.03),
               0 16px 48px rgba(0,0,0,0.08),
               0 0 60px rgba(59,130,246,0.04);
    animation:cardIn 0.55s cubic-bezier(0.23,1,0.32,1);
}
@keyframes cardIn{
    from{opacity:0;transform:translateY(36px) scale(0.96)}
    to{opacity:1;transform:translateY(0) scale(1)}
}
.login-box::before{
    content:'';position:absolute;top:0;left:28px;right:28px;
    height:1px;
    background:linear-gradient(90deg,transparent,rgba(59,130,246,0.2),rgba(14,165,233,0.2),transparent);
}
@media(max-width:480px){
    .login-box{border-radius:20px;padding:36px 24px 32px}
}

/* Brand */
.brand{text-align:center;margin-bottom:30px}
.brand .logo{
    width:66px;height:66px;border-radius:16px;
    object-fit:cover;margin-bottom:16px;
    box-shadow:0 0 0 3px rgba(59,130,246,0.12),0 8px 24px rgba(59,130,246,0.15);
    transition:all 0.3s ease;
}
.brand .logo:hover{
    transform:scale(1.06);
    box-shadow:0 0 0 4px rgba(59,130,246,0.2),0 12px 32px rgba(59,130,246,0.22);
}
.brand h1{
    font-size:21px;color:#0f172a;margin-bottom:5px;font-weight:700;letter-spacing:0.3px;
}
.brand .sub{font-size:13px;color:#94a3b8;letter-spacing:0.8px}

/* 表单 */
.form-group{margin-bottom:18px}
.form-group label{
    display:block;font-size:12px;color:#64748b;
    margin-bottom:6px;font-weight:600;letter-spacing:0.5px;
}
.input-wrap{position:relative;display:flex;align-items:center}
.input-wrap .icon{
    position:absolute;left:14px;color:#94a3b8;z-index:1;
    font-size:16px;pointer-events:none;transition:color 0.25s;
}
.form-group input{
    position:relative;z-index:0;
    width:100%;padding:14px 16px 14px 42px;
    background:#f8fafc;
    border:1.5px solid #e2e8f0;
    border-radius:12px;font-size:15px;
    color:#0f172a;outline:none;
    transition:all 0.25s cubic-bezier(0.4,0,0.2,1);
    font-family:inherit;
    -webkit-appearance:none;appearance:none;
}
.form-group input::placeholder{color:#94a3b8}
.form-group input:focus{
    background:#fff;
    border-color:#3b82f6;
    box-shadow:0 0 0 4px rgba(59,130,246,0.1);
    transform:translateY(-1px);
}
.input-wrap:focus-within .icon{color:#3b82f6}
.form-group input.pwd{letter-spacing:0.1em}

/* 验证码 */
.captcha-row{display:flex;gap:10px;align-items:stretch}
.captcha-row .form-group{flex:1;margin-bottom:0}
.captcha-img{
    height:50px;width:130px;border-radius:12px;
    border:1.5px solid #e2e8f0;
    cursor:pointer;background:#f8fafc;
    flex-shrink:0;display:block;transition:all 0.2s;
}
.captcha-img:hover{border-color:#3b82f6;transform:scale(1.03)}
.captcha-img:active{opacity:0.85;transform:scale(0.97)}
@media(max-width:380px){.captcha-img{width:110px}}

/* 按钮 */
.btn-login{
    width:100%;padding:15px;
    background:linear-gradient(135deg,#3b82f6 0%,#0ea5e9 50%,#06b6d4 100%);
    background-size:200% 200%;
    color:#fff;border:none;border-radius:13px;
    font-size:16px;font-weight:600;cursor:pointer;
    margin-top:10px;letter-spacing:0.8px;
    transition:all 0.3s cubic-bezier(0.4,0,0.2,1);
    box-shadow:0 4px 16px rgba(59,130,246,0.25);
    position:relative;overflow:hidden;
}
.btn-login::after{
    content:'';position:absolute;inset:0;
    background:linear-gradient(90deg,transparent,rgba(255,255,255,0.2),transparent);
    transform:translateX(-100%);transition:transform 0.5s ease;
}
.btn-login:hover{
    transform:translateY(-2px);
    box-shadow:0 8px 28px rgba(59,130,246,0.35);
    background-position:100% 50%;
}
.btn-login:hover::after{transform:translateX(100%)}
.btn-login:active{transform:translateY(0) scale(0.98)}
.btn-login:disabled{
    opacity:0.45;cursor:not-allowed;box-shadow:none;transform:none;
}

/* 反馈 */
.error{
    background:#fef2f2;border:1px solid #fecaca;
    color:#dc2626;padding:12px 14px;border-radius:12px;
    font-size:13px;margin-bottom:16px;text-align:center;
    line-height:1.5;animation:shake 0.4s ease;
}
.warn-banner{
    background:#fffbeb;border:1px solid #fde68a;
    color:#d97706;padding:12px 14px;border-radius:12px;
    font-size:12px;margin-bottom:16px;line-height:1.5;
}
.lock-banner{
    background:#fef2f2;border:1px solid #fecaca;
    color:#dc2626;padding:14px;border-radius:12px;
    font-size:13px;margin-bottom:16px;
    text-align:center;line-height:1.6;
}
.lock-banner .timer{font-weight:700;font-size:15px}
@keyframes shake{
    0%,100%{transform:translateX(0)}
    20%{transform:translateX(-5px)}
    40%{transform:translateX(5px)}
    60%{transform:translateX(-3px)}
    80%{transform:translateX(3px)}
}
/* 记住我复选框 */
.remember-me{
    display:flex;align-items:center;gap:10px;
    margin-top:12px;cursor:pointer;
    user-select:none;-webkit-user-select:none;
}
.remember-me input{
    position:absolute;opacity:0;pointer-events:none;
}
.remember-me .checkmark{
    width:18px;height:18px;border:2px solid #cbd5e1;
    border-radius:5px;position:relative;flex-shrink:0;
    transition:all 0.2s ease;
}
.remember-me:hover .checkmark{border-color:#94a3b8}
.remember-me input:checked + .checkmark{
    background:linear-gradient(135deg,#3b82f6,#0ea5e9);
    border-color:#3b82f6;
}
.remember-me input:checked + .checkmark::after{
    content:'';position:absolute;left:5px;top:1px;
    width:4px;height:8px;border:solid #fff;border-width:0 2px 2px 0;
    transform:rotate(45deg);
}
.remember-me .text{font-size:13px;color:#64748b}
.remember-me:hover .text{color:#475569}

.tip{
    font-size:12px;color:#94a3b8;text-align:center;
    margin-top:24px;line-height:1.6;
}
.tip a{color:#3b82f6;text-decoration:none;font-weight:500;transition:color 0.2s}
.tip a:hover{color:#2563eb;text-decoration:underline}
</style>
</head>
<body>
<canvas id="rippleCanvas"></canvas>
<!-- 光晕 -->
<div class="glow glow-1"></div>
<div class="glow glow-2"></div>
<div class="glow glow-3"></div>

<div class="login-box">
    <div class="brand">
        <img class="logo" src="logo.png" alt="Logo" />
        <h1>橘猫客户端</h1>
        <div class="sub">来电来信转发系统</div>
    </div>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off" novalidate id="loginForm">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="password_encrypted" id="passwordEncrypted" value="">

        <div class="form-group">
            <label for="username">账号</label>
            <div class="input-wrap">
                <span class="icon">👤</span>
                <input type="text" id="username" name="username" value="<?= htmlspecialchars($username) ?>"
                       required autofocus autocomplete="username"
                       autocapitalize="off" autocorrect="off" spellcheck="false"
                       inputmode="text" enterkeyhint="next" placeholder="请输入账号">
            </div>
        </div>

        <div class="form-group">
            <label for="password">密码</label>
            <div class="input-wrap">
                <span class="icon">🔒</span>
                <input type="password" id="password" class="pwd"
                       required autocomplete="current-password"
                       inputmode="text" enterkeyhint="done" placeholder="请输入密码">
            </div>
        </div>

        <?php if ($showCap): ?>
        <div class="form-group">
            <label for="captcha">验证码（密码错误后必填）</label>
            <div class="captcha-row">
                <div class="form-group">
                    <div class="input-wrap">
                        <span class="icon">😂</span>
                        <input type="text" id="captcha" name="captcha"
                               maxlength="4" autocomplete="off"
                               autocapitalize="characters" autocorrect="off" spellcheck="false"
                               inputmode="text" enterkeyhint="done"
                               pattern="[A-Za-z0-9]{4}" placeholder="请输入验证码">
                    </div>
                </div>
                <img class="captcha-img" id="captchaImg" src="captcha.php?v=<?= time() ?>" alt="验证码" title="点击换一张">
            </div>
        </div>
        <?php endif; ?>

        <label class="remember-me">
            <input type="checkbox" name="remember_me" id="rememberMe">
            <span class="checkmark"></span>
            <span class="text">记住我（有效期 <?= intval(REMEMBER_ME_DURATION / 86400) ?> 天）</span>
        </label>

        <button type="submit" class="btn-login" id="submitBtn">登 录</button>
    </form>

    <div class="tip">
        账号失败 <?= LOGIN_LOCK_THRESHOLD ?> 次锁定 <?= intval(LOGIN_ATTEMPT_WINDOW / 60) ?> 分钟 | IP失败 <?= IP_LOCK_THRESHOLD ?> 次封禁 <?= intval(IP_ATTEMPT_WINDOW / 60) ?> 分钟
    </div>
</div>

<script>
// ── 鼠标水波纹涟漪 ──
(function() {
    var canvas = document.getElementById('rippleCanvas');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    var ripples = [];
    var w, h;

    function resize() {
        w = canvas.width  = window.innerWidth;
        h = canvas.height = window.innerHeight;
    }
    resize();
    window.addEventListener('resize', resize);

    // 节流：每 40ms 最多一个涟漪
    var lastTs = 0;
    document.addEventListener('mousemove', function(e) {
        var now = Date.now();
        if (now - lastTs < 40) return;
        lastTs = now;
        ripples.push({ x: e.clientX, y: e.clientY, r: 0, opacity: 0.5, ts: now });
    });

    function draw() {
        ctx.clearRect(0, 0, w, h);
        var now = Date.now();

        for (var i = ripples.length - 1; i >= 0; i--) {
            var rp = ripples[i];
            var elapsed = (now - rp.ts) / 1000;
            rp.r += 1.2;
            rp.opacity -= 0.005;

            if (rp.opacity <= 0 || rp.r > 200) {
                ripples.splice(i, 1);
                continue;
            }

            // 双重同心圆，更有水波感
            for (var ring = 0; ring < 2; ring++) {
                var rr = rp.r - ring * 12;
                if (rr <= 0) continue;
                var alpha = rp.opacity * (1 - ring * 0.45);
                if (alpha <= 0) continue;

                ctx.beginPath();
                ctx.arc(rp.x, rp.y, rr, 0, Math.PI * 2);
                var grad = ctx.createRadialGradient(rp.x, rp.y, rr * 0.6, rp.x, rp.y, rr);
                grad.addColorStop(0, 'rgba(255,255,255,' + alpha + ')');
                grad.addColorStop(0.5, 'rgba(59,130,246,' + (alpha * 0.5) + ')');
                grad.addColorStop(1, 'rgba(14,165,233,' + (alpha * 0.15) + ')');
                ctx.strokeStyle = grad;
                ctx.lineWidth = 1.8;
                ctx.stroke();
            }
        }

        requestAnimationFrame(draw);
    }
    draw();
})();


// 验证码点击刷新
(function() {
    var img = document.getElementById('captchaImg');
    if (img) {
        img.addEventListener('click', function() {
            img.src = 'captcha.php?v=' + Date.now();
        });
    }
})();

// 锁定倒计时刷新（支持所有 .timer 元素）
(function() {
    var timers = document.querySelectorAll('.timer');
    timers.forEach(function(t) {
        var s = parseInt(t.getAttribute('data-seconds') || '0', 10);
        if (s <= 0) return;
        function tick() {
            s--;
            if (s <= 0) { 
                // 倒计时结束时刷新页面
                location.reload(); 
                return; 
            }
            var m = Math.floor(s / 60), sec = s % 60;
            if (m > 0) {
                t.textContent = m + ' 分 ' + sec + ' 秒';
            } else {
                t.textContent = sec + ' 秒';
            }
            setTimeout(tick, 1000);
        }
        tick();
    });
})();

// AES 加密工具函数（基于 Web Crypto API）
async function aesEncrypt(key, plaintext) {
    var iv = crypto.getRandomValues(new Uint8Array(16));
    var keyBytes = new TextEncoder().encode(key);
    // 密钥长度补齐/截断到 32 字节（AES-256）
    var paddedKey = new Uint8Array(32);
    paddedKey.set(keyBytes.slice(0, 32));
    keyBytes = paddedKey;
    
    var cryptoKey = await crypto.subtle.importKey(
        'raw', keyBytes, { name: 'AES-CBC' }, false, ['encrypt']
    );
    
    var encrypted = await crypto.subtle.encrypt(
        { name: 'AES-CBC', iv: iv },
        cryptoKey,
        new TextEncoder().encode(plaintext)
    );
    
    // 拼接 IV + 密文，Base64 编码
    var result = new Uint8Array(iv.length + encrypted.byteLength);
    result.set(iv, 0);
    result.set(new Uint8Array(encrypted), iv.length);
    return btoa(String.fromCharCode.apply(null, result));
}

// 动态显示错误提示
function showError(msg) {
    var el = document.querySelector('.error');
    if (!el) {
        el = document.createElement('div');
        el.className = 'error';
        var logo = document.querySelector('.brand');
        if (logo) logo.after(el);
    }
    el.textContent = msg;
    el.style.display = 'block';
}

// 防止重复提交 + 密码加密
(function() {
    var form = document.getElementById('loginForm');
    var btn  = document.getElementById('submitBtn');
    var passwordInput = document.getElementById('password');
    var passwordEncryptedInput = document.getElementById('passwordEncrypted');
    
    if (!form || !btn || !passwordInput || !passwordEncryptedInput) return;
    
    // 页面加载时检查 HTTPS：非 HTTPS 直接禁用表单
    var isSecure = window.isSecureContext || location.protocol === 'https:';
    if (!isSecure) {
        passwordInput.disabled = true;
        passwordInput.placeholder = '请使用 HTTPS 访问';
        passwordInput.style.background = '#fee2e2';
        passwordInput.style.borderColor = '#ef4444';
        passwordInput.style.color = '#991b1b';
        btn.disabled = true;
        btn.style.background = '#94a3b8';
        btn.style.cursor = 'not-allowed';
        btn.textContent = '需要 HTTPS';
        showError(' 请使用 HTTPS 访问本站，当前连接不安全');
        return;
    }
    
    var sent = false;
    var encryptionKey = 'sms_encryption_key_32bytes!'; // 必须与服务端一致
    
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        if (sent) return false;
        
        // 加密密码
        var password = passwordInput.value;
        if (password) {
            try {
                var encrypted = await aesEncrypt(encryptionKey, password);
                passwordEncryptedInput.value = encrypted;
                passwordInput.value = ''; // 清空明文密码
            } catch (e) {
                console.error('Encryption error:', e);
                showError('密码加密失败，请使用 HTTPS 访问');
                return false;
            }
        }
        
        sent = true;
        btn.disabled = true;
        btn.textContent = '登录中...';
        
        // 提交表单
        form.submit();
        
        setTimeout(function() { 
            sent = false; 
            btn.disabled = false; 
            btn.textContent = '登 录'; 
        }, 8000);
    });
})();
</script>
</body>
</html>