<?php
declare(strict_types=1);

require_once __DIR__ . '/admin/storage.php';

function contact_redirect(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    contact_redirect('index.html');
}

$name = trim((string)($_POST['name'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$serviceAddress = trim((string)($_POST['serviceAddress'] ?? ''));
$servicePostalCode = trim((string)($_POST['servicePostalCode'] ?? ''));
$serviceCity = trim((string)($_POST['serviceCity'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));
$sourcePage = trim((string)($_POST['sourcePage'] ?? 'homepage'));
$website = trim((string)($_POST['website'] ?? ''));
$formStarted = (int)($_POST['form_started'] ?? 0);

if ($website !== '') {
    contact_redirect('index.html?status=review#offert-form');
}

$nowMs = (int)round(microtime(true) * 1000);
$elapsedMs = $formStarted > 0 ? $nowMs - $formStarted : null;

// Treat only unnaturally fast JS-enabled submissions as spam.
if ($elapsedMs !== null && $elapsedMs < 2500) {
    contact_redirect('index.html?status=review#offert-form');
}

if ($name === '' || $phone === '' || $email === '' || $serviceAddress === '' || $servicePostalCode === '' || $serviceCity === '') {
    contact_redirect('index.html?status=validation#offert-form');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    contact_redirect('index.html?status=validation#offert-form');
}

$combinedText = mb_strtolower($name . ' ' . $message . ' ' . $email, 'UTF-8');
$urlCount = preg_match_all('/https?:\/\//i', $combinedText);
$spamHints = ['viagra', 'casino', 'crypto', 'loan', 'seo service', 'backlink', 'telegram', 'whatsapp'];

foreach ($spamHints as $hint) {
    if (str_contains($combinedText, $hint)) {
        contact_redirect('index.html?status=review#offert-form');
    }
}

if ($urlCount > 1) {
    contact_redirect('index.html?status=review#offert-form');
}

$to = 'info@nyskickstenaltan.se';
$subject = 'Ny offertförfrågan från nyskickstenaltan.se';

$bodyLines = [
    "Ny offertförfrågan från hemsidan",
    "",
    "Namn: " . $name,
    "Telefon: " . $phone,
    "E-post: " . $email,
    "Adress: " . $serviceAddress,
    "Postnummer: " . $servicePostalCode,
    "Ort: " . $serviceCity,
    "Meddelande: " . ($message !== '' ? $message : 'Ej angivet'),
];

$body = implode("\n", $bodyLines);
$safeReplyTo = str_replace(["\r", "\n"], '', $email);

$headers = [
    'From: info@nyskickstenaltan.se',
    'Reply-To: ' . $safeReplyTo,
    'Content-Type: text/plain; charset=UTF-8',
];

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
                'message' => $message,
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
    contact_redirect('tack.html');
}

contact_redirect('index.html?status=error#offert-form');
