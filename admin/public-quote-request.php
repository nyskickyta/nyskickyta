<?php
declare(strict_types=1);

require_once __DIR__ . '/storage.php';

$allowedOrigins = [
    'https://www.nyskickstenaltan.se',
    'https://nyskickstenaltan.se',
    'http://127.0.0.1:8000',
    'http://localhost:8000',
    'http://127.0.0.1:8001',
    'http://localhost:8001',
    'http://127.0.0.1:8002',
    'http://localhost:8002',
];

$origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

function public_quote_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function normalized_email_value(string $value): string
{
    $email = trim($value);
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function web_quote_mail_recipients(array $organization, array $data): array
{
    $organizationId = (int)($organization['id'] ?? 0);
    $regionId = (int)($organization['region_id'] ?? 0);
    $recipients = [];

    foreach (($data['users'] ?? []) as $user) {
        if (empty($user['is_active'])) {
            continue;
        }

        $email = normalized_email_value((string)($user['email'] ?? ''));
        if ($email === '') {
            continue;
        }

        $userOrganizationId = (int)($user['organization_id'] ?? 0);
        $userRegionId = (int)($user['region_id'] ?? 0);

        if (($organizationId > 0 && $userOrganizationId === $organizationId) || ($regionId > 0 && $userRegionId === $regionId)) {
            $recipients[] = $email;
        }
    }

    foreach (($data['organization_memberships'] ?? []) as $membership) {
        if ((int)($membership['organization_id'] ?? 0) !== $organizationId) {
            continue;
        }

        $userId = (int)($membership['user_id'] ?? 0);
        foreach (($data['users'] ?? []) as $user) {
            if ((int)($user['id'] ?? 0) !== $userId || empty($user['is_active'])) {
                continue;
            }

            $email = normalized_email_value((string)($user['email'] ?? ''));
            if ($email !== '') {
                $recipients[] = $email;
            }
            break;
        }
    }

    $recipients[] = 'info@nyskickstenaltan.se';

    return array_values(array_unique(array_filter($recipients, static fn(string $email): bool => $email !== '')));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    public_quote_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$contentType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? '')));
$rawInput = file_get_contents('php://input');
$input = [];

