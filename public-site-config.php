<?php

declare(strict_types=1);

$allowedOrigins = [
    'https://nyskickstenaltan.se',
    'https://www.nyskickstenaltan.se',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

echo json_encode([
    'googleAnalyticsMeasurementId' => 'G-9TKSWWGZF7',
    'googleMapsApiKey' => 'AIzaSyDuJVe5lEDhE_5w2FOBvOvt4E30qKpVtqk',
    'googleMapsAutocompleteCountry' => 'se',
    'quoteFormEndpoint' => 'https://admin.nyskickstenaltan.se/public-quote-request.php',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
