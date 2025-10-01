<?php
// This test file has been removed for production
http_response_code(404);
echo json_encode(['success' => false, 'error' => 'Test files removed for production']);
?>