<?php
/**
 * migrate.php
 * Initializes the TiDB Cloud database schema and imports existing flat-file data.
 */

require_once __DIR__ . '/db.php';

header('Content-Type: text/plain');

try {
    $db = getDbConnection();
    echo "Successfully connected to TiDB Cloud!\n\n";
    
    // 1. Create tables
    echo "Creating 'leads' table...\n";
    $db->exec("CREATE TABLE IF NOT EXISTS `leads` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `date` DATETIME NOT NULL,
        `name` VARCHAR(255) NOT NULL,
        `email` VARCHAR(255) NOT NULL,
        `company` VARCHAR(255) NOT NULL,
        `phone` VARCHAR(100) NULL,
        `looking_for` VARCHAR(255) NULL,
        `ip_address` VARCHAR(100) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "leads table ready.\n\n";

    echo "Creating 'pricing_config' table...\n";
    $db->exec("CREATE TABLE IF NOT EXISTS `pricing_config` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `config_json` LONGTEXT NOT NULL,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "pricing_config table ready.\n\n";

    // 2. Import existing leads from CSV
    $leadsFile = __DIR__ . '/data/leads.csv';
    if (file_exists($leadsFile)) {
        echo "Found existing leads file: data/leads.csv. Starting import...\n";
        $file = fopen($leadsFile, 'r');
        $headers = fgetcsv($file); // skip header row
        
        $insertedCount = 0;
        $stmt = $db->prepare("INSERT INTO `leads` (`date`, `name`, `email`, `company`, `phone`, `looking_for`, `ip_address`) 
                              VALUES (:date, :name, :email, :company, :phone, :looking, :ip)");
        
        while (($row = fgetcsv($file)) !== false) {
            if (count($row) >= 4) {
                // Ensure date parsing or fallback to now
                $dateVal = !empty($row[0]) ? date('Y-m-d H:i:s', strtotime($row[0])) : date('Y-m-d H:i:s');
                
                $stmt->execute([
                    ':date'    => $dateVal,
                    ':name'    => $row[1] ?? '',
                    ':email'   => $row[2] ?? '',
                    ':company' => $row[3] ?? '',
                    ':phone'   => $row[4] ?? null,
                    ':looking' => $row[5] ?? null,
                    ':ip'      => $row[6] ?? null,
                ]);
                $insertedCount++;
            }
        }
        fclose($file);
        echo "Successfully imported {$insertedCount} leads.\n\n";
    } else {
        echo "No leads.csv file found to import.\n\n";
    }

    // 3. Import existing pricing from JSON
    $pricingFile = __DIR__ . '/data/pricing.json';
    if (file_exists($pricingFile)) {
        echo "Found existing pricing file: data/pricing.json. Importing...\n";
        $pricingJson = file_get_contents($pricingFile);
        
        // Validate JSON
        $decoded = json_decode($pricingJson, true);
        if ($decoded && json_last_error() === JSON_ERROR_NONE) {
            // Check if database already has a config
            $stmtCheck = $db->query("SELECT COUNT(*) FROM `pricing_config`");
            $configCount = $stmtCheck->fetchColumn();
            
            if ($configCount == 0) {
                $stmtInsert = $db->prepare("INSERT INTO `pricing_config` (`config_json`) VALUES (:json)");
                $stmtInsert->execute([':json' => json_encode($decoded)]);
                echo "Successfully imported pricing config to database.\n\n";
            } else {
                echo "Pricing config already exists in database. Skipping import to avoid overwriting.\n\n";
            }
        } else {
            echo "Failed to import pricing.json: Invalid JSON format.\n\n";
        }
    } else {
        echo "No pricing.json file found to import.\n\n";
    }
    
    echo "Migration completed successfully!\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
