<?php
/**
 * Shared helpers for review.php.
 */

// Buffer everything so a stray BOM/whitespace in config.php (e.g. saved as
// "UTF-8 with BOM" by some editors) can't break the JSON response below.
ob_start();

if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Server not configured. Copy config.example.php to config.php and fill it in.']);
    exit;
}
require_once __DIR__ . '/config.php';

function json_out($obj, $status = 200) {
    while (ob_get_level()) ob_end_clean();
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($obj);
    exit;
}

function require_post_and_origin() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['error' => 'Method not allowed'], 405);
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin) {
        $originHost = parse_url($origin, PHP_URL_HOST);
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($originHost && $originHost !== $host) {
            json_out(['error' => 'Forbidden'], 403);
        }
    }
}

/**
 * Very small per-IP rate limiter using flat files in the writable data/ folder.
 * Not a substitute for a real WAF rule, but keeps a single script from being
 * hammered on shared hosting with no other protection in front of it.
 */
function rate_limit($bucket, $maxPerMinute = 8) {
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $bucket . '_' . $ip);
    $file = $dir . '/' . $key . '.json';
    $now = time();
    $window = 60;

    $fp = @fopen($file, 'c+');
    if (!$fp) return; // fail open, don't block real users if disk isn't writable
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $data = $raw ? json_decode($raw, true) : null;
    if (!$data || ($now - $data['start']) > $window) {
        $data = ['start' => $now, 'count' => 0];
    }
    $data['count']++;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);

    if ($data['count'] > $maxPerMinute) {
        json_out(['error' => 'Too many requests. Please wait a minute.'], 429);
    }
}

function clip($v, $n) {
    if (!is_string($v)) return '';
    $v = trim($v);
    return function_exists('mb_substr') ? mb_substr($v, 0, $n) : substr($v, 0, $n);
}

function clean_email($v) {
    $e = clip($v, 200);
    return preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $e) ? $e : '';
}

function esc($s) {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * $attachments (optional): array of ['filename' => string, 'content' => base64 string]
 */
function send_mail($to, $subject, $html, $replyTo = null, $attachments = null) {
    if (!defined('RESEND_API_KEY') || RESEND_API_KEY === '' || !$to) return false;
    $from = (defined('FROM_EMAIL') && FROM_EMAIL !== '') ? FROM_EMAIL : 'Resumerica <onboarding@resend.dev>';
    $payload = ['from' => $from, 'to' => [$to], 'subject' => $subject, 'html' => $html];
    if ($replyTo) $payload['reply_to'] = $replyTo;
    if ($attachments) $payload['attachments'] = $attachments;

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . RESEND_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
    ]);
    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300) {
        error_log('Resend error ' . $status . ' ' . $res);
        return false;
    }
    return true;
}

function row($k, $v) {
    return $v ? "<tr><td style=\"padding:4px 12px 4px 0;color:#6A7286\">{$k}</td><td>" . esc($v) . "</td></tr>" : '';
}

function review_lead_html($email, $roles, $salary, $notes, $fileName) {
    $rows = row('Email', $email) . row('Targeted role(s)', $roles) . row('Desired salary', $salary) . row('File', $fileName);
    $notesBlock = $notes ? '<p style="margin-top:14px"><b>Notes:</b><br>' . nl2br(esc($notes)) . '</p>' : '';
    return "<h2 style=\"font-family:Georgia,serif;color:#14264A\">New free CV review request</h2>"
        . "<table style=\"font-family:Arial,sans-serif;font-size:14px\">{$rows}</table>"
        . $notesBlock
        . "<p style=\"font-family:Arial,sans-serif;font-size:13px;color:#6A7286;margin-top:14px\">Resume is attached. Hit reply to respond directly to " . esc($email) . ".</p>";
}