if (str_contains($contentType, 'application/json')) {
    $decoded = json_decode((string)$rawInput, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
} else {
    $input = $_POST;
    if ($input === [] && is_string($rawInput) && $rawInput !== '') {
        parse_str($rawInput, $input);
    }
}

$name = trim((string)($input['name'] ?? ''));
$phone = trim((string)($input['phone'] ?? ''));
$email = trim((string)($input['email'] ?? ''));
$requestType = trim((string)($input['requestType'] ?? ''));
$surfaceSize = trim((string)($input['surfaceSize'] ?? ''));
$growthLevel = trim((string)($input['growthLevel'] ?? ''));
$hasDetails = trim((string)($input['hasDetails'] ?? ''));
$serviceAddress = trim((string)($input['serviceAddress'] ?? ''));
$servicePostalCode = trim((string)($input['servicePostalCode'] ?? ''));
$serviceCity = trim((string)($input['serviceCity'] ?? ''));
$message = trim((string)($input['message'] ?? ''));
$sourcePage = trim((string)($input['sourcePage'] ?? 'homepage'));
$website = trim((string)($input['website'] ?? ''));
$formStarted = (int)($input['form_started'] ?? 0);

if ($website !== '') {
    public_quote_response(['ok' => false, 'error' => 'review'], 422);
}

$nowMs = (int)round(microtime(true) * 1000);
$elapsedMs = $formStarted > 0 ? $nowMs - $formStarted : null;
if ($elapsedMs !== null && $elapsedMs < 2500) {
    public_quote_response(['ok' => false, 'error' => 'review'], 422);
}

if (
    $name === ''
    || $phone === ''
    || $requestType === ''
    || $surfaceSize === ''
    || $growthLevel === ''
    || $hasDetails === ''
    || $serviceAddress === ''
    || $servicePostalCode === ''
    || $serviceCity === ''
) {
    public_quote_response(['ok' => false, 'error' => 'validation'], 422);
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    public_quote_response(['ok' => false, 'error' => 'validation'], 422);
}

$structuredMessageParts = [
    'Gäller: ' . $requestType,
    'Storlek: ' . $surfaceSize,
    'Påväxt: ' . $growthLevel,
    'Trappor eller flera nivåer: ' . $hasDetails,
];

if ($message !== '') {
    $structuredMessageParts[] = 'Meddelande: ' . $message;
}

$structuredMessage = implode("\n", $structuredMessageParts);

$combinedText = mb_strtolower($name . ' ' . $structuredMessage . ' ' . $email, 'UTF-8');
$urlCount = preg_match_all('/https?:\/\//i', $combinedText);
$spamHints = ['viagra', 'casino', 'crypto', 'loan', 'seo service', 'backlink', 'telegram', 'whatsapp'];

foreach ($spamHints as $hint) {
    if (str_contains($combinedText, $hint)) {
        public_quote_response(['ok' => false, 'error' => 'review'], 422);
    }
}

if ($urlCount > 1) {
    public_quote_response(['ok' => false, 'error' => 'review'], 422);
}

$mailData = mysql_is_configured() ? load_data_mysql() : ['regions' => [], 'organizations' => [], 'users' => [], 'organization_memberships' => []];
$selectedRegion = infer_region_from_postcode($servicePostalCode, $mailData['regions'] ?? []);
$matchedOrganization = null;

if ($selectedRegion !== null && !empty($selectedRegion['is_active'])) {
    $matchedOrganization = find_active_organization_for_region($mailData['organizations'] ?? [], (int)($selectedRegion['id'] ?? 0));
}

if ($matchedOrganization === null) {
    $matchedOrganization = find_dalarna_fallback_organization($mailData['organizations'] ?? [], $mailData['regions'] ?? []);
}

$recipients = $matchedOrganization !== null
    ? web_quote_mail_recipients($matchedOrganization, $mailData)
    : ['info@nyskickstenaltan.se'];

$to = implode(',', $recipients);
$subject = 'Ny offertförfrågan från nyskickstenaltan.se';
$bodyLines = [
    'Ny offertförfrågan från hemsidan',
    '',
    'Namn: ' . $name,
    'Telefon: ' . $phone,
    'E-post: ' . ($email !== '' ? $email : 'Ej angivet'),
    'Gäller: ' . $requestType,
    'Storlek: ' . $surfaceSize,
    'Påväxt: ' . $growthLevel,
    'Trappor eller flera nivåer: ' . $hasDetails,
    'Adress: ' . $serviceAddress,
    'Postnummer: ' . $servicePostalCode,
    'Ort: ' . $serviceCity,
    'Meddelande: ' . ($message !== '' ? $message : 'Ej angivet'),
];
$body = implode("\n", $bodyLines);
$safeReplyTo = str_replace(["\r", "\n"], '', $email);
$headers = [
    'From: info@nyskickstenaltan.se',
    'Content-Type: text/plain; charset=UTF-8',
];
if ($safeReplyTo !== '') {
    $headers[] = 'Reply-To: ' . $safeReplyTo;
}

$savedInSystem = false;
if (mysql_is_configured()) {
    try {
        $pdo = admin_pdo();
        if (mysql_table_exists($pdo, 'web_quote_requests')) {
            mysql_create_web_quote_request([
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
                'serviceAddress' => $serviceAddress,
                'servicePostalCode' => $servicePostalCode,
                'serviceCity' => $serviceCity,
                'message' => $structuredMessage,
                'sourcePage' => $sourcePage,
            ]);
            $savedInSystem = true;
        }
    } catch (Throwable $exception) {
        error_log('Kunde inte spara webbforfragan: ' . $exception->getMessage());
    }
}

$sent = mail($to, $subject, $body, implode("\r\n", $headers));

if ($sent || $savedInSystem) {
    public_quote_response(['ok' => true]);
}

public_quote_response(['ok' => false, 'error' => 'send_failed'], 500);
