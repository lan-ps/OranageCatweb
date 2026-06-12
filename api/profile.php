<?php
/**
 * 用户个人资料接口
 * 
 * 提供用户信息修改功能，包括修改用户名和密码
 * 
 * 接口地址: POST /api/profile.php
 * 
 * 认证方式: Cookie会话（必须已登录）
 * 
 * 请求体:
 * {
 *   "action": "change_username|change_password",
 *   "csrf": "CSRF令牌",
 *   // change_username 时需要:
 *   "new_username": "新用户名",
 *   // change_password 时需要（均为AES加密后的Base64字符串）:
 *   "old_password_encrypted": "旧密码",
 *   "new_password_encrypted": "新密码",
 *   "confirm_password_encrypted": "确认密码"
 * }
 * 
 * 成功响应:
 * - 修改用户名: {"code": 200, "msg": "用户名修改成功", "username": "新用户名"}
 * - 修改密码: {"code": 200, "msg": "密码修改成功"}
 * 
 * 错误响应:
 * - 400: 参数错误
 * - 401: 未登录
 * - 403: CSRF验证失败
 * - 405: 请求方法错误（仅支持POST）
 * - 409: 用户名已被占用
 * - 500: 服务器内部错误
 * 
 * 安全特性:
 * - 修改密码后自动清除所有 remember_tokens，强制所有设备重新登录
 * - 密码传输采用 AES-256-CBC 加密
 * - CSRF 令牌验证防止跨站请求伪造
 */
define('APP_STARTED', true);
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

// 强制要求登录（仅支持Cookie会话）
requireWebLogin();

// 验证请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['code' => 405, 'msg' => '仅支持POST请求']);
    exit;
}

try {
    // 解析请求体
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) {
        http_response_code(400);
        echo json_encode(['code' => 400, 'msg' => '请求体格式错误']);
        exit;
    }

    // CSRF 验证
    $csrf = $body['csrf'] ?? '';
    if (!verifyCsrfToken($csrf)) {
        http_response_code(403);
        echo json_encode(['code' => 403, 'msg' => 'CSRF验证失败']);
        exit;
    }

    // 获取操作类型并验证白名单
    $action = $body['action'] ?? '';
    if (!in_array($action, ['change_username', 'change_password'], true)) {
        http_response_code(400);
        echo json_encode(['code' => 400, 'msg' => '未知操作']);
        exit;
    }

    // 获取当前用户ID
    $userId = intval($_SESSION['user_id']);

    // 处理修改密码请求
    if ($action === 'change_password') {
        // 获取加密的密码参数
        $oldPasswordEncrypted = $body['old_password_encrypted'] ?? '';
        $newPasswordEncrypted = $body['new_password_encrypted'] ?? '';
        $confirmPasswordEncrypted = $body['confirm_password_encrypted'] ?? '';

        // 验证必填字段
        if (!$oldPasswordEncrypted || !$newPasswordEncrypted || !$confirmPasswordEncrypted) {
            echo json_encode(['code' => 400, 'msg' => '请填写所有字段']);
            exit;
        }

        // 解密前端传来的密码
        $oldPassword = aesDecrypt($oldPasswordEncrypted);
        $newPassword = aesDecrypt($newPasswordEncrypted);
        $confirmPassword = aesDecrypt($confirmPasswordEncrypted);

        // 检查解密是否成功
        if ($oldPassword === null || $newPassword === null || $confirmPassword === null) {
            echo json_encode(['code' => 400, 'msg' => '密码解密失败']);
            exit;
        }

        // 验证新密码长度
        if (strlen($newPassword) < PASSWORD_MIN_LENGTH) {
            echo json_encode(['code' => 400, 'msg' => '新密码至少' . PASSWORD_MIN_LENGTH . '个字符']);
            exit;
        }

        // 验证两次新密码一致
        if ($newPassword !== $confirmPassword) {
            echo json_encode(['code' => 400, 'msg' => '两次输入的新密码不一致']);
            exit;
        }

        // 验证新旧密码不同
        if ($oldPassword === $newPassword) {
            echo json_encode(['code' => 400, 'msg' => '新密码不能与旧密码相同']);
            exit;
        }

        // 验证旧密码正确性
        $db = getDB();
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($oldPassword, $user['password_hash'])) {
            echo json_encode(['code' => 401, 'msg' => '旧密码错误']);
            exit;
        }

        // 更新密码
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->execute([$hash, $userId]);

        // 清除所有记住我令牌（强制所有设备重新登录）
        $db->prepare("DELETE FROM remember_tokens WHERE user_id = ?")->execute([$userId]);

        echo json_encode(['code' => 200, 'msg' => '密码修改成功']);
        exit;

    // 处理修改用户名请求
    } elseif ($action === 'change_username') {
        $newUsername = trim($body['new_username'] ?? '');

        // 验证用户名非空
        if (!$newUsername) {
            echo json_encode(['code' => 400, 'msg' => '用户名不能为空']);
            exit;
        }

        // 验证用户名格式（中文、字母、数字、下划线、@、.、-，2-32字符）
        if (!preg_match('/^[\x{4e00}-\x{9fa5}a-zA-Z0-9_@.\-]{2,32}$/u', $newUsername)) {
            echo json_encode(['code' => 400, 'msg' => '用户名格式不正确']);
            exit;
        }

        $db = getDB();

        // 检查用户名是否已被占用
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
        $stmt->execute([$newUsername, $userId]);
        if ($stmt->fetch()) {
            echo json_encode(['code' => 409, 'msg' => '该用户名已被占用']);
            exit;
        }

        // 更新用户名
        $stmt = $db->prepare("UPDATE users SET username = ? WHERE id = ?");
        $stmt->execute([$newUsername, $userId]);

        // 同步更新会话中的用户名
        $_SESSION['username'] = $newUsername;

        echo json_encode(['code' => 200, 'msg' => '用户名修改成功', 'username' => $newUsername]);
        exit;
    }
    
} catch (PDOException $e) {
    // 数据库异常处理
    http_response_code(500);
    echo json_encode(['code' => 500, 'msg' => '操作失败，请稍后重试']);
}
