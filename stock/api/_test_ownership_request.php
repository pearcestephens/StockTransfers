<?php
http_response_code(410);
header('Content-Type: application/json');
echo json_encode(['success'=>false,'error'=>'_test_ownership_request deprecated']);
exit;