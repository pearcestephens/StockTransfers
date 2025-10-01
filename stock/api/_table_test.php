<?php
http_response_code(410);
header('Content-Type: application/json');
echo json_encode(['success'=>false,'error'=>'_table_test deprecated']);
exit;
    $pdo = cis_pdo();
    
    // Test table exists and get structure
    $columns = $pdo->query("DESCRIBE transfer_pack_lock_requests")->fetchAll(PDO::FETCH_ASSOC);
    
    // Test basic insert
    $pdo->prepare("DELETE FROM transfer_pack_lock_requests WHERE transfer_id = 999")->execute();
    
    $insert = $pdo->prepare("
        INSERT INTO transfer_pack_lock_requests 
        (transfer_id, user_id, requested_at, status, expires_at, client_fingerprint)
        VALUES (999, 1, NOW(), 'pending', DATE_ADD(NOW(), INTERVAL 60 SECOND), 'test')
    ");
    
    $insertSuccess = $insert->execute();
    $insertId = $pdo->lastInsertId();
    
    // Fetch the inserted record
    $select = $pdo->prepare("SELECT * FROM transfer_pack_lock_requests WHERE id = ?");
    $select->execute([$insertId]);
    $record = $select->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'table_columns' => $columns,
        'insert_success' => $insertSuccess,
        'insert_id' => $insertId,
        'sample_record' => $record,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
?>