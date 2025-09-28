<?php
declare(strict_types=1);
require __DIR__.'/_lib/validate.php';
cors_and_headers();

// In prod, resolve via DB (vend_outlets) + rural map (NZPost/GSS zone, RD codes, etc.)
$to = (int)($_GET['to_outlet_id'] ?? 0);
if (!$to) fail("MISSING_PARAM","to_outlet_id required");

// TODO: Replace with DB-backed logic
ok([
  "rural" => false,
  "saturday_serviceable" => true
]);
