<?php
/**
 * API v1 - Geocoding
 * Disaster Response & Resource Coordination System
 * 
 * Endpoints:
 *   GET /api/v1/geocode?lat=-1.2921&lng=36.8219 - Reverse geocode
 *   GET /api/v1/geocode?address=Nairobi - Forward geocode (using Nominatim)
 * 
 * Uses OpenStreetMap Nominatim API (free, no API key required)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit();
}

$lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
$lng = isset($_GET['lng']) ? (float)$_GET['lng'] : null;
$address = $_GET['address'] ?? null;

// Reverse geocoding (coordinates to address)
if ($lat && $lng) {
    $url = "https://nominatim.openstreetmap.org/reverse?format=json&lat=$lat&lon=$lng&zoom=18&addressdetails=1";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'DisasterResponseSystem/1.0');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        
        if ($data && isset($data['display_name'])) {
            sendResponse([
                'success' => true,
                'latitude' => $lat,
                'longitude' => $lng,
                'address' => $data['display_name'],
                'road' => $data['address']['road'] ?? null,
                'city' => $data['address']['city'] ?? $data['address']['town'] ?? $data['address']['village'] ?? null,
                'county' => $data['address']['county'] ?? $data['address']['state'] ?? null,
                'country' => $data['address']['country'] ?? null,
                'postcode' => $data['address']['postcode'] ?? null
            ]);
        }
    }
    
    sendResponse(['success' => false, 'error' => 'Could not reverse geocode'], 400);
}

// Forward geocoding (address to coordinates)
if ($address) {
    $url = "https://nominatim.openstreetmap.org/search?format=json&q=" . urlencode($address) . "&limit=1";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'DisasterResponseSystem/1.0');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        
        if ($data && count($data) > 0) {
            sendResponse([
                'success' => true,
                'address' => $address,
                'latitude' => (float)$data[0]['lat'],
                'longitude' => (float)$data[0]['lon'],
                'display_name' => $data[0]['display_name']
            ]);
        }
    }
    
    sendResponse(['success' => false, 'error' => 'Address not found'], 404);
}

sendResponse(['error' => 'Missing lat/lng or address parameter'], 400);
?>