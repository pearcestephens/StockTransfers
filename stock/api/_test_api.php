<?php
http_response_code(410);
echo json_encode(['success'=>false,'error'=>'_test_api deprecated']);
exit; // Ensure no further code is executed
?>
    require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';
    
    session_start();
    
    // Force a user ID for testing
    $_SESSION['user_id'] = 1;
    
    header('Content-Type: application/json');
    
    echo json_encode([
        'success' => true,
        'message' => 'API is working',
        'session_user_id' => $_SESSION['user_id'] ?? 'none',
        'timestamp' => date('Y-m-d H:i:s'),
        'server_info' => [
            'document_root' => $_SERVER['DOCUMENT_ROOT'],
            'script_name' => $_SERVER['SCRIPT_NAME']
        ]
    ]);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Exception: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
?>