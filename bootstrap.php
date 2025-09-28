<?php
declare(strict_types=1);

/**
 * Bootstrap file for Stock Transfers System
 * 
 * Initializes the application, loads environment variables,
 * sets up error handling, and configures basic services.
 * 
 * @author Pearce Stephens <pearce.stephens@ecigdis.co.nz>
 * @copyright 2025 Ecigdis Limited
 * @since 1.0.0
 */

// Set strict error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Define application constants
define('APP_START_TIME', microtime(true));
define('APP_ROOT', __DIR__);
define('STOCK_DIR', APP_ROOT . '/stock');
define('CONFIG_DIR', APP_ROOT . '/config');
define('STORAGE_DIR', APP_ROOT . '/storage');
define('LOGS_DIR', STORAGE_DIR . '/logs');

// Ensure required directories exist
$requiredDirs = [
    STORAGE_DIR,
    LOGS_DIR,
    STORAGE_DIR . '/cache',
    STORAGE_DIR . '/sessions',
    STORAGE_DIR . '/uploads'
];

foreach ($requiredDirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Load Composer autoloader
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    // Fallback for development without Composer
    spl_autoload_register(function ($class) {
        $prefix = 'VapeShed\\StockTransfers\\';
        $baseDir = __DIR__ . '/src/';
        
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }
        
        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
        
        if (file_exists($file)) {
            require $file;
        }
    });
}

// Load environment variables
if (file_exists(__DIR__ . '/.env')) {
    $envFile = file_get_contents(__DIR__ . '/.env');
    $lines = explode("\n", $envFile);
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }
        
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value, '"\'');
            putenv(trim($key) . '=' . trim($value, '"\''));
        }
    }
}

// Configuration helper function
function env(string $key, $default = null) {
    return $_ENV[$key] ?? $default;
}

// Application configuration
$config = [
    'app' => [
        'name' => env('APP_NAME', 'Stock Transfers System'),
        'env' => env('APP_ENV', 'production'),
        'debug' => env('APP_DEBUG', 'false') === 'true',
        'url' => env('APP_URL', 'https://staff.vapeshed.co.nz/modules/StockTransfers'),
    ],
    'database' => [
        'host' => env('DB_HOST', 'localhost'),
        'port' => (int) env('DB_PORT', 3306),
        'database' => env('DB_DATABASE', 'vape_shed_transfers'),
        'username' => env('DB_USERNAME', ''),
        'password' => env('DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
        ],
    ],
    'logging' => [
        'level' => env('LOG_LEVEL', 'info'),
        'max_files' => (int) env('LOG_MAX_FILES', 14),
    ],
    'security' => [
        'bcrypt_rounds' => (int) env('BCRYPT_ROUNDS', 12),
        'session_lifetime' => (int) env('SESSION_LIFETIME', 120),
    ],
];

// Global configuration accessor
$GLOBALS['config'] = $config;

function config(string $key, $default = null) {
    $keys = explode('.', $key);
    $value = $GLOBALS['config'];
    
    foreach ($keys as $segment) {
        if (!isset($value[$segment])) {
            return $default;
        }
        $value = $value[$segment];
    }
    
    return $value;
}

// Set timezone
date_default_timezone_set('Pacific/Auckland');

// Enhanced error handling
function handleError(int $severity, string $message, string $file = '', int $line = 0): void {
    if (!(error_reporting() & $severity)) {
        return;
    }
    
    $errorLog = LOGS_DIR . '/error-' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] ERROR: {$message} in {$file} on line {$line}\n";
    
    file_put_contents($errorLog, $logEntry, FILE_APPEND | LOCK_EX);
    
    if (config('app.debug')) {
        echo "<div style='background:#ff6b6b;color:white;padding:10px;margin:5px;border-radius:4px;'>";
        echo "<strong>Error:</strong> {$message}<br>";
        echo "<strong>File:</strong> {$file}<br>";
        echo "<strong>Line:</strong> {$line}";
        echo "</div>";
    }
}

function handleException(Throwable $exception): void {
    $errorLog = LOGS_DIR . '/error-' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $message = $exception->getMessage();
    $file = $exception->getFile();
    $line = $exception->getLine();
    $trace = $exception->getTraceAsString();
    
    $logEntry = "[{$timestamp}] EXCEPTION: {$message} in {$file} on line {$line}\nStack trace:\n{$trace}\n\n";
    
    file_put_contents($errorLog, $logEntry, FILE_APPEND | LOCK_EX);
    
    if (config('app.debug')) {
        echo "<div style='background:#ff6b6b;color:white;padding:15px;margin:5px;border-radius:4px;'>";
        echo "<h3>Uncaught Exception</h3>";
        echo "<strong>Message:</strong> {$message}<br>";
        echo "<strong>File:</strong> {$file}<br>";
        echo "<strong>Line:</strong> {$line}<br>";
        echo "<details><summary>Stack Trace</summary><pre>{$trace}</pre></details>";
        echo "</div>";
    } else {
        http_response_code(500);
        echo json_encode([
            'error' => 'Internal server error',
            'message' => 'An unexpected error occurred',
            'timestamp' => date('c'),
        ]);
    }
}

// Register error handlers
set_error_handler('handleError');
set_exception_handler('handleException');

// Database connection helper
function getDatabase(): PDO {
    static $pdo = null;
    
    if ($pdo === null) {
        $host = config('database.host');
        $port = config('database.port');
        $database = config('database.database');
        $username = config('database.username');
        $password = config('database.password');
        $charset = config('database.charset');
        $options = config('database.options');
        
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
        
        try {
            $pdo = new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            handleException($e);
            exit(1);
        }
    }
    
    return $pdo;
}

// Session configuration
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => config('security.session_lifetime') * 60,
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// CSRF protection helper
function generateCsrfToken(): string {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Input sanitization helpers
function sanitizeInput(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function sanitizeArray(array $input): array {
    return array_map('sanitizeInput', $input);
}

// JSON response helper
function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// Log request for debugging (only in development)
if (config('app.debug') && env('ENABLE_REQUEST_LOGGING', false)) {
    $requestLog = LOGS_DIR . '/requests-' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    
    $logEntry = "[{$timestamp}] {$method} {$uri} - IP: {$ip} - UA: {$userAgent}\n";
    file_put_contents($requestLog, $logEntry, FILE_APPEND | LOCK_EX);
}

// Application is now bootstrapped and ready
define('APP_BOOTSTRAPPED', true);