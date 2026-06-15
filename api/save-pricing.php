<?php
/**
 * save-pricing.php
 * Session-gated endpoint: receives JSON pricing data from admin, writes to data/pricing.json
 * Returns 403 if admin session is not active.
 */

session_start();
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Block non-POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Require active admin session
if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden — admin login required']);
    exit;
}

// Read and validate incoming JSON
$raw = file_get_contents('php://input');
if (!$raw) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty request body']);
    exit;
}

$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit;
}

// Basic structure check — must have at least one known pricing category
$expectedKeys = ['social', 'ugc', 'dvc', 'brand', 'cgi', 'anime', 'scripting', 'addons'];
$hasAny = false;
foreach ($expectedKeys as $key) {
    if (isset($data[$key])) { $hasAny = true; break; }
}
if (!$hasAny) {
    http_response_code(400);
    echo json_encode(['error' => 'Pricing data missing expected categories']);
    exit;
}

// Add metadata
$data['__version']   = 'v2';
$data['__saved_at']  = date('c');  // ISO 8601 timestamp
$data['__saved_by']  = 'admin';

// Save to TiDB Cloud (with local file fallback)
$dbSaved = false;
try {
    $db = getDbConnection();
    
    // Check if configuration already exists, then update or insert
    $stmtCheck = $db->query("SELECT `id` FROM `pricing_config` ORDER BY `id` DESC LIMIT 1");
    $lastId = $stmtCheck->fetchColumn();
    
    if ($lastId) {
        $stmtUpdate = $db->prepare("UPDATE `pricing_config` SET `config_json` = :json WHERE `id` = :id");
        $stmtUpdate->execute([':json' => json_encode($data), ':id' => $lastId]);
    } else {
        $stmtInsert = $db->prepare("INSERT INTO `pricing_config` (`config_json`) VALUES (:json)");
        $stmtInsert->execute([':json' => json_encode($data)]);
    }
    $dbSaved = true;
} catch (Exception $e) {
    error_log("Database pricing save failed: " . $e->getMessage());
}

// Always write to fallback local JSON file to ensure robustness
$filePath = __DIR__ . '/../data/pricing.json';
$written = file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

if ($written === false && !$dbSaved) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save pricing configuration. Database connection failed and local file writing failed. Check folder permissions.']);
    exit;
}

echo json_encode([
    'success'  => true,
    'saved_at' => $data['__saved_at'],
    'bytes'    => $written !== false ? $written : 0,
    'db_sync'  => $dbSaved
]);
