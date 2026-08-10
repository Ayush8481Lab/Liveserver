<?php
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

// 1. Scrape and Cache the Platform Token (Updates every 10 mins)
function fetchCachedPlatformToken() {
    $cacheFile = 'token_cache.json';
    $cacheTime = 600; // 10 minutes in seconds

    // Check if cache exists and is newer than 10 minutes
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
        $cacheData = json_decode(file_get_contents($cacheFile), true);
        if (!empty($cacheData['token'])) {
            return $cacheData['token'];
        }
    }

    // If cache is expired or missing, scrape the Zee News page again
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://www.zee5.com/live-tv/zee-news/0-9-zeenews',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9'
        ]
    ]);
    $html = curl_exec($ch);
    curl_close($ch);

    if (preg_match('/"gwapiPlatformToken"\s*:\s*"([^"]+)"/', $html, $matches)) {
        $token = $matches[1];
        // Save the new token to the cache file
        file_put_contents($cacheFile, json_encode(['token' => $token]));
        return $token;
    }

    echo json_encode(["error" => "Failed to scrape gwapiPlatformToken from Zee News webpage. IP might be blocked."]);
    exit;
}

// 2. Fetch the Base HLS URL for the requested Channel ID using Catalog API
function fetchCatalogBaseUrl($id) {
    $url = "https://catalogapi.zee5.com/v1/channel/{$id}?translation=en&country=IN";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko)'
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    if (isset($data['stream_url_hls']) && !empty($data['stream_url_hls'])) {
        return $data['stream_url_hls'];
    }
    
    echo json_encode(["error" => "Failed to find stream_url_hls in Catalog API for requested ID: $id"]);
    exit;
}

// 3. Generate a Free Token by hitting SPAPI using Zee News default channel
function fetchZeeNewsTokenizedUrl($platformToken) {
    $guestToken = generateGuestToken();
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        // Hardcoded to 0-9-zeenews to bypass premium checks and get a free CDN token
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
    
    echo json_encode(["error" => "Could not fetch SPAPI token using Zee News", "raw_response" => $responseData]);
    exit;
}

// Main logic building everything together
function buildFinalData($userAgent) {
    // Get requested ID from URL (e.g. ?id=0-9-zeeanmol), fallback to zeeanmol if none provided
    $req_id = isset($_GET['id']) && !empty($_GET['id']) ? $_GET['id'] : '0-9-zeeanmol';
    
    // Step 1: Get Cached Platform Token
    $platformToken = fetchCachedPlatformToken();
    
    // Step 2: Get the Base Master M3U8 URL for the requested channel
    $baseUrl = fetchCatalogBaseUrl($req_id);
    
    // Step 3: Get the Tokenized Zee News URL
    $zeeNewsTokenizedUrl = fetchZeeNewsTokenizedUrl($platformToken);
    
    // Step 4: Extract the token query string and merge it with the Base URL
    $parsedUrl = parse_url($zeeNewsTokenizedUrl);
    $queryString = isset($parsedUrl['query']) ? $parsedUrl['query'] : '';
    
    if (empty($queryString)) {
        echo json_encode(["error" => "No token query found in the Zee News SPAPI response."]);
        exit;
    }
    
    $separator = (strpos($baseUrl, '?') !== false) ? '&' : '?';
    $finalM3u8Url = $baseUrl . $separator . $queryString;
    
    // Step 5: Process exactly as it was (Extract Cookie from final URL if possible)
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
    
    $extractedCookie = "Not found or IP blocked at CDN level.";
    if ($httpcode === 200 && preg_match('/hdntl=([^\s"]+)/', $result, $matches)) {
        $extractedCookie = $matches[0];
    }
    
    // Return all data
    return [
        'status' => 'success',
        'requested_channel' => $req_id,
        'catalog_base_url' => $baseUrl,
        'constructed_master_m3u8' => $finalM3u8Url,
        'extracted_cookie' => $extractedCookie
    ];
}

// === EXECUTION BLOCK ===
$userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36';
$finalOutput = buildFinalData($userAgent);

echo json_encode($finalOutput, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;
?>
