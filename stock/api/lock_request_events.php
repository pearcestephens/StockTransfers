<?php
declare(strict_types=1);

/**
 * Lock Request Events API - Emergency Stub
 * This file was lost during systematic corruption on Oct 1, 2025
 * This is a minimal stub to prevent fatal errors
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache');

// Basic error handling
try {
    // Return minimal response structure to prevent crashes
    $response = [
        'status' => 'ok',
        'events' => [],
        'timestamp' => time(),
        'message' => 'Service temporarily unavailable - using fallback'
    ];
    
    echo json_encode($response);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Service temporarily unavailable',
        'timestamp' => time()
    ]);
}