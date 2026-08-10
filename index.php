<?php
// Prevent PHP from stopping if the user disconnects, crucial for background processing
ignore_user_abort(true);
set_time_limit(0);

// Set header so the browser/client reads the output as JSON
header('Content-Type: application/json');

function generateDDToken() {
    return base64_encode(json_encode([
        'schema_version' => '1',
        'os_name' => 'N/A',
        'os_version' => 'N/A',
        'platform_name' => 'Chrome',
        'platform_version' => '104',
        'device_name' => '',
        'app_name' => 'Web',
        'app_version' => '2.52.31',
        'player_capabilities' => [
            'audio_channel' => ['STEREO'],
            'video_codec' => ['H264'],
            'container' => ['MP4', 'TS'],
            'package' => ['DASH', 'HLS'],
            'resolution' => ['240p', 'SD', 'HD', 'FHD'],
            'dynamic_range' => ['SDR']
        ],
        'security_capabilities' => [
            'encryption' => ['WIDEVINE_AES_CTR'],
            'widevine_security_level' => ['L3'],
            'hdcp_version' => ['HDCP_V1', 'HDCP_V2', 'HDCP_V2_1', 'HDCP_V2_2']
        ]
    ]));
}

function generateGuestToken() {
    $bin = bin2hex(random_bytes(16));
    return substr($bin, 0, 8) . '-' .
           substr($bin, 8, 4) . '-' .
           substr($bin, 12, 4) . '-' .
           substr($bin, 16, 4) . '-' .
           substr($bin, 20);
}

// 1. Fetch fresh Platform Token from the Vercel API (Used in Background or on First Run)
function fetchTokenFromApi() {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://jiotvegp.vercel.app/api/token',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 40 // Give API plenty of time
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    if (isset($data['success']) && $data['success'] === true && !empty($data['token'])) {
        return $data['token'];
    }
    return false;
}

// 2. Fetch the Base HLS URL for the requested Channel ID using Catalog API
function fetchCatalogBaseUrl($id) {
    $url = "https://catalogapi.zee5.com/v1/channel/{$id}?translation=en&country=IN";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    if (isset($data['stream_url_hls']) && !empty($data['stream_url_hls'])) {
        return $data['stream_url_hls'];
    }
    
    echo json_encode(["error" => "Failed to find stream_url_hls in Catalog API for requested ID: $id", "raw_response" => $data]);
    exit;
}

// 3. Generate Token using SPAPI and Zee News Channel ID
function fetchZeeNewsTokenizedUrl($platformToken) {
    $guestToken = generateGuestToken();
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://spapi.zee5.com/singlePlayback/getDetails/secure?channel_id=0-9-zeenews&device_id=' . $guestToken . '&platform_name=desktop_web&translation=en&user_language=en,hi,te&country=IN&state=&app_version=4.24.0&user_type=guest&check_parental_control=false',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'content-type: application/json',
            'origin: https://www.zee5.com',
            'referer: https://www.zee5.com/',
            'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'x-access-token' => $platformToken,
            'X-Z5-Guest-Token' => $guestToken,
            'x-dd-token' => generateDDToken()
        ])
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    $responseData = json_decode($response, true);
    
    if (isset($responseData['keyOsDetails']['video_token'])) {
        return $responseData['keyOsDetails']['video_token'];
    }
    
    echo json_encode(["error" => "Could not fetch SPAPI token. Vercel Token might be invalid.", "raw_response" => $responseData]);
    exit;
}

