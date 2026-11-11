<?php

// Exercise the real bot and cron entry point against local mock APIs, using a
// disposable copy so neither production credentials nor real APIs are touched.
$root = dirname(__DIR__);
$temp = sys_get_temp_dir() . '/ticket-integration-' . bin2hex(random_bytes(8));
mkdir($temp);
$php = [PHP_BINARY, '-n', '-d', 'extension_dir=' . ini_get('extension_dir'),
    '-d', 'extension=sqlite3', '-d', 'extension=curl'];
$server = null;
$appServer = null;

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function runPhp(array $command, string $cwd, string $expectedError = ''): void
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    verify($status === 0 && ($errors === '' || ($expectedError !== '' && str_contains($errors, $expectedError)
        && !str_contains($errors, 'Fatal') && !str_contains($errors, 'Warning'))), "PHP worker failed: $output $errors");
    verify(trim($output) === '', 'Worker should not print configuration or debug data');
}

try {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $base = 'http://' . $address;
    foreach (['check_tickets.php', 'send_queue.php', 'cron_probe.php', 'cron_runtime.php', 'runFetchTickets.php', 'ticket_search.php', 'expired_trips.php', 'trip_dates.php', 'trip_preferences.php', 'trip_filter_ui.php',
        'train_follows.php', 'train_follow_bot.php', 'traveler_list_storage.php', 'notification_diagnostics.php', 'telegram_webapp_auth.php', 'telegram-alerts.php', 'telegram-alerts.html', 'telegram-alerts.css', 'telegram-alerts.js',
        'telegram-app.php','telegram-app.html','telegram-app.css','telegram-app.js','miniapp-calendar.js','miniapp-motion.js','miniapp-people.js','passenger_app.php','miniapp_assets.php','trip_app.php','train_cities.json','telegram_delivery.php','broadcast_queue.php','diagnose_notifications.php'] as $file) {
        copy($root . '/' . $file, $temp . '/' . $file);
    }
    mkdir($temp.'/fonts');mkdir($temp.'/fonts/webfonts');
    copy($root.'/fonts/webfonts/Vazirmatn[wght].woff2',$temp.'/fonts/webfonts/Vazirmatn[wght].woff2');
    // Real rate limiting is verified with an injected clock in unit tests; keep
    // mock HTTP scenarios fast without adding a production bypass setting.
    file_put_contents($temp.'/telegram_delivery.php',str_replace(['CHAT_INTERVAL = 1.05','GLOBAL_INTERVAL = 0.04'],['CHAT_INTERVAL = 0.0','GLOBAL_INTERVAL = 0.0'],file_get_contents($root.'/telegram_delivery.php')));
    // Replace service origins only in the test copy; no real bookings or messages.
    file_put_contents($temp . '/bot.php', str_replace(['https://api.telegram.org', 'https://ghasedak24.com'], $base, file_get_contents($root . '/bot.php')));
    file_put_contents($temp . '/.env', "BOT_TOKEN=TEST\nADMIN_CHAT_ID=999\nURL=$base/search\nDB_PATH=$temp/users.db\nHEADERS={}\n");
    file_put_contents($temp . '/router.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/telegram-alerts.php') { require __DIR__ . '/telegram-alerts.php'; return; }
if ($path === '/telegram-app.php') { require __DIR__ . '/telegram-app.php'; return; }
if ($path === '/ticket-worker-health.php') { require __DIR__.'/ticket-worker-health.php'; return; }
if ($path === '/cron_probe.php' || $path === '/ticket-cron-probe-result.php') { require __DIR__.$path; return; }
if (in_array($path,['/check_tickets.php','/runFetchTickets.php','/send_queue.php'],true)) { require __DIR__.$path; return; }
if (preg_match('/\.(css|js|woff2)$/D',$path)) { http_response_code(403); echo 'Forbidden'; return; }
$payload = $_POST;
if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === 0) {
    $payload = json_decode(file_get_contents('php://input'), true);
}
file_put_contents(__DIR__ . '/requests.jsonl', json_encode(['path' => $path, 'payload' => $payload]) . "\n", FILE_APPEND | LOCK_EX);
header('Content-Type: application/json');
if ($path === '/search') {
    if (is_file(__DIR__ . '/override-search.json')) {
        readfile(__DIR__ . '/override-search.json');
        return;
    }
    if (is_file(__DIR__ . '/empty-search')) {
        echo '{"data":{"status":"success","data":{"departure":[]}}}';
        return;
    }
    echo json_encode(['data' => ['status' => 'success', 'data' => ['departure' => [[
        'id' => 'ticket-1', 'from_title' => 'تهران', 'to_title' => 'مشهد',
        'counting_en' => is_file(__DIR__.'/low-search') ? 2 : 10,
        'counting' => is_file(__DIR__.'/low-search') ? '۲' : '۱۰', 'train_number' => '123',
        'wagon_name' => 'قطار', 'jdate_fa' => '1405-08-20', 'time' => '۱۲:۰۰', 'cost_title' => '10000000',
        'cost'=>1000000, 'owner_name'=>'فدک', 'iscompartment'=>1, 'compartment_capacity'=>4,
    ]]]]]);
} elseif (in_array($path, ['/botTEST/sendMessage','/botTEST/editMessageText','/botTEST/answerCallbackQuery'], true)) {
    if ($path==='/botTEST/sendMessage') {
        $markup=json_decode($payload['reply_markup'] ?? '{}',true);
        foreach ($markup['inline_keyboard'] ?? [] as $row) foreach ($row as $button) {
            if (isset($button['callback_data']) && (strlen($button['callback_data'])>64 || strlen($button['callback_data'])<1)) {
                http_response_code(400);echo '{"ok":false,"error_code":400,"description":"BUTTON_DATA_INVALID"}';return;
            }
        }
        if (is_file(__DIR__.'/telegram-error.json')) {
            $error=json_decode(file_get_contents(__DIR__.'/telegram-error.json'),true);
            if ((!isset($error['chat_id']) || (string)$error['chat_id']===(string)$payload['chat_id'])
                && (empty($error['only_hint']) || str_starts_with($payload['text'],'تنظیم هشدار سفر'))) {
                http_response_code($error['error_code']);echo json_encode($error+['ok'=>false]);return;
            }
        }
    }
    echo '{"ok":true}';
} elseif ($path === '/train/reservation/ticket-1/0') {
    if(is_file(__DIR__.'/reservation-failure')) { echo '{"status":"failed"}'; }
    else { echo '{"status":"success","rsid":"mock-reservation"}'; }
} else {
    http_response_code(404);
    echo '{"ok":false}';
}
PHP);
    $server = proc_open(array_merge($php, ['-S', $address, $temp . '/router.php']),
        [0 => ['pipe', 'r'], 1 => ['file', $temp . '/server.log', 'a'], 2 => ['file', $temp . '/server.log', 'a']],
        $serverPipes, $temp);
    fclose($serverPipes[0]);
    [$host, $port] = explode(':', $address);
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $probe = @fsockopen($host, (int) $port, $errorCode, $errorMessage, 0.1);
        if ($probe) {
            fclose($probe);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    verify($ready, 'Mock API did not start');
    $publicGet=static function(string $path,array $headers=[],string $method='GET')use($base):array{
        $received=[];$curl=curl_init($base.$path);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_HEADERFUNCTION=>static function($curl,$line)use(&$received){if(strpos($line,':')!==false){[$name,$value]=explode(':',$line,2);$received[strtolower(trim($name))]=trim($value);}return strlen($line);}]);
        if($method==='HEAD')curl_setopt($curl,CURLOPT_NOBODY,true);
        elseif($method==='POST')curl_setopt($curl,CURLOPT_POST,true);
        $body=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
        return [$status,$body,$received];
    };
    rename($temp.'/.env',$temp.'/.env.asset-test');
    try {
        verify($publicGet('/telegram-app.css?v=2')[0]===403,'Reproduce static-file denial');
        foreach(['style'=>['telegram-app.css','text/css'],'app'=>['telegram-app.js','text/javascript'],'calendar'=>['miniapp-calendar.js','text/javascript'],'motion'=>['miniapp-motion.js','text/javascript'],'people'=>['miniapp-people.js','text/javascript'],'font'=>['fonts/webfonts/Vazirmatn[wght].woff2','font/woff2']] as $name=>[$source,$mime]){
            [$status,$body,$headers]=$publicGet('/telegram-app.php?asset='.$name.'&v=3');
            verify($status===200 && str_starts_with($headers['content-type']??'',$mime),'UI asset must have the correct MIME type: '.$name);
            verify(hash('sha256',$body)===hash_file('sha256',$temp.'/'.$source),'Asset bytes must match the source: '.$name);
            verify(($headers['x-content-type-options']??'')==='nosniff','Assets must not rely on MIME sniffing');
            verify($publicGet('/telegram-app.php?asset='.$name.'&v=3',['If-None-Match: '.$headers['etag']])[0]===304,'Unchanged assets support cache revalidation');
        }
        verify($publicGet('/telegram-alerts.php?asset=style')[0]===200,'Legacy entry supports the same asset route');
        verify($publicGet('/telegram-app.php?asset=style',[],'HEAD')[0]===200,'Asset HEAD succeeds');
        verify($publicGet('/telegram-app.php?asset=style',[],'POST')[0]===405,'Asset POST cannot run the API');
        foreach(['.env','../.env','bot.php','unknown']as$name)verify($publicGet('/telegram-app.php?asset='.rawurlencode($name))[0]===404,'Only explicitly listed assets may be read');
        verify($publicGet('/telegram-app.php?asset%5B%5D=style')[0]===404,'Array parameters cannot select a file');
        verify(!is_file($temp.'/users.db'),'Serving UI assets must not open or create a database');
        echo "PASS Hosting regression: denied static files, PHP CSS/JS/font, MIME, ETag, safe allowlist and no credentials/database dependency\n";
    }finally{rename($temp.'/.env.asset-test',$temp.'/.env');}
    file_put_contents($temp . '/seed.php', <<<'PHP'
<?php
define('TRAIN_TICKET_BOT_LIBRARY_ONLY', true);
require __DIR__ . '/bot.php';
$db = initDatabase();
$db->exec("INSERT INTO users (chat_id, approved) VALUES ('101',1),('202',1),('303',1),('404',0)");
$db->exec("INSERT INTO traveler_lists (id,chat_id,name,members) VALUES (11,'101','First user','[]'),(22,'202','Second user','[]')");
$db->exec("INSERT INTO travelers (id,chat_id,first_name,last_name,national_code,passenger_type,gender,wheelchair) VALUES
    (1,'101','Test','One','0000000000',0,1,1),
    (2,'303','Test','Two','0000000000',0,1,0),(3,'303','Test','Three','0000000000',0,1,0),
    (4,'303','Test','Four','0000000000',0,1,0),(5,'303','Test','Infant','0000000000',2,1,0),(6,'202','Test','Six','0000000000',0,1,0)");
$db->exec("UPDATE traveler_lists SET members='[1]' WHERE id=11");
$db->exec("UPDATE traveler_lists SET members='[6]' WHERE id=22");
$db->exec("INSERT INTO traveler_lists (id,chat_id,name,members) VALUES (33,'303','Large group','[2,3,4]'),(34,'303','With infant','[2,5]')");
$future = (new DateTimeImmutable('tomorrow', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
$trip = ['route'=>'tehran-mashhad','date'=>$future,'return_date'=>$future,'count'=>1,'type'=>0,'coupe'=>0,'filter'=>0];
saveUserTrip('101', $trip);
saveUserTrip('202', $trip);
$trip['count'] = 2;
saveUserTrip('303', $trip);
$trip['count'] = 3;
saveUserTrip('404', $trip);
$trip['date'] = '1400-01-01';
saveUserTrip('101', $trip);
saveUserTrip('404', $trip);
$db->exec("UPDATE user_trips SET no_counting_notif=1, no_ticket_notif=1, bad_data_notif=1 WHERE chat_id='101'");
PHP);
    runPhp(array_merge($php, [$temp . '/seed.php']), $temp);
    runPhp(array_merge($php, [$temp . '/runFetchTickets.php']), $temp);
    $readRequests = static function () use ($temp) {
        return array_map(static fn($line) => json_decode($line, true), file($temp . '/requests.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    };
    $requests = $readRequests();
    $searches = array_values(array_filter($requests, static fn($request) => $request['path'] === '/search'));
    $messages = array_values(array_filter($requests, static fn($request) => $request['path'] === '/botTEST/sendMessage'));
    verify(count($searches) === 1, 'Different passenger counts must share the same search');
    verify(array_column(array_column($searches, 'payload'), 'count') === ['1'], 'Broad search must ask for one seat');
    verify(count($messages) === 8, 'Expected three ticket messages, three trip footers and two expiration messages');
    $tickets = array_values(array_filter($messages, static fn($message) => str_contains($message['payload']['text'], 'بلیط موجود است')));
    verify(array_column(array_column($tickets, 'payload'), 'chat_id') === ['101', '202', '303'], 'Each approved subscriber must receive its own result');
    $firstKeyboard = json_decode($tickets[0]['payload']['reply_markup'], true);
    $secondKeyboard = json_decode($tickets[1]['payload']['reply_markup'], true);
    verify(in_array('reserve_list_11_ticket-1_0', array_column(array_merge(...$firstKeyboard['inline_keyboard']), 'callback_data'), true), 'First user reservation list was mixed up');
    verify(in_array('reserve_list_22_ticket-1_0', array_column(array_merge(...$secondKeyboard['inline_keyboard']), 'callback_data'), true), 'Second user reservation list was mixed up');
    verify(str_contains($tickets[2]['payload']['reply_markup'], '2-0-0'), 'Passenger count must be preserved in reservation links');
    foreach ($tickets as $ticketMessage) {
        verify(empty($ticketMessage['payload']['disable_notification']),'New ticket alerts must keep normal audible Telegram delivery');
        $buttons = array_merge(...json_decode($ticketMessage['payload']['reply_markup'],true)['inline_keyboard']);
        $muteButtons = array_values(array_filter($buttons,static fn($button)=>str_starts_with($button['callback_data'] ?? '', 'train_mute_')));
        verify(count($muteButtons)===1 && strlen($muteButtons[0]['callback_data'])<=64,'Every ticket must carry a scoped mute button within Telegram callback limits');
    }
    $db = new SQLite3($temp . '/users.db');
    verify($db->querySingle('SELECT COUNT(*) FROM user_trips') === 4, 'Only expired trips should be removed');
    verify($db->querySingle('SELECT COUNT(*) FROM expired_trip_notifications WHERE sent_at IS NOT NULL') === 2, 'Expiration delivery must be recorded');
    verify($db->querySingle("SELECT COUNT(*) FROM user_trips WHERE chat_id IN ('101','202','303') AND (no_counting_notif != 0 OR no_ticket_notif != 0 OR bad_data_notif != 0)") === 0, 'Available tickets must reset each subscriber notification flags');
    $db->close();
    runPhp(array_merge($php, [$temp . '/check_tickets.php']), $temp);
    $requests = $readRequests();
    verify(count(array_filter($requests, static fn($request) => $request['path'] === '/search')) === 2, 'Next cycle must fetch fresh ticket data');
    verify(count(array_filter($requests, static fn($request) => $request['path'] === '/botTEST/sendMessage')) === 8, 'Unchanged availability, trip hints and expiration notices must all remain quiet');

    // Sharing a response must not share the subscribers' suppression flags.
    $db = new SQLite3($temp . '/users.db');
    $db->exec("UPDATE user_trips SET no_ticket_notif = CASE WHEN chat_id='202' THEN 1 ELSE 0 END");
    $db->close();
    touch($temp . '/empty-search');
    runPhp(array_merge($php, [$temp . '/check_tickets.php']), $temp);
    $newRequests = array_slice($readRequests(), count($requests));
    $newMessages = array_values(array_filter($newRequests, static fn($request) => $request['path'] === '/botTEST/sendMessage'));
    verify(array_column(array_column($newMessages, 'payload'), 'chat_id') === ['101', '303'], 'No-ticket alerts must respect each trip notification flags');
    $requests = $readRequests();
    runPhp(array_merge($php, [$temp . '/check_tickets.php']), $temp);
    $newRequests = array_slice($readRequests(), count($requests));
    verify(count(array_filter($newRequests, static fn($request) => $request['path'] === '/botTEST/sendMessage')) === 0, 'No-ticket alerts should not repeat');
    $requests = $readRequests();
    // An overlapping invocation should exit without fetching or sending anything.
    $lock = fopen($temp . '/ticket-check.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        runPhp(array_merge($php, [$temp . '/check_tickets.php']), $temp);
        verify(count($readRequests()) === count($requests), 'Overlapping worker should be skipped');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    // A shared broad search can still produce different results per subscriber.
    unlink($temp . '/empty-search');
    touch($temp . '/low-search');
    $db = new SQLite3($temp . '/users.db');
    $db->exec("UPDATE user_trips SET count=4, allow_partial_capacity=0, search_filters='{}', no_counting_notif=0");
    $db->exec("UPDATE user_trips SET allow_partial_capacity=1 WHERE chat_id='303'");
    $db->close();
    $before = count($readRequests());
    runPhp(array_merge($php, [$temp . '/check_tickets.php']), $temp);
    $newRequests = array_slice($readRequests(), $before);
    verify(count(array_filter($newRequests, static fn($request) => $request['path'] === '/search')) === 1, 'Different capacity policies should share one search');
    $lowTickets = array_values(array_filter($newRequests, static fn($request) => str_contains($request['payload']['text'] ?? '', 'بلیط موجود است')));
    verify(count($lowTickets) === 1 && $lowTickets[0]['payload']['chat_id'] === '303', 'Only the opted-in subscriber should see insufficient capacity');
    verify(str_contains($lowTickets[0]['payload']['text'], 'ظرفیت کمتر'), 'Partial capacity must be labelled');
    $keyboard = $lowTickets[0]['payload']['reply_markup'];
    verify(str_contains($keyboard, '2-0-0'), 'Partial-capacity purchase link must not request four seats when only two exist');
    verify(!str_contains($keyboard, 'reserve_list_33_'), 'Oversized passenger lists must not be offered');
    verify(str_contains($keyboard, 'reserve_list_34_'), 'An infant without a seat must not consume seat capacity');

    $db = new SQLite3($temp . '/users.db');
    $db->exec("UPDATE user_trips SET count=1, allow_partial_capacity=0, no_counting_notif=0");
    $stmt = $db->prepare('UPDATE user_trips SET search_filters=:filters WHERE chat_id=:chat');
    foreach (['101'=>['companies'=>['فدک']], '202'=>['companies'=>['رجا']], '303'=>['min_price'=>1000001]] as $chat=>$filters) {
        $stmt->bindValue(':chat', (string) $chat, SQLITE3_TEXT);
        $stmt->bindValue(':filters', json_encode($filters, JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
        $stmt->execute();
    }
    unset($stmt);
    $db->close();
    $before = count($readRequests());
    runPhp(array_merge($php, [$temp . '/check_tickets.php']), $temp);
    $newRequests = array_slice($readRequests(), $before);
    verify(count(array_filter($newRequests, static fn($request) => $request['path'] === '/search')) === 1, 'Different local filters should share one search');
    $filteredTickets = array_values(array_filter($newRequests, static fn($request) => str_contains($request['payload']['text'] ?? '', 'بلیط موجود است')));
    verify(count($filteredTickets) === 1 && $filteredTickets[0]['payload']['chat_id'] === '101', 'Company and price filters must remain independent');

    // Exercise the user-facing setup, callbacks, validation and editing ownership.
    file_put_contents($temp . '/preferences.php', <<<'PHP'
<?php
define('TRAIN_TICKET_BOT_LIBRARY_ONLY', true);
require __DIR__ . '/bot.php';
function check($ok) { if (!$ok) throw new RuntimeException('Preference workflow failed'); }
function click($chat, $data) { handleCallbackQuery(['id'=>'test-callback','message'=>['chat'=>['id'=>$chat],'message_id'=>1],'data'=>$data]); }
$future = (new DateTimeImmutable('tomorrow', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
setUserState('505', 'SET_TRIP_COUNT', ['route'=>'tehran-mashhad','date'=>$future,'return_date'=>$future]);
handleSetTripCount('505', '0');
check(getUserState('505')['current_state'] === 'SET_TRIP_COUNT');
handleSetTripCount('505', '۴');
check(getUserState('505')['current_state'] === 'SET_TRIP_CAPACITY');
click('505', 'partial_capacity_1');
check(getUserState('505')['temp_data']['allow_partial_capacity'] === 1);
click('505', 'trip_type_0');
click('505', 'coupe_0');
check(getUserState('505')['current_state'] === 'SET_TRIP_FILTERS');
click('505', 'trip_filter_type_compartment_4');
click('505', 'trip_filter_companies');
handleTripFilterInput('505', 'فدک، بن ریل');
click('505', 'trip_filter_time');
handleTripFilterInput('505', '25:00-18:00');
check(getUserState('505')['current_state'] === 'SET_TRIP_FILTER_TIME');
handleTripFilterInput('505', '۰۸:۰۰-۱۸:۰۰');
click('505', 'trip_filter_price');
handleTripFilterInput('505', '۵۰۰,۰۰۰-۲,۰۰۰,۰۰۰');
click('505', 'trip_filter_save');
check(getUserState('505') === null);
$initial = getUserTrips('505');
check(count($initial)===1 && $initial[0]['follow_mode']==='all');
click('505', 'train_follow_native_' . $initial[0]['id']);
check(getUserState('505')['current_state'] === 'EDIT_TRAIN_FOLLOWS');
$draft = getUserState('505')['temp_data'];
$nonce = $draft['nonce'];
click('505', 'tf_' . $nonce . '_none');
click('505', 'tf_' . $nonce . '_toggle_0');
click('505', 'tf_' . $nonce . '_save');
check(getUserState('505') === null);
$trips = getUserTrips('505');
check(count($trips) === 1 && $trips[0]['count'] === 4 && $trips[0]['allow_partial_capacity'] === 1);
$filters = tripFilters($trips[0]);
check($filters['companies'] === ['فدک','بن ریل'] && $filters['train_types'] === ['compartment_4']);
check($filters['departure_from'] === '08:00' && $filters['max_price'] === 2000000);
click('505', 'trip_filter_save');
check(count(getUserTrips('505')) === 1); // Stale save button cannot duplicate a trip.
click('505', 'trip_type_2');
check(getUserState('505') === null); // Old setup keyboards must not restart a draft.
click('606', 'trip_settings_' . $trips[0]['id']);
check(getUserState('606') === null);
click('505', 'trip_settings_' . $trips[0]['id']);
click('505', 'trip_filter_partial');
click('505', 'trip_filter_reset');
click('505', 'trip_filter_save');
$edited = getUserTrips('505');
check(count($edited) === 1 && $edited[0]['allow_partial_capacity'] === 0 && tripFilters($edited[0]) === []);
// Wheelchair is obtained from the passenger record and sent only at reservation.
$passengers = getTravelersFromList(11, '101');
check($passengers[0]['wheelchair'] === 1);
$passengers[0]['food_id'] = '0';
$response = makeReservation('ticket-1', $passengers, ['fullName'=>'Test','email'=>'test@example.test','mobileNumber'=>'9000000000'], 0);
check($response['rsid'] === 'mock-reservation');
// Long trip lists must remain within Telegram's message limit.
for ($i=0; $i<20; $i++) {
    saveUserTrip('707', ['route'=>'tehran-mashhad','date'=>$future,'return_date'=>$future,'count'=>1,'type'=>0,'coupe'=>0,'filter'=>0]);
}
handleShowTripsCommand('707');
PHP);
    runPhp(array_merge($php, [$temp . '/preferences.php']), $temp);
    $reservations = array_values(array_filter($readRequests(), static fn($request) => $request['path'] === '/train/reservation/ticket-1/0'));
    verify(count($reservations) === 1 && $reservations[0]['payload']['passengers'][0]['isWheelchairOrdered'] === true, 'Wheelchair choice must survive through the reservation payload');
    $tripLists = array_values(array_filter($readRequests(), static fn($request) => ($request['payload']['chat_id'] ?? '') === '707'));
    verify(count($tripLists) > 1, 'Long trip list should be split into readable messages');
    $settingsCount = 0;
    foreach ($tripLists as $list) {
        verify(preg_match_all('/./us', $list['payload']['text']) <= 4096, 'Trip list exceeds Telegram message limit');
        verify(!isset($list['payload']['parse_mode']), 'Filter summaries should be sent as plain text');
        $settingsCount += substr_count($list['payload']['reply_markup'], 'trip_settings_');
    }
    verify($settingsCount === 20, 'Every listed trip needs its own settings button');

    file_put_contents($temp . '/follow-scenario.php', <<<'PHP'
<?php
define('TRAIN_TICKET_BOT_LIBRARY_ONLY', true);
require __DIR__ . '/bot.php';
$db = initDatabase();
$db->exec("UPDATE user_trips SET follow_mode='none'");
$db->exec("UPDATE user_trips SET follow_mode='all', search_filters='{}', count=2, allow_partial_capacity=0 WHERE id=1");
$trip = ownedFollowTrip($db, 1, '101');
$base = ['from_title'=>'تهران','to_title'=>'مشهد','wagon_name'=>'فدک','owner_name'=>'فدک','owner'=>25,
    'wagon_type'=>503,'source'=>'raja','jdate_fa'=>'1405-08-20','cost'=>1000000,'cost_title'=>'10000000',
    'iscompartment'=>1,'compartment_capacity'=>4];
$tickets = [
    $base + ['id'=>'train-a','train_number'=>'۱۸۸','train_number_en'=>188,'time'=>'۱۱:۴۰','counting_en'=>0,'counting'=>'۰'],
    $base + ['id'=>'train-b','train_number'=>'۱۸۹','train_number_en'=>189,'time'=>'۱۵:۲۰','counting_en'=>10,'counting'=>'۱۰'],
];
storeTrainCatalog($db, TicketSearchBatch::payload($trip), $tickets);
file_put_contents(__DIR__.'/override-search.json', json_encode(['data'=>['status'=>'success','data'=>['departure'=>$tickets]]], JSON_UNESCAPED_UNICODE));
PHP);
    runPhp(array_merge($php, [$temp . '/follow-scenario.php']), $temp);
    $authFor = static function (int $id, int $age = 0): string {
        $fields = ['auth_date'=>(string) (time()-$age), 'query_id'=>'mock-query', 'user'=>json_encode(['id'=>$id,'first_name'=>'Test'])];
        ksort($fields, SORT_STRING);
        $check = [];
        foreach ($fields as $key=>$value) $check[] = "$key=$value";
        $fields['hash'] = hash_hmac('sha256', implode("\n", $check), hash_hmac('sha256','TEST','WebAppData',true));
        return http_build_query($fields);
    };
    // Keep the app server separate so real outbound cURL can reach the mock API.
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errorCode,$errorMessage);
    $appAddress=stream_socket_get_name($socket,false);fclose($socket);$appBase='http://'.$appAddress;
    $appServer=proc_open(array_merge($php,['-S',$appAddress,$temp.'/router.php']),
        [0=>['pipe','r'],1=>['file',$temp.'/app-server.log','a'],2=>['file',$temp.'/app-server.log','a']],$appPipes,$temp);
    fclose($appPipes[0]);[$appHost,$appPort]=explode(':',$appAddress);$ready=false;
    for($attempt=0;$attempt<50;$attempt++){
        $probe=@fsockopen($appHost,(int)$appPort,$errorCode,$errorMessage,0.1);
        if($probe){fclose($probe);$ready=true;break;}usleep(100000);
    }
    verify($ready,'App test server did not start');
    $api = static function (array $body, string $entry = 'telegram-alerts.php') use ($appBase): array {
        $curl = curl_init($appBase . '/' . $entry . '?api=1');
        curl_setopt_array($curl, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8]);
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        return [$status, json_decode($response, true)];
    };
    $auth = $authFor(101);
    $before = count($readRequests());
    [$status, $loaded] = $api(['init_data'=>$auth,'trip_id'=>1]);
    verify($status === 200 && count(array_filter($loaded['data']['options'] ?? [], static fn($option) => !$option['missing'])) === 2,
        'Mini app must expose the complete owned catalog (HTTP ' . $status . ')');
    verify($loaded['data']['options'][0]['capacity'] === 0, 'Sold-out services must remain selectable');
    verify(count($readRequests()) === $before, 'Opening the mini app should reuse the shared catalog');
    verify($api(['init_data'=>'fake','trip_id'=>1])[0] === 401, 'Forged mini app authentication must fail');
    verify($api(['init_data'=>$authFor(101,3601),'trip_id'=>1])[0] === 401, 'Expired mini app sessions must fail');
    verify($api(['init_data'=>$authFor(202),'trip_id'=>1])[0] === 404, 'Users cannot inspect another user trip');
    $first = $loaded['data']['options'][0]['key'];
    $second = $loaded['data']['options'][1]['key'];
    [$status, $saved] = $api(['action'=>'save','init_data'=>$auth,'trip_id'=>1,'mode'=>'selected','keys'=>[$first],'revision'=>0]);
    verify($status === 200 && $saved['data']['mode'] === 'selected', 'Mini app must save a sold-out followed service');
    verify($api(['action'=>'save','init_data'=>$auth,'trip_id'=>1,'mode'=>'all','keys'=>[],'revision'=>0])[0] === 409, 'Concurrent stale edits must fail');
    $newMessages = static function (int $after) use ($readRequests): array {
        return array_values(array_filter(array_slice($readRequests(), $after), static fn($request) => $request['path'] === '/botTEST/sendMessage'));
    };
    $before = count($readRequests());
    runPhp(array_merge($php, [$temp . '/check_tickets.php']), $temp);
    verify(count($newMessages($before)) === 0, 'Unselected available trains and generic unavailability must stay quiet');
    $response = json_decode(file_get_contents($temp . '/override-search.json'), true);
    $response['data']['data']['departure'][0]['counting_en'] = 2;
    $response['data']['data']['departure'][0]['counting'] = '۲';
    file_put_contents($temp . '/override-search.json', json_encode($response));
    $before = count($readRequests());
    runPhp(array_merge($php, [$temp . '/check_tickets.php']), $temp);
    $opened = $newMessages($before);
    verify(count($opened) === 1 && str_contains($opened[0]['payload']['text'], '۱۸۸'), 'Opening a selected service should send its ticket without repeating the old trip hint');
    $before = count($readRequests());
    runPhp(array_merge($php, [$temp . '/check_tickets.php']), $temp);
    verify(count($newMessages($before)) === 0, 'Steady availability must not repeat alerts');
    $response['data']['data']['departure'][0]['id'] = 'replacement-id';
    file_put_contents($temp . '/override-search.json', json_encode($response));
    $before = count($readRequests());
    runPhp(array_merge($php, [$temp . '/check_tickets.php']), $temp);
    verify(count($newMessages($before)) === 0, 'Changing an inventory ID must not lose the follow or duplicate the alert');
    file_put_contents($temp . '/override-search.json', '{"data":{"status":"raja_backup"}}');
    $before = count($readRequests());
    runPhp(array_merge($php, [$temp . '/check_tickets.php']), $temp, 'Upstream train search unavailable');
    verify(count($newMessages($before)) === 0, 'Upstream outages should not spam selected-train subscribers');
    file_put_contents($temp . '/override-search.json', json_encode($response));
    $before = count($readRequests());
    runPhp(array_merge($php, [$temp . '/check_tickets.php']), $temp);
    verify(count($newMessages($before)) === 0, 'An outage must not re-arm a steady available service');
    $response['data']['data']['departure'][0]['counting_en'] = 0;
    file_put_contents($temp . '/override-search.json', json_encode($response));
    runPhp(array_merge($php, [$temp . '/check_tickets.php']), $temp);
    $response['data']['data']['departure'][0]['counting_en'] = 2;
    file_put_contents($temp . '/override-search.json', json_encode($response));
    $before = count($readRequests());
    runPhp(array_merge($php, [$temp . '/check_tickets.php']), $temp);
    verify(count($newMessages($before)) === 1, 'A later reopening must notify again without repeating the trip hint');
    [$status, $paused] = $api(['action'=>'save','init_data'=>$auth,'trip_id'=>1,'mode'=>'selected','keys'=>[],'revision'=>1]);
    verify($status === 200 && $paused['data']['mode'] === 'none', 'Clearing all choices must pause this trip');
    $before = count($readRequests());
    runPhp(array_merge($php, [$temp . '/check_tickets.php']), $temp);
    verify(count($readRequests()) === $before, 'Paused trips should generate neither searches nor alerts');
    [$status, $changed] = $api(['action'=>'save','init_data'=>$auth,'trip_id'=>1,'mode'=>'selected','keys'=>[$second],'revision'=>2]);
    verify($status === 200 && $changed['data']['selected'] === [$second], 'Users must be able to remove a train and follow another later');
    $before = count($readRequests());
    runPhp(array_merge($php, [$temp . '/check_tickets.php']), $temp);
    $opened = $newMessages($before);
    verify(count($opened) === 1 && str_contains($opened[0]['payload']['text'], '۱۸۹'), 'Only the newly followed train should notify');

    [$status] = $api(['action'=>'save','init_data'=>$auth,'trip_id'=>1,'mode'=>'all','keys'=>[],'revision'=>3]);
    verify($status===200,'All-trains mode should be selectable');
    $before=count($readRequests());
    runPhp(array_merge($php,[$temp.'/check_tickets.php']),$temp);
    $batch=$newMessages($before);
    $ticketMessages=array_values(array_filter($batch,static fn($message)=>str_contains($message['payload']['text'],'بلیط موجود است')));
    verify(count($ticketMessages)===1 && count($batch)===1 && str_contains($ticketMessages[0]['payload']['text'],'۱۸۸'),'Switching to all keeps existing alerts quiet and only enables the newly followed train');
    verify(str_contains($batch[0]['payload']['reply_markup'],'train_follow_1'),'Ticket buttons still open the selector for the same trip');
    $firstButtons=array_merge(...json_decode($ticketMessages[0]['payload']['reply_markup'],true)['inline_keyboard']);
    $mute=array_values(array_filter($firstButtons,static fn($button)=>str_starts_with($button['callback_data'] ?? '', 'train_mute_')))[0]['callback_data'];
    file_put_contents($temp.'/message-controls.php', <<<'PHP'
<?php
define('TRAIN_TICKET_BOT_LIBRARY_ONLY',true);
require __DIR__.'/bot.php';
handleCallbackQuery(['id'=>'test-control','message'=>['chat'=>['id'=>$argv[1]],'message_id'=>20],'data'=>$argv[2]]);
PHP);
    runPhp(array_merge($php,[$temp.'/message-controls.php','202',$mute]),$temp);
    verify($api(['init_data'=>$auth,'trip_id'=>1])[1]['data']['mode']==='all','Another user cannot mute this trip');
    $before=count($readRequests());
    runPhp(array_merge($php,[$temp.'/message-controls.php','101',$mute]),$temp);
    $confirmation=$newMessages($before);
    $restoreButtons=array_merge(...json_decode($confirmation[0]['payload']['reply_markup'],true)['inline_keyboard']);
    $restore=array_values(array_filter($restoreButtons,static fn($button)=>str_starts_with($button['callback_data'] ?? '', 'train_restore_')))[0]['callback_data'];
    $settings=$api(['init_data'=>$auth,'trip_id'=>1])[1]['data'];
    verify($settings['mode']==='except' && count($settings['excluded'])===1,'Ticket mute must be reflected in mini app settings');
    $newService=$response['data']['data']['departure'][1];
    $newService['id']='train-c';$newService['train_number']='۱۹۰';$newService['train_number_en']=190;$newService['time']='17:00';
    $response['data']['data']['departure'][]=$newService;
    file_put_contents($temp.'/override-search.json',json_encode($response));
    $before=count($readRequests());
    runPhp(array_merge($php,[$temp.'/check_tickets.php']),$temp);
    $remaining=array_values(array_filter($newMessages($before),static fn($message)=>str_contains($message['payload']['text'],'بلیط موجود است')));
    verify(count($remaining)===1 && str_contains($remaining[0]['payload']['text'],'۱۹۰'),
        'Muting A must keep B quiet in the same state and notify the newly discovered C');
    verify(str_contains($remaining[0]['payload']['text'],'۱۹۰'),'A newly discovered unattempted service gets a turn before already-attempted services');
    runPhp(array_merge($php,[$temp.'/message-controls.php','101',$restore]),$temp);
    verify($api(['init_data'=>$auth,'trip_id'=>1])[1]['data']['mode']==='all','Restore action should re-enable the muted train');
    $before=count($readRequests());
    runPhp(array_merge($php,[$temp.'/check_tickets.php']),$temp);
    $restored=array_values(array_filter($newMessages($before),static fn($message)=>str_contains($message['payload']['text'],'بلیط موجود است')));
    verify(count($restored)===1 && str_contains($restored[0]['payload']['text'],'۱۸۸'),'Restoring A must not re-arm unchanged B and C');
    $app = static fn(array $body) => $api($body, 'telegram-app.php');
    [$status,$bootstrap] = $app(['init_data'=>$auth,'action'=>'bootstrap']);
    verify($status===200 && count($bootstrap['data']['cities'])===36 && count($bootstrap['data']['trips'])===1,'Unified app loads owned trips and local stations');
    verify($app(['init_data'=>'fake','action'=>'bootstrap'])[0]===401,'Unified app rejects forged auth');
    $input=['origin'=>'tehran','destination'=>'mashhad','date'=>'1490-01-01','count'=>2,'type'=>0,'coupe'=>false,'allow_partial_capacity'=>false,'search_filters'=>['train_types'=>['compartment_4']]];
    $create=['init_data'=>$auth,'action'=>'create_trip','request_id'=>str_repeat('x',32),'trip'=>$input];
    $before=count($readRequests());
    [$status,$new] = $app($create);
    verify($status===200 && $new['data']['created']===true && $new['data']['trip']['mode']==='all','Complete creation defaults to all trains');
    verify(count($readRequests())===$before,'Creation is acknowledged without waiting for the initial search');
    $id=$new['data']['trip']['id'];
    $initial=['init_data'=>$auth,'action'=>'initial_check','trip_id'=>$id];
    [$status,$checked]=$app($initial);
    verify($status===200 && $checked['data']['state']==='ready' && $checked['data']['source']==='live' && $checked['data']['sent_count']===3,'A new trip immediately searches missing inventory and sends matching tickets');
    $sent=$newMessages($before);
    verify(count($sent)===4 && count(array_filter($sent,static fn($r)=>str_contains($r['payload']['text'],'بلیط موجود است')))===3,'Initial results include per-ticket reservation/mute controls and one management hint');
    $before=count($readRequests());
    verify($app($initial)[1]['data']['source']==='history','Ambiguous first-check retries reuse the recorded outcome');
    verify(count($readRequests())===$before,'An initial-check retry does not search or resend accepted tickets');
    $foreignInitial=$initial;$foreignInitial['init_data']=$authFor(202);
    verify($app($foreignInitial)[0]===404,'Initial results are scoped to the signed trip owner');
    $duplicate=$create;$duplicate['request_id']=str_repeat('z',32);
    [$status,$same]=$app($duplicate);
    verify($status===200 && !$same['data']['created'] && $same['data']['trip']['id']===$id,'A new request key cannot create the same subscription twice');
    $shared=$create;$shared['request_id']=str_repeat('s',32);$shared['trip']['count']=3;
    $sharedId=$app($shared)[1]['data']['trip']['id'];$before=count($readRequests());
    [$status,$sharedCheck]=$app(['init_data'=>$auth,'action'=>'initial_check','trip_id'=>$sharedId]);
    verify($status===200 && $sharedCheck['data']['source']==='cache' && $sharedCheck['data']['sent_count']===2,'A second passenger count receives locally filtered results from fresh shared inventory');
    verify(count(array_filter(array_slice($readRequests(),$before),static fn($r)=>$r['path']==='/search'))===0,'Matching initial searches do not fan out upstream');
    $db=new SQLite3($temp.'/users.db');$db->exec('UPDATE user_trips SET follow_mode=\'none\' WHERE id='.(int)$sharedId);$db->close();
    $before=count($readRequests());runPhp(array_merge($php,[$temp.'/check_tickets.php']),$temp);
    verify(count($newMessages($before))===0,'The next fresh cron poll cannot repeat tickets accepted by the immediate check');
    $pending=$create;$pending['init_data']=$authFor(404);$pending['request_id']=str_repeat('p',32);
    $pendingId=$app($pending)[1]['data']['trip']['id'];$before=count($readRequests());
    $pendingCheck=$app(['init_data'=>$authFor(404),'action'=>'initial_check','trip_id'=>$pendingId]);
    verify($pendingCheck[0]===200 && $pendingCheck[1]['data']['state']==='inactive' && count($readRequests())===$before,'An unapproved account gets an immediate explanation without searches or bot sends');
    [$status,$retry]=$app($create);
    verify($status===200 && $retry['data']['created']===false && $retry['data']['trip']['id']===$id,'Retry returns original trip');
    $mismatch=$create;$mismatch['trip']['count']=3;
    verify($app($mismatch)[0]===409,'Reused request key with changed payload conflicts');
    $bad=$create;$bad['request_id']=str_repeat('b',32);$bad['trip']['date']='1404-12-30';
    verify($app($bad)[0]===400,'Invalid leap date is rejected');
    $preferences=['init_data'=>$auth,'action'=>'update_preferences','trip_id'=>$id,'revision'=>0,'allow_partial_capacity'=>true,'search_filters'=>['max_price'=>1000000]];
    $foreign=$preferences;$foreign['init_data']=$authFor(202);
    verify($app($foreign)[0]===404,'Foreign preference writes are rejected');
    [$status,$updated]=$app($preferences);
    verify($status===200 && $updated['data']['revision']===1 && $updated['data']['allow_partial_capacity']===true,'Local preferences update with revision');
    verify($app($preferences)[0]===409,'Stale preferences cannot overwrite newer edits');
    file_put_contents($temp.'/launch-app.php', <<<'PHP'
<?php
define('TRAIN_TICKET_BOT_LIBRARY_ONLY', true);
require __DIR__.'/bot.php';
$_ENV['ALERTS_WEB_APP_URL']='https://example.test/bot/telegram-alerts.php';
if (trainMiniAppUrl() !== 'https://example.test/bot/telegram-app.php?view=create'
    || followMiniAppUrl(1) !== 'https://example.test/bot/telegram-app.php?view=alerts&trip_id=1') {
    throw new RuntimeException('Legacy URL must resolve to the unified app');
}
if (isset(getMainMenuKeyboard(101)['keyboard'][0][0]['web_app'])) {
    throw new RuntimeException('Authenticated entry must not use a reply-keyboard web app');
}
handleSetTripCommand(101);
handleShowTripsCommand('707');
showTrainAlertTrips('707');
showUserTrips('707');
handleShowTripsCommand('808');
PHP);
    $before=count($readRequests());
    runPhp(array_merge($php,[$temp.'/launch-app.php']),$temp);
    $launcher=$newMessages($before);
    verify(count($launcher)===5 && str_contains($launcher[0]['payload']['reply_markup'],'telegram-app.php?view=create'),'Registration uses an inline authenticated launcher');
    foreach(array_slice($launcher,1,3) as $entry){
        $buttons=array_merge(...json_decode($entry['payload']['reply_markup'],true)['inline_keyboard']);
        verify(count($buttons)===2 && count(array_filter($buttons,static fn($button)=>isset($button['web_app'])))===2,'Twenty trips must produce one compact Mini App entry, not twenty chat cards');
        verify(preg_match_all('/./us',$entry['payload']['text'])<200 && !str_contains($entry['payload']['reply_markup'],'trip_settings_'),'Trip details must stay in the Mini App');
    }
    $emptyEntry=json_decode($launcher[4]['payload']['reply_markup'],true)['inline_keyboard'];
    verify(count($emptyEntry)===1 && str_contains($emptyEntry[0][0]['web_app']['url'],'view=create'),'Empty accounts get one first-trip action');
    $remove=['init_data'=>$auth,'action'=>'delete_trip','trip_id'=>$id,'revision'=>1];
    $foreignRemove=$remove;$foreignRemove['init_data']=$authFor(202);
    verify($app($foreignRemove)[0]===404,'Foreign trips cannot be deleted');
    $staleRemove=$remove;$staleRemove['revision']=0;
    verify($app($staleRemove)[0]===409,'Changed trips cannot be deleted from stale UI');
    [$status,$deleted]=$app($remove);
    verify($status===200 && $deleted['data']['deleted']===true,'Confirmed deletion removes the owned trip');
    verify($app(['init_data'=>$auth,'action'=>'load','trip_id'=>$id])[0]===404,'Removed trips cannot receive more alerts');
    echo "PASS Compact trips entry: one message for twenty trips, common alerts/list entry, empty state and owned confirmed deletion\n";
    // Reproduce a realistic long provider ID, a rejected Telegram send and a
    // falling capacity before retry. None may mark an undelivered alert as sent.
    file_put_contents($temp.'/delivery-scenario.php', <<<'PHP'
<?php
define('TRAIN_TICKET_BOT_LIBRARY_ONLY',true);
require __DIR__.'/bot.php';
$db=initDatabase();
$db->exec("UPDATE user_trips SET follow_mode='none' WHERE id<>1");
$db->exec("UPDATE user_trips SET count=2,allow_partial_capacity=1,search_filters='{}' WHERE id=1");
$trip=$db->querySingle('SELECT * FROM user_trips WHERE id=1',true);
$ticket=['id'=>str_repeat('long-provider-id-',10).'_tail','train_number'=>'۹۹۹','train_number_en'=>999,'time'=>'۱۰:۰۰',
    'from_title'=>'تهران','to_title'=>'مشهد','wagon_name'=>'نام [قطار_آزمایشی] *','jdate_fa'=>'1405-08-20','counting_en'=>3,'counting'=>'۳',
    'cost_title'=>'10000000','cost'=>1000000,'owner_name'=>'فدک','owner'=>25,'wagon_type'=>503,'source'=>'raja','iscompartment'=>1,'compartment_capacity'=>4];
storeTrainCatalog($db,TicketSearchBatch::payload($trip),[$ticket]);
saveTrainFollows($db,1,'101','selected',[trainFollowKey($ticket)],(int)$trip['follow_revision']);
file_put_contents(__DIR__.'/override-search.json',json_encode(['data'=>['status'=>'success','data'=>['departure'=>[$ticket]]]],JSON_UNESCAPED_UNICODE));
PHP);
    runPhp(array_merge($php,[$temp.'/delivery-scenario.php']),$temp);
    file_put_contents($temp.'/telegram-error.json',json_encode(['chat_id'=>'101','error_code'=>429,'description'=>'Too Many Requests','parameters'=>['retry_after'=>30]]));
    $before=count($readRequests());
    runPhp(array_merge($php,[$temp.'/check_tickets.php']),$temp,'Telegram delivery:');
    $failed=$newMessages($before);
    verify(count($failed)===1,'One rate-limit rejection must not fan out into more sends');
    verify(!isset($failed[0]['payload']['parse_mode']),'Provider text must not be parsed as Markdown');
    verify(str_contains($failed[0]['payload']['text'],'[قطار_آزمایشی] *'),'Provider punctuation must remain literal text');
    $buttons=array_merge(...json_decode($failed[0]['payload']['reply_markup'],true)['inline_keyboard']);
    $tokens=array_values(array_filter($buttons,static fn($b)=>str_starts_with($b['callback_data']??'','reserve_token_')));
    verify(count($tokens)===1 && strlen($tokens[0]['callback_data'])<=64,'Long provider IDs must produce usable short reservation buttons');
    $db=new SQLite3($temp.'/users.db');
    verify((int)$db->querySingle('SELECT pending FROM train_follow_delivery WHERE trip_id=1')===1,'Failed delivery remains pending');
    verify((int)$db->querySingle("SELECT COUNT(*) FROM telegram_ticket_delivery WHERE trip_id=1 AND error_code=429")===1,'Telegram rejection is recorded');
    $db->close();
    $before=count($readRequests());
    runPhp(array_merge($php,[$temp.'/check_tickets.php']),$temp);
    verify(count($newMessages($before))===0,'A durable cooldown avoids hammering Telegram on the next cron');
    $db=new SQLite3($temp.'/users.db');$db->exec('UPDATE telegram_send_limits SET not_before=0');$db->close();
    unlink($temp.'/telegram-error.json');
    $inventory=json_decode(file_get_contents($temp.'/override-search.json'),true);$inventory['data']['data']['departure'][0]['counting_en']=1;$inventory['data']['data']['departure'][0]['counting']='۱';
    file_put_contents($temp.'/override-search.json',json_encode($inventory,JSON_UNESCAPED_UNICODE));
    $before=count($readRequests());
    runPhp(array_merge($php,[$temp.'/check_tickets.php']),$temp);
    verify(count($newMessages($before))===1,'An undelivered alert retries at still-allowed partial capacity without repeating the trip hint');
    [$status,$diagnosticPicker]=$app(['init_data'=>$auth,'action'=>'load','trip_id'=>1]);
    verify($status===200 && $diagnosticPicker['data']['options'][0]['eligible']===true,'Mini App explains eligibility for the exact user trip');
    verify($diagnosticPicker['data']['options'][0]['last_sent_at']!==null && $diagnosticPicker['data']['options'][0]['send_error_code']===0,'Mini App distinguishes successful Telegram acceptance');
    verify($diagnosticPicker['data']['last_poll']['sent_count']===1 && $diagnosticPicker['data']['worker']['finished_at']!==null,'Cron and per-trip delivery checkpoints are available');
    $before=count($readRequests());
    runPhp(array_merge($php,[$temp.'/check_tickets.php']),$temp);
    verify(count($newMessages($before))===0,'A successfully delivered selected train stays quiet in a steady state');
    $process=proc_open(array_merge($php,[$temp.'/diagnose_notifications.php','--trip=1']),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$temp);
    fclose($pipes[0]);$report=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    verify(proc_close($process)===0 && $errors==='' && json_decode($report,true)['trip']['trip_id']===1,'Read-only diagnostics must work without sending messages');
    verify(!str_contains($report,'BOT_TOKEN') && !str_contains($report,'chat_id'),'Diagnostics must not expose credentials or chat identifiers');
    $db=new SQLite3($temp.'/users.db');$db->exec("UPDATE user_trips SET follow_mode='all',follow_revision=follow_revision+1,no_ticket_notif=0 WHERE id=1");$db->close();
    file_put_contents($temp.'/override-search.json','{"data":{"status":"success","data":{"departure":[]}}}');
    file_put_contents($temp.'/telegram-error.json',json_encode(['chat_id'=>'101','error_code'=>429,'description'=>'Too Many Requests','parameters'=>['retry_after'=>30]]));
    runPhp(array_merge($php,[$temp.'/check_tickets.php']),$temp,'Telegram delivery:');
    $db=new SQLite3($temp.'/users.db');
    verify((int)$db->querySingle('SELECT no_ticket_notif FROM user_trips WHERE id=1')===0,'Rejected no-ticket notices must not be permanently suppressed');
    $db->exec('UPDATE telegram_send_limits SET not_before=0');$db->close();unlink($temp.'/telegram-error.json');
    runPhp(array_merge($php,[$temp.'/check_tickets.php']),$temp);
    $db=new SQLite3($temp.'/users.db');verify((int)$db->querySingle('SELECT no_ticket_notif FROM user_trips WHERE id=1')===1,'Successful notice sets its suppression flag');$db->close();
    $picker=$app(['init_data'=>$auth,'action'=>'load','trip_id'=>1])[1]['data'];
    $switchKey=$picker['options'][0]['key'];
    $switch=['init_data'=>$auth,'action'=>'set_train','trip_id'=>1,'train_key'=>$switchKey,'enabled'=>false];
    $foreignSwitch=$switch;$foreignSwitch['init_data']=$authFor(202);
    verify($app($foreignSwitch)[0]===404,'Switches enforce trip ownership');
    $invalidSwitch=$switch;$invalidSwitch['enabled']=0;
    verify($app($invalidSwitch)[0]===400,'Switches require a real boolean state');
    $invalidSwitch=$switch;$invalidSwitch['train_key']=substr($switchKey,0,24).str_repeat('f',40);
    verify($app($invalidSwitch)[0]===400,'A matching prefix cannot substitute for a known full train key');
    $before=count($readRequests());
    [$status,$off]=$app($switch);
    verify($status===200 && $off['data']['mode']==='except' && in_array($switchKey,$off['data']['excluded'],true),'One switch immediately disables only its service');
    [$status,$sameOff]=$app($switch);
    verify($status===200 && $sameOff['data']['revision']===$off['data']['revision'],'A retried switch is idempotent');
    $switch['enabled']=true;
    [$status,$on]=$app($switch);
    verify($status===200 && $on['data']['mode']==='all','Turning the service back on preserves all/future-train semantics');
    verify(count($readRequests())===$before,'Switch changes must not refetch upstream inventory or send messages');
    // A malformed optional passenger list must never prevent available-ticket alerts.
    $inventory['data']['data']['departure'][0]['counting_en']=10;
    $inventory['data']['data']['departure'][0]['counting']='۱۰';
    file_put_contents($temp.'/override-search.json',json_encode($inventory,JSON_UNESCAPED_UNICODE));
    $db=new SQLite3($temp.'/users.db');$db->exec("UPDATE traveler_lists SET members='1' WHERE id=11");$db->close();
    $before=count($readRequests());
    runPhp(array_merge($php,[$temp.'/check_tickets.php']),$temp);
    $sent=$newMessages($before);
    verify(count(array_filter($sent,static fn($r)=>str_contains($r['payload']['text'],'بلیط موجود است')&&(string)$r['payload']['chat_id']==='101'))===1,'Malformed passenger lists cannot swallow the ticket message or purchase button');
    foreach($sent as $message) {
        if((string)$message['payload']['chat_id']==='101' && str_contains($message['payload']['text'],'بلیط موجود است')) {
            $buttons=array_merge(...json_decode($message['payload']['reply_markup'],true)['inline_keyboard']);
            verify(count(array_filter($buttons,static fn($b)=>str_starts_with($b['callback_data']??'','reserve_')))===0,'Invalid passenger lists cannot create zero-passenger reservation buttons');
        }
    }
    file_put_contents($temp.'/empty-list-management.php', <<<'PHP'
<?php
define('TRAIN_TICKET_BOT_LIBRARY_ONLY',true);require __DIR__.'/bot.php';
$lists=listTravelerLists('101');if(count($lists)!==1 || $lists[0]['member_count']!==0)throw new RuntimeException('Empty lists must remain accessible for editing');
PHP);
    runPhp(array_merge($php,[$temp.'/empty-list-management.php']),$temp);
    // Reporting uses signed ownership and reads stored status, never send/fetch APIs.
    $before=count($readRequests());
    [$status,$report]=$app(['init_data'=>$auth,'action'=>'diagnostics','trip_id'=>1]);
    verify($status===200 && $report['data']['cron']['stage']==='finished','Web report includes the CLI checkpoint without shell access');
    verify($report['data']['cron']['sqlite'] && $report['data']['cron']['curl'],'CLI extensions are reported separately from web PHP');
    verify(count($readRequests())===$before,'Reading diagnostics cannot fetch inventory or send Telegram messages');
    verify(!str_contains(json_encode($report),'chat_id') && !str_contains(json_encode($report),'BOT_TOKEN'),'Web report excludes identifiers and credentials');
    verify($app(['init_data'=>$authFor(202),'action'=>'diagnostics','trip_id'=>1])[0]===404,'Diagnostics enforce trip ownership');
    verify($app(['init_data'=>'invalid','action'=>'diagnostics','trip_id'=>1])[0]===401,'Diagnostics cannot be accessed with an unsigned browser request');
    verify($publicGet('/ticket-worker-health.php')[0]!==200,'Runtime health checkpoints are not public endpoints');
    // The second historical schema has a membership relation, not JSON members.
    $db=new SQLite3($temp.'/users.db');
    $db->exec('CREATE TABLE IF NOT EXISTS traveler_list_members(list_id INTEGER,traveler_id INTEGER)');
    $db->exec('INSERT INTO traveler_list_members VALUES(11,1),(11,6),(22,6)');
    $db->exec('ALTER TABLE traveler_lists DROP COLUMN members');$db->close();
    $picker=$app(['init_data'=>$auth,'action'=>'load','trip_id'=>1])[1]['data'];
    $switch['train_key']=array_values(array_filter($picker['options'],static fn($option)=>$option['eligible']))[0]['key'];
    $switch['enabled']=false;$app($switch);$switch['enabled']=true;$app($switch);
    $before=count($readRequests());runPhp(array_merge($php,[$temp.'/check_tickets.php']),$temp);
    $sent=array_values(array_filter($newMessages($before),static fn($r)=>str_contains($r['payload']['text'],'بلیط موجود است')&&(string)$r['payload']['chat_id']==='101'));
    verify(count($sent)===1 && str_contains($sent[0]['payload']['reply_markup'],'reserve_'),'Legacy list schema cannot block alerts and retains owned reservation buttons');
    file_put_contents($temp.'/reservation-result.php', <<<'PHP'
<?php
define('TRAIN_TICKET_BOT_LIBRARY_ONLY',true);require __DIR__.'/bot.php';
$db=initDatabase();$db->exec("INSERT INTO private_info(chat_id,person_name,phone_number,email) VALUES('101','Test Person','9123456789','test@example.test')");
$db->exec('DROP TABLE IF EXISTS temporary_food_selections');
saveReservationSession('101',['list_id'=>11,'ticket_id'=>'ticket-1','coupe'=>0]);
completeReservation('101');
PHP);
    touch($temp.'/reservation-failure');$before=count($readRequests());
    runPhp(array_merge($php,[$temp.'/reservation-result.php']),$temp);
    $sent=$newMessages($before);verify(count($sent)===1 && empty($sent[0]['payload']['reply_markup']),'Failed reservations still send an explanation and never invent a payment button');
    file_put_contents($temp.'/invalid-reservation.php', <<<'PHP'
<?php
define('TRAIN_TICKET_BOT_LIBRARY_ONLY',true);require __DIR__.'/bot.php';handleListReservation('reserve_token_'.str_repeat('f',32),'101');
PHP);
    $before=count($readRequests());runPhp(array_merge($php,[$temp.'/invalid-reservation.php']),$temp);
    verify(count($newMessages($before))===1,'Expired reservation callbacks must send an explanation, not silently return a string');
    $process=proc_open([PHP_BINARY,'-n',$temp.'/check_tickets.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$temp);
    fclose($pipes[0]);stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    verify(proc_close($process)===1 && str_contains($errors,'Ticket check failed:'),'Reproduce cron PHP without SQLite');
    [$status,$report]=$app(['init_data'=>$auth,'action'=>'diagnostics','trip_id'=>1]);
    verify($status===200 && in_array('cron_sqlite_missing',$report['data']['issues'],true) && in_array('worker_failed',$report['data']['issues'],true),'Startup failure must remain readable from web PHP without shell access');
    $publicHealth=$publicGet('/ticket-worker-health.php');verify($publicHealth[0]===404 && $publicHealth[1]==='','Checkpoint guard must hide runtime data even when requested directly');
    $before=count($readRequests());$workerBefore=file_get_contents($temp.'/ticket-worker-health.php');
    verify($publicGet('/cron_probe.php')[0]===403 && !is_file($temp.'/ticket-cron-probe-result.php'),'HTTP cannot trigger a local cron probe');
    $process=proc_open(array_merge($php,[$temp.'/cron_probe.php']),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$temp);
    fclose($pipes[0]);$probeOutput=stream_get_contents($pipes[1]);$probeErrors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    verify(proc_close($process)===0 && $probeErrors==='' && str_contains($probeOutput,'Probe result saved.'),'Script-only cron probe must record the runtime');
    [$status,$report]=$app(['init_data'=>$auth,'action'=>'diagnostics','trip_id'=>1]);
    verify($status===200 && $report['data']['probe']['sapi']==='cli' && $report['data']['probe']['php_version']===PHP_VERSION,'Probe runtime is readable from the signed Mini App report');
    verify($report['data']['probe']['sqlite'] && $report['data']['probe']['curl'] && $report['data']['probe']['worker_file_exists'],'Probe checks the cron extensions and worker file');
    verify(count($readRequests())===$before && file_get_contents($temp.'/ticket-worker-health.php')===$workerBefore,'A probe cannot fetch inventory, send messages or pretend the ticket worker ran');
    $publicProbe=$publicGet('/ticket-cron-probe-result.php');verify($publicProbe[0]===404 && $publicProbe[1]==='','Probe runtime information is not publicly readable');
    foreach(['/check_tickets.php','/runFetchTickets.php','/send_queue.php'] as $entry) {
        verify($publicGet($entry)[0]===403,'Cron entry points must remain inaccessible over HTTP: '.$entry);
    }
    $cgiBinary=dirname(PHP_BINARY).'/php-cgi'.(PHP_OS_FAMILY==='Windows'?'.exe':'');
    if (is_file($cgiBinary)) {
        $cgi=[$cgiBinary,'-n','-d','extension_dir='.ini_get('extension_dir'),'-d','extension=sqlite3','-d','extension=curl'];
        $runCgi=static function(string $entry,?array $environment=null)use($cgi,$temp):array {
            $command=array_merge($cgi,[$temp.'/'.$entry]);
            if(isset($environment['REQUEST_METHOD'])) {
                $command=$cgi;$environment['SCRIPT_FILENAME']=$temp.'/'.$entry;$environment['SCRIPT_NAME']='/'.$entry;
            }
            $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$temp,$environment);
            fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            return [proc_close($process),$output,$errors];
        };
        $switch['enabled']=false;$app($switch);$switch['enabled']=true;$app($switch);
        $before=count($readRequests());
        [$exit,$output,$errors]=$runCgi('check_tickets.php');
        verify($exit===0 && $errors==='' && !str_contains($output,'403') && !str_contains($output,'Fatal'),'Actual local CGI worker must reach ticket processing');
        $cgiHealth=json_decode(substr(file_get_contents($temp.'/ticket-worker-health.php'),strlen("<?php http_response_code(404); exit; ?>\n")),true);
        verify($cgiHealth['sapi']==='cgi-fcgi' && $cgiHealth['stage']==='finished' && $cgiHealth['error']===null,'Actual CGI execution must record a successful worker checkpoint');
        verify(count($newMessages($before))>=1,'CGI worker must send an eligible re-enabled ticket with reservation buttons');
        file_put_contents($temp.'/seed-queue.php', <<<'PHP'
<?php
define('TRAIN_TICKET_BOT_LIBRARY_ONLY',true);require __DIR__.'/bot.php';
$db=initQueueDatabase();$db->exec("INSERT INTO message_queue(chat_id,message) VALUES('101','Queue CGI test')");
PHP);
        runPhp(array_merge($php,[$temp.'/seed-queue.php']),$temp);
        $before=count($readRequests());[$exit,$output,$errors]=$runCgi('send_queue.php');
        verify($exit===0 && $errors==='' && count($newMessages($before))===1,'Actual local CGI queue worker sends its queued message');
        $before=count($readRequests());
        $httpEnvironment=array_merge(getenv(),['REQUEST_METHOD'=>'GET','REMOTE_ADDR'=>'127.0.0.1','HTTP_HOST'=>'localhost',
            'SERVER_PROTOCOL'=>'HTTP/1.1','GATEWAY_INTERFACE'=>'CGI/1.1','REDIRECT_STATUS'=>'1']);
        foreach(['check_tickets.php','runFetchTickets.php','send_queue.php'] as $entry) {
            [$exit,$output,$errors]=$runCgi($entry,$httpEnvironment);
            verify($exit===0 && $errors==='' && str_contains($output,'403'),'Actual CGI HTTP requests must remain forbidden: '.$entry);
        }
        verify(count($readRequests())===$before,'Forbidden CGI HTTP requests cannot fetch inventory or send messages');
        echo "PASS Actual CGI cron: ticket and queue delivery, runtime checkpoint and HTTP denial\n";
    }
    file_put_contents($temp.'/broadcast-scenario.php', <<<'PHP'
<?php
define('TRAIN_TICKET_BOT_LIBRARY_ONLY',true);require __DIR__.'/bot.php';
$text='خبر تست * _ [ ] ۱۲۳ https://example.test/123?q=456';
file_put_contents(__DIR__.'/broadcast-recipients.json',json_encode(getApprovedUsers()));
$first=broadcastMessage($text,'999','integration-broadcast-1');$again=broadcastMessage($text,'999','integration-broadcast-1');
if(!$first['created']||$again['created']||$first['id']!==$again['id'])throw new RuntimeException('Idempotency failed');
$queue=initQueueDatabase();$before=$queue->querySingle('SELECT COUNT(*) FROM broadcasts');
broadcastMessage('unauthorized','101','unauthorized');
if($queue->querySingle('SELECT COUNT(*) FROM broadcasts')!==$before)throw new RuntimeException('Admin scope failed');
$db=initDatabase();$db->exec("UPDATE users SET approved=0 WHERE chat_id='202'");
PHP);
    runPhp(array_merge($php,[$temp.'/broadcast-scenario.php']),$temp);
    $recipients=json_decode(file_get_contents($temp.'/broadcast-recipients.json'),true);
    file_put_contents($temp.'/telegram-error.json',json_encode(['chat_id'=>'303','error_code'=>403,'description'=>'Forbidden: bot was blocked']));
    $before=count($readRequests());runPhp(array_merge($php,[$temp.'/send_queue.php']),$temp,'Telegram delivery:');
    $broadcastMessages=$newMessages($before);$actualChats=[];
    foreach($broadcastMessages as$message){
        verify($message['payload']['text']==='خبر تست * _ [ ] ۱۲۳ https://example.test/123?q=456','Broadcast text, Persian digits and URL digits must remain intact');
        verify(!isset($message['payload']['parse_mode']),'Broadcasts cannot accidentally become malformed Markdown');
        $actualChats[]=(string)$message['payload']['chat_id'];
    }
    $expectedChats=array_values(array_diff($recipients,['202']));sort($expectedChats);sort($actualChats);
    verify($actualChats===$expectedChats,'Broadcast attempts must reach every approved snapshot recipient except revoked approval');
    $queueDb=new SQLite3($temp.'/queue.db');
    verify((int)$queueDb->querySingle("SELECT COUNT(*) FROM message_queue WHERE broadcast_id=1 AND status='sent'")===count($recipients)-2,'Successful recipients are recorded independently');
    verify((int)$queueDb->querySingle("SELECT COUNT(*) FROM message_queue WHERE broadcast_id=1 AND status='failed' AND error_code=403")===1,'Blocked recipient has an explicit failure');
    verify((int)$queueDb->querySingle("SELECT COUNT(*) FROM message_queue WHERE broadcast_id=1 AND status='skipped'")===1,'Revoked approval is explicitly excluded');
    $queueDb->close();unlink($temp.'/telegram-error.json');
    $before=count($readRequests());runPhp(array_merge($php,[$temp.'/send_queue.php']),$temp);
    verify(count($newMessages($before))===0,'Finished or permanently failed broadcast recipients are not resent');
    file_put_contents($temp.'/broadcast-status.php', <<<'PHP'
<?php
define('TRAIN_TICKET_BOT_LIBRARY_ONLY',true);require __DIR__.'/bot.php';
showBroadcastStatus('101');showBroadcastStatus('999');
PHP);
    $before=count($readRequests());runPhp(array_merge($php,[$temp.'/broadcast-status.php']),$temp);
    $statusMessages=$newMessages($before);verify(count($statusMessages)===1 && (string)$statusMessages[0]['payload']['chat_id']==='999','Broadcast progress is admin-only');
    file_put_contents($temp.'/trip-isolation.php', <<<'PHP'
<?php
define('TRAIN_TICKET_BOT_LIBRARY_ONLY',true);require __DIR__.'/bot.php';
$db=initDatabase();$good=ownedFollowTrip($db,1,'101');$bad=$good;$bad['route']='tehran-qom';
$badId=saveUserTrip('101',$bad);$bad=ownedFollowTrip($db,$badId,'101');
$batch=new TicketSearchBatch(static function($payload){if($payload['route']==='tehran-qom')throw new RuntimeException('Synthetic per-trip error');return requestTicketSearch($payload);});
foreach([$bad,$good]as$trip){processTicketTripSafely($trip,$batch);}
if($db->querySingle('SELECT error FROM ticket_trip_runs WHERE trip_id='.(int)$badId)!=='RuntimeException')throw new RuntimeException('Failure was not recorded');
if($db->querySingle('SELECT error FROM ticket_trip_runs WHERE trip_id=1')!==null)throw new RuntimeException('Healthy trip did not continue');
$db->exec("UPDATE users SET approved=0 WHERE chat_id='101'");
PHP);
    $switch['enabled']=false;$app($switch);$switch['enabled']=true;$app($switch);
    $before=count($readRequests());runPhp(array_merge($php,[$temp.'/trip-isolation.php']),$temp,'Ticket trip failed');
    verify(count($newMessages($before))>=1,'An unexpected per-trip error cannot swallow a later user trip ticket');
    file_put_contents($temp.'/revoked-trip.php', <<<'PHP'
<?php
define('TRAIN_TICKET_BOT_LIBRARY_ONLY',true);require __DIR__.'/bot.php';processUserTrips('101');
PHP);
    $before=count($readRequests());runPhp(array_merge($php,[$temp.'/revoked-trip.php']),$temp);
    verify(count($readRequests())===$before,'A revoked account cannot trigger manual ticket searches or delivery');
    // A failed help footer must retry independently of already delivered tickets.
    file_put_contents($temp.'/hint-scenario.php', <<<'PHP'
<?php
define('TRAIN_TICKET_BOT_LIBRARY_ONLY',true);require __DIR__.'/bot.php';
$db=initDatabase();$db->exec("UPDATE user_trips SET follow_mode='none',follow_revision=follow_revision+1");
$db->exec("INSERT INTO users(chat_id,approved)VALUES('808',1)");
$future=(new DateTimeImmutable('tomorrow',new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
$id=saveUserTrip('808',['route'=>'tehran-mashhad','date'=>$future,'return_date'=>$future,'count'=>1,'type'=>0,'coupe'=>0,'filter'=>0]);
file_put_contents(__DIR__.'/hint-trip-id',(string)$id);
PHP);
    runPhp(array_merge($php,[$temp.'/hint-scenario.php']),$temp);
    $hintTripId=(int)file_get_contents($temp.'/hint-trip-id');
    file_put_contents($temp.'/telegram-error.json',json_encode(['chat_id'=>'808','error_code'=>500,'description'=>'Temporary failure','only_hint'=>true]));
    $before=count($readRequests());runPhp(array_merge($php,[$temp.'/check_tickets.php']),$temp,'Telegram delivery:');
    $sent=$newMessages($before);
    verify(count($sent)===2 && str_contains($sent[0]['payload']['text'],'بلیط موجود است') && str_starts_with($sent[1]['payload']['text'],'تنظیم هشدار سفر'),'Ticket acceptance and failed help delivery are independent');
    $db=new SQLite3($temp.'/users.db');
    verify($db->querySingle('SELECT sent_at FROM ticket_trip_hints WHERE trip_id='.$hintTripId)===null,'Failed hint is not marked delivered');
    $db->exec("UPDATE ticket_trip_hints SET claim_token='other-worker',claimed_at=".time().' WHERE trip_id='.$hintTripId);$db->close();
    unlink($temp.'/telegram-error.json');
    $before=count($readRequests());runPhp(array_merge($php,[$temp.'/check_tickets.php']),$temp);
    verify(count($newMessages($before))===0,'An active footer claim prevents duplicate delivery while steady tickets remain quiet');
    $db=new SQLite3($temp.'/users.db');$db->exec('UPDATE ticket_trip_hints SET claimed_at=1 WHERE trip_id='.$hintTripId);$db->close();
    $before=count($readRequests());runPhp(array_merge($php,[$temp.'/check_tickets.php']),$temp);
    $sent=$newMessages($before);
    verify(count($sent)===1 && str_starts_with($sent[0]['payload']['text'],'تنظیم هشدار سفر') && empty($sent[0]['payload']['disable_notification']),'An abandoned hint retries with a normal notification without repeating accepted tickets');
    $before=count($readRequests());runPhp(array_merge($php,[$temp.'/check_tickets.php']),$temp);
    verify(count($newMessages($before))===0,'Successful help is sent once per trip, even after a worker restart');
    $db=new SQLite3($temp.'/users.db');$db->exec('DELETE FROM user_trips WHERE id='.$hintTripId);
    verify((int)$db->querySingle('SELECT COUNT(*) FROM ticket_trip_hints WHERE trip_id='.$hintTripId)===0,'Deleting a trip also deletes its help delivery state');$db->close();
    // Exercise the signed address-book API against the real legacy relational DB.
    $db=new SQLite3($temp.'/users.db');$db->exec("INSERT INTO users(chat_id,approved)VALUES('909',1),('910',1)");$db->close();
    $peopleCall=static fn(string $action,array $payload=[])=>$app(['init_data'=>$authFor(909),'action'=>$action]+$payload);
    verify($app(['init_data'=>'fake','action'=>'people_load'])[0]===401,'Identity records require signed Telegram authentication');
    $person=['first_name'=>'مسافر','last_name'=>'آزمایشی','national_code'=>'۰۰۱۲۳۴۵۶۷۸','passenger_type'=>0,'gender'=>1,'wheelchair'=>true];
    $createPerson=['request_id'=>str_repeat('p',32),'traveler'=>$person];[$status,$address]=$peopleCall('people_save_traveler',$createPerson);
    verify($status===200 && $address['data']['travelers'][0]['national_code']==='0012345678','Passenger creation preserves leading zeros and Persian digit input');$personId=$address['data']['mutation']['id'];
    verify($peopleCall('people_save_traveler',$createPerson)[1]['data']['replayed']===true,'An interrupted passenger create cannot duplicate a record');
    $person['national_code']='0012345679';$person['passenger_type']=2;$person['first_name']='نوزاد';
    $infant=$peopleCall('people_save_traveler',['request_id'=>str_repeat('i',32),'traveler'=>$person])[1]['data']['mutation']['id'];
    $person['national_code']='0099999999';$foreignPerson=$app(['init_data'=>$authFor(910),'action'=>'people_save_traveler','request_id'=>str_repeat('p',32),'traveler'=>$person])[1]['data'];
    verify($peopleCall('people_delete_traveler',['request_id'=>str_repeat('f',32),'id'=>$foreignPerson['mutation']['id'],'version'=>$foreignPerson['travelers'][0]['version']])[0]===404,'A signed caller still cannot mutate another account passenger');
    [$status,$address]=$peopleCall('people_save_list',['request_id'=>str_repeat('l',32),'name'=>'هم‌سفرها','member_ids'=>[$personId,$infant]]);$listId=$address['data']['mutation']['id'];
    verify($status===200 && $address['data']['lists'][0]['seat_count']===1,'Lists created in the Mini App distinguish infants from required seats');
    verify($peopleCall('people_save_list',['request_id'=>str_repeat('x',32),'name'=>'غیرمجاز','member_ids'=>[$foreignPerson['mutation']['id']]])[0]===400,'Foreign passenger IDs cannot be inserted into a list');
    $contact=$address['data']['contact'];$address=$peopleCall('people_save_contact',['request_id'=>str_repeat('c',32),'version'=>$contact['version'],'person_name'=>'خریدار آزمایشی','phone_number'=>'+۹۸۹۱۲۳۴۵۶۷۸۹','email'=>''])[1]['data'];
    verify($address['contact']['phone_number']==='09123456789','Contact updates normalize the legacy INTEGER phone field');
    file_put_contents($temp.'/people-booking.php', <<<'PHP'
<?php
define('TRAIN_TICKET_BOT_LIBRARY_ONLY',true);require __DIR__.'/bot.php';
[$script,$list]= $argv;$db=initDatabase();$people=getTravelersFromList((int)$list,'909');
if(count($people)!==2||!$people[0]['wheelchair']||$people[1]['passenger_type']!==2)throw new RuntimeException('Address book is not available to booking');
$trip=['chat_id'=>'909','date'=>'1490-01-01','coupe'=>0];$callback=ticketListPickerCallback($db,$trip,['id'=>'ticket-1']);file_put_contents(__DIR__.'/people-picker-callback',$callback);
$_ENV['MINI_APP_URL']='https://example.test/telegram-app.php';handleShowTravelersCommand('909');handleShowTravelerListsCommand('909');
saveReservationSession('909',['list_id'=>(int)$list,'ticket_id'=>'ticket-1','coupe'=>0]);completeReservation('909');
PHP);
    unlink($temp.'/reservation-failure');$before=count($readRequests());runPhp(array_merge($php,[$temp.'/people-booking.php',(string)$listId]),$temp);
    $newRequests=array_slice($readRequests(),$before);$reservation=array_values(array_filter($newRequests,static fn($r)=>$r['path']==='/train/reservation/ticket-1/0'));
    verify(count($reservation)===1 && $reservation[0]['payload']['user']['mobileNumber']==='09123456789','Mini App contact data reach reservation without a duplicated leading zero');
    verify($reservation[0]['payload']['passengers'][1]['ageType']==='infant' && $reservation[0]['payload']['passengers'][0]['isWheelchairOrdered']===true,'The existing booking flow retains age, gender and wheelchair choices');
    $launches=$newMessages($before);verify(str_contains($launches[0]['payload']['reply_markup'],'view=people')&&str_contains($launches[1]['payload']['reply_markup'],'section=lists'),'Old bot entry points launch the matching Mini App section');
    $freshList=$peopleCall('people_save_list',['request_id'=>str_repeat('n',32),'name'=>'لیست تازه','member_ids'=>[$personId]])[1]['data'];$freshListId=$freshList['mutation']['id'];
    file_put_contents($temp.'/people-picker.php', <<<'PHP'
<?php
define('TRAIN_TICKET_BOT_LIBRARY_ONLY',true);require __DIR__.'/bot.php';
$_ENV['MINI_APP_URL']='https://example.test/telegram-app.php';showCurrentTicketLists($argv[1],substr(file_get_contents(__DIR__.'/people-picker-callback'),strlen('ticket_lists_')));
PHP);
    $before=count($readRequests());runPhp(array_merge($php,[$temp.'/people-picker.php','909']),$temp);$pickerMessages=$newMessages($before);
    verify(count($pickerMessages)===1 && str_contains($pickerMessages[0]['payload']['reply_markup'],'reserve_list_'.$freshListId.'_ticket-1_0'),'An existing ticket picker discovers a newly created list without waiting for another stock alert');
    $before=count($readRequests());runPhp(array_merge($php,[$temp.'/people-picker.php','910']),$temp);verify(!str_contains($newMessages($before)[0]['payload']['reply_markup']??'','reserve_'),'A ticket list picker token is scoped to its chat owner');
    $current=$peopleCall('people_load')[1]['data'];$owned=array_column($current['travelers'],null,'id')[$personId];
    $after=$peopleCall('people_delete_traveler',['request_id'=>str_repeat('d',32),'id'=>$personId,'version'=>$owned['version']])[1]['data'];
    verify(count($after['travelers'])===1 && !in_array($personId,array_merge(...array_column($after['lists'],'member_ids')),true),'Deleting a saved person detaches all owned list memberships');
    echo "PASS Passenger Mini App: signed ownership, legacy list writes, idempotent create, contacts, infant/wheelchair booking, bot launchers and current-ticket list refresh\n";
    echo "PASS Quiet alerts: normal notifications, one help per trip, independent failure retry and active/abandoned help leases\n";
    echo "PASS Broadcast delivery: all approved recipients, atomic idempotent enqueue, plain raw text, revoked/blocked states, admin progress and no resends\n";
    echo "PASS Trip isolation: a failed trip cannot stop healthy ones and revoked accounts cannot send\n";
    echo "PASS Script-only cron probe: local runtime, signed report, no bot calls and protected result\n";
    echo "PASS Delivery repair: malformed and legacy passenger lists, owned web diagnostics, cron checkpoint and visible reservation failures\n";
    echo "PASS Immediate switches: ownership, full keys, boolean validation, idempotent retries and no upstream fan-out\n";
    echo "PASS Delivery regression: long callback, raw provider punctuation, durable 429 cooldown, lower-capacity retry, acceptance status and quiet steady state\n";
    echo "PASS Unified Mini App API: signed bootstrap, complete create, retry, conflicts, local preferences and ownership\n";
    echo "PASS Telegram worker: shared requests, per-user buttons and flags, expiry, fresh cycles and cron locking\n";
    echo "PASS Capacity, local filters, setup/edit callbacks, ownership, partial links and wheelchair payload\n";
    echo "PASS Follow selection: full catalog, signed mini app API, quiet steady states, reopening, edits and pause\n";
    echo "PASS Default all, per-message mute/restore, future trains, trip footer and menu controls\n";
} finally {
    if (isset($db) && $db instanceof SQLite3) { try { $db->close(); } catch (Throwable $ignored) {} }
    if (is_resource($appServer)) { proc_terminate($appServer); proc_close($appServer); }
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    // This directory is freshly created above; remove only its known flat files.
    if (is_file($temp.'/fonts/webfonts/Vazirmatn[wght].woff2')) { unlink($temp.'/fonts/webfonts/Vazirmatn[wght].woff2'); }
    if (is_dir($temp.'/fonts/webfonts')) { rmdir($temp.'/fonts/webfonts'); }
    if (is_dir($temp.'/fonts')) { rmdir($temp.'/fonts'); }
    foreach (glob($temp . '/*') as $file) {
        unlink($file);
    }
    if (is_file($temp . '/.env')) {
        unlink($temp . '/.env');
    }
    rmdir($temp);
}
