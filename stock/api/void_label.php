<?php
declare(strict_types=1);
require __DIR__.'/_lib/validate.php';
require __DIR__.'/adapters/nz_post.php';
require __DIR__.'/adapters/nzc_gss.php';

cors_and_headers();
$H   = require_headers();
$req = read_json_body();

$carrier = $req['carrier'] ?? '';
if ($carrier==='nz_post') ok(nz_post_void($req,$H));
if ($carrier==='nzc')     ok(nzc_void($req,$H));
fail("UNSUPPORTED_CARRIER");
