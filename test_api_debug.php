<?php
// test_api_fixed.php - Corrected version
error_reporting(E_ALL);
ini_set('display_errors', 1);

// !!! REPLACE WITH YOUR SANDBOX API KEY FROM SANDBOX DASHBOARD !!!
$api_key = 'atsk_b59122ea545a590f16cc77b4cf492afdc07a44ccad8e37cbcef8519b0708ea6ea434e551';  // <-- MUST be from Sandbox
$username = 'sandbox';  // <-- MUST be exactly 'sandbox' (lowercase)
$phone = '254727272727';
$message = 'Test message from Disaster Response System';
$from = 'DisasterResp';

echo "=== Africa's Talking API Test ===\n";
echo "Username: $username\n";
echo "API Key (first 10 chars): " . substr($api_key, 0, 10) . "...\n";
echo "Environment: Sandbox\n";
echo "URL: https://api.sandbox.africastalking.com/version1/messaging\n\n";

// CRITICAL: Use sandbox subdomain
$url = 'https://api.sandbox.africastalking.com/version1/messaging';

$data = [
    'username' => $username,
    'to' => $phone,
    'message' => $message,
    'from' => $from
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'apiKey: ' . $api_key,
    'Accept: application/json',
    'Content-Type: application/x-www-form-urlencoded'
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_VERBOSE, true);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

echo "HTTP Code: $http_code\n";
echo "Response: $response\n";

if ($curl_error) {
    echo "cURL Error: $curl_error\n";
}

$result = json_decode($response, true);
if ($http_code == 201 || $http_code == 200) {
    echo "\n✅ SUCCESS! Authentication working!\n";
} else {
    echo "\n❌ Authentication failed. Check:\n";
    echo "1. Username is exactly 'sandbox' (lowercase)\n";
    echo "2. API key was generated from SANDBOX dashboard (not live)\n";
    echo "3. You waited 5 minutes after generating new key\n";
    echo "4. The API key has no extra spaces when copied\n";
}
?>