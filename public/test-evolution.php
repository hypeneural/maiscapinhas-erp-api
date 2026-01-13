<?php
/**
 * Test Evolution API Connection
 * DELETE THIS FILE AFTER USE!
 */

// Evolution API Config
$baseUrl = 'https://evolution.soclick.click';
$apiKey = '63512EC6A794-4EA7-9A90-B71ED7E95ED3';
$instanceName = 'anderson';

echo "<h2>Evolution API Connection Test</h2>";
echo "<pre>";

// Test 1: DNS Resolution
echo "1. Testing DNS resolution for: evolution.soclick.click\n";
$ip = gethostbyname('evolution.soclick.click');
if ($ip === 'evolution.soclick.click') {
    echo "   ❌ DNS FAILED - Could not resolve hostname\n";
    echo "   → The server cannot resolve this domain\n\n";

    // Try with IP directly
    echo "2. Trying direct IP connection (173.212.252.111)...\n";
    $baseUrl = 'https://173.212.252.111';
} else {
    echo "   ✅ DNS OK - Resolved to: $ip\n\n";
}

// Test 2: Connection to Evolution API
echo "3. Testing connection to Evolution API...\n";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "$baseUrl/instance/connectionState/$instanceName",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_HTTPHEADER => [
        "apikey: $apiKey",
        "Content-Type: application/json"
    ]
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
$errno = curl_errno($ch);
curl_close($ch);

if ($errno) {
    echo "   ❌ CONNECTION FAILED\n";
    echo "   Error: $error (code: $errno)\n\n";
} else {
    echo "   ✅ Connection successful (HTTP $httpCode)\n";
    echo "   Response: $response\n\n";
}

// Test 3: Send test message
echo "4. Attempting to check number (5548999999999)...\n";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "$baseUrl/chat/whatsappNumbers/$instanceName",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_HTTPHEADER => [
        "apikey: $apiKey",
        "Content-Type: application/json"
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'numbers' => ['5548999999999']
    ])
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo "   ❌ FAILED: $error\n";
} else {
    echo "   HTTP $httpCode\n";
    echo "   Response: $response\n";
}

echo "\n</pre>";
echo "<p style='color:red; font-weight:bold;'>⚠️ DELETE THIS FILE NOW!</p>";
