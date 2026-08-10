<?php
/* ─── Configuration ─── */
define('TOKEN_CACHE_FILE', __DIR__ . '/token_cache.json');
define('TOKEN_REFRESH_INTERVAL', 600);      // 10 minutes for platform token
define('M3U8_CACHE_DIR', __DIR__ . '/tmp/');
define('M3U8_CACHE_TTL', 100);              // 100 seconds for M3U8 URL

// Ensure cache directory exists
if (!file_exists(M3U8_CACHE_DIR)) {
    mkdir(M3U8_CACHE_DIR, 0755, true);
}

/* ─── Helper: generate a random UUID v4 ─── */
function generateUUID() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/* ─── Generate dd token ─── */
function generateDDToken() {
    return base64_encode(json_encode([
        'schema_version'       => '1',
        'os_name'              => 'N/A',
        'os_version'           => 'N/A',
        'platform_name'        => 'Chrome',
        'platform_version'     => '104',
        'device_name'          => '',
        'app_name'             => 'Web',
        'app_version'          => '2.52.31',
        'player_capabilities'  => [
            'audio_channel'  => ['STEREO'],
            'video_codec'    => ['H264'],
            'container'      => ['MP4', 'TS'],
            'package'        => ['DASH', 'HLS'],
            'resolution'     => ['240p', 'SD', 'HD', 'FHD'],
            'dynamic_range'  => ['SDR']
        ],
        'security_capabilities' => [
            'encryption'            => ['WIDEVINE_AES_CTR'],
            'widevine_security_level'=> ['L3'],
            'hdcp_version'          => ['HDCP_V1', 'HDCP_V2', 'HDCP_V2_1', 'HDCP_V2_2']
        ]
    ]));
}

/* ─── Platform token management (external API + file cache) ─── */
function getPlatformToken() {
    if (file_exists(TOKEN_CACHE_FILE)) {
        $cacheTime = filemtime(TOKEN_CACHE_FILE);
        if ((time() - $cacheTime) < TOKEN_REFRESH_INTERVAL) {
            $data = json_decode(file_get_contents(TOKEN_CACHE_FILE), true);
            if (isset($data['token']) && !empty($data['token'])) {
                return $data['token'];
            }
        }
    }

    $token = fetchTokenFromApi();
    if (!$token && file_exists(TOKEN_CACHE_FILE)) {
        $data = json_decode(file_get_contents(TOKEN_CACHE_FILE), true);
        if (isset($data['token'])) return $data['token'];
    }
    return $token;
}

function fetchTokenFromApi() {
    $ch = curl_init('https://jiotvegp.vercel.app/api/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($response)) return null;
    $data = json_decode($response, true);
    if (isset($data['success'], $data['token']) && $data['success'] === true) {
        $token = $data['token'];
        $fp = fopen(TOKEN_CACHE_FILE, 'c+');
        if ($fp && flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            fwrite($fp, json_encode(['token' => $token, 'fetched_at' => time()]));
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        return $token;
    }
    return null;
}

/* ─── Fetch M3U8 URL from Zee5 API (fresh, without cache) ─── */
function fetchFreshM3U8url($channelId) {
    $deviceId   = generateUUID();
    $guestToken = $deviceId;
    $platformToken = getPlatformToken();
    if (!$platformToken) return null;

    $queryParams = http_build_query([
        'channel_id'              => $channelId,
        'device_id'               => $deviceId,
        'platform_name'           => 'mobile_web',
        'translation'             => 'en',
        'user_language'           => 'en,hi,hr,mr',
        'country'                 => 'IN',
        'state'                   => '',
        'app_version'             => '6.5.12',
        'user_type'               => 'guest',
        'check_parental_control'  => 'false',
        'uid'                     => 'Z5X_' . $deviceId,
        'ppid'                    => $deviceId,
        'version'                 => '15',
        'os'                      => 'android'
    ]);

    $url = 'https://spapi.zee5.com/singlePlayback/getDetails/secure?' . $queryParams;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'content-type: application/json',
            'origin: https://www.zee5.com',
            'referer: https://www.zee5.com/',
            'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'x-access-token'   => $platformToken,
            'X-Z5-Guest-Token' => $guestToken,
            'x-dd-token'       => generateDDToken()
        ])
    ]);

    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);

    if ($data && isset($data['keyOsDetails']['video_token'])) {
        return $data['keyOsDetails']['video_token'];
    }
    return null;
}

/* ─── Get M3U8 URL with 100‑second file cache ─── */
function getCachedM3U8Url($channelId) {
    $cacheFile = M3U8_CACHE_DIR . 'm3u8_' . md5($channelId) . '.cache';

    // Check if fresh cache exists
    if (file_exists($cacheFile)) {
        $cacheTime = filemtime($cacheFile);
        if ((time() - $cacheTime) < M3U8_CACHE_TTL) {
            $url = file_get_contents($cacheFile);
            if (!empty($url)) return $url;
        }
    }

    // Cache miss or expired → fetch fresh
    $freshUrl = fetchFreshM3U8url($channelId);

    if ($freshUrl) {
        // Save to cache (locking)
        $fp = fopen($cacheFile, 'c+');
        if ($fp && flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            fwrite($fp, $freshUrl);
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        return $freshUrl;
    }

    // If fetch failed but we have a stale cache, use it as fallback
    if (file_exists($cacheFile)) {
        $url = file_get_contents($cacheFile);
        if (!empty($url)) return $url;
    }

    return null; // total failure
}

/* ─── Determine channel ID from URL path or query ─── */
function getChannelIdFromRequest() {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url($requestUri, PHP_URL_PATH);
    if (preg_match('#/([a-zA-Z0-9\-_]+)\.m3u8$#', $path, $m)) {
        return $m[1];
    }
    return $_GET['id'] ?? null;
}

/* ─── Main ─── */
$channelId = getChannelIdFromRequest();
if (!$channelId) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(["error" => "Channel ID is required. Use /{id}.m3u8 or ?id=CHANNEL_ID"]);
    exit;
}

// Get the tokenised M3U8 URL (cached for 100 seconds)
$m3u8MasterUrl = getCachedM3U8Url($channelId);
if (!$m3u8MasterUrl) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(["error" => "Could not fetch M3U8 URL from Zee5 API."]);
    exit;
}

// If the request was a .m3u8 path → redirect
if (preg_match('#\.m3u8$#', $_SERVER['REQUEST_URI'] ?? '')) {
    header('Cache-Control: public, max-age=100');  // optional client-side caching
    header('Location: ' . $m3u8MasterUrl, true, 302);
    exit;
}

// Otherwise, JSON debug response
header('Content-Type: application/json');
echo json_encode([
    'status'           => 'success',
    'playable_redirect'=> $m3u8MasterUrl,
    'note'             => 'Use the .m3u8 endpoint for direct streaming. Cached for 100 seconds.'
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;
