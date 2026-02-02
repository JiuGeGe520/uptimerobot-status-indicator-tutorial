<?php
/**
 * 状态检查 API（简化版）
 * 
 * 直接读取 status.php 生成的 uptime_cache.json 文件
 * 无需重复配置 UptimeRobot API Key
 * 
 * 返回格式：
 * {
 *   "status": "ok" | "partial" | "error" | "loading",
 *   "message": "所有业务正常",
 *   "monitors": [...],
 *   "summary": { "total": 4, "up": 4, "down": 0 }
 * }
 */
declare(strict_types=1);

/* ================= CORS ================= */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* ================= 配置 ================= */
// 缓存文件路径（由 status.php 生成）
$cacheFile = __DIR__ . '/uptime_cache.json';

// 缓存过期时间（秒），超过此时间会标记为 stale
$staleThreshold = 600; // 10分钟

/* ================= UptimeRobot 状态码 ================= */
const STATUS_PAUSED = 0;      // 暂停
const STATUS_NOT_CHECKED = 1; // 尚未检测
const STATUS_UP = 2;          // 正常运行
const STATUS_SEEMS_DOWN = 8;  // 似乎宕机
const STATUS_DOWN = 9;        // 宕机

/* ================= 辅助函数 ================= */

/**
 * 获取状态文字描述
 */
function getStatusText(int $status): string {
    $statusMap = [
        STATUS_PAUSED => '已暂停',
        STATUS_NOT_CHECKED => '待检测',
        STATUS_UP => '正常',
        STATUS_SEEMS_DOWN => '响应异常',
        STATUS_DOWN => '宕机'
    ];
    return $statusMap[$status] ?? '未知';
}

/**
 * 格式化 uptime 百分比字符串
 */
function formatUptimeRanges(string $ranges): array {
    $parts = explode('-', $ranges);
    return [
        'day_1' => isset($parts[0]) ? round((float)$parts[0], 2) . '%' : '0%',
        'day_7' => isset($parts[1]) ? round((float)$parts[1], 2) . '%' : '0%',
        'day_30' => isset($parts[2]) ? round((float)$parts[2], 2) . '%' : '0%'
    ];
}

/**
 * 分析监控数据
 */
function analyzeMonitors(array $data): array {
    $monitors = $data['monitors'] ?? [];
    
    if (empty($monitors)) {
        return [
            'status' => 'error',
            'message' => '没有监控数据',
            'monitors' => [],
            'summary' => ['total' => 0, 'up' => 0, 'down' => 0, 'paused' => 0]
        ];
    }
    
    $result = [
        'monitors' => [],
        'summary' => [
            'total' => count($monitors),
            'up' => 0,
            'down' => 0,
            'paused' => 0
        ]
    ];
    
    foreach ($monitors as $monitor) {
        $status = (int)($monitor['status'] ?? 0);
        $uptimeRanges = formatUptimeRanges($monitor['custom_uptime_ranges'] ?? '0-0-0');
        
        // 统计
        if ($status === STATUS_PAUSED) {
            $result['summary']['paused']++;
        } elseif ($status === STATUS_UP) {
            $result['summary']['up']++;
        } elseif ($status === STATUS_DOWN || $status === STATUS_SEEMS_DOWN) {
            $result['summary']['down']++;
        }
        
        // 监控详情
        $result['monitors'][] = [
            'id' => $monitor['id'] ?? 0,
            'name' => $monitor['friendly_name'] ?? 'Unknown',
            'url' => $monitor['url'] ?? '',
            'status' => $status,
            'status_text' => getStatusText($status),
            'uptime' => $uptimeRanges,
            'avg_response' => round((float)($monitor['average_response_time'] ?? 0), 2) . 'ms'
        ];
    }
    
    // 判断总体状态
    $active = $result['summary']['total'] - $result['summary']['paused'];
    
    if ($active === 0) {
        $result['status'] = 'error';
        $result['message'] = '没有活跃的监控';
    } elseif ($result['summary']['down'] === 0) {
        $result['status'] = 'ok';
        $result['message'] = '所有业务正常';
    } elseif ($result['summary']['down'] < $active) {
        $result['status'] = 'partial';
        $result['message'] = sprintf('部分服务异常 (%d/%d 宕机)', 
            $result['summary']['down'], $active);
    } else {
        $result['status'] = 'error';
        $result['message'] = '所有服务异常';
    }
    
    return $result;
}

/* ================= 主逻辑 ================= */

try {
    // 检查缓存文件是否存在
    if (!file_exists($cacheFile)) {
        echo json_encode([
            'status' => 'loading',
            'message' => '正在获取状态数据...',
            'monitors' => [],
            'summary' => ['total' => 0, 'up' => 0, 'down' => 0, 'paused' => 0]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    
    // 读取缓存
    $content = file_get_contents($cacheFile);
    if (!$content) {
        echo json_encode([
            'status' => 'error',
            'message' => '无法读取缓存文件',
            'monitors' => [],
            'summary' => ['total' => 0, 'up' => 0, 'down' => 0, 'paused' => 0]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    
    $data = json_decode($content, true);
    if (!$data || !isset($data['monitors'])) {
        echo json_encode([
            'status' => 'error',
            'message' => '缓存数据格式错误',
            'monitors' => [],
            'summary' => ['total' => 0, 'up' => 0, 'down' => 0, 'paused' => 0]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    
    // 分析监控数据
    $result = analyzeMonitors($data);
    
    // 添加缓存信息
    $cacheAge = time() - filemtime($cacheFile);
    $result['cache'] = [
        'age' => $cacheAge,
        'age_text' => $cacheAge < 60 ? $cacheAge . '秒前' : round($cacheAge / 60) . '分钟前',
        'stale' => $cacheAge > $staleThreshold
    ];
    
    // 如果缓存过期太久，标记警告
    if ($cacheAge > $staleThreshold) {
        $result['message'] .= ' (数据可能过期)';
    }
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => '服务器错误: ' . $e->getMessage(),
        'monitors' => [],
        'summary' => ['total' => 0, 'up' => 0, 'down' => 0, 'paused' => 0]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
