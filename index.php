<?php
/* ─── Configuration ─── */
define('TOKEN_CACHE_FILE', __DIR__ . '/token_cache.json');
define('TOKEN_REFRESH_INTERVAL', 600);          // 10 minutes
define('M3U8_CACHE_DIR', __DIR__ . '/tmp/');
define('PLAYABLE_URL_CACHE_TTL', 100);          // 100 seconds

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

/* ─── Platform token ─── */
function getPlatformToken() {
    if (file_exists(TOKEN_CACHE_FILE)) {
        $cacheTime = filemtime(TOKEN_CACHE_FILE);
        if ((time() - $cacheTime) < TOKEN_REFRESH_INTERVAL) {
            $data = json_decode(file_get_contents(TOKEN_CACHE_FILE), true);
            if (!empty($data['token'])) return $data['token'];
        }
    }
    $token = fetchTokenFromApi();
    if (!$token && file_exists(TOKEN_CACHE_FILE)) {
        $data = json_decode(file_get_contents(TOKEN_CACHE_FILE), true);
        if (!empty($data['token'])) return $data['token'];
    }
    return $token;
}

function fetchTokenFromApi() {
    $ch = curl_init('https://jiotvegp.vercel.app/api/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'Mozilla/5.0'
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200 || empty($response)) return null;
    $data = json_decode($response, true);
    if ($data['success'] && !empty($data['token'])) {
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

/* ─── Fetch playable Akamai URL ─── */
function fetchFreshPlayableUrl($channelId) {
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
            'user-agent: Mozilla/5.0'
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
    return $data['keyOsDetails']['video_token'] ?? null;
}

/* ─── Cache playable URL ─── */
function getCachedPlayableUrl($channelId) {
    $cacheFile = M3U8_CACHE_DIR . 'playable_' . md5($channelId) . '.cache';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < PLAYABLE_URL_CACHE_TTL) {
        return file_get_contents($cacheFile);
    }
    $freshUrl = fetchFreshPlayableUrl($channelId);
    if ($freshUrl) {
        file_put_contents($cacheFile, $freshUrl, LOCK_EX);
        return $freshUrl;
    }
    return file_exists($cacheFile) ? file_get_contents($cacheFile) : null;
}

/* ─── Extract channel ID ─── */
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
    setCorsHeaders();
    header('Content-Type: application/json');
    echo json_encode(["error" => "Channel ID required. Use /{id}.m3u8 or ?id=CHANNEL_ID"]);
    exit;
}

// If request ends with .m3u8 → redirect to Akamai (or serve minimal playlist)
// (keeping the redirect as simplest working version)
if (preg_match('#\.m3u8$#', $_SERVER['REQUEST_URI'] ?? '')) {
    $playableUrl = getCachedPlayableUrl($channelId);
    if (!$playableUrl) {
        http_response_code(502);
        setCorsHeaders();
        echo 'Failed to obtain stream URL';
        exit;
    }

    setCorsHeaders();
    header('Referrer-Policy: no-referrer');   // helps with some geo‑checks
    header('Cache-Control: no-store');
    header('Location: ' . $playableUrl, true, 302);
    exit;
}

// Otherwise, ?id= → JSON debug output (CORS enabled)
$playableUrl = getCachedPlayableUrl($channelId);
if (!$playableUrl) {
    http_response_code(502);
    setCorsHeaders();
    header('Content-Type: application/json');
    echo json_encode(["error" => "Could not fetch playable URL."]);
    exit;
}

setCorsHeaders();
header('Content-Type: application/json');
echo json_encode([
    'status'           => 'success',
    'playable_redirect'=> $playableUrl
]);
exit;
