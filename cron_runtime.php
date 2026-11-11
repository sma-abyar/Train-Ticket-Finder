<?php

/** Some shared hosts invoke local PHP cron files with php-cgi rather than CLI. */
function isLocalPhpCronRuntime(?string $sapi=null, ?array $server=null): bool
{
    $sapi=$sapi??PHP_SAPI;$server=$server??$_SERVER;
    if (!in_array($sapi,['cli','cgi-fcgi'],true)) { return false; }
    // A CGI name alone is never permission to run the worker over HTTP.
    foreach (['REQUEST_METHOD','REMOTE_ADDR','HTTP_HOST','SERVER_PROTOCOL','GATEWAY_INTERFACE'] as $key) {
        if (isset($server[$key])) { return false; }
    }
    return true;
}

function requireLocalPhpCronRuntime(): void
{
    if (!isLocalPhpCronRuntime()) { http_response_code(403); exit; }
    // Match CLI's unlimited PHP timer when running a local CGI cron process.
    if (function_exists('set_time_limit')) { @set_time_limit(0); }
}

function writeCronError(string $message): void
{
    if (defined('STDERR')) { fwrite(STDERR,$message); }
    else { file_put_contents('php://stderr',$message); }
}
