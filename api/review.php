<?php
/**
 * POST /api/review.php -> free CV review lead capture.
 * Accepts multipart/form-data: resume (file), email, roles, salary, notes, website (honeypot).
 * Emails the resume + details to NOTIFY_EMAIL, and (once FROM_EMAIL is configured) a
 * confirmation to the visitor.
 */
require_once __DIR__ . '/_helpers.php';
require_post_and_origin();
rate_limit('review', 5);

const MAX_FILE_BYTES = 8 * 1024 * 1024; // 8 MB
const ALLOWED_EXT = ['pdf', 'doc', 'docx'];

if (!empty($_POST['website'])) json_out(['ok' => true]); // honeypot filled -> bot, pretend success

$email = clean_email($_POST['email'] ?? '');
$roles = clip($_POST['roles'] ?? '', 300);
$salary = clip($_POST['salary'] ?? '', 100);
$notes = clip($_POST['notes'] ?? '', 3000);

if (!$email) json_out(['error' => 'A valid email is required'], 400);
if (!$roles) json_out(['error' => "Please tell us your targeted role(s)"], 400);

if (!isset($_FILES['resume']) || $_FILES['resume']['error'] !== UPLOAD_ERR_OK) {
    json_out(['error' => 'Please attach your resume (PDF or Word)'], 400);
}
$file = $_FILES['resume'];
if ($file['size'] > MAX_FILE_BYTES) json_out(['error' => 'File too large (max 8 MB)'], 413);

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ALLOWED_EXT, true)) {
    json_out(['error' => 'Please upload a PDF or Word document (.pdf, .doc, .docx)'], 400);
}

$safeName = preg_replace('/[^A-Za-z0-9 ._-]/', '_', $file['name']);
$content = base64_encode(file_get_contents($file['tmp_name']));

$notified = send_mail(
    NOTIFY_EMAIL,
    "New CV review request — {$email}",
    review_lead_html($email, $roles, $salary, $notes, $safeName),
    $email,
    [['filename' => $safeName, 'content' => $content]]
);
if (!$notified) {
    error_log('CV REVIEW (email not configured or failed): ' . json_encode(['email' => $email, 'roles' => $roles, 'salary' => $salary]));
    if (defined('RESEND_API_KEY') && RESEND_API_KEY !== '') json_out(['error' => 'Could not send request'], 502);
}

$confirmed = false;
if (defined('FROM_EMAIL') && FROM_EMAIL !== '' && defined('RESEND_API_KEY') && RESEND_API_KEY !== '') {
    $html = "<p>Hi there,</p>"
        . "<p>Thank you for submitting your request. Your CV is being reviewed by our professionals and we will get back to you with a report within 24 hours.</p>"
        . "<p>— The Resumerica team</p>";
    $confirmed = send_mail($email, 'We received your CV — Resumerica', $html, NOTIFY_EMAIL);
}

json_out(['ok' => true, 'confirmed' => $confirmed]);
