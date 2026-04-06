<?php
declare(strict_types=1);

header('Content-Type: application/javascript; charset=UTF-8');

/**
 * Lightweight public config for browser features.
 * Only expose values intended for client-side use.
 */
function site_config_env(string $key, string $default = ''): string
{
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return (string)$value;
    }

    $envPath = __DIR__ . '/.env';
    if (!is_file($envPath) || !is_readable($envPath)) {
        return $default;
    }

    static $parsed = null;
    if ($parsed === null) {
        $parsed = [];
        $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$name, $raw] = explode('=', $line, 2);
            $name = trim((string)$name);
            $raw = trim((string)$raw);
            $raw = trim($raw, "\"'");
            if ($name !== '') {
                $parsed[$name] = $raw;
            }
        }
    }

    return isset($parsed[$key]) && $parsed[$key] !== '' ? (string)$parsed[$key] : $default;
}

$googleMapsApiKey = site_config_env('GOOGLE_MAPS_API_KEY', '');
$googleMapsCountry = site_config_env('GOOGLE_MAPS_AUTOCOMPLETE_COUNTRY', 'se');
$quoteFormEndpoint = site_config_env('QUOTE_FORM_ENDPOINT', 'https://admin.nyskickstenaltan.se/public-quote-request.php');

$config = [
    'googleMapsApiKey' => $googleMapsApiKey,
    'googleMapsAutocompleteCountry' => $googleMapsCountry,
    'quoteFormEndpoint' => $quoteFormEndpoint,
];

echo 'window.NYSKICK_SITE_CONFIG = ' . json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';';
