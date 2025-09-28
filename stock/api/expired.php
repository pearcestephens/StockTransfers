<?php
declare(strict_types=1);
require __DIR__.'/_lib/validate.php';
cors_and_headers();
require_headers();

ok([
  "ok"=>true,
  "reservations"=>[
    ["carrier"=>"nz_post","type"=>"track","number"=>"NZX123456789","reserved_at"=>"2025-09-20T10:44:00Z","expires_at"=>"2025-09-27T10:44:00Z"],
    ["carrier"=>"nzc","type"=>"ticket","number"=>"C123-998877","reserved_at"=>"2025-09-19T09:15:00Z","expires_at"=>"2025-09-26T09:15:00Z"]
  ]
]);
