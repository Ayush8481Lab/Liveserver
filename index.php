<?php
/* ─── Configuration ─── */
define('TOKEN_CACHE_FILE', __DIR__ . '/token_cache.json');
define('TOKEN_REFRESH_INTERVAL', 600);      // 10 minutes
define('M3U8_CACHE_DIR', __DIR__ . '/tmp/');
define('M3U8_URL_CACHE_TTL', 100);          // 100 seconds for M3U8 URL
define('M3U8_CONTENT_CACHE_TTL', 100);      // 100 seconds for playlist content

// Ensure cache directory exists
if (!file_exists(M3U8_CACHE_DIR)) {
    mkdir(M3U8_CACHE_DIR, 0755, true);
}

/* ─── Helpers ─── */
function generateUUID() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

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

/* ─── Platform token (unchanged) ─── */
function getPlatformToken() {
    if (file_exists(TOKEN_CACHE_FILE)) {
        $cacheTime = filemtime(TOKEN_CACHE_FILE);
        if ((time() - $cacheTime) < TOKEN_REFRESH_INTERVAL) {
            $data = json_decode(file_get_contents(TOKEN_CACHE_FILE), true);
            if (isset($data['token']) && !empty($data['token'])) return $data['token'];
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

/* ─── Fetch fresh M3U8 URL ─── */
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

/* ─── Cached M3U8 URL ─── */
function getCachedM3U8Url($channelId) {
    $cacheFile = M3U8_CACHE_DIR . 'm3u8_url_' . md5($channelId) . '.cache';

    if (file_exists($cacheFile)) {
        $cacheTime = filemtime($cacheFile);
        if ((time() - $cacheTime) < M3U8_URL_CACHE_TTL) {
            $url = file_get_contents($cacheFile);
            if (!empty($url)) return $url;
        }
    }

    $freshUrl = fetchFreshM3U8url($channelId);
    if ($freshUrl) {
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

    if (file_exists($cacheFile)) {
        $url = file_get_contents($cacheFile);
        if (!empty($url)) return $url;
    }
    return null;
}

/* ─── Cached M3U8 content (the playlist text) ─── */
function getCachedM3U8Content($channelId) {
    $contentCacheFile = M3U8_CACHE_DIR . 'm3u8_content_' . md5($channelId) . '.cache';

    // Check if fresh cached content exists
    if (file_exists($contentCacheFile)) {
        $cacheTime = filemtime($contentCacheFile);
        if ((time() - $cacheTime) < M3U8_CONTENT_CACHE_TTL) {
            $content = file_get_contents($contentCacheFile);
            if ($content !== false) return $content;
        }
    }

    // Get the tokenised M3U8 URL (cached separately)
    $masterUrl = getCachedM3U8Url($channelId);
    if (!$masterUrl) return null;

    // Fetch the playlist from Akamai
    $ch = curl_init($masterUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);
    $content = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode !== 200 || empty($content)) return null;

    // Cache the content
    $fp = fopen($contentCacheFile, 'c+');
    if ($fp && flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, $content);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    return $content;
}

/* ─── Determine channel ID ─── */
function getChannelIdFromRequest() {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (preg_match('#/([a-zA-Z0-9\-_]+)\.m3u8$#', $path, $m)) {
        return $m[1];
    }
    return $_GET['id'] ?? null;
}

/* ─── CORS helper ─── */
function setCorsHeaders() {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: *');
    header('Access-Control-Max-Age: 86400');
}

/* ─── Main ─── */

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    setCorsHeaders();
    http_response_code(204);
    exit;
}

$channelId = getChannelIdFromRequest();
if (!$channelId) {
    http_response_code(400);
    header('Content-Type: application/json');
    setCorsHeaders();
    echo json_encode(["error" => "Channel ID is required. Use /{id}.m3u8 or ?id=CHANNEL_ID"]);
    exit;
}

// If the request is a .m3u8 path → serve playlist directly with CORS
if (preg_match('#\.m3u8$#', $_SERVER['REQUEST_URI'] ?? '')) {
    $content = getCachedM3U8Content($channelId);
    if (!$content) {
        http_response_code(502);
        setCorsHeaders();
        echo 'Failed to fetch M3U8 playlist.';
        exit;
    }

    setCorsHeaders();
    header('Content-Type: application/vnd.apple.mpegurl');
    echo $content;
    exit;
}

// Otherwise, ?id= → JSON debug output
$m3u8MasterUrl = getCachedM3U8Url($channelId);
if (!$m3u8MasterUrl) {
    http_response_code(502);
    setCorsHeaders();
    header('Content-Type: application/json');
    echo json_encode(["error" => "Could not fetch M3U8 URL from Zee5 API."]);
    exit;
}

setCorsHeaders();
header('Content-Type: application/json');
echo json_encode([
    'status'           => 'success',
    'playable_redirect'=> $m3u8MasterUrl,
    'note'             => 'Use the .m3u8 endpoint for direct playlist (CORS enabled).'
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;
