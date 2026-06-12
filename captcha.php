<?php
/**
 * 图片验证码生成接口
 * 
 * 功能说明:
 * - 生成4位随机字母数字验证码图片（PNG格式）
 * - 验证码答案存储在 Session 中，有效期为 CAPTCHA_TTL 秒
 * - 验证码不区分大小写，一次性使用（验证后立即清除）
 * 
 * 访问方式: GET /captcha.php
 * 
 * 认证要求:
 * - 无需登录
 * - 已登录用户禁止访问（返回403）
 * 
 * 安全特性:
 * - 添加噪点（280个随机像素点）防止OCR识别
 * - 添加干扰线（5条随机线条）增加识别难度
 * - 字符位置随机偏移，防止模板攻击
 * - 禁用浏览器缓存（防止验证码被缓存重用）
 * 
 * 图片参数:
 * - 尺寸: 130x48 像素
 * - 背景色: RGB(245, 247, 250)
 * - 文字颜色: RGB(32, 38, 56)
 * - 噪点颜色: RGB(190, 195, 210)
 * - 干扰线颜色: RGB(210, 215, 225)
 * 
 * 响应头:
 * - Content-Type: image/png
 * - Cache-Control: no-store, no-cache, must-revalidate, max-age=0
 * - Pragma: no-cache
 * - Expires: 0
 * 
 * 依赖:
 * - config.php: 全局配置和 Session 管理
 * - GD 扩展: 图片生成（imagecreatetruecolor）
 * 
 * 使用场景:
 * - 登录失败后显示验证码（防止暴力破解）
 * - 由 login.php 通过 <img> 标签引用
 */
define('APP_STARTED', true);
require_once __DIR__ . '/config.php';

// 已登录用户禁止访问验证码接口（防止滥用）
if (!empty($_SESSION['user_id'])) {
    http_response_code(403);
    exit;
}

// 检查 GD 扩展是否可用
if (!function_exists('imagecreatetruecolor')) {
    http_response_code(500);
    exit('GD extension required');
}

// 生成验证码（4位随机字符，存储在 Session 中）
$code = generateCaptcha();

// 图片尺寸配置
$width  = 130;
$height = 48;
$im = imagecreatetruecolor($width, $height);

// 调色板定义（背景、文字、噪点、干扰线）
$bg        = imagecolorallocate($im, 245, 247, 250);
$textColor = imagecolorallocate($im, 32, 38, 56);
$noiseCol  = imagecolorallocate($im, 190, 195, 210);
$lineCol   = imagecolorallocate($im, 210, 215, 225);

// 填充背景色
imagefilledrectangle($im, 0, 0, $width, $height, $bg);

// 添加噪点（280个随机像素点，防止OCR识别）
for ($i = 0; $i < 280; $i++) {
    imagesetpixel($im, random_int(0, $width - 1), random_int(0, $height - 1), $noiseCol);
}

// 添加干扰线（5条随机线条，增加识别难度）
for ($i = 0; $i < 5; $i++) {
    imageline(
        $im,
        random_int(0, $width),  random_int(0, $height),
        random_int(0, $width),  random_int(0, $height),
        $lineCol
    );
}

// 绘制验证码字符（每个字符位置随机偏移，防止模板攻击）
$len = strlen($code);
$slot = intval($width / ($len + 1));
for ($i = 0; $i < $len; $i++) {
    $x = $slot * ($i + 1) - 10 + random_int(-3, 3);  // X轴随机偏移±3像素
    $y = random_int(12, 22);                          // Y轴随机位置
    imagestring($im, 5, $x, $y, $code[$i], $textColor);
}

// 输出图片（PNG格式）
header('Content-Type: image/png');
// 禁用浏览器缓存（防止验证码被缓存重用）
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
imagepng($im);
imagedestroy($im);
