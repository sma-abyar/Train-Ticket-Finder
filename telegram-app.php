<?php
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
require_once __DIR__.'/miniapp_assets.php';
if (serveMiniAppAsset()) { exit; }
if (!isset($_GET['api'])) {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); exit; }
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' https://telegram.org; style-src 'self'; font-src 'self'; connect-src 'self'; img-src 'self' data:; base-uri 'none'; object-src 'none'; frame-ancestors 'self' https://web.telegram.org https://*.telegram.org");
    readfile(__DIR__ . '/telegram-app.html');
    exit;
}
header('Content-Type: application/json; charset=utf-8');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { throw new RuntimeException('روش درخواست معتبر نیست.', 405); }
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if (strlen($raw) > 65536) { throw new RuntimeException('درخواست بیش از حد بزرگ است.', 413); }
    $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($body) || !is_string($body['init_data'] ?? null)) { throw new InvalidArgumentException('اطلاعات ورود کامل نیست.', 400); }
    chdir(__DIR__);
    define('TRAIN_TICKET_BOT_LIBRARY_ONLY', true);
    require __DIR__ . '/bot.php';
    require_once __DIR__ . '/telegram_webapp_auth.php';
    require_once __DIR__ . '/trip_app.php';
    $chat = telegramWebAppUser($body['init_data'], $GLOBALS['botToken']);
    $db = initDatabase();
    $action = $body['action'] ?? 'bootstrap';
    if ($action === 'bootstrap') {
        $rows = followSql($db, 'SELECT * FROM user_trips WHERE chat_id=:chat ORDER BY id DESC', [':chat'=>$chat]);
        $trips = [];
        while ($row = $rows->fetchArray(SQLITE3_ASSOC)) { if (!isTripExpired($row['date'])) { $trips[] = miniAppTrip($row); } }
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
        $data = ['cities'=>miniAppCities(), 'today'=>$now->format('Y-m-d'), 'server_time'=>$now->getTimestamp(), 'user_id'=>$chat, 'approved'=>isUserApproved($chat), 'trips'=>$trips];
    } elseif ($action === 'create_trip') {
        $data = miniAppCreate($db, $chat, $body);
    } elseif ($action === 'update_preferences') {
        $data = miniAppPreferences($db, $chat, $body);
    } elseif (in_array($action, ['load','refresh','save'], true)) {
        if (!is_int($body['trip_id'] ?? null) || $body['trip_id'] < 1) { throw new InvalidArgumentException('سفر معتبر نیست.', 400); }
        $trip = ownedFollowTrip($db, $body['trip_id'], $chat);
        if ($action === 'save') {
            if (!is_string($body['mode'] ?? null) || !is_array($body['keys'] ?? null) || !is_int($body['revision'] ?? null) || !is_array($body['excluded_keys'] ?? [])) { throw new InvalidArgumentException('انتخاب قطارها معتبر نیست.', 400); }
            $trip = saveTrainFollows($db, $body['trip_id'], $chat, $body['mode'], $body['keys'], $body['revision'], $body['excluded_keys'] ?? []);
        }
        $data = followPickerData($db, $trip, $action === 'refresh');
    } else { throw new InvalidArgumentException('درخواست معتبر نیست.', 400); }
    echo json_encode(['ok'=>true, 'data'=>$data], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $code = in_array($error->getCode(), [400,401,404,405,409,413,503], true) ? $error->getCode() : 500;
    if ($error instanceof JsonException) { $code = 400; }
    http_response_code($code);
    if ($code === 500) { error_log('Train mini app: '.$error->getMessage()); }
    echo json_encode(['ok'=>false, 'error'=>$code === 500 ? 'ارتباط انجام نشد؛ دوباره تلاش کنید.' : $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
