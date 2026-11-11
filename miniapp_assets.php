<?php

/** Public UI assets only. Do not turn this into an arbitrary file reader. */
function serveMiniAppAsset(): bool
{
    if (!isset($_GET['asset'])) { return false; }
    $assets = [
        'style'=>['telegram-app.css','text/css; charset=utf-8'],
        'app'=>['telegram-app.js','text/javascript; charset=utf-8'],
        'calendar'=>['miniapp-calendar.js','text/javascript; charset=utf-8'],
        'motion'=>['miniapp-motion.js','text/javascript; charset=utf-8'],
        'font'=>['fonts/webfonts/Vazirmatn[wght].woff2','font/woff2'],
    ];
    $name = $_GET['asset'];
    if (!is_string($name) || !isset($assets[$name])) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Asset not found';
        return true;
    }
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET','HEAD'], true)) {
        http_response_code(405);
        header('Allow: GET, HEAD');
        return true;
    }
    [$relative,$type] = $assets[$name];
    $path = __DIR__.'/'.$relative;
    if (!is_file($path) || !is_readable($path)) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Mini App asset unavailable';
        return true;
    }
    $etag = '"'.hash_file('sha256',$path).'"';
    header('Content-Type: '.$type);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=300, must-revalidate');
    header('ETag: '.$etag);
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        return true;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') { readfile($path); }
    return true;
}
