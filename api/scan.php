<?php
/**
 * POST /api/scan.php -> sends the resume PDF to Claude, returns the ATS report JSON.
 * PHP port of the /api/scan route in src/worker.js (Cloudflare Worker version) —
 * keep the two in sync if you change the scoring prompt or schema.
 */
require_once __DIR__ . '/_helpers.php';
require_post_and_origin();
rate_limit('scan', 8);

const MAX_PDF_BASE64 = 7000000; // ~5 MB PDF
const SCHEMA = '{"atsScore":<int 0-100>,"detectedRole":"<inferred target role>","verdict":"<3-6 word verdict>","summary":"<2 sentence plain overview>","categories":[{"name":"Parseability & formatting","score":<int>,"note":"<why this score, 6-10 words>"},{"name":"Section structure","score":<int>,"note":"<6-10 words>"},{"name":"Keyword & role match","score":<int>,"note":"<6-10 words>"},{"name":"Impact & metrics","score":<int>,"note":"<6-10 words>"},{"name":"Contact & links","score":<int>,"note":"<6-10 words>"}],"strengths":["<specific thing already helping the ATS score>","<...>","<...>","<...>"],"issues":[{"problem":"<specific thing hurting the ATS score>","fix":"<the exact change to make>"},{"problem":"<...>","fix":"<...>"},{"problem":"<...>","fix":"<...>"},{"problem":"<...>","fix":"<...>"}],"keywordGaps":["<missing term>","<missing term>","<missing term>","<missing term>"]}';
$PROMPT = "You are a senior ATS and AI-resume-screening analyst. Analyze the attached resume PDF exactly as a modern applicant-tracking system would (Workday/Greenhouse/Ashby-style LLM screening plus classic keyword parsing). Infer the candidate's most likely target role. Judge on clean machine parseability, standard section structure, keyword/role match, quantified impact, and complete contact/links. Give a genuinely detailed read: in \"strengths\" list 4-6 concrete things ALREADY favoring the ATS score, and in \"issues\" list 4-6 concrete things HURTING it, each paired with the exact change to make. Be specific to THIS resume, not generic. Be realistic and slightly strict — most real resumes land 45-80. Return ONLY minified JSON, no markdown, no code fences, no preamble, matching exactly: " . SCHEMA;

if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === '') {
    json_out(['error' => 'Scanner not configured'], 500);
}

$body = json_decode(file_get_contents('php://input'), true);
$pdf = $body['pdf'] ?? null;
if (!is_string($pdf) || strlen($pdf) < 100) json_out(['error' => 'Missing PDF'], 400);
if (strlen($pdf) > MAX_PDF_BASE64) json_out(['error' => 'PDF too large (max 5 MB)'], 413);
if (strpos($pdf, 'JVBER') !== 0) json_out(['error' => "That file isn't a valid PDF"], 400); // base64 of "%PDF"

$email = clean_email($body['email'] ?? '');
$fileName = clip($body['fileName'] ?? '', 200);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'content-type: application/json',
        'x-api-key: ' . ANTHROPIC_API_KEY,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'model' => defined('MODEL') && MODEL !== '' ? MODEL : 'claude-sonnet-5',
        'max_tokens' => 2000,
        'messages' => [[
            'role' => 'user',
            'content' => [
                ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $pdf]],
                ['type' => 'text', 'text' => $PROMPT],
            ],
        ]],
    ]),
    CURLOPT_TIMEOUT => 60,
]);
$res = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($status < 200 || $status >= 300) {
    error_log('Anthropic error ' . $status . ' ' . $res);
    json_out(['error' => 'Scan failed'], 502);
}

$data = json_decode($res, true);
$text = '';
foreach (($data['content'] ?? []) as $block) {
    if (($block['type'] ?? '') === 'text') $text .= $block['text'];
}
$clean = trim(preg_replace('/```json/i', '', str_replace('```', '', $text)));
$start = strpos($clean, '{');
$end = strrpos($clean, '}');
$report = ($start !== false && $end !== false) ? json_decode(substr($clean, $start, $end - $start + 1), true) : null;

if (!is_array($report) || !isset($report['atsScore']) || !is_numeric($report['atsScore'])) {
    json_out(['error' => 'Could not read scan result'], 502);
}

$report['_emailed'] = false;
if ($email) {
    // Lead notification to you
    send_mail(NOTIFY_EMAIL, "New scan lead: {$email} — ATS {$report['atsScore']}", lead_html($email, $report, $fileName), $email);
    // Copy of the report to the visitor (only once your domain is verified in Resend)
    if (defined('FROM_EMAIL') && FROM_EMAIL !== '' && defined('RESEND_API_KEY') && RESEND_API_KEY !== '') {
        $report['_emailed'] = send_mail($email, "Your Resumerica ATS report — {$report['atsScore']}/100", report_html($report));
    }
}

json_out($report);
