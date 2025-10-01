<?php
/**
 * Database Discovery Script
 * Try to find working database configuration
 */

echo "=== Database Discovery Test ===\n";

// Common Cloudways configurations to try
$configs = [
    ['host' => 'localhost', 'user' => 'master', 'name' => 'jcepnzzkmj', 'pass' => ''],
    ['host' => '127.0.0.1', 'user' => 'master', 'name' => 'jcepnzzkmj', 'pass' => ''],
    ['host' => 'localhost', 'user' => 'jcepnzzkmj', 'name' => 'jcepnzzkmj', 'pass' => ''],
    ['host' => '127.0.0.1', 'user' => 'jcepnzzkmj', 'name' => 'jcepnzzkmj', 'pass' => ''],
    ['host' => 'localhost', 'user' => 'root', 'name' => 'jcepnzzkmj', 'pass' => ''],
];

foreach ($configs as $i => $config) {
    echo "\nTrying config " . ($i + 1) . ": {$config['user']}@{$config['host']}/{$config['name']}\n";
    
    try {
        $dsn = "mysql:host={$config['host']};dbname={$config['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        
        // Test with a simple query
        $result = $pdo->query("SELECT 1 as test, DATABASE() as db_name")->fetch();
        echo "✓ SUCCESS! Connected to database: " . $result['db_name'] . "\n";
        echo "  Config: user='{$config['user']}', host='{$config['host']}', db='{$config['name']}'\n";
        
        // Try to check if users table exists
        try {
            $tables = $pdo->query("SHOW TABLES LIKE 'users'")->fetchAll();
            if ($tables) {
                echo "  ✓ 'users' table found\n";
                
                // Check if user ID 1 exists (from your example)
                $user = $pdo->query("SELECT id, first_name, last_name FROM users WHERE id = 1 LIMIT 1")->fetch();
                if ($user) {
                    echo "  ✓ Test user found: {$user['first_name']} {$user['last_name']} (ID: {$user['id']})\n";
                } else {
                    echo "  ! No user with ID 1 found\n";
                }
            } else {
                echo "  ! 'users' table not found\n";
            }
        } catch (Exception $e) {
            echo "  ! Could not check users table: " . $e->getMessage() . "\n";
        }
        
        // This configuration works!
        echo "\n*** USE THIS CONFIGURATION ***\n";
        break;
        
    } catch (Exception $e) {
        echo "✗ Failed: " . $e->getMessage() . "\n";
    }
}

echo "\n=== Discovery Complete ===\n";
?>