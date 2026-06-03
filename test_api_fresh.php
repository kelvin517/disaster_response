<?php
// test_api_fresh.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// !!! ACTION REQUIRED HERE !!!
// 1. Log into your Africa's Talking account.
// 2. Go to the "Sandbox" section.
// 3. Go to "Settings" -> "API Key".
// 4. Click "Regenerate API Key".
// 5. Copy the NEW key that appears on screen (it is only shown once).
// 6. Paste it inside the quotes below.
$sandbox_api_key = 'atsk_b59122ea545a590f16cc77b4cf492afdc07a44ccad8e37cbcef8519b0708ea6ea434e551';

$username = 'sandbox'; // Must be exactly 'sandbox' for sandbox
$phone = '254792460351';
$message = 'Test message from Disaster Response System';

// Critical: Use the sandbox subdomain
$url = 'https://api.sandbox.africastalking.com/version1/messaging';

echo "=== Africa's Talking API Fresh Test ===\n";
echo "URL: $url\n";
echo "Username: $username\n";
echo "API Key (first 10 chars): " . substr($sandbox_api_key, 0, 10) . "...\n\n";

$data = [
    'username' => $username,
    'to' => $phone,
    'message' => $message
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'apiKey: ' . $sandbox_api_key,
    'Accept: application/json',
    'Content-Type: application/x-www-form-urlencoded'
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $http_code\n";
echo "Response: $response\n";

$result = json_decode($response, true);
if ($http_code == 201 || $http_code == 200) {
    echo "\n✅ SUCCESS! Authentication is working.\n";
} else {
    echo "\n❌ Authentication failed. Please confirm:\n";
    echo "- You generated the API key from the SANDBOX dashboard (Settings -> API Key).\n";
    echo "- You waited at least 5 minutes after generating it.\n";
    echo "- The username is exactly 'sandbox' (all lowercase).\n";
    echo "- You are using the correct URL: 'https://api.sandbox.africastalking.com/'\n";
}
?>