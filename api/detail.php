<?php
/**
 * 记录详情接口
 * 
 * 根据记录ID查询单条转发记录的详细信息
 * 
 * 接口地址: GET /api/detail.php?id=xxx
 * 
 * 请求参数:
 * - id (int, 必填): 记录ID
 * 
 * 认证方式:
 * - Cookie会话（Web端登录后自动携带）
 * - Token认证（通过 HTTP_TOKEN header 或 token 参数传递）
 * 
 * 成功响应:
 * {
 *   "code": 200,
 *   "msg": "success",
 *   "data": {
 *     "id": 1,
 *     "device_name": "设备名称",
 *     "type": "sms|call",
 *     "phone_number": "电话号码",
 *     "content": "内容",
 *     "location": "归属地",
 *     "event_time": "事件时间",
 *     "created_at": "入库时间"
 *   }
 * }
 * 
 * 错误响应:
 * - 400: 参数错误
 * - 401: 未授权（未登录或Token无效）
 * - 404: 记录不存在
 * - 405: 请求方法错误（仅支持GET）
 * - 500: 服务器内部错误
 */
define('APP_STARTED', true);
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';

// 认证检查：支持登录会话或Token认证
checkWebAuthOrToken();

// 验证请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['code' => 405, 'msg' => '仅支持GET请求']);
    exit;
}

// 获取并验证记录ID
$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['code' => 400, 'msg' => '缺少有效的id参数']);
    exit;
}

try {
    // 连接数据库并查询记录
    $db = getDB();
    $stmt = $db->prepare(
        "SELECT id, device_name, type, phone_number, content, location, event_time, created_at 
         FROM forward_records WHERE id = :id"
    );
    $stmt->execute([':id' => $id]);
    $record = $stmt->fetch();
    
    // 检查记录是否存在
    if (!$record) {
        http_response_code(404);
        echo json_encode(['code' => 404, 'msg' => '记录不存在']);
        exit;
    }
    
    // 返回成功响应
    echo json_encode(['code' => 200, 'msg' => 'success', 'data' => $record]);
    
} catch (PDOException $e) {
    // 数据库查询异常处理
    http_response_code(500);
    echo json_encode(['code' => 500, 'msg' => '查询失败，请稍后重试']);
}
