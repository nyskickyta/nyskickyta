<?php
declare(strict_types=1);

function redirect(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.html');
}

$name = trim((string)($_POST['name'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));
$website = trim((string)($_POST['website'] ?? ''));
$formStarted = (int)($_POST['form_started'] ?? 0);

if ($website !== '') {
    redirect('index.html?status=review#offert-form');
}

$nowMs = (int)round(microtime(true) * 1000);
$elapsedMs = $formStarted > 0 ? $nowMs - $formStarted : null;

// Treat only unnaturally fast JS-enabled submissions as spam.
if ($elapsedMs !== null && $elapsedMs < 2500) {
    redirect('index.html?status=review#offert-form');
}

if ($name === '' || $phone === '' || $email === '') {
    redirect('index.html?status=validation#offert-form');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirect('index.html?status=validation#offert-form');
}

$combinedText = mb_strtolower($name . ' ' . $message . ' ' . $email, 'UTF-8');
$urlCount = preg_match_all('/https?:\/\//i', $combinedText);
$spamHints = ['viagra', 'casino', 'crypto', 'loan', 'seo service', 'backlink', 'telegram', 'whatsapp'];

foreach ($spamHints as $hint) {
    if (str_contains($combinedText, $hint)) {
        redirect('index.html?status=review#offert-form');
    }
}

if ($urlCount > 1) {
    redirect('index.html?status=review#offert-form');
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
$safeReplyTo = str_replace(["\r", "\n"], '', $email);

$headers = [
    'From: info@nyskickstenaltan.se',
    'Reply-To: ' . $safeReplyTo,
    'Content-Type: text/plain; charset=UTF-8',
];

$sent = mail($to, $subject, $body, implode("\r\n", $headers));

if ($sent) {
    redirect('tack.html');
}

redirect('index.html?status=error#offert-form');
