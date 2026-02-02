<?php
/**
 * UptimeRobot 状态判断 API
 * 
 * 返回格式：
 * {
 *   "status": "ok" | "partial" | "error",
 *   "message": "状态描述",
 *   "monitors": [
 *     {
 *       "name": "监控名称",
 *       "status": 2,  // 2=正常, 0=暂停, 9=异常等
 *       "uptime": "99.99%"
 *     }
 *   ],
 *   "summary": {
 *     "total": 4,
 *     "up": 3,
 *     "down": 1
 *   }
 * }
 */
declare(strict_types=1);

/* ================= CORS ================= */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* ================= 配置 ================= */
// UptimeRobot API 配置
$uptimeRobotApiKey = 'ur3180812-xxxxxxxxxxxxxxxxxxxxxx'; // 替换为你的 API Key
$uptimeRobotApiUrl = 'https://api.uptimerobot.com/v2/getMonitors';

// 缓存配置
$cacheFile = __DIR__ . '/uptime_cache.json';
$cacheTTL  = 300; // 缓存秒数（5分钟）

/* ================= 状态码定义 ================= */
// UptimeRobot 监控状态码
const STATUS_PAUSED = 0;      // 暂停
const STATUS_NOT_CHECKED = 1; // 尚未检测
const STATUS_UP = 2;          // 正常运行
const STATUS_SEEMS_DOWN = 8;  // 似乎宕机
const STATUS_DOWN = 9;        // 宕机

/* ================= 辅助函数 ================= */

/**
 * 判断监控是否正常
 */
function isMonitorUp(int $status): bool {
    return $status === STATUS_UP;
}

/**
 * 判断监控是否异常（宕机或似乎宕机）
 */
function isMonitorDown(int $status): bool {
    return $status === STATUS_DOWN || $status === STATUS_SEEMS_DOWN;
}

/**
 * 从缓存文件读取数据
 */
function readCache(string $cacheFile, int $cacheTTL): ?array {
    if (!file_exists($cacheFile)) {
        return null;
    }
    
    $cacheAge = time() - filemtime($cacheFile);
    if ($cacheAge >= $cacheTTL) {
        return null; // 缓存过期
    }
    
    $content = file_get_contents($cacheFile);
    if (!$content) {
        return null;
    }
    
    return json_decode($content, true);
}

/**
 * 写入缓存
 */
function writeCache(string $cacheFile, array $data): void {
    file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE));
}

/**
 * 从 UptimeRobot 获取监控数据
 */
function fetchFromUptimeRobot(string $apiUrl, string $apiKey): ?array {
    $postData = json_encode([
        'api_key' => $apiKey,
        'format' => 'json',
        'logs' => 1,
        'response_times' => 1,
        'custom_uptime_ranges' => '1-7-30'
    ]);

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $errno    = curl_errno($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0 || $httpCode !== 200 || !$response) {
        return null;
    }

    return json_decode($response, true);
}

/**
 * 分析监控状态并返回结果
 */
function analyzeStatus(array $rawData): array {
    $monitors = $rawData['monitors'] ?? [];
    
    if (empty($monitors)) {
        return [
            'status' => 'error',
            'message' => '没有监控数据',
            'monitors' => [],
            'summary' => [
                'total' => 0,
                'up' => 0,
                'down' => 0,
                'paused' => 0
            ]
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
        $uptimeRanges = explode('-', $monitor['custom_uptime_ranges'] ?? '0-0-0');
        
        // 统计状态
        if ($status === STATUS_PAUSED) {
            $result['summary']['paused']++;
        } elseif (isMonitorUp($status)) {
            $result['summary']['up']++;
        } elseif (isMonitorDown($status)) {
            $result['summary']['down']++;
        }
        
        // 添加监控信息
        $result['monitors'][] = [
            'id' => $monitor['id'] ?? 0,
            'name' => $monitor['friendly_name'] ?? 'Unknown',
            'url' => $monitor['url'] ?? '',
            'status' => $status,
            'status_text' => getStatusText($status),
            'uptime_1d' => ($uptimeRanges[0] ?? '0') . '%',
            'uptime_7d' => ($uptimeRanges[1] ?? '0') . '%',
            'uptime_30d' => ($uptimeRanges[2] ?? '0') . '%',
            'avg_response' => $monitor['average_response_time'] ?? '0'
        ];
    }
    
    // 判断总体状态
    $activeMonitors = $result['summary']['total'] - $result['summary']['paused'];
    
    if ($activeMonitors === 0) {
        $result['status'] = 'error';
        $result['message'] = '没有活跃的监控';
    } elseif ($result['summary']['down'] === 0) {
        $result['status'] = 'ok';
        $result['message'] = '所有业务正常';
    } elseif ($result['summary']['down'] < $activeMonitors) {
        $result['status'] = 'partial';
        $result['message'] = sprintf('部分服务异常 (%d/%d)', 
            $result['summary']['down'], $activeMonitors);
    } else {
        $result['status'] = 'error';
        $result['message'] = '所有服务异常';
    }
    
    return $result;
}

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

/* ================= 主逻辑 ================= */

try {
    // 1. 尝试读取缓存
    $cachedData = readCache($cacheFile, $cacheTTL);
    
    if ($cachedData !== null) {
        // 缓存有效，分析缓存数据
        $result = analyzeStatus($cachedData);
        $result['cached'] = true;
        $result['cache_age'] = time() - filemtime($cacheFile);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    
    // 2. 缓存无效或过期，从 UptimeRobot 获取数据
    $rawData = fetchFromUptimeRobot($uptimeRobotApiUrl, $uptimeRobotApiKey);
    
    if ($rawData === null) {
        // 3. 获取失败，尝试使用过期缓存
        if (file_exists($cacheFile)) {
            $oldCache = json_decode(file_get_contents($cacheFile), true);
            if ($oldCache) {
                $result = analyzeStatus($oldCache);
                $result['cached'] = true;
                $result['stale'] = true;
                $result['message'] .= ' (使用缓存数据)';
                echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                exit;
            }
        }
        
        // 完全无法获取数据
        echo json_encode([
            'status' => 'error',
            'message' => 'UptimeRobot API 请求失败',
            'monitors' => [],
            'summary' => ['total' => 0, 'up' => 0, 'down' => 0, 'paused' => 0]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    
    // 4. 成功获取数据，写入缓存
    writeCache($cacheFile, $rawData);
    
    // 5. 分析并返回结果
    $result = analyzeStatus($rawData);
    $result['cached'] = false;
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => '服务器内部错误: ' . $e->getMessage(),
        'monitors' => [],
        'summary' => ['total' => 0, 'up' => 0, 'down' => 0, 'paused' => 0]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
