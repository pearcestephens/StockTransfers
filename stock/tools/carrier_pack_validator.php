<?php
declare(strict_types=1);
/**
 * File: modules/transfers/stock/tools/carrier_pack_validator.php
 * Purpose: Validate a submitted Carrier Pack (CSV sheets + JSON samples) for Shipping Control Tower onboarding.
 * Usage (CLI):
 *   php modules/transfers/stock/tools/carrier_pack_validator.php /absolute/path/to/carrier_pack_dir
 * Expected Directory Structure:
 *   /dir/
 *     Credentials.csv
 *     Services.csv
 *     Dimensional.csv
 *     Zones_Rural.csv
 *     Surcharges.csv
 *     Labels.csv
 *     Permissions_Roles.csv
 *     Compliance.csv
 *     Samples_List.csv
 *     samples_json/ (folder)
 *        quote_sample.json
 *        reserve_sample.json
 *        create_label_sample.json
 *        tracking_in_transit.json
 *        tracking_delivered.json
 *        void_sample.json
 *        error_sample.json
 * Output:
 *   JSON summary (errors, warnings, stats). Exit code 0 if no errors, 1 if errors.
 *
 * Notes:
 *  - This avoids heavy XLSX dependencies by expecting CSV exports.
 *  - Carriers can fill the provided Excel then export each sheet as CSV.
 *  - Extend enumerations / required fields easily below.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(400);
    echo 'CLI only';
    exit(1);
}

$dir = $argv[1] ?? '';
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "Usage: php $argv[0] /path/to/carrier_pack_dir\n");
    exit(1);
}

// ---------- Config ----------
$expectedFiles = [
    'Credentials.csv', 'Services.csv', 'Dimensional.csv', 'Zones_Rural.csv', 'Surcharges.csv',
    'Labels.csv', 'Permissions_Roles.csv', 'Compliance.csv', 'Samples_List.csv'
];
$expectedJsonSamples = [
    'quote_sample.json' => ['carrier','origin','destination','packages','quotes'],
    'reserve_sample.json' => ['reservation_id','expires_at','selected_service'],
    'create_label_sample.json' => ['shipment_id','carrier','service_code','labels','charges'],
    'tracking_in_transit.json' => ['tracking_number','status','events'],
    'tracking_delivered.json' => ['tracking_number','status','events','delivered_at'],
    'void_sample.json' => ['shipment_id','voided'],
    'error_sample.json' => ['error']
];

// Sheet -> required columns mapping
$requiredColumns = [
    'Credentials.csv' => ['Carrier','Environment (Live/Test)','Account Number','API Key','API Base URL'],
    'Services.csv' => ['Carrier','Service Code','Service Name','Domestic/International','Signature Available'],
    'Dimensional.csv' => ['Carrier','Service Code','Divisor (cm³ per kg)'],
    'Zones_Rural.csv' => ['Carrier','Zone Code','Zone Name','Country','Postcode Range'],
    'Surcharges.csv' => ['Carrier','Surcharge Name','Calculation Method (%, per kg, per consignment)','Rate/Value'],
    'Labels.csv' => ['Carrier','Format (PDF A4/A6, ZPL etc.)','DPI Required','Label Size (mm)'],
    'Permissions_Roles.csv' => ['Role','Create Shipment (Y/N)','Void Shipment (Y/N)'],
    'Compliance.csv' => ['Data Type (Shipment, Label, Tracking, PII)','Retention Period'],
    'Samples_List.csv' => ['Sample Type','File Name (attach in email or include in ZIP)']
];

// Enumerations / validation rules
$enumYesNo = ['Y','N','Y/N',''];
$serviceDomesticIntl = ['Domestic','International',''];
$labelFormatsAllowedPattern = '/\b(PDF|ZPL|EPL)\b/i';
$datePattern = '/^\d{4}-\d{2}-\d{2}$/';
$timePattern = '/^([01]\d|2[0-3]):[0-5]\d$/';
$weightNumericMax = 200.0; // Hard sanity cap

$issues = [];
function issue(string $severity, string $file, string $message, ?int $row=null): void {
    global $issues; $issues[] = ['severity'=>$severity,'file'=>$file,'row'=>$row,'message'=>$message];
}

// ---------- Helpers ----------
function readCsv(string $path): array {
    $rows=[]; if (!is_file($path)) return $rows; $fh=fopen($path,'r'); if(!$fh) return $rows; $header=null; $lineNo=0;
    while(($cols=fgetcsv($fh))!==false){ $lineNo++; if($lineNo===1){ $header=$cols; continue; } if($header===null) continue; $row=[]; foreach($header as $i=>$h){ $row[$h]= $cols[$i] ?? ''; } $rows[]=$row; }
    fclose($fh); return ['header'=>$header,'rows'=>$rows];
}

// ---------- File Presence ----------
foreach ($expectedFiles as $f) {
    if (!is_file($dir . '/' . $f)) issue('error',$f,'Missing required CSV file');
}

// ---------- Validate each CSV ----------
foreach ($expectedFiles as $f) {
    $path = $dir . '/' . $f;
    if (!is_file($path)) continue; // already logged
    $parsed = readCsv($path);
    $header = $parsed['header'] ?? [];
    if (!$header) { issue('error',$f,'Empty or unreadable CSV'); continue; }
    // Required columns
    foreach (($requiredColumns[$f] ?? []) as $col) {
        if (!in_array($col,$header,true)) issue('error',$f,"Missing required column '$col'");
    }
    $rows = $parsed['rows'] ?? [];
    if (!$rows) issue('warning',$f,'No data rows (is this intentional?)');
    // Row validations
    foreach ($rows as $idx=>$row) {
        $rn = $idx+2; // account for header line
        switch ($f) {
            case 'Services.csv':
                $domintl = $row['Domestic/International'] ?? '';
                if ($domintl !== '' && !in_array($domintl,$serviceDomesticIntl,true)) issue('error',$f,'Invalid Domestic/International value: '.$domintl,$rn);
                $sig = $row['Signature Available'] ?? '';
                if ($sig!=='' && !in_array($sig,$enumYesNo,true)) issue('error',$f,'Signature Available must be Y/N',$rn);
                $maxW = trim($row['Max Weight (kg)'] ?? '');
                if ($maxW !== '' && (!is_numeric($maxW) || (float)$maxW <=0 || (float)$maxW>$weightNumericMax)) issue('error',$f,'Max Weight invalid (numeric >0 <= '.$weightNumericMax.')',$rn);
                break;
            case 'Dimensional.csv':
                $div = trim($row['Divisor (cm³ per kg)'] ?? '');
                if ($div !== '' && (!ctype_digit($div) || (int)$div < 2000 || (int)$div > 8000)) issue('warning',$f,'Divisor unusual (expect 2000-8000) got '.$div,$rn);
                break;
            case 'Zones_Rural.csv':
                $pc = $row['Postcode Range'] ?? '';
                if ($pc !== '' && !preg_match('/^(\d{3,4}-\d{3,4})(;\s*\d{3,4}-\d{3,4})*$/',$pc)) issue('warning',$f,'Postcode Range format not matched (expect 1230-1299; 1300-1399)',$rn);
                break;
            case 'Surcharges.csv':
                $calc = $row['Calculation Method (%, per kg, per consignment)'] ?? '';
                if ($calc!=='' && !in_array($calc,['%','per kg','per consignment'],true)) issue('error',$f,'Unsupported Calculation Method: '.$calc,$rn);
                $eff = $row['Effective From (YYYY-MM-DD)'] ?? '';
                if ($eff !== '' && !preg_match($datePattern,$eff)) issue('error',$f,'Bad date format Effective From',$rn);
                break;
            case 'Labels.csv':
                $fmt = $row['Format (PDF A4/A6, ZPL etc.)'] ?? '';
                if ($fmt !== '' && !preg_match($labelFormatsAllowedPattern,$fmt)) issue('warning',$f,'Label Format does not include known types (PDF/ZPL/EPL)',$rn);
                $dpi = trim($row['DPI Required'] ?? '');
                if ($dpi!=='' && !in_array($dpi,['203','300'],true)) issue('warning',$f,'DPI unusual (203 or 300 expected)',$rn);
                break;
            case 'Permissions_Roles.csv':
                $create = $row['Create Shipment (Y/N)'] ?? '';
                if ($create!=='' && !in_array($create,$enumYesNo,true)) issue('error',$f,'Create Shipment must be Y or N',$rn);
                break;
            case 'Compliance.csv':
                $ret = $row['Retention Period'] ?? '';
                if ($ret!=='' && !preg_match('/^(\d+\s*(days?|months?|years?))$/i',$ret)) issue('warning',$f,'Retention Period free-form; consider standardized (e.g. 24 months)',$rn);
                break;
        }
    }
}

// Uniqueness checks
$servicesPath = $dir.'/Services.csv';
if (is_file($servicesPath)) {
    $svc = readCsv($servicesPath);
    $codes = [];
    foreach (($svc['rows']??[]) as $r) {
        $code = trim($r['Service Code'] ?? '');
        if ($code==='') continue;
        if (isset($codes[$code])) issue('error','Services.csv','Duplicate Service Code: '.$code);
        $codes[$code]=true;
    }
}

// JSON samples validation
$jsonDir = rtrim($dir,'/').'/samples_json';
if (!is_dir($jsonDir)) issue('error','samples_json','Missing samples_json directory');
else {
    foreach ($expectedJsonSamples as $file=>$requiredKeys) {
        $fp = $jsonDir.'/'.$file;
        if (!is_file($fp)) { issue('error',$file,'Missing JSON sample'); continue; }
        $raw = file_get_contents($fp);
        if ($raw === false) { issue('error',$file,'Unreadable JSON file'); continue; }
        $decoded = json_decode($raw,true);
        if (!is_array($decoded)) { issue('error',$file,'Invalid JSON structure'); continue; }
        foreach ($requiredKeys as $rk) {
            if (!array_key_exists($rk,$decoded)) issue('error',$file,"Missing key '$rk'");
        }
        // Specific cross-field checks
        if ($file === 'create_label_sample.json') {
            if (empty($decoded['labels']) || !is_array($decoded['labels'])) issue('error',$file,'labels array required & non-empty');
        }
        if ($file === 'tracking_in_transit.json' || $file === 'tracking_delivered.json') {
            if (!isset($decoded['events']) || !is_array($decoded['events'])) issue('error',$file,'events array required');
        }
    }
}

// Stats
$errorCount = count(array_filter($issues, fn($i)=>$i['severity']==='error'));
$warnCount = count(array_filter($issues, fn($i)=>$i['severity']==='warning'));

usort($issues, function($a,$b){ return [$a['severity'],$a['file'],$a['row']??0] <=> [$b['severity'],$b['file'],$b['row']??0]; });

$summary = [
    'ok' => $errorCount === 0,
    'errors' => $errorCount,
    'warnings' => $warnCount,
    'issues' => $issues,
    'checked_at' => date('c'),
    'directory' => realpath($dir)
];

echo json_encode($summary, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . "\n";
exit($errorCount === 0 ? 0 : 1);
