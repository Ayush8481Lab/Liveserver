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

// MODIFIED: Only accepts the token dynamically from the URL, skips page parsing completely.
function fetchPlatformToken() {
    if (isset($_GET['tok']) && !empty($_GET['tok'])) {
        return $_GET['tok'];
    }
    
    // If no token is provided in the URL, stop execution and show this error.
    echo json_encode(["error" => "Missing gwapiPlatformToken. Please pass it in the URL like: This.php?tok=YOUR_TOKEN_HERE"]);
    exit;
}

function fetchM3U8url() {
    $guestToken = generateGuestToken();
    $platformToken = fetchPlatformToken();
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://spapi.zee5.com/singlePlayback/getDetails/secure?channel_id=0-9-zeetv&device_id=' . $guestToken . '&platform_name=desktop_web&translation=en&user_language=en,hi,te&country=IN&state=&app_version=4.24.0&user_type=guest&check_parental_control=false',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'content-type: application/json',
            'origin: https://www.zee5.com',
            'referer: https://www.zee5.com/',
            'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36'
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
            'm3u8_url' => $responseData['keyOsDetails']['video_token'],
            'post_api_response' => $responseData 
        ];
    } else {
        echo json_encode(["error" => "Could not fetch m3u8 URL", "raw_response" => $responseData]);
        exit;
    }
}

function generateCookieZee5($userAgent) {
    $fetchedData = fetchM3U8url();
    $m3u8Url = $fetchedData['m3u8_url'];
    $apiResponse = $fetchedData['post_api_response'];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $m3u8Url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    $result = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpcode !== 200) {
        echo json_encode(["error" => "Required hdntl token can't be extracted. IP blocked at Akamai CDN level."]);
        exit;
    }
    
    if (preg_match('/hdntl=([^\s"]+)/', $result, $matches)) {
        return [
            'status' => 'success',
            'extracted_cookie' => $matches[0],
            'm3u8_master_url' => $m3u8Url,
            'zee5_api_response' => $apiResponse 
        ];
    }
    
    echo json_encode(["error" => "Something went wrong. hdntl cookie not found in the m3u8 text."]);
    exit;
}

// === EXECUTION BLOCK ===
$userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36';

$finalOutput = generateCookieZee5($userAgent);

echo json_encode($finalOutput, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;
?>
