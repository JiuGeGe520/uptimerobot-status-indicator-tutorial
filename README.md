# UptimeRobot 状态监控指示器

> **作者**：九戈戈  
> **博客**：[https://blog.jiugg.fun/](https://blog.jiugg.fun/)  
> **协议**：MIT License

一个轻量级的网站状态监控指示器，通过 UptimeRobot API 实时显示服务状态。

## ✨ 功能特性

- 🟢 四种状态自动判断：加载中 / 正常 / 部分异常 / 错误
- 🎨 精美的 CSS 动画效果（呼吸、脉冲、闪烁）
- 🔄 自动轮询刷新（默认 5 分钟）
- 📍 自动插入到 ICP 备案号旁边
- 🔒 通过 PHP 代理保护 API Key
- 📦 无依赖，纯原生 JavaScript

## 📋 效果预览

| 状态 | 颜色 | 动画 | 说明 |
|------|------|------|------|
| `loading` | 灰色 | 脉冲 | 状态获取中... |
| `ok` | 绿色 | 呼吸发光 | 所有业务正常 |
| `partial` | 橙色 | 闪烁 | 部分服务异常 |
| `error` | 红色 | 快速闪烁 | 状态获取失败 |

## 📦 文件说明

| 文件名 | 说明 | 推荐 |
|--------|------|------|
| `status_indicator_complete.html` | 完整版，前端判断状态 | ⭐⭐⭐ |
| `status_indicator.html` | 基础版，需配合 status_check.php | ⭐⭐ |
| `status.php` | UptimeRobot API 代理 | 必需 |
| `status_check.php` | 状态判断 PHP（读取缓存） | 可选 |
| `status_api.php` | 独立状态 API | 可选 |
| `uptime_cache.json` | 缓存文件（自动生成） | - |

---

## 🚀 快速开始

### 前置准备：获取 UptimeRobot API Key

1. 登录 [UptimeRobot](https://uptimerobot.com/)
2. 进入 **My Settings** → **API Settings**
3. 创建 **Read-Only API Key**（格式：`ur000000-xxxxxxxx`）

---

## 📌 方案一：完整版（推荐）

**适用场景**：最简单，只需要 `status.php` + `status_indicator_complete.html`

### 步骤 1：部署 PHP 代理

将 `status.php` 上传到你的服务器：

```php
<?php
declare(strict_types=1);

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
    echo json_encode(["error" => "Method Not Allowed"]);
    exit;
}

$cacheFile = __DIR__ . '/uptime_cache.json';
$cacheTTL  = 300; // 缓存 5 分钟

if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTTL)) {
    echo file_get_contents($cacheFile);
    exit;
}

$input = file_get_contents("php://input");
if (!$input) {
    http_response_code(400);
    echo json_encode(["error" => "Empty body"]);
    exit;
}

$ch = curl_init("https://api.uptimerobot.com/v2/getMonitors");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
    CURLOPT_POSTFIELDS     => $input,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$response = curl_exec($ch);
$errno    = curl_errno($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($errno !== 0 || $httpCode !== 200 || !$response) {
    if (file_exists($cacheFile)) {
        echo file_get_contents($cacheFile);
        exit;
    }
    http_response_code(502);
    echo json_encode(["error" => "Upstream error"]);
    exit;
}

file_put_contents($cacheFile, $response);
echo $response;
```

### 步骤 2：配置 HTML

修改 `status_indicator_complete.html` 中的配置：

```javascript
const CONFIG = {
  // 状态页面 URL（点击跳转）
  statusPageUrl: 'https://your-status-page.com/',

  // PHP 代理地址
  proxyUrl: 'https://your-domain.com/status.php',

  // UptimeRobot API Key
  apiKey: 'ur000000-xxxxxxxxxxxxxxxxxxxxxxxx',

  // 轮询间隔（毫秒），默认 5 分钟
  pollInterval: 5 * 60 * 1000,

  // ICP 备案号匹配正则
  icpPattern: /[京津沪渝冀豫云辽黑湘皖鲁新苏浙赣鄂桂甘晋蒙陕吉闽贵粤青藏川宁琼]ICP备\d+号?-?\d*/
};
```

### 步骤 3：添加到网站

将 HTML 代码添加到网站 `</body>` 标签前。

**不同平台添加方式**：
- **Hexo**：添加到主题的 `footer.ejs` 或使用注入配置
- **Hugo**：添加到 `layouts/partials/footer.html`
- **WordPress**：添加到主题的 `footer.php` 或使用插件
- **其他**：直接添加到 HTML 文件

---

## 📌 方案二：独立 PHP 判断

**适用场景**：不想在前端暴露 API Key，或需要更复杂的状态判断

### 步骤 1：部署文件

1. 上传 `status.php` 到服务器
2. 上传 `status_check.php` 到**同一目录**

### 步骤 2：配置 status_check.php

`status_check.php` 会自动读取 `uptime_cache.json` 并返回判断后的状态：

```json
{
  "status": "ok",
  "message": "所有业务正常",
  "monitors": [...],
  "summary": { "total": 4, "up": 4, "down": 0, "paused": 0 }
}
```

### 步骤 3：配置 HTML

使用 `status_indicator.html`，修改配置：

```javascript
const CONFIG = {
  statusPageUrl: 'https://your-status-page.com/',
  
  // 使用 status_check.php 地址
  statusApiUrl: 'https://your-domain.com/status_check.php',
  
  pollInterval: 5 * 60 * 1000,
  insertCheckInterval: 500,
  icpPattern: /[京津沪渝冀豫云辽黑湘皖鲁新苏浙赣鄂桂甘晋蒙陕吉闽贵粤青藏川宁琼]ICP备\d+号?-?\d*/
};
```

### 步骤 4：初始化缓存

首次使用需要手动触发一次 `status.php` 生成缓存：

```bash
curl -X POST https://your-domain.com/status.php \
  -H "Content-Type: application/json" \
  -d '{"api_key":"your-api-key","format":"json"}'
```

---

## 🎨 自定义样式

### 修改颜色

```css
/* 正常状态 - 绿色 */
.footer-uptime-link.status-ok .footer-uptime-dot {
  background-color: #10b981;
  box-shadow: 0 0 8px rgba(16, 185, 129, 0.6);
}

/* 部分异常 - 橙色 */
.footer-uptime-link.status-partial .footer-uptime-dot {
  background-color: #f59e0b;
  box-shadow: 0 0 8px rgba(245, 158, 11, 0.6);
}

/* 错误状态 - 红色 */
.footer-uptime-link.status-error .footer-uptime-dot {
  background-color: #ef4444;
  box-shadow: 0 0 8px rgba(239, 68, 68, 0.6);
}

/* 加载中 - 灰色 */
.footer-uptime-link.status-loading .footer-uptime-dot {
  background-color: #9ca3af;
}
```

### 修改状态文字

```javascript
const STATUS_TEXT = {
  loading: '检测中...',
  ok: '服务正常',
  partial: '部分异常',
  error: '服务故障'
};
```

### 自定义插入位置

默认会自动插入到 ICP 备案号后面，如需自定义：

```javascript
function insertIndicator() {
  // 方式1：指定元素 ID
  const target = document.getElementById('your-element-id');
  
  // 方式2：指定 CSS 选择器
  const target = document.querySelector('.footer .copyright');
  
  if (!target) return false;
  
  uptimeElement = createUptimeElement();
  target.appendChild(uptimeElement);
  return true;
}
```

---

## 📊 状态判断逻辑

### UptimeRobot 状态码

| 码值 | 含义 | 处理方式 |
|------|------|----------|
| 0 | 暂停监控 | 不计入统计 |
| 1 | 尚未检测 | - |
| 2 | 正常运行 | ✅ 计入正常 |
| 8 | 响应异常 | ⚠️ 计入异常 |
| 9 | 宕机 | ❌ 计入异常 |

### 总体状态判断逻辑

```javascript
const active = total - paused;  // 活跃监控数

if (active === 0)    → error   // 无活跃监控
if (down === 0)      → ok      // 全部正常
if (down < active)   → partial // 部分异常
else                 → error   // 全部异常
```

---

## ❓ 常见问题

### Q1: 状态一直显示"获取失败"

**排查步骤**：

1. 检查 `proxyUrl` 是否正确
2. 检查 `apiKey` 是否有效
3. 测试 PHP 代理：
   ```bash
   curl -X POST https://your-domain.com/status.php \
     -H "Content-Type: application/json" \
     -d '{"api_key":"your-api-key","format":"json"}'
   ```
4. 检查服务器是否能访问 `api.uptimerobot.com`

### Q2: 找不到 ICP 备案号元素

修改 `icpPattern` 正则以匹配你的备案号：

```javascript
// 通用中国备案号
icpPattern: /[京津沪渝冀豫云辽黑湘皖鲁新苏浙赣鄂桂甘晋蒙陕吉闽贵粤青藏川宁琼]ICP备\d+号?-?\d*/

// 匹配特定文字
icpPattern: /备案号/

// 匹配特定备案号
icpPattern: /粤ICP备12345678号/
```

### Q3: 跨域问题 (CORS)

确保 `status.php` 包含正确的 CORS 头：

```php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
```

### Q4: 如何关闭自动轮询

```javascript
function startPolling() {
  fetchStatus();
  // 注释以下两行，只获取一次
  // if (pollTimer) clearInterval(pollTimer);
  // pollTimer = setInterval(fetchStatus, CONFIG.pollInterval);
}
```

### Q5: 如何修改轮询间隔

```javascript
// 1 分钟
pollInterval: 1 * 60 * 1000,

// 10 分钟
pollInterval: 10 * 60 * 1000,

// 30 分钟
pollInterval: 30 * 60 * 1000,
```

---

## 📄 开源协议

MIT License

---

## 🔗 相关链接

- [UptimeRobot](https://uptimerobot.com/) - 免费服务器监控
- [UptimeRobot API 文档](https://uptimerobot.com/api/)
