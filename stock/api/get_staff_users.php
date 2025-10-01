<?php
// Get user names for ownership request display
require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';

session_start();

header('Content-Type: application/json');

try {
    $pdo = cis_pdo();
    
    // Get staff users (adjust table name as needed)
    $stmt = $pdo->prepare("
        SELECT user_id, CONCAT(first_name, ' ', last_name) as full_name, username, email
        FROM users 
        WHERE active = 1 AND user_type IN ('staff', 'admin')
        ORDER BY first_name, last_name
    ");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Create lookup array
    $userLookup = [];
    foreach ($users as $user) {
        $userLookup[$user['user_id']] = [
            'name' => $user['full_name'] ?: $user['username'],
            'email' => $user['email']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'users' => $userLookup
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>