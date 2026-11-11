<?php
// A local PHP-script-only cron probe. No bot, .env, database or network calls.
if (isset($_SERVER['REQUEST_METHOD']) || isset($_SERVER['REMOTE_ADDR']) || isset($_SERVER['HTTP_HOST'])
    || isset($_SERVER['SERVER_PROTOCOL']) || isset($_SERVER['GATEWAY_INTERFACE'])) {
    http_response_code(403);
    exit;
}

$eaPath='/usr/local/bin/ea-php84';
if (function_exists('error_clear_last')) { error_clear_last(); }
$eaExists=@is_file($eaPath);
$eaError=error_get_last();
$eaState=$eaError?'unconfirmed':($eaExists?'present':'not_found');
$result=[
    'checked_at'=>time(),
    'php_version'=>PHP_VERSION,
    'sapi'=>PHP_SAPI,
    'php_binary'=>PHP_BINARY,
    'sqlite'=>extension_loaded('sqlite3'),
    'curl'=>extension_loaded('curl'),
    'ea_php84_path'=>$eaPath,
    'ea_php84_state'=>$eaState,
    'ea_php84_executable'=>$eaState==='present'?@is_executable($eaPath):null,
    'worker_file_exists'=>is_file(__DIR__.'/check_tickets.php'),
    'folder_writable'=>is_writable(__DIR__),
];
$guard="<?php http_response_code(404); exit; ?>\n";
$json=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
$path=__DIR__.'/ticket-cron-probe-result.php';
$temporary=__DIR__.'/.cron-probe-'.getmypid().'.php';
$saved=false;
if ($json!==false && @file_put_contents($temporary,$guard.$json,LOCK_EX)!==false) {
    @chmod($temporary,0644);
    $saved=@rename($temporary,$path);
}
if (is_file($temporary)) { @unlink($temporary); }
// Cron Email can show this output even if writing the result was not permitted.
echo $json.PHP_EOL.($saved?'Probe result saved.':'Probe result could not be saved.').PHP_EOL;
exit($saved?0:1);
