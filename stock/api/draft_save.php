<?php
/**
 * DEPRECATED: draft_save.php
 * This endpoint has been replaced by draft_save_api.php (lock-validated & audited).
 * Returns 410 Gone to signal clients to upgrade.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
http_response_code(410);
echo json_encode([
  'success' => false,
  'error' => [
    'code' => 'DEPRECATED_ENDPOINT',
    'message' => 'Use /modules/transfers/stock/api/draft_save_api.php'
  ],
  'moved_to' => '/modules/transfers/stock/api/draft_save_api.php'
]);
exit;
?>
