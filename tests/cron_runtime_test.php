<?php
require_once __DIR__.'/../cron_runtime.php';

$tests['local shared-host CGI cron is accepted without accepting HTTP']=static function() {
    expect(isLocalPhpCronRuntime('cli',[]),true);
    expect(isLocalPhpCronRuntime('cgi-fcgi',[]),true);
    expect(isLocalPhpCronRuntime('cgi-fcgi',['REDIRECT_STATUS'=>'1','SCRIPT_FILENAME'=>'worker.php']),true);
    foreach (['REQUEST_METHOD'=>'GET','REMOTE_ADDR'=>'127.0.0.1','HTTP_HOST'=>'localhost','SERVER_PROTOCOL'=>'HTTP/1.1','GATEWAY_INTERFACE'=>'CGI/1.1'] as $key=>$value) {
        expect(isLocalPhpCronRuntime('cgi-fcgi',[$key=>$value]),false,$key.' must keep HTTP requests out');
        expect(isLocalPhpCronRuntime('cli',[$key=>$value]),false);
    }
    foreach (['fpm-fcgi','apache2handler','cli-server','unknown'] as $sapi) { expect(isLocalPhpCronRuntime($sapi,[]),false); }
};
