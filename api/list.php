<?php
/**
 * 记录列表接口
 * 
 * 查询转发记录列表，支持分页、筛选和增量拉取
 * 
 * 接口地址: GET /api/list.php
 * 
 * 请求参数:
 * - page (int, 默认1): 页码
 * - limit (int, 默认20, 范围1-100): 每页条数
 * - device_name (string, 可选): 按设备名称筛选
 * - type (string, 可选): 类型筛选，值为 sms 或 call
 * - since (string, 可选): 时间筛选，格式 YYYY-MM-DD 或 YYYY-MM-DD HH:MM:SS
 * - since_id (int, 可选): 增量拉取起点，返回 id > since_id 的记录
 * 
 * 认证方式:
 * - Cookie会话（Web端登录后自动携带）
 * - Token认证（通过 HTTP_TOKEN header 或 token 参数传递）
 * 
 * 响应说明:
 * 1. 常规分页查询（不带 since_id）:
 * {
 *   "code": 200,
 *   "msg": "success",
 *   "data": {
 *     "total": 100,          // 符合条件的总数
 *     "total_total": 500,    // 全局总记录数
 *     "sms_total": 300,      // 全局短信总数
 *     "call_total": 200,     // 全局来电总数
 *     "page": 1,             // 当前页码
 *     "limit": 20,           // 每页条数
 *     "total_pages": 5,      // 总页数
 *     "records": [...],      // 记录列表
 *     "devices": [...],      // 设备列表
 *     "max_id": 100,         // 本次返回的最大ID
 *     "is_delta": false      // 是否增量响应
 *   }
 * }
 * 
 * 2. 增量拉取（带 since_id）:
 * {
 *   "code": 200,
 *   "msg": "success",
 *   "data": {
 *     "records": [...],         // 新增记录
 *     "max_id": 100,            // 本次返回的最大ID
 *     "global_max_id": 100,     // 数据库全局最大ID（用于检测数据库被清空）
 *     "is_delta": true,          // 标识增量响应
 *     "has_more": false          // 是否还有更多未拉取的记录
 *   }
 * }
 * 
 * 错误响应:
 * - 400: 参数错误
 * - 401: 未授权（未登录或Token无效）
 * - 405: 请求方法错误（仅支持GET）
 * - 500: 服务器内部错误
 * 
 * 特性:
 * - 支持 ETag/304 缓存，无新数据时返回零流量响应
 * - 增量拉取模式提高实时更新效率
 * - 自动检测数据库清空事件
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

// 解析分页参数
$page = max(1, intval($_GET['page'] ?? 1));
$limit = min(100, max(1, intval($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

// 解析筛选参数
$device_name = $_GET['device_name'] ?? null;
$type = $_GET['type'] ?? null;

// 时间筛选参数验证（格式：YYYY-MM-DD 或 YYYY-MM-DD HH:MM:SS）
$since_time = $_GET['since'] ?? null;
if ($since_time) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}(\s+\d{2}:\d{2}:\d{2})?$/', trim($since_time))) {
        http_response_code(400);
        echo json_encode(['code' => 400, 'msg' => 'since 参数格式错误，应为 YYYY-MM-DD 或 YYYY-MM-DD HH:MM:SS']);
        exit;
    }
}

// 增量拉取参数验证（仅接受纯数字，防止SQL注入）
$since_id = 0;
if (isset($_GET['since_id'])) {
    $input = trim($_GET['since_id']);
    if (preg_match('/^\d+$/', $input)) {
        $since_id = intval($input);
    } else {
        http_response_code(400);
        echo json_encode(['code' => 400, 'msg' => 'since_id 参数格式错误，只能是纯数字']);
        exit;
    }
}

try {
    // 连接数据库
    $db = getDB();

    // 构建查询条件
    $where = [];
    $params = [];

    // 设备筛选
    if ($device_name) {
        $where[] = "device_name = :device_name";
        $params[':device_name'] = $device_name;
    }
    
    // 类型筛选（仅允许 sms 或 call）
    if ($type && in_array($type, ['sms', 'call'])) {
        $where[] = "type = :type";
        $params[':type'] = $type;
    }

    // 时间筛选：返回 event_time >= since 的记录
    if ($since_time) {
        $where[] = "event_time >= :since_time";
        $params[':since_time'] = $since_time;
    }

    // 增量拉取：返回 id > since_id 的记录
    if ($since_id > 0) {
        $where[] = "id > :since_id";
        $params[':since_id'] = $since_id;
        // 增量拉取时提高上限，方便一次性追平遗漏数据
        $limit = max($limit, 50);
        $offset = 0;  // 增量拉取不分页
    }

    // 构建 WHERE 子句
    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // 查询全局统计数据（不受筛选条件影响）
    $statsStmt = $db->prepare(
        "SELECT 
            COUNT(*) as global_total,
            SUM(CASE WHEN type = 'sms' THEN 1 ELSE 0 END) as sms_total,
            SUM(CASE WHEN type = 'call' THEN 1 ELSE 0 END) as call_total
         FROM forward_records"
    );
    $statsStmt->execute();
    $stats = $statsStmt->fetch();
    
    // 查询符合条件的记录总数
    $countStmt = $db->prepare("SELECT COUNT(*) as total FROM forward_records {$whereSQL}");
    $countStmt->execute($params);
    $total = $countStmt->fetch()['total'];
    
    // 查询记录列表
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;
    $dataStmt = $db->prepare(
        "SELECT id, device_name, type, phone_number, content, location, event_time, created_at 
         FROM forward_records {$whereSQL} 
         ORDER BY id DESC 
         LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $key => $val) {
        $dataStmt->bindValue($key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $dataStmt->execute();
    $records = $dataStmt->fetchAll();
    
    // 计算本次返回的最大ID，用于增量跟踪
    $maxId = 0;
    foreach ($records as $r) {
        if (intval($r['id']) > $maxId) $maxId = intval($r['id']);
    }

    // 构建响应数据
    if ($since_id > 0) {
        // 增量拉取模式：返回精简响应
        $globalMaxRow = $db->query("SELECT MAX(id) AS m FROM forward_records")->fetch();
        $globalMaxId = intval($globalMaxRow['m'] ?? 0);
        
        $payload = [
            'code' => 200,
            'msg'  => 'success',
            'data' => [
                'records'       => $records,
                'max_id'        => $maxId,
                'global_max_id' => $globalMaxId,  // 用于检测数据库被清空
                'is_delta'      => true,
                'has_more'      => count($records) >= 50,  // 是否还有更多数据
            ],
        ];
    } else {
        // 常规分页模式：返回完整响应
        $payload = [
            'code' => 200,
            'msg'  => 'success',
            'data' => [
                'total'         => intval($total),
                'total_total'   => intval($stats['global_total']),
                'sms_total'     => intval($stats['sms_total']),
                'call_total'    => intval($stats['call_total']),
                'page'          => $page,
                'limit'         => $limit,
                'total_pages'   => $total > 0 ? (int)ceil($total / $limit) : 0,
                'records'       => $records,
                'devices'       => getDeviceList($db),
                'max_id'        => $maxId,
                'is_delta'      => false,
            ],
        ];
    }

    // ETag 缓存处理：无新数据时返回 304 Not Modified
    $etagKey = $maxId . '|' . $total . '|' . ($device_name ?? '') . '|' . ($type ?? '') . '|' . ($since_time ?? '') . '|' . $page . '|' . count($records);
    $etag = '"fwd-' . md5($etagKey) . '"';
    header('ETag: ' . $etag);
    header('Cache-Control: private, max-age=2');
    
    // 检查客户端缓存是否有效
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        exit;
    }

    // 输出响应
    echo json_encode($payload);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['code' => 500, 'msg' => '查询失败，请稍后重试']);
}