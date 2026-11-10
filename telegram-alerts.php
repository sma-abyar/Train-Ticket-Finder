<?php

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

if (!isset($_GET['api'])) {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' https://telegram.org; style-src 'self'; font-src 'self'; connect-src 'self'; img-src 'self' data:; base-uri 'none'; object-src 'none'; frame-ancestors 'self' https://web.telegram.org https://*.telegram.org");
    // Keep previously sent links valid while using the same unified interface.
    readfile(__DIR__ . '/telegram-app.html');
    exit;
}

header('Content-Type: application/json; charset=utf-8');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('روش درخواست نامعتبر است.', 405);
    }
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if (strlen($raw) > 65536) {
        throw new RuntimeException('درخواست بیش از حد بزرگ است.', 413);
    }
    $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($body) || !is_string($body['init_data'] ?? null)
        || !is_int($body['trip_id'] ?? null) || $body['trip_id'] < 1) {
        throw new InvalidArgumentException('اطلاعات درخواست کامل نیست.', 400);
    }
    chdir(__DIR__);
    define('TRAIN_TICKET_BOT_LIBRARY_ONLY', true);
    require __DIR__ . '/bot.php';
    require_once __DIR__ . '/telegram_webapp_auth.php';
    $chatId = telegramWebAppUser($body['init_data'], $GLOBALS['botToken']);
    $db = initDatabase();
    $trip = ownedFollowTrip($db, $body['trip_id'], $chatId);
    $action = $body['action'] ?? 'load';
    if ($action === 'save') {
        if (!is_string($body['mode'] ?? null) || !is_array($body['keys'] ?? null) || !is_int($body['revision'] ?? null)) {
            throw new InvalidArgumentException('انتخاب قطارها نامعتبر است.', 400);
        }
        if (isset($body['excluded_keys']) && !is_array($body['excluded_keys'])) {
            throw new InvalidArgumentException('انتخاب قطارها نامعتبر است.', 400);
        }
        $trip = saveTrainFollows($db, $body['trip_id'], $chatId, $body['mode'], $body['keys'], $body['revision'], $body['excluded_keys'] ?? []);
    } elseif (!in_array($action, ['load', 'refresh'], true)) {
        throw new InvalidArgumentException('درخواست نامعتبر است.', 400);
    }
    echo json_encode(['ok'=>true, 'data'=>followPickerData($db, $trip, $action === 'refresh')], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $code = in_array($error->getCode(), [400,401,404,405,409,413,503], true) ? $error->getCode() : 500;
    if ($error instanceof JsonException) {
        $code = 400;
    }
    http_response_code($code);
    if ($code === 500) {
        error_log('Train follow mini app failed: ' . $error->getMessage());
    }
    echo json_encode(['ok'=>false, 'error'=>$code === 500 ? 'دریافت یا ذخیرهٔ تنظیمات انجام نشد؛ دوباره تلاش کنید.' : $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
