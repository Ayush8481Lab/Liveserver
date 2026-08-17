<?php
/* ─── Configuration ─── */
define('TOKEN_CACHE_FILE', __DIR__ . '/token_cache.json');
define('TOKEN_REFRESH_INTERVAL', 10800);         // 3 hours – token is valid for at least 3 hours
define('PLAYABLE_URL_CACHE_TTL', 1200);          // 25 minutes – stream URL is valid 40‑60 minutes
define('LOCK_FILE', __DIR__ . '/token_refresh.lock');

/* ─── Background token refresh guard ───
   When called from CLI with the BACKGROUND_TOKEN_REFRESH constant,
   run only the refresh and exit – do NOT process a normal web request. */
if (defined('BACKGROUND_TOKEN_REFRESH') && BACKGROUND_TOKEN_REFRESH === true) {
    doBackgroundTokenRefresh();
    exit;
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

/* ─── Token management (non‑blocking) ─── */

/**
 * Returns the cached token instantly.
 * If the token is missing, fetches it synchronously (only once).
 */
function getPlatformToken() {
    $cache = loadTokenCache();
    if ($cache && !empty($cache['token'])) {
        // If token is stale, trigger a background refresh (but still return old token)
        if ((time() - $cache['fetched_at']) >= TOKEN_REFRESH_INTERVAL) {
            triggerBackgroundTokenRefresh();
        }
        return $cache['token'];
    }

    // No token at all – fetch synchronously (first run)
    $token = fetchTokenFromApi();
    if ($token) {
        saveTokenCache($token);
        return $token;
    }
    return null;
}

/**
 * Loads token cache from file.
 */
function loadTokenCache() {
    if (!file_exists(TOKEN_CACHE_FILE)) return null;
    $data = file_get_contents(TOKEN_CACHE_FILE);
    return json_decode($data, true);
}

/**
 * Saves token to cache file.
 */
function saveTokenCache($token) {
    $data = ['token' => $token, 'fetched_at' => time()];
    file_put_contents(TOKEN_CACHE_FILE, json_encode($data), LOCK_EX);
}

/**
 * Fetches a new token from the API (blocking call).
 * Used only during background refresh or first‑time sync.
 */
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
        return $data['token'];
    }
    return null;
}

/**
 * Triggers a background token refresh if not already running.
 * Uses a lock file to prevent multiple concurrent refreshes.
 */
function triggerBackgroundTokenRefresh() {
    // Already refreshing? Skip.
    if (file_exists(LOCK_FILE)) {
        $lockAge = time() - filemtime(LOCK_FILE);
        if ($lockAge < 60) return; // assume refresh is still ongoing
        // If lock is older than 60s, it's stale – remove it and proceed
        @unlink(LOCK_FILE);
    }

    // Create lock file
    file_put_contents(LOCK_FILE, time(), LOCK_EX);

    // Spawn background process (async) – now correctly calls only the refresh function
    $cmd = 'php -d display_errors=0 -r "define(\'BACKGROUND_TOKEN_REFRESH\', true); require_once \'' . __FILE__ . '\';" > /dev/null 2>&1 &';
    exec($cmd);
}

/**
 * Background task – called by the spawned process.
 */
function doBackgroundTokenRefresh() {
    set_time_limit(30);
    $lockFile = LOCK_FILE;

    // Double‑check lock (should exist)
    if (!file_exists($lockFile)) return;

    $newToken = fetchTokenFromApi();
    if ($newToken) {
        saveTokenCache($newToken);
    }
    @unlink($lockFile);
}

/* ─── Playable URL with caching ─── */

function fetchFreshPlayableUrl($channelId) {
    $deviceId   = generateUUID();
    $guestToken = $deviceId;
    $platformToken = getPlatformToken(); // non‑blocking
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

function getCachedPlayableUrl($channelId) {
    $cacheFile = __DIR__ . '/tmp/playable_' . md5($channelId) . '.cache';
    // Create tmp dir if missing
    if (!is_dir(__DIR__ . '/tmp')) mkdir(__DIR__ . '/tmp', 0755, true);

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < PLAYABLE_URL_CACHE_TTL) {
        return file_get_contents($cacheFile);
    }
    $freshUrl = fetchFreshPlayableUrl($channelId);
    if ($freshUrl) {
        file_put_contents($cacheFile, $freshUrl, LOCK_EX);
        return $freshUrl;
    }
    // If fetch fails, return stale cache if exists
    return file_exists($cacheFile) ? file_get_contents($cacheFile) : null;
}

/* ─── Request helpers ─── */

function getChannelIdFromRequest() {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (preg_match('#/([a-zA-Z0-9\-_]+)\.m3u8$#', $path, $m)) {
        return $m[1];
    }
    return $_GET['id'] ?? null;
}

function setCorsHeaders() {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: *');
}

/* ─── Main ─── */

// CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    setCorsHeaders();
    http_response_code(204);
    exit;
}

$channelId = getChannelIdFromRequest();

// HEALTH CHECK – responds 200 when no channel ID is given (e.g. cron keep‑alive)
if (!$channelId) {
    setCorsHeaders();
    header('Content-Type: application/json');
    http_response_code(200);
    echo json_encode(["status" => "ok", "message" => "Server is running"]);
    exit;
}

// If request ends with .m3u8 → redirect to Akamai (fast)
if (preg_match('#\.m3u8$#', $_SERVER['REQUEST_URI'] ?? '')) {
    $playableUrl = getCachedPlayableUrl($channelId);
    if (!$playableUrl) {
        http_response_code(502);
        setCorsHeaders();
        echo 'Failed to obtain stream URL';
        exit;
    }
    setCorsHeaders();
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    header('Location: ' . $playableUrl, true, 302);
    exit;
}

// ?id= → JSON debug (CORS enabled)
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
