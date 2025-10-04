<?php
/**
 * check_disallowed_filenames.php
 * Purpose: CI/CLI guard to prevent reintroduction of insecure debug/test artifacts inside the transfers module.
 * Patterns blocked: _debug_, _test_, _syntax_, _workflow_debug, _table_test, _sandbox_
 * Usage (from project root or module root):
 *   php modules/transfers/stock/tools/check_disallowed_filenames.php [--strict] [--json]
 * Exit Codes:
 *   0 = OK
 *   2 = Violations detected
 */
declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
if(!$root){ fwrite(STDERR, "Unable to resolve module root\n"); exit(1);} 

$patterns = [
    '_debug_',
    '_test_',
    '_syntax_',
    '_workflow_debug',
    '_table_test',
    '_sandbox_'
];

$allowListFile = $root . '/docs/SECURITY_ALLOWED_DEBUG_FILES.txt';
$allow = [];
if(is_file($allowListFile)){
    $allow = array_filter(array_map('trim', file($allowListFile)));
}

$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$violations = [];
foreach($iter as $file){
    if(!$file->isFile()) continue;
    $rel = substr($file->getPathname(), strlen($root)+1);
    // Skip backup dir
    if(str_starts_with($rel, 'backups/')) continue;
    // Skip docs, except if actual disallowed file inside docs root
    // We ALLOW docs references; we only care about real web/exec files.
    if(str_starts_with($rel,'docs/')) continue;

    foreach($patterns as $p){
        if(stripos($rel, $p) !== false){
            if(in_array($rel, $allow, true)) continue;
            $violations[] = $rel;
            break;
        }
    }
}

if(isset($argv) && in_array('--json',$argv,true)){
    echo json_encode([
        'ok' => count($violations)===0,
        'violations' => $violations,
        'patterns' => $patterns,
        'allowlist' => $allow
    ], JSON_PRETTY_PRINT) . "\n";
}

if(count($violations)>0){
    fwrite(STDERR, "Disallowed filenames detected (".count($violations)."):\n - ".implode("\n - ",$violations)."\n");
    fwrite(STDERR, "Block patterns: ".implode(', ',$patterns)."\n");
    fwrite(STDERR, "Add intentional exceptions to docs/SECURITY_ALLOWED_DEBUG_FILES.txt if absolutely required.\n");
    exit(2);
}

if(!in_array('--json',$argv,true)){
    echo "Filename policy check passed.\n";
}
exit(0);
