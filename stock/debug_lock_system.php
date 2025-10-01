<?php
http_response_code(410);
echo 'deprecated debug_lock_system';
// Load the system bootstrap using relative paths
$localShimsPath = __DIR__ . '/../_local_shims.php';
$appPath = realpath(__DIR__ . '/../../../../app.php');

echo "Local shims path: " . $localShimsPath . "\n";
echo "App path: " . $appPath . "\n";
echo "Local shims exists: " . (file_exists($localShimsPath) ? 'YES' : 'NO') . "\n";
echo "App exists: " . (file_exists($appPath) ? 'YES' : 'NO') . "\n";

require_once $localShimsPath;

// Set up database connection that the system expects
if (empty($GLOBALS['pdo'])) {
  $host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?? 'localhost';
  $user = $_ENV['DB_USER'] ?? getenv('DB_USER') ?? '';
  $pass = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?? '';
  $name = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?? '';
  $port = (int)($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?? 3306);
  
  echo "Environment variables found:\n";
  echo "DB_HOST: " . ($host ?: 'NOT SET') . "\n";
  echo "DB_USER: " . ($user ?: 'NOT SET') . "\n";
  echo "DB_NAME: " . ($name ?: 'NOT SET') . "\n";
  echo "DB_PORT: " . $port . "\n";
  
  if ($user && $name) {
    try {
      $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
      $GLOBALS['pdo'] = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]);
      echo "✓ PDO connection established and set in \$GLOBALS['pdo']\n";
    } catch (Exception $e) {
      echo "✗ Failed to create PDO connection: " . $e->getMessage() . "\n";
    }
  } else {
    echo "✗ Missing required environment variables (DB_USER and DB_NAME)\n";
  }
}

if ($appPath && file_exists($appPath)) {
    require_once $appPath;
} else {
    echo "Warning: app.php not found, continuing without it\n";
}

// Start session for testing
session_start();

echo "=== Database Connection Test ===\n";

try {
    $pdo = cis_pdo();
    echo "✓ Database connection successful\n";
    
    // Test basic query
    $result = $pdo->query("SELECT 1 as test")->fetch();
    echo "✓ Database query test successful: " . $result['test'] . "\n";
    
} catch (Exception $e) {
    echo "✗ Database connection failed: " . $e->getMessage() . "\n";
    echo "Environment variables:\n";
    echo "DB_DSN: " . (getenv('DB_DSN') ?: 'NOT SET') . "\n";
    echo "DB_USER: " . (getenv('DB_USER') ?: 'NOT SET') . "\n";
    echo "DB_PASS: " . (getenv('DB_PASS') ? '[SET]' : 'NOT SET') . "\n";
    exit(1);
}

echo "\n=== User Table Test ===\n";

try {
    // Check if users table exists and has expected structure
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "✓ Users table exists\n";
    echo "Columns: " . implode(', ', $columns) . "\n";
    
    // Check if we have the expected columns
    $requiredColumns = ['id', 'first_name', 'last_name'];
    foreach ($requiredColumns as $col) {
        if (in_array($col, $columns)) {
            echo "✓ Column '$col' exists\n";
        } else {
            echo "✗ Column '$col' missing\n";
        }
    }
    
    // Test user lookup (using ID 1 from the example you provided)
    $userStmt = $pdo->prepare("SELECT id, CONCAT(first_name, ' ', last_name) as name FROM users WHERE id = 1");
    $userStmt->execute();
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "✓ Test user found: " . $user['name'] . " (ID: " . $user['id'] . ")\n";
    } else {
        echo "✗ Test user (ID: 1) not found\n";
    }
    
} catch (Exception $e) {
    echo "✗ User table test failed: " . $e->getMessage() . "\n";
}

echo "\n=== Lock Tables Test ===\n";

try {
    // Create the lock tables
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS transfer_pack_locks (
          transfer_id    INT UNSIGNED NOT NULL PRIMARY KEY,
          user_id        INT UNSIGNED NOT NULL,
          acquired_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          expires_at     DATETIME NOT NULL,
          heartbeat_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          client_fingerprint VARCHAR(64) DEFAULT NULL,
          INDEX (expires_at),
          INDEX (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    echo "✓ transfer_pack_locks table created/verified\n";
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS transfer_pack_lock_requests (
          id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          transfer_id   INT UNSIGNED NOT NULL,
          user_id       INT UNSIGNED NOT NULL,
          requested_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          status        ENUM('pending','accepted','declined','expired','cancelled') NOT NULL DEFAULT 'pending',
          responded_at  DATETIME NULL,
          expires_at    DATETIME NULL,
          client_fingerprint VARCHAR(64) DEFAULT NULL,
          INDEX (transfer_id, status),
          INDEX (expires_at),
          INDEX (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    echo "✓ transfer_pack_lock_requests table created/verified\n";
    
} catch (Exception $e) {
    echo "✗ Lock tables creation failed: " . $e->getMessage() . "\n";
}

echo "\n=== Session Test ===\n";

// Test session variables for authentication
$_SESSION['user_id'] = 1; // Set test user ID
echo "✓ Session user_id set to: " . $_SESSION['user_id'] . "\n";

$currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['userID'] ?? 0);
echo "✓ Current user ID resolved to: " . $currentUserId . "\n";

echo "\n=== Test Complete ===\n";
echo "If all tests passed, the lock_acquire.php API should work properly.\n";
echo "Test by making a POST request to: api/lock_acquire.php\n";
echo "With POST data: transfer_id=123&fingerprint=test-browser\n";

?>