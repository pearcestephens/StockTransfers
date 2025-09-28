<?php
declare(strict_types=1);
require __DIR__.'/_lib/validate.php';
cors_and_headers();
require_headers();

ok([
  "ok"=>true,
  "services"=>[
    ["carrier"=>"nz_post","service_code"=>"ECONOMY","name"=>"Economy","attrs"=>["sig","sat?","age?"]],
    ["carrier"=>"nz_post","service_code"=>"OVERNIGHT","name"=>"Overnight","attrs"=>["sig","sat?","age?"]],
    ["carrier"=>"nzc","service_code"=>"NZC_STANDARD","name"=>"Standard","attrs"=>["sig","sat?","age?"]],
    ["carrier"=>"nzc","service_code"=>"NZC_SAT_AM","name"=>"Sat AM","attrs"=>["sig","sat","age?"]]
  ]
]);
