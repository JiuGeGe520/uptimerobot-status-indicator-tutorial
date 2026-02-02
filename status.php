<?php
declare(strict_types=1);

/* ================= CORS ================= */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "只支持 POST 请求"], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ================= 缓存配置 ================= */
$cacheFile = __DIR__ . '/uptime_cache.json';
$cacheTTL  = 300; // 缓存秒数（推荐 30 / 60 / 120）

// 如果缓存有效，直接返回
if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTTL)) {
    echo file_get_contents($cacheFile);
    exit;
}

/* ================= 读取请求体 ================= */
$input = file_get_contents("php://input");
if (!$input) {
    http_response_code(400);
    echo json_encode(["error" => "请求体为空"], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ================= 请求 UptimeRobot ================= */
$ch = curl_init("https://api.uptimerobot.com/v2/getMonitors");

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
    CURLOPT_POSTFIELDS     => $input,

    // ⚡ 关键优化
    CURLOPT_CONNECTTIMEOUT => 3, // 连接超时
    CURLOPT_TIMEOUT        => 5, // 总超时

    // 国内服务器常见优化
    CURLOPT_SSL_VERIFYPEER => false,
]);

$response = curl_exec($ch);
$errno    = curl_errno($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

/* ================= 异常兜底 ================= */
if ($errno !== 0 || $httpCode !== 200 || !$response) {

    // 有旧缓存就返回旧缓存
    if (file_exists($cacheFile)) {
        echo file_get_contents($cacheFile);
        exit;
    }

    // 连缓存都没有，返回错误
    http_response_code(502);
    echo json_encode([
        "error" => "UptimeRobot 请求失败",
        "code"  => $errno,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ================= 成功：写缓存 ================= */
file_put_contents($cacheFile, $response);
echo $response;
