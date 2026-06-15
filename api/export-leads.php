<?php
/**
 * export-leads.php
 * Session-gated: downloads leads.csv as a file attachment.
 */
session_start();
require_once __DIR__ . '/../db.php';

if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$filename = 'aic-leads-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Add UTF-8 BOM so Excel opens it correctly with Indian characters
echo "\xEF\xBB\xBF";

try {
    $db = getDbConnection();
    $stmt = $db->query("SELECT `date` as `Date`, `name` as `Name`, `email` as `Email`, `company` as `Company`, `phone` as `Phone`, `looking_for` as `Looking For`, `ip_address` as `IP` FROM `leads` ORDER BY `date` DESC");
    $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $out = fopen('php://output', 'w');
    // Write headers
    fputcsv($out, ['Date', 'Name', 'Email', 'Company', 'Phone', 'Looking For', 'IP']);
    // Write data rows
    foreach ($leads as $lead) {
        fputcsv($out, [
            $lead['Date'],
            $lead['Name'],
            $lead['Email'],
            $lead['Company'],
            $lead['Phone'] ?? '',
            $lead['Looking For'] ?? '',
            $lead['IP'] ?? '',
        ]);
    }
    fclose($out);
} catch (Exception $e) {
    error_log("Database leads export failed, falling back to local file: " . $e->getMessage());
    $leadsFile = __DIR__ . '/../data/leads.csv';
    if (file_exists($leadsFile)) {
        readfile($leadsFile);
    } else {
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Error']);
        fputcsv($out, ['Failed to load leads from database, and no fallback CSV exists: ' . $e->getMessage()]);
        fclose($out);
    }
}