// Build everything and fix the ACL string
function buildFinalData($req_id, $platformToken, $userAgent) {
    // A. Get Base Master M3U8 URL
    $baseUrl = fetchCatalogBaseUrl($req_id);
    
    // B. Get Tokenized Zee News URL
    $zeeNewsTokenizedUrl = fetchZeeNewsTokenizedUrl($platformToken);
    
    // C. Extract Token Query String
    $parsedUrl = parse_url($zeeNewsTokenizedUrl);
    $queryString = isset($parsedUrl['query']) ? $parsedUrl['query'] : '';
    
    if (empty($queryString)) {
        echo json_encode(["error" => "No token query found in the SPAPI response."]);
        exit;
    }

    // --- FIXING THE ACL DYNAMICALLY ---
    // 1. Extract the path from the new Base URL (e.g., /out/v1/ZEE5_Live_Channels/Zee-Anmol-SD/master/master.m3u8)
    $parsedBaseUrl = parse_url($baseUrl, PHP_URL_PATH); 
    
    // 2. Get the directory and make sure it ends with a slash (e.g., /out/v1/ZEE5_Live_Channels/Zee-Anmol-SD/master/)
    $newAclPath = dirname($parsedBaseUrl);
    if (substr($newAclPath, -1) !== '/') {
        $newAclPath .= '/';
    }
    
    // 3. Append the required '*' at the end of the ACL
    $newAcl = $newAclPath . '*';
    
    // 4. Regex string replace: Finds the old acl=... up to the ~ separator, and overwrites it.
    // (Note: Akamai verifies HMAC. If Akamai enforces HMAC on ACL, this might throw 403, but this correctly fulfills the string replacement request)
    $queryString = preg_replace('/(acl=)([^~&]+)/', '${1}' . $newAcl, $queryString);
    // ----------------------------------
    
    // D. Combine Base URL and modified query string
    $separator = (strpos($baseUrl, '?') !== false) ? '&' : '?';
    $finalM3u8Url = $baseUrl . $separator . $queryString;
    
    // E. Extract the hdntl Cookie
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $finalM3u8Url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    $result = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $extractedCookie = "Not found. (If Akamai returned 403, string-replacing the ACL broke the HMAC signature).";
    if ($httpcode === 200 && preg_match('/hdntl=([^\s"]+)/', $result, $matches)) {
        $extractedCookie = $matches[0];
    }
    
    return [
        'status' => 'success',
        'requested_channel' => $req_id,
        'catalog_base_url' => $baseUrl,
        'constructed_master_m3u8' => $finalM3u8Url,
        'extracted_cookie' => $extractedCookie
    ];
}

// === EXECUTION & BACKGROUND MANAGER ===

$req_id = isset($_GET['id']) && !empty($_GET['id']) ? $_GET['id'] : '0-9-zeeanmol';
$userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';

$cacheFile = sys_get_temp_dir() . '/zee5_token_cache.json';
$cacheTime = 600; // 10 minutes
$platformToken = null;
$trigger_bg_refresh = false;

// Determine if we use manual override, cached token, or need to block and wait
if (isset($_GET['tok']) && !empty($_GET['tok'])) {
    $platformToken = $_GET['tok'];
} else if (file_exists($cacheFile)) {
    $cacheData = json_decode(file_get_contents($cacheFile), true);
    if (!empty($cacheData['token'])) {
        $platformToken = $cacheData['token'];
        // Check if 10 mins have passed. If yes, use THIS token, but refresh in background
        if ((time() - filemtime($cacheFile)) > $cacheTime) {
            $trigger_bg_refresh = true; 
        }
    }
}

// If it's the very first request ever and no cache exists, we MUST wait for the Vercel API
if (!$platformToken) {
    $platformToken = fetchTokenFromApi();
    if ($platformToken) {
        file_put_contents($cacheFile, json_encode(['token' => $platformToken]));
    } else {
        echo json_encode(["error" => "Failed to fetch platform token from Vercel API."]);
        exit;
    }
}

// Build all final data instantly using the available token
$finalOutput = buildFinalData($req_id, $platformToken, $userAgent);

// --- SEAMLESS BACKGROUND TRICK ---
// Clean buffer and send data instantly to client, closing their connection so they don't wait.
if (ob_get_level()) ob_end_clean();
header("Connection: close");
ob_start();
echo json_encode($finalOutput, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$size = ob_get_length();
header("Content-Length: $size");
ob_end_flush();
flush();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request(); // Best for Render/FPM environments
}

// --- BACKGROUND TASK ---
// Client is already gone and has their JSON. PHP is still running silently.
if ($trigger_bg_refresh) {
    $newToken = fetchTokenFromApi();
    if ($newToken) {
        file_put_contents($cacheFile, json_encode(['token' => $newToken]));
    }
}
exit;
?>
