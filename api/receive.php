<?php
/**
 * 接收转发记录接口
 * 
 * 接收来自客户端设备的短信/来电转发记录并入库
 * 
 * 接口地址: POST /api/receive.php
 * 
 * 认证方式: Token认证（通过 HTTP_TOKEN header 或 token 参数传递）
 * 
 * 请求体（JSON或Form Data）:
 * {
 *   "device_name": "设备名称",      // 必填，发送设备标识
 *   "type": "sms|call",            // 必填，类型：短信或来电
 *   "phone_number": "电话号码",     // 必填，来电/短信号码
 *   "event_time": "事件时间",       // 必填，格式 YYYY-MM-DD HH:MM:SS
 *   "content": "内容",              // 可选，短信内容（来电时为空）
 *   "location": "归属地",           // 可选，号码归属地
 *   // 以下为兼容旧版字段：
 *   "call_time": "通话时间",        // 兼容字段，来电时使用
 *   "receive_time": "接收时间"      // 兼容字段，短信时使用
 * }
 * 
 * 成功响应:
 * {
 *   "code": 200,
 *   "msg": "success",
 *   "id": 123  // 新插入记录的ID
 * }
 * 
 * 错误响应:
 * - 400: 参数错误（缺少必填字段或类型错误）
 * - 401: 未授权（Token无效）
 * - 405: 请求方法错误（仅支持POST）
 * - 500: 服务器内部错误（数据库插入失败）
 * 
 * 注意事项:
 * - event_time 优先使用，若未提供则根据类型使用 call_time 或 receive_time
 * - 支持 JSON 和 Form Data 两种格式的请求体
 */
define('APP_STARTED', true);
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

// 设备端Token认证（仅允许携带正确Token的设备上报数据）
checkAuth();

// 验证请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['code' => 405, 'msg' => '仅支持POST请求']);
    exit;
}

// 解析请求体（支持JSON和Form Data）
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

// 验证必填字段
$requiredFields = ['device_name', 'type', 'phone_number', 'event_time'];
foreach ($requiredFields as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['code' => 400, 'msg' => "缺少必填字段: {$field}"]);
        exit;
    }
}

// 验证类型字段
$type = $input['type'];
if (!in_array($type, ['sms', 'call'])) {
    http_response_code(400);
    echo json_encode(['code' => 400, 'msg' => 'type 必须为 sms 或 call']);
    exit;
}

// 确定事件时间（优先使用 event_time，兼容旧版字段）
if ($type === 'call') {
    $event_time = $input['call_time'] ?? $input['event_time'];
} else {
    $event_time = $input['receive_time'] ?? $input['event_time'];
}

try {
    // 连接数据库并插入记录
    $db = getDB();
    $stmt = $db->prepare(
        "INSERT INTO forward_records (device_name, type, phone_number, content, location, event_time) 
         VALUES (:device_name, :type, :phone_number, :content, :location, :event_time)"
    );
    $stmt->execute([
        ':device_name' => $input['device_name'],
        ':type' => $type,
        ':phone_number' => $input['phone_number'],
        ':content' => $input['content'] ?? null,
        ':location' => $input['location'] ?? null,
        ':event_time' => $event_time,
    ]);
    
    // 获取新插入记录的ID
    $newId = $db->lastInsertId();

    // 返回成功响应
    echo json_encode(['code' => 200, 'msg' => 'success', 'id' => $newId]);
    
} catch (PDOException $e) {
    // 数据库插入异常处理
    http_response_code(500);
    echo json_encode(['code' => 500, 'msg' => '入库失败，请稍后重试']);
}
