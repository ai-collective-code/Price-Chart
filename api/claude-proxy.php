<?php
/**
 * claude-proxy.php
 * Server-side proxy for the Claude API.
 * - Keeps the API key OUT of the browser completely.
 * - Only callable by a logged-in admin (session check).
 * - Accepts: POST { "script_text": "...", "ref_type": "...", "output_fmt": "...", "duration": "..." }
 * - Returns: the raw Anthropic API response JSON
 */

session_start();

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// ─── YOUR CLAUDE API KEY ───────────────────────────────────────────────────
// Paste your key here. It NEVER leaves the server.
define('CLAUDE_API_KEY', 'PASTE_YOUR_CLAUDE_API_KEY_HERE');
// ──────────────────────────────────────────────────────────────────────────

// Block non-POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Require admin session (prevents anyone else from burning your API credits)
if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden — admin login required']);
    exit;
}

// Parse request body
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!$body || empty($body['script_text'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing script_text in request body']);
    exit;
}

$scriptText = trim($body['script_text']);
$refType    = $body['ref_type']    ?? 'none';
$outFmt     = $body['output_fmt']  ?? 'dvc';
$duration   = $body['duration']    ?? '0';

// Label maps (mirroring the original JS)
$refLabels = [
    'none'       => 'No reference provided',
    'ai_ref'     => 'AI video reference supplied',
    'trad_ref'   => 'Traditional film reference supplied',
    'storyboard' => 'Storyboard/animatic supplied',
    'mood'       => 'Mood board only',
];
$fmtLabels = [
    'social' => 'Social/Reel',
    'dvc'    => 'DVC/Ad Film',
    'brand'  => 'Brand Film',
    'cgi'    => 'CGI-heavy',
];

$refLabel = $refLabels[$refType] ?? 'No reference provided';
$fmtLabel = $fmtLabels[$outFmt]  ?? 'DVC/Ad Film';
$durLabel = ($duration === '0') ? 'auto-detect' : $duration . 's';

$systemPrompt = 'You are AIC (AI Collective), a 100% AI production studio pricing engine. Respond ONLY with valid JSON, no markdown, no backticks. Analyse the script and output:
{"shots":[{"shot_num":1,"description":"brief shot description","format":"DVC Standard","tool":"primary AI tool","duration_sec":5,"complexity":"low|medium|high|very high","indicative_price":15000,"reasoning":"1 sentence why"}],"summary":{"total_shots":N,"master_duration_sec":N,"dominant_format":"format","scripting_fee":18000,"total_production":90000,"total_with_scripting":108000,"gst_total":127440,"moq_met":true,"notes":"any notes"}}
Price ref (INR base 30s): Social Starter 8000, Social Standard 18000, Social Premium 35000, UGC Basic 12000, DVC Core 55000, DVC Standard 90000, DVC Premium 150000, Brand Film Std 350000, Brand Film Prem 600000, CGI Product Std 45000, CGI Product Prem 120000, CGI Human 100000-200000, Full CGI 450000, Anime Std 70000, Anime Prem 140000, Folk Art 85000. Pro-rate all prices by shot duration vs 30s base.';

$userMessage = "Script:\n{$scriptText}\n\nRef type: {$refLabel}\nFormat intent: {$fmtLabel}\nMaster duration: {$durLabel}";

$payload = json_encode([
    'model'      => 'claude-sonnet-4-20250514',
    'max_tokens' => 1000,
    'system'     => $systemPrompt,
    'messages'   => [
        ['role' => 'user', 'content' => $userMessage],
    ],
]);

// cURL request to Anthropic
$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . CLAUDE_API_KEY,
        'anthropic-version: 2023-06-01',
    ],
]);

$response   = curl_exec($ch);
$httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError  = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(502);
    echo json_encode(['error' => 'cURL error: ' . $curlError]);
    exit;
}

// Pass Anthropic's response and status code straight through to the browser
http_response_code($httpStatus);
echo $response;
