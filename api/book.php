<?php
/**
 * POST /api/book.php -> emails you the consultation request (and optionally confirms to the customer).
 * PHP port of the /api/book route in src/worker.js (Cloudflare Worker version).
 */
require_once __DIR__ . '/_helpers.php';
require_post_and_origin();
rate_limit('book', 8);

$b = json_decode(file_get_contents('php://input'), true);
if (!is_array($b)) json_out(['error' => 'Bad request'], 400);
if (!empty($b['website'])) json_out(['ok' => true, 'confirmed' => false]); // honeypot filled -> bot, pretend success

$name = clip($b['name'] ?? '', 100);
$email = clean_email($b['email'] ?? '');
if (!$name || !$email) json_out(['error' => 'Name and a valid email are required'], 400);

$f = [
    'name' => $name,
    'email' => $email,
    'phone' => clip($b['phone'] ?? '', 40),
    'date' => clip($b['date'] ?? '', 20),
    'time' => clip($b['time'] ?? '', 20),
    'service' => clip($b['service'] ?? '', 120) ?: 'General consultation',
    'goal' => clip($b['goal'] ?? '', 2000),
];

$notified = send_mail(NOTIFY_EMAIL, "New booking request: {$f['name']} ({$f['service']})", booking_html($f), $f['email']);
if (!$notified) {
    error_log('BOOKING (email not configured): ' . json_encode($f));
    if (defined('RESEND_API_KEY') && RESEND_API_KEY !== '') json_out(['error' => 'Could not send request'], 502);
}

$confirmed = false;
if (defined('FROM_EMAIL') && FROM_EMAIL !== '' && defined('RESEND_API_KEY') && RESEND_API_KEY !== '') {
    $firstName = esc(explode(' ', $f['name'])[0]);
    $html = "<p>Hi {$firstName},</p>"
        . "<p>Thanks for reaching out about <b>" . esc($f['service']) . "</b>. We've received your request"
        . ($f['date'] ? " for " . esc($f['date']) . " at " . esc($f['time']) : "")
        . " and will confirm the exact time shortly.</p>"
        . "<p>The Resumerica team</p>";
    $confirmed = send_mail($f['email'], 'We received your Resumerica consultation request', $html, NOTIFY_EMAIL);
}

json_out(['ok' => true, 'confirmed' => $confirmed]);
