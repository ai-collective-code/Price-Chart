<?php
/**
 * db.php
 * Handles database connection and environment loading for TiDB Cloud integration.
 */

function loadEnv($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) continue;
        
        // Parse key-value pairs
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $name  = trim($parts[0]);
            $value = trim($parts[1]);
            
            // Strip optional quotes
            if (preg_match('/^"([^"]*)"$/', $value, $matches) || preg_match('/^\'([^\']*)\'$/', $value, $matches)) {
                $value = $matches[1];
            }
            
            $_ENV[$name] = $value;
            putenv("{$name}={$value}");
        }
    }
}

// Load env configuration
loadEnv(__DIR__ . '/.env');

define('DB_HOST', getenv('DB_HOST') ?: 'gateway01.us-east-1.prod.aws.tidbcloud.com');
define('DB_PORT', getenv('DB_PORT') ?: '4000');
define('DB_USER', getenv('DB_USER') ?: '2KXqS8WDu6fsY38.root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'ai_collective_spec_sheet');
define('DB_CA_PATH', __DIR__ . '/certs/isrgrootx1.pem');

/**
 * Get a PDO database connection using SSL/TLS.
 * @return PDO
 */
function getDbConnection() {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    
    // Configure SSL if certificate exists
    if (file_exists(DB_CA_PATH)) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = DB_CA_PATH;
    }
    
    return new PDO($dsn, DB_USER, DB_PASS, $options);
}
