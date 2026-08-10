<?php
header('Content-Type: application/json');

/* ─── Configuration ─── */
define('TOKEN_CACHE_FILE', __DIR__ . '/token_cache.json');
define('TOKEN_REFRESH_INTERVAL', 600); // 10 minutes

/* ─── Helper: generate a random UUID v4 ─── */
function generateUUID() {
    $data = random_bytes(16);
    // Set version 4 (random)
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    // Set variant bits
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/* ─── Generate dd token (unchanged) ─── */
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
    // If a fresh cached token exists, use it
    if (file_exists(TOKEN_CACHE_FILE)) {
        $cacheTime = filemtime(TOKEN_CACHE_FILE);
        if ((time() - $cacheTime) < TOKEN_REFRESH_INTERVAL) {
            $data = json_decode(file_get_contents(TOKEN_CACHE_FILE), true);
            if (isset($data['token']) && !empty($data['token'])) {
                return $data['token'];
            }
        }
    }

    // Fetch a new token from the external API
    $token = fetchTokenFromApi();

    // Fallback: if API fails but old token exists, use it
    if (!$token && file_exists(TOKEN_CACHE_FILE)) {
        $data = json_decode(file_get_contents(TOKEN_CACHE_FILE), true);
        if (isset($data['token'])) {
            return $data['token'];
        }
    }

    if (!$token) {
        http_response_code(502);
        echo json_encode(["error" => "Unable to obtain platform token from API."]);
        exit;
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

    if ($httpCode !== 200 || empty($response)) {
        return null;
    }

    $data = json_decode($response, true);
    if (isset($data['success'], $data['token']) && $data['success'] === true) {
        $token = $data['token'];
        // Save to cache (with file locking)
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

/* ─── Fetch M3U8 URL using NEW mobile_web API parameters ─── */
function fetchM3U8url($channelId) {
    $deviceId   = generateUUID();          // proper UUID
    $guestToken = $deviceId;               // can be same or separate; here we reuse
    $platformToken = getPlatformToken();

    $queryParams = http_build_query([
        'channel_id'              => $channelId,
        'device_id'               => $deviceId,
        'platform_name'           => 'mobile_web',
        'translation'             => 'en',
        'user_language'           => 'en,hi,hr,mr',
        'country'                 => 'IN',
        'state'                   => '',          // leave empty (or use 'RJ' if needed)
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
            'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36'
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

    if (!$data) {
        http_response_code(502);
        echo json_encode(["error" => "Invalid response from Zee5 API."]);
        exit;
    }

    if (isset($data['keyOsDetails']['video_token'])) {
        return $data['keyOsDetails']['video_token'];
    } else {
        http_response_code(500);
        echo json_encode([
            "error" => "Could not fetch M3U8 URL",
            "raw_response" => $data
        ]);
        exit;
    }
}

/* ─── Extract Akamai token from M3U8 ─── */
function generateCookieZee5($channelId) {
    $m3u8Url = fetchM3U8url($channelId);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $m3u8Url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_FOLLOWLOCATION => true
    ]);
    $result   = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode !== 200) {
        http_response_code(502);
        echo json_encode(["error" => "Failed to fetch M3U8. CDN blocked or token expired."]);
        exit;
    }

    // Look for hdntl or hdnea token
    if (preg_match('/(hdntl|hdnea)=([^\s"]+)/', $result, $matches)) {
        $tokenKey   = $matches[1];
        $tokenValue = $matches[2];
        $fullCookie = $tokenKey . '=' . $tokenValue;

        return [
            'status'           => 'success',
            'extracted_cookie' => $fullCookie,
            'playable_url'     => $m3u8Url . '?' . $fullCookie,
            'm3u8_master_url'  => $m3u8Url,
            'token_type'       => $tokenKey
        ];
    }

    http_response_code(500);
    echo json_encode(["error" => "Akamai token not found in M3U8."]);
    exit;
}

/* ─── Main Execution ─── */
$channelId = $_GET['id'] ?? null;
if (!$channelId) {
    http_response_code(400);
    echo json_encode(["error" => "Channel ID is required. Usage: ?id=0-9-zeetv"]);
    exit;
}

$final = generateCookieZee5($channelId);
echo json_encode($final, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;
