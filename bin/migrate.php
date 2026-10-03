<?php

declare(strict_types=1);

/**
 * Smart Split – Standalone Database Migration Runner
 */

require dirname(__DIR__) . '/src/Core/Env.php';

use App\Core\Env;

// Load environment variables
$envPath = dirname(__DIR__) . '/.env';
if (file_exists($envPath)) {
    Env::load($envPath);
}

$host = (string) Env::get('DB_HOST', '127.0.0.1');
$port = (int) Env::get('DB_PORT', 3306);
$dbName = isset($argv[1]) && trim($argv[1]) !== '' ? trim($argv[1]) : (string) Env::get('DB_NAME', 'smart_split');
$username = (string) Env::get('DB_USER', 'root');
$password = (string) Env::get('DB_PASS', '');
$charset = (string) Env::get('DB_CHARSET', 'utf8mb4');

echo "=====================================================\n";
echo " Smart Split – Database Migration Runner\n";
echo "=====================================================\n";
echo "Connecting to MySQL server at {$host}:{$port}...\n";

try {
    // 1. Connect to MySQL server (without database selected yet)
    $dsnWithoutDb = "mysql:host={$host};port={$port};charset={$charset}";
    $pdo = new PDO($dsnWithoutDb, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // 2. Ensure Database Exists
    echo "Ensuring database `{$dbName}` exists...\n";
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$dbName}`");

    // 3. Ensure Migration Tracking Table Exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `schema_migrations` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `migration` VARCHAR(255) NOT NULL UNIQUE,
            `executed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 4. Fetch Already Executed Migrations
    $stmt = $pdo->query("SELECT `migration` FROM `schema_migrations`");
    $appliedMigrations = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // 5. Scan Migrations Directory
    $migrationsDir = dirname(__DIR__) . '/migrations';
    if (!is_dir($migrationsDir)) {
        throw new RuntimeException("Migrations directory not found at: {$migrationsDir}");
    }

    $migrationFiles = glob($migrationsDir . '/*.sql');
    if ($migrationFiles === false) {
        $migrationFiles = [];
    }
    sort($migrationFiles);

    $pendingCount = 0;

    foreach ($migrationFiles as $filePath) {
        $filename = basename($filePath);

        if (in_array($filename, $appliedMigrations, true)) {
            echo "  [SKIPPED] {$filename} (already applied)\n";
            continue;
        }

        echo "  [APPLYING] {$filename}...\n";
        $sqlContent = file_get_contents($filePath);
        if ($sqlContent === false || trim($sqlContent) === '') {
            echo "  [WARNING] Empty migration file: {$filename}\n";
            continue;
        }

        // Execute migration SQL
        $pdo->exec($sqlContent);

        // Record execution in tracking table
        $recordStmt = $pdo->prepare("INSERT INTO `schema_migrations` (`migration`) VALUES (:migration)");
        $recordStmt->execute([':migration' => $filename]);

        echo "  [SUCCESS] {$filename} applied successfully.\n";
        $pendingCount++;
    }

    echo "=====================================================\n";
    if ($pendingCount === 0) {
        echo "Database is already up to date. No pending migrations.\n";
    } else {
        echo "Successfully applied {$pendingCount} migration(s).\n";
    }
    echo "=====================================================\n";

} catch (Throwable $e) {
    echo "\n[FATAL ERROR] Migration failed:\n";
    echo $e->getMessage() . "\n";
    echo "In " . $e->getFile() . " on line " . $e->getLine() . "\n";
    exit(1);
}
