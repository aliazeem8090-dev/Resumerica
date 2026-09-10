<?php
/**
 * Shared helpers for scan.php and book.php.
 * Mirrors the logic in src/worker.js (the Cloudflare Worker version) so both
 * backends behave identically.
 */

// Buffer everything so a stray BOM/whitespace in config.php (e.g. saved as
// "UTF-8 with BOM" by some editors) can't break the JSON response below.
ob_start();

if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Server not configured — copy config.example.php to config.php and fill it in.']);
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
    if (!$fp) return; // fail open — don't block real users if disk isn't writable
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
        json_out(['error' => 'Too many requests — please wait a minute.'], 429);
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

function send_mail($to, $subject, $html, $replyTo = null) {
    if (!defined('RESEND_API_KEY') || RESEND_API_KEY === '' || !$to) return false;
    $from = (defined('FROM_EMAIL') && FROM_EMAIL !== '') ? FROM_EMAIL : 'Resumerica <onboarding@resend.dev>';
    $payload = ['from' => $from, 'to' => [$to], 'subject' => $subject, 'html' => $html];
    if ($replyTo) $payload['reply_to'] = $replyTo;

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . RESEND_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 20,
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

function booking_html($f) {
    $rows = row('Name', $f['name']) . row('Email', $f['email']) . row('Phone', $f['phone'])
        . row('Service', $f['service']) . row('Preferred date', $f['date']) . row('Time', $f['time']) . row('Goal', $f['goal']);
    return "<h2 style=\"font-family:Georgia,serif;color:#14264A\">New consultation request</h2>"
        . "<table style=\"font-family:Arial,sans-serif;font-size:14px\">{$rows}</table>"
        . "<p style=\"font-family:Arial,sans-serif;font-size:13px;color:#6A7286\">Hit reply to respond directly to " . esc($f['email']) . ".</p>";
}

function report_html($r) {
    $cats = '';
    foreach (($r['categories'] ?? []) as $c) {
        $cats .= '<li>' . esc($c['name'] ?? '') . ': <b>' . esc($c['score'] ?? '') . '</b> — ' . esc($c['note'] ?? '') . '</li>';
    }
    $str = '';
    foreach (($r['strengths'] ?? []) as $s) $str .= '<li>' . esc($s) . '</li>';
    $iss = '';
    foreach (($r['issues'] ?? []) as $i) {
        $iss .= '<li><b>' . esc($i['problem'] ?? '') . '</b><br>Fix: ' . esc($i['fix'] ?? '') . '</li>';
    }
    $gaps = implode(', ', array_map('esc', $r['keywordGaps'] ?? []));

    $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#14264A;line-height:1.5">'
        . '<h3>ATS score: ' . esc($r['atsScore'] ?? '') . '/100 — ' . esc($r['verdict'] ?? '') . '</h3><p>' . esc($r['summary'] ?? '') . '</p>'
        . '<h4>Breakdown</h4><ul>' . $cats . '</ul>'
        . '<h4>What\'s working</h4><ul>' . $str . '</ul>'
        . '<h4>What to fix</h4><ul>' . $iss . '</ul>';
    if ($gaps) $html .= '<h4>Missing keywords</h4><p>' . $gaps . '</p>';
    return $html . '</div>';
}

function lead_html($email, $r, $fileName) {
    $rows = row('Email', $email) . row('File', $fileName)
        . row('ATS score', (string)($r['atsScore'] ?? '')) . row('Detected role', $r['detectedRole'] ?? '') . row('Verdict', $r['verdict'] ?? '');
    return "<h2 style=\"font-family:Georgia,serif;color:#14264A\">New ATS scan lead</h2>"
        . "<table style=\"font-family:Arial,sans-serif;font-size:14px\">{$rows}</table>"
        . report_html($r);
}
