<?php

declare(strict_types=1);

$allowedOrigins = [
    'https://nyskickstenaltan.se',
    'https://www.nyskickstenaltan.se',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$googleAnalyticsMeasurementId = getenv('GOOGLE_ANALYTICS_MEASUREMENT_ID') ?: 'G-9TKSWWGZF7';
$googleMapsApiKey = getenv('GOOGLE_MAPS_API_KEY') ?: '';
$googleMapsAutocompleteCountry = getenv('GOOGLE_MAPS_AUTOCOMPLETE_COUNTRY') ?: 'se';
$quoteFormEndpoint = getenv('QUOTE_FORM_ENDPOINT') ?: 'https://admin.nyskickstenaltan.se/public-quote-request.php';

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
    'googleAnalyticsMeasurementId' => $googleAnalyticsMeasurementId,
    'googleMapsApiKey' => $googleMapsApiKey,
    'googleMapsAutocompleteCountry' => $googleMapsAutocompleteCountry,
    'quoteFormEndpoint' => $quoteFormEndpoint,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
