<?php
header('Content-Type: application/json');
$h = function_exists('getallheaders') ? getallheaders() : [];
$out = [
  'getallheaders' => $h,
  '_server_http'  => array_filter($_SERVER, fn($k)=>strpos($k,'HTTP_')===0, ARRAY_FILTER_USE_KEY),
];
echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
