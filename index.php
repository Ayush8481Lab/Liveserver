<?php
header('Content-Type: application/json');

// Path to the local cache file (writable by the web server on Render)
define('TOKEN_CACHE_FILE', __DIR__ . '/token_cache.json');
// Refresh interval in seconds (10 minutes)
define('TOKEN_REFRESH_INTERVAL', 600);

/**
 * Generates the dd token used in the Playback Details API.
 */
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

/**
 * Generates a random guest token in UUID-like format.
 */
function generateGuestToken() {
    $bin = bin2hex(random_bytes(16));
    return substr($bin, 0, 8) . '-' .
           substr($bin, 8, 4) . '-' .
           substr($bin, 12, 4) . '-' .
           substr($bin, 16, 4) . '-' .
           substr($bin, 20);
}

/**
 * Returns a valid platform token, refreshing it automatically when needed.
 * Uses a file-based cache to avoid unnecessary API calls.
 */
function getPlatformToken() {
    // Check if a fresh cached token exists
    if (file_exists(TOKEN_CACHE_FILE)) {
        $cacheTime = filemtime(TOKEN_CACHE_FILE);
        if ((time() - $cacheTime) < TOKEN_REFRESH_INTERVAL) {
            // Token is still fresh
            $data = json_decode(file_get_contents(TOKEN_CACHE_FILE), true);
            if (isset($data['token']) && !empty($data['token'])) {
                return $data['token'];
            }
        }
    }

    // Token missing or expired – fetch a new one
    $token = fetchTokenFromApi();

    // If fetching failed but we have an old token, use it as fallback
    if (!$token && file_exists(TOKEN_CACHE_FILE)) {
        $data = json_decode(file_get_contents(TOKEN_CACHE_FILE), true);
        if (isset($data['token'])) {
            return $data['token']; // stale but better than nothing
        }
    }

    if (!$token) {
        echo json_encode(["error" => "Unable to obtain platform token from API."]);
        exit;
    }

    return $token;
}

/**
 * Fetches a new platform token from the external API.
 * Returns the token string on success, or null on failure.
 */
function fetchTokenFromApi() {
    $ch = curl_init('https://jiotvegp.vercel.app/api/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36'
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($response)) {
        return null;
    }

    $data = json_decode($response, true);
    if (isset($data['success']) && $data['success'] === true && isset($data['token'])) {
        $token = $data['token'];
        // Save to cache file (use file locking to avoid race conditions)
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

/**
 * Fetches the m3u8 URL from Zee5 API using the latest platform token.
 */
function fetchM3U8url() {
    $guestToken    = generateGuestToken();
    $platformToken = getPlatformToken();

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://spapi.zee5.com/singlePlayback/getDetails/secure?channel_id=0-9-9z583538&device_id=' . $guestToken . '&platform_name=desktop_web&translation=en&user_language=en,hi,te&country=IN&state=&app_version=4.24.0&user_type=guest&check_parental_control=false',
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
            'x-access-token'     => $platformToken,
            'X-Z5-Guest-Token'   => $guestToken,
            'x-dd-token'         => generateDDToken()
        ])
    ]);

    $response = curl_exec($ch);
    curl_close($ch);
    $responseData = json_decode($response, true);

    if (!$responseData) {
        echo json_encode(["error" => "Invalid response received from API. IP is likely blocked."]);
        exit;
    }

    if (isset($responseData['keyOsDetails']['video_token'])) {
        if (!filter_var($responseData['keyOsDetails']['video_token'], FILTER_VALIDATE_URL)) {
            echo json_encode(["error" => "Invalid URL received."]);
            exit;
        }
        return [
            'm3u8_url'          => $responseData['keyOsDetails']['video_token'],
            'post_api_response' => $responseData
        ];
    } else {
        echo json_encode(["error" => "Could not fetch m3u8 URL", "raw_response" => $responseData]);
        exit;
    }
}

/**
 * Extracts the hdntl cookie from the m3u8 master playlist.
 */
function generateCookieZee5() {
    $userAgent   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36';
    $fetchedData = fetchM3U8url();
    $m3u8Url     = $fetchedData['m3u8_url'];
    $apiResponse = $fetchedData['post_api_response'];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $m3u8Url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => $userAgent,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    $result   = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode !== 200) {
        echo json_encode(["error" => "Required hdntl token can't be extracted. IP blocked at Akamai CDN level."]);
        exit;
    }

    if (preg_match('/hdntl=([^\s"]+)/', $result, $matches)) {
        return [
            'status'           => 'success',
            'extracted_cookie' => $matches[0],
            'm3u8_master_url'  => $m3u8Url,
            'zee5_api_response'=> $apiResponse
        ];
    }

    echo json_encode(["error" => "Something went wrong. hdntl cookie not found in the m3u8 text."]);
    exit;
}

// === Execution ===
$finalOutput = generateCookieZee5();
echo json_encode($finalOutput, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;
