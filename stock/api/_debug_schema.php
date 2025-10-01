<?php
// Quick schema debug
require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';

$pdo = cis_pdo();
$result = $pdo->query("DESCRIBE transfer_pack_lock_requests");
$schema = $result->fetchAll(PDO::FETCH_ASSOC);

echo "<pre>";
print_r($schema);
echo "</pre>";

echo "<hr>";

// Show actual data
$result = $pdo->query("SELECT * FROM transfer_pack_lock_requests ORDER BY created_at DESC LIMIT 5");
$data = $result->fetchAll(PDO::FETCH_ASSOC);

echo "<h3>Recent Data:</h3>";
echo "<pre>";
print_r($data);
echo "</pre>";
?>