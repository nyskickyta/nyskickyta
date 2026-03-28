<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.html');
    exit;
}

$name = trim((string)($_POST['name'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));
$website = trim((string)($_POST['website'] ?? ''));
$formStarted = (int)($_POST['form_started'] ?? 0);

if ($website !== '') {
    header('Location: tack.html');
    exit;
}

$nowMs = (int)round(microtime(true) * 1000);
$elapsedMs = $formStarted > 0 ? $nowMs - $formStarted : 0;

if ($formStarted <= 0 || $elapsedMs < 2500) {
    header('Location: tack.html');
    exit;
}

if ($name === '' || $phone === '' || $email === '') {
    header('Location: index.html#offert-form');
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: index.html#offert-form');
    exit;
}

$combinedText = mb_strtolower($name . ' ' . $message . ' ' . $email, 'UTF-8');
$urlCount = preg_match_all('/https?:\/\//i', $combinedText);
$spamHints = ['viagra', 'casino', 'crypto', 'loan', 'seo service', 'backlink', 'telegram', 'whatsapp'];

foreach ($spamHints as $hint) {
    if (str_contains($combinedText, $hint)) {
        header('Location: tack.html');
        exit;
    }
}

if ($urlCount > 1) {
    header('Location: tack.html');
    exit;
}

$to = 'info@nyskickstenaltan.se';
$subject = 'Ny offertförfrågan från nyskickstenaltan.se';

$bodyLines = [
    "Ny offertförfrågan från hemsidan",
    "",
    "Namn: " . $name,
    "Telefon: " . $phone,
    "E-post: " . $email,
    "Meddelande: " . ($message !== '' ? $message : 'Ej angivet'),
];

$body = implode("\n", $bodyLines);

$headers = [
    'From: info@nyskickstenaltan.se',
    'Reply-To: ' . $email,
    'Content-Type: text/plain; charset=UTF-8',
];

$sent = mail($to, $subject, $body, implode("\r\n", $headers));

if ($sent) {
    header('Location: tack.html');
    exit;
}

header('Location: index.html#offert-form');
exit;
