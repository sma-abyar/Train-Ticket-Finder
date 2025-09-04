<?php

// env file loader
function loadEnv($filePath)
{
    if (!file_exists($filePath)) {
        throw new Exception("فایل .env یافت نشد: $filePath");
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        // Ignore comments or invalid lines
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }

        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        // Handle quoted values
        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
        } elseif (str_starts_with($value, "'") && str_ends_with($value, "'")) {
            $value = substr($value, 1, -1);
        }

        // Handle JSON values
        if ((str_starts_with($value, '{') && str_ends_with($value, '}')) || (str_starts_with($value, '[') && str_ends_with($value, ']'))) {
            $decodedValue = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decodedValue;
            } else {
                throw new Exception("خطای JSON در مقدار: $key");
            }
        }

        // Populate $_ENV and $GLOBALS
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
            $GLOBALS[$key] = $value;
        }
    }
}

// Load .env file
loadEnv(__DIR__ . '/.env');

// Use .env values
$GLOBALS['botToken'] = $_ENV['BOT_TOKEN'];
$adminChatId = $_ENV['ADMIN_CHAT_ID'];
$url = $_ENV['URL'];
$dbPath = $_ENV['DB_PATH'];

$jsonHeaders = $_ENV['HEADERS'];

// Initialize $headers array
$headers = [];

// بارگیری لیست بن شده‌ها
if (file_exists('banned_users.json')) {
    $bannedUsers = json_decode(file_get_contents('banned_users.json'), true) ?: [];
} else {
    $bannedUsers = [
        '5211349114',
          // یوزر آیدی کاربران مزاحم رو اینجا بذار
    ];
}

// Check if headers are valid JSON
// Check if $jsonHeaders is already an array
if (is_array($jsonHeaders)) {
    $headers = [];
    foreach ($jsonHeaders as $key => $value) {
        $headers[] = "$key: $value";
    }
} else {
    // Decode if it's a JSON string
    $decodedHeaders = json_decode($jsonHeaders, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $headers = [];
        foreach ($decodedHeaders as $key => $value) {
            $headers[] = "$key: $value";
        }
    } else {
        throw new Exception("خطا در پردازش JSON برای HEADERS.");
    }
}

print_r($headers); // خروجی برای بررسی

// Create and connect database
function initDatabase()
{
    global $dbPath;
    $db = new SQLite3($dbPath);

    // تنظیم زمان انتظار برای آزاد شدن قفل (مثلاً ۵۰۰۰ میلی‌ثانیه یا ۵ ثانیه)
    $db->busyTimeout(5000);

    // فعال کردن WAL mode برای بهبود مدیریت قفل‌ها
    $db->exec('PRAGMA journal_mode = WAL;');

    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY, 
        chat_id TEXT UNIQUE, 
        approved INTEGER DEFAULT 1
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS user_trips (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        chat_id TEXT NOT NULL,
        route TEXT NOT NULL,
        date TEXT NOT NULL,
        return_date TEXT,
        count INTEGER NOT NULL,
        type INTEGER,
        coupe INTEGER,
        filter INTEGER,
        no_counting_notif INTEGER,
        no_ticket_notif INTEGER,
        bad_data_notif INTEGER
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS travelers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        chat_id TEXT NOT NULL,
        first_name TEXT NOT NULL,
        last_name TEXT NOT NULL,
        national_code TEXT NOT NULL,
        passenger_type INTEGER NOT NULL,
        gender INTEGER NOT NULL,
        services TEXT,
        wheelchair INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS traveler_lists (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        chat_id TEXT NOT NULL,
        name TEXT NOT NULL,
        members TEXT DEFAULT '[]',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS private_info (
        chat_id TEXT NOT NULL,
        person_name TEXT NOT NULL,
        phone_number INTEGER NOT NULL,
        email TEXT
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS reservation_sessions (
        chat_id TEXT PRIMARY KEY,
        session_data TEXT,
        created_at DATETIME,
        UNIQUE(chat_id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS user_states (
        chat_id TEXT PRIMARY KEY,
        current_state TEXT NOT NULL,
        temp_data TEXT,
        last_update DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    return $db;
}

function initQueueDatabase()
{
    $queueDbPath = __DIR__ . '/queue.db'; // مسیر دیتابیس جدید
    $db = new SQLite3($queueDbPath);

    // تنظیمات برای بهینه‌سازی دسترسی همزمان
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode = WAL;');

    // ساخت جدول صف پیام‌ها
    $db->exec("CREATE TABLE IF NOT EXISTS message_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        chat_id TEXT NOT NULL,
        message TEXT NOT NULL,
        sent INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    return $db;
}



function setUserState($chat_id, $state, $temp_data = null)
{
    global $dbPath;
    $db = new SQLite3($dbPath);

    $temp_data_json = $temp_data ? json_encode($temp_data) : null;

    $stmt = $db->prepare("INSERT INTO user_states (chat_id, current_state, temp_data, last_update) 
        VALUES (:chat_id, :current_state, :temp_data, CURRENT_TIMESTAMP)
        ON CONFLICT(chat_id) DO UPDATE SET current_state = excluded.current_state, temp_data = excluded.temp_data, last_update = CURRENT_TIMESTAMP");

    $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
    $stmt->bindValue(':current_state', $state, SQLITE3_TEXT);
    $stmt->bindValue(':temp_data', $temp_data_json, SQLITE3_TEXT);
    $stmt->execute();
}

function getUserState($chat_id)
{
    global $dbPath;
    $db = new SQLite3($dbPath);

    $stmt = $db->prepare("SELECT current_state, temp_data FROM user_states WHERE chat_id = :chat_id");
    $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
    $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if ($result) {
        $result['temp_data'] = $result['temp_data'] ? json_decode($result['temp_data'], true) : null;
        return $result;
    }
    return null;
}

function clearUserState($chat_id)
{
    global $dbPath;
    $db = new SQLite3($dbPath);

    $stmt = $db->prepare("DELETE FROM user_states WHERE chat_id = :chat_id");
    $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
    $stmt->execute();
}

// Save user data
function saveUserTrip($chat_id, $data)
{
    $db = initDatabase();
    $stmt = $db->prepare("INSERT INTO user_trips (chat_id, route, date, return_date, count, type, coupe, filter, no_counting_notif, no_ticket_notif, bad_data_notif) 
                          VALUES (:chat_id, :route, :date, :return_date, :count, :type, :coupe, :filter, :no_counting_notif, :no_ticket_notif, :bad_data_notif)");
    $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
    $stmt->bindValue(':route', $data['route'], SQLITE3_TEXT);
    $stmt->bindValue(':date', $data['date'], SQLITE3_TEXT);
    $stmt->bindValue(':return_date', $data['return_date'], SQLITE3_TEXT);
    $stmt->bindValue(':count', $data['count'], SQLITE3_INTEGER);
    $stmt->bindValue(':type', $data['type'], SQLITE3_INTEGER);
    $stmt->bindValue(':coupe', $data['coupe'], SQLITE3_INTEGER);
    $stmt->bindValue(':filter', $data['filter'], SQLITE3_INTEGER);
    $stmt->bindValue(':no_counting_notif', 0, SQLITE3_INTEGER);
    $stmt->bindValue(':no_ticket_notif', 0, SQLITE3_INTEGER);
    $stmt->bindValue(':bad_data_notif', 0, SQLITE3_INTEGER);
    $stmt->execute();
}

// Get user trips data
function getUserTrips($chat_id)
{
    $db = initDatabase();
    $stmt = $db->prepare("SELECT * FROM user_trips WHERE chat_id = :chat_id");
    $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
    $result = $stmt->execute();

    $trips = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $trips[] = $row;
    }
    return $trips;
}


// register new user
function registerUser($chat_id, $username)
{
    if (!isUserApproved($chat_id)) {
        $db = initDatabase();
        $stmt = $db->prepare("INSERT OR IGNORE INTO users (chat_id) VALUES (:chat_id)");
        $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
        $stmt->execute();

        // Create an inline keyboard with an "Approve User" button
        $inlineKeyboard = [
            'inline_keyboard' => [
                [
                    ['text' => 'تأیید کاربر', 'callback_data' => "approve_user_$chat_id"],
                    ['text' => 'ارسال پیام هندی', 'callback_data' => "india_user_$chat_id"]
                ]
            ]
        ];

        // Send the message to the admin with the inline button
        sendMessage($GLOBALS['adminChatId'], "کاربر جدید با مشخصات: $username\nبرای تأیید، دکمه زیر را کلیک کنید:", $inlineKeyboard, false);
    }
}

function isUserApproved($chat_id)
{
    $db = initDatabase();
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE chat_id = :chat_id AND approved = 1");
    $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
    $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $result['count'] > 0;
}


// approve new user
function approveUser($chat_id)
{
    if (!isUserApproved($chat_id)) {
        $db = initDatabase();
        $stmt = $db->prepare("UPDATE users SET approved = 1 WHERE chat_id = :chat_id");
        $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
        $stmt->execute();
        $keyboard = [
            "inline_keyboard" => [
                [["text" => "تکمیل اطلاعات شخصی", "callback_data" => "add_private_info"]]
            ]
        ];
        // sendMessage($chat_id, "شما تأیید شدید!", getMainMenuKeyboard($chat_id));
        sendMessage($chat_id, "سلام🙌 \n برای رزرو سفر توسط ربات، نیاز داریم که اطلاعات شما رو به عنوان رزرو کننده داشته باشیم. بریم تکمیلش کنیم؟ 😊", $keyboard);
    }
}

// Get a list of approved users
function getApprovedUsers()
{
    $db = initDatabase();
    $result = $db->query("SELECT chat_id FROM users WHERE approved = 1");
    $users = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $users[] = $row['chat_id'];
    }
    return $users;
}

// Send request to get ticket for all users
function fetchTicketsForAllUsers()
{
    $db = initDatabase();
    $result = $db->query("SELECT * FROM user_trips WHERE chat_id IN (SELECT chat_id FROM users WHERE approved = 1)");

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        fetchTickets($row);
    }
}

// Send requset to get ticket for a user
function processUserTrips($chat_id)
{
    $trips = getUserTrips($chat_id);
    foreach ($trips as $trip) {
        fetchTickets($trip);
    }
}

// Send request to get ticket for each user and tell them
function fetchTickets($userTrip)
{
    global $url;
    $postFields = [
        'route' => $userTrip['route'],
        'car_transport' => 0,
        'date' => $userTrip['date'],
        'return_date' => '',
        'count' => $userTrip['count'],
        'type' => $userTrip['type'],
        'coupe' => $userTrip['coupe'],
        'filter' => $userTrip['filter'],
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/x-www-form-urlencoded; charset=UTF-8",
        "Referer: https://ghasedak24.com/train-ticket",
        "X-Requested-With: XMLHttpRequest",
        "User-Agent: Mozilla/5.0"
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    $route_title = '';
    if (isset($data['data']['status']) && $data['data']['status'] === 'success') {
        $departure = $data['data']['data']['departure'];
        if (!empty($departure)) {
            $found = false;
            foreach ($departure as $ticket) {
                $route_title = "{$ticket['from_title']} به {$ticket['to_title']}";
                if ($ticket['counting_en'] > 0) {
                    $found = true;
                    $message = "🎟 *بلیط موجود است* 🎟\n"
                        . "📝 *آیدی سفر*: {$userTrip['id']} - {$route_title}\n"
                        . "🚂 *شماره قطار*: {$ticket['train_number']}\n"
                        . "🚋 *نام قطار*: {$ticket['wagon_name']}\n"
                        . "💺 *نوع بلیط*: " . getTripType($userTrip['type']) . "\n"
                        . "🗓 *تاریخ حرکت*: {$ticket['jdate_fa']}\n"
                        . "⏰ *زمان حرکت*: {$ticket['time']}\n"
                        . "📊 *ظرفیت باقی‌مانده*: {$ticket['counting']}\n"
                        . "💰 *قیمت*: {$ticket['cost_title']} ریال\n";

                    // گرفتن لیست‌های مسافران برای این کاربر
                    $travelerLists = listTravelerLists($userTrip['chat_id']);

                    // ایجاد پیام و دکمه‌های جدید
                    $messageData = modifyTicketMessage($message, $userTrip, $ticket, $travelerLists);

                    // ارسال پیام با دکمه‌های جدید
                    sendMessage($userTrip['chat_id'], $messageData['message'], $messageData['reply_markup']);

                    updateNotificationStatus($userTrip['id'], 'no_counting_notif', 0);
                    updateNotificationStatus($userTrip['id'], 'no_ticket_notif', 0);
                    updateNotificationStatus($userTrip['id'], 'bad_data_notif', 0);
                }
            }
            if (!$found && ($userTrip['no_counting_notif'] == 0)) {
                sendMessage($userTrip['chat_id'], "*دیر رسیدی خوشگله!*\nهیچ قطاری برای تاریخ {$userTrip['date']} در مسیر {$route_title} صندلی خالی نداره.\n حالا توکل به خدا، صبر کن شاید موجود شد. خبر از ما😊😉", getMainMenuKeyboard($userTrip['chat_id']));
                updateNotificationStatus($userTrip['id'], 'no_counting_notif', 1);
            }
        } elseif ($userTrip['no_ticket_notif'] == 0) {
            sendMessage($userTrip['chat_id'], "*این مملکت درست نمی‌شه!*\n هیچ قطاری برای تاریخ {$userTrip['date']} در مسیر " . translateRoute($userTrip['route']) . " وجود نداره.\nاگر چیزی ثبت شد (به شرط حیات) خبرت می‌‌کنیم 😎 \n البته ...\nمی‌خوای  خودت هم یه دور اطلاعات سفرت رو چک کن شاید مسیر یا تاریخ اشتباه وارد شده باشه و نیاز باشه سریع درستش کنیم😊", getMainMenuKeyboard($userTrip['chat_id']));
            updateNotificationStatus($userTrip['id'], 'no_ticket_notif', 1);
        }
    } elseif (isset($data['data']['status']) && $data['data']['status'] === 'raja_backup' && $userTrip['bad_data_notif'] == 0) {
        sendMessage($userTrip['chat_id'], "دوست خوبم آب از سمت رجا قطعه😂 \nبچه‌های رجا مشغول به‌روزرسانی سامانه‌ی ریلی هستن.👷‍♂️ \nدرست شد لیست بلیط‌ها به صورت خودکار برات میاد، غمت نباشه😙");
        updateNotificationStatus($userTrip['id'], 'bad_data_notif', 1);
    } elseif (isset($data['data']['status']) && $data['data']['status'] === 'failed' && $userTrip['bad_data_notif'] == 0) {
        sendMessage($userTrip['chat_id'], "اوه اوه اوه! آب قند بیارین بچه‌های رجا از حال رفتن😂 \nبه دلیل اضافه شدن بلیط تاریخ‌های جدید، فعلا بلیط‌ها رو در اختیار هیچ سامانه‌ای قرار ندادن. \nدرست شد لیست بلیط‌ها که برای همه باز شد، به صورت خودکار برات میاد، حواسمون بهت هست😙");
        updateNotificationStatus($userTrip['id'], 'bad_data_notif', 1);
    } elseif ($userTrip['bad_data_notif'] == 0) {
        // چاپ اطلاعات دریافتی از سرور
        $debug_info = "Debug Info:\n" .
            "Response: " . print_r(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), true) . "\n" .
            "Trip Info: " . print_r($userTrip, true);

        // ارسال پیام خطا به کاربر
        sendMessage($userTrip['chat_id'], "بابا جون من یه مقدار چشمام ضعیفه 👨🏻‍🦳، قطاری توی تاریخ {$userTrip['date']} پیدا نکردم. \nبذار برم عینکمو بیارم، هر وقت چیزی به چشمم خورد خبرت می‌کنم 🧐");

        // ارسال اطلاعات خطا به لاگ یا ادمین (یا خود کاربر اگر مایل هستید)
        file_put_contents('debug.log', "Received info: " . $debug_info . "\n", FILE_APPEND);

        updateNotificationStatus($userTrip['id'], 'bad_data_notif', 1);
    }
    // else if ($userTrip['critical_notif'] == 0) {  // اضافه کردن یک فیلد جدید برای این وضعیت
    //     sendMessage($userTrip['chat_id'], "خیلی اوضاع خیطه 😬 \nبه ادمین یه ندا بده ❤");
    //     updateNotificationStatus($userTrip['id'], 'critical_notif', 1);
    // }
}

// update notif state of each tripz
function updateNotificationStatus($userTripId, $field, $data)
{
    $db = initDatabase();
    $stmt = $db->prepare("UPDATE user_trips SET $field = $data WHERE id = :id");
    $stmt->bindValue(':id', $userTripId, SQLITE3_INTEGER);
    $stmt->execute();
}


// Send message to user
function toPersianNumbers($string)
{
    $persianNumbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $englishNumbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($englishNumbers, $persianNumbers, $string);
}

function toEnglishNumbers($string)
{
    $persianNumbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $englishNumbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($persianNumbers, $englishNumbers, $string);
}


function sendMessage($chat_id, $text, $replyMarkup = null, $isPersian = true)
{
    $botToken = $GLOBALS['botToken'];
    $url = "https://tapi.bale.ai/bot$botToken/sendMessage";

    if ($isPersian) {
        $text = toPersianNumbers($text);
    }

    $postData = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'Markdown',
    ];

    if ($replyMarkup) {
        $postData['reply_markup'] = json_encode($replyMarkup);
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    $response = curl_exec($ch);
    curl_close($ch);

    $responseArray = json_decode($response, true);
    return $responseArray['ok'] ?? false;
}


// Check for formats
function escapeMarkdownV2($text)
{
    $escapeChars = ['\\', '_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
    foreach ($escapeChars as $char) {
        $text = str_replace($char, '\\' . $char, $text);
    }
    return $text;
}


function getUsernameFromMessage($message)
{
    if (isset($message['from']['username'])) {
        return '@' . $message['from']['username'];
    } elseif (isset($message['from']['id'])) {
        return 'https://t.me/' . $message['from']['id'];
    } else {
        return 'Unknown User';
    }
}

function isUserBanned($chat_id, $bannedUsers) {
    return in_array($chat_id, $bannedUsers);
}

function logBannedUserAttempt($chat_id, $text) {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] Banned user $chat_id attempted: $text\n";
    file_put_contents('banned_log.txt', $logEntry, FILE_APPEND | LOCK_EX);
}

// Add the REMOVE_TRIP state to the state handling logic
$update = json_decode(file_get_contents('php://input'), true);


// 4. چک کردن بن برای تمام انواع آپدیت‌ها
$chat_id_to_check = null;
$update_type = '';

// تعیین chat_id و نوع آپدیت
if (isset($update['message']['web_app_data'])) {
    $chat_id_to_check = $update['message']['chat']['id'];
    $update_type = 'web_app_data';
} elseif (isset($update['message'])) {
    $chat_id_to_check = $update['message']['chat']['id'];
    $update_type = 'message';
} elseif (isset($update['callback_query'])) {
    $chat_id_to_check = $update['callback_query']['from']['id'];
    $update_type = 'callback_query';
} elseif (isset($update['inline_query'])) {
    $chat_id_to_check = $update['inline_query']['from']['id'];
    $update_type = 'inline_query';
}

// اگر کاربر بن است، هیچ کاری نکن
if ($chat_id_to_check && isUserBanned($chat_id_to_check, $bannedUsers)) {
    $text_to_log = '';
    if (isset($update['message']['text'])) {
        $text_to_log = $update['message']['text'];
    } elseif (isset($update['callback_query']['data'])) {
        $text_to_log = $update['callback_query']['data'];
    } else {
        $text_to_log = $update_type;
    }
    
    logBannedUserAttempt($chat_id_to_check, $text_to_log);
    exit; // کاربر بن است - هیچ جوابی نده
}


if (isset($update['message']['web_app_data'])) {
    $chat_id = $update['message']['chat']['id'];
    $webAppData = json_decode($update['message']['web_app_data']['data'], true);
    if (isset($webAppData['route']) && isset($webAppData['date'])) {
        $routeCode = $webAppData['route'];
        $reservationDate = $webAppData['date'];

        if (strpos($routeCode, '-') !== false) {
            $parts = explode('-', $routeCode);
            if (count($parts) >= 2) {
                $origin = $parts[0];
                $destination = $parts[1];

                // ارسال پاسخ به کاربر
                $message = "مسیر دریافت شد: " . translateRoute($routeCode) . "\n";

                $message .= "تاریخ:\n" . $reservationDate;
                sendMessage($chat_id, $message);

                // handleSetTripRoute($chat_id, $routeCode);
                handleWebAppData($chat_id, $routeCode, $reservationDate);
            } else {
                sendMessage($chat_id, "فرمت داده نامعتبر است.");
            }
        } else {
            sendMessage($chat_id, "داده دریافتی: $routeCode - فرمت داده شامل خط تیره نیست.");
        }
    } else {
        sendMessage($chat_id, "داده دریافتی نامعتبر است.");
    }
} elseif (isset($update['inline_query'])) {
    handleInlineQuery($update['inline_query']);
    exit;
} elseif (isset($update['callback_query'])) {
    handleCallbackQuery($update['callback_query']);
} elseif (isset($update['message'])) {
    $chat_id = $update['message']['chat']['id'];
    $text = toEnglishNumbers($update['message']['text']);

    // Handle cancel button
    if ($text === 'لغو') {
        clearUserState($chat_id);
        sendMessage($chat_id, "عملیات لغو شد.", getMainMenuKeyboard($chat_id));
        return;
    } // چک کردن دستور settrip_route
    if (strpos($text, '/settrip_route_') === 0) {
        $route = str_replace('/settrip_route_', '', $text);
        handleSetTripRoute($chat_id, $route);
        exit;
    }

    // Handle new commands and cancel current state if needed
    if (strpos($text, '/') === 0 && $text !== '/help') {
        $userState = getUserState($chat_id);
        if ($userState && isset($userState['current_state'])) {
            clearUserState($chat_id);
            sendMessage($chat_id, "عملیات قبلی لغو شد. در حال پردازش دستور جدید...");
        }
    }

    $userState = getUserState($chat_id);

    // Handle commands and button clicks
    switch ($text) {
        case '/start':
        case 'شروع':
            setUserState($chat_id, 'START');
            break;
        // case '/help':
        case 'راهنما':
            setUserState($chat_id, 'HELP');
            break;
        case 'تنظیم سفر':
            setUserState($chat_id, 'SET_TRIP');
            break;
        case 'نمایش سفرها':
            setUserState($chat_id, 'SHOW_TRIPS');
            break;
        case 'افزودن مسافر':
            setUserState($chat_id, 'ADD_TRAVELER');
            break;
        case 'مسافران سابق':
            setUserState($chat_id, 'SHOW_TRAVELERS');
            break;
        case 'افزودن لیست مسافران':
            setUserState($chat_id, 'ADD_TRAVELER_LIST');
            break;
        case 'لیست‌های مسافران':
            setUserState($chat_id, 'SHOW_TRAVELER_LISTS');
            break;
        case 'حذف مسافر':
            setUserState($chat_id, 'REMOVE_TRAVELER');
            sendMessage($chat_id, "لطفاً شماره مسافر را برای حذف وارد کنید (مثال: 1):");
            break;
        case 'حذف لیست':
            setUserState($chat_id, 'REMOVE_LIST_OF_TRAVELER');
            sendMessage($chat_id, "لطفاً شماره لیست را برای حذف وارد کنید (مثال: 1):");
            break;
        case 'حذف سفر':
            setUserState($chat_id, 'REMOVE_TRIP');
            showUserTrips($chat_id);
            sendMessage($chat_id, "لطفاً شماره سفر را برای حذف وارد کنید (مثال: 1):");
            break;
        case 'اطلاعات شخصی':
            setUserState($chat_id, 'SET_PRIVATE_INFO');
            // clearUserState($chat_id);
            // showPrivateInfo($chat_id);
            break;
        case 'ارسال پیام همگانی':
            if ($chat_id == $adminChatId) {
                setUserState($chat_id, 'GET_BROADCASTMESSAGE');
            }
            break;
        default:
            if (!$userState || !isset($userState['current_state'])) {
                sendMessage($chat_id, "دوست خوبم🌹\nبیا بازیگوشی نکنیم و از گزینه‌های قرار داده شده استفاده کنیم😁", getMainMenuKeyboard($chat_id));
            }
    }

    // Handle state-based interactions
    $userState = getUserState($chat_id);
    if ($userState && isset($userState['current_state'])) {
        switch ($userState['current_state']) {
            case 'START':
                handleStartCommand($chat_id, $update);
                break;
            case 'HELP':
                handleHelpCommand($chat_id);
                break;
            case 'SET_TRIP':
                handleSetTripCommand($chat_id);
                break;
            case 'SHOW_TRIPS':
                handleShowTripsCommand($chat_id);
                break;
            case 'ADD_TRAVELER':
                handleAddTravelerCommand($chat_id);
                break;
            case 'SHOW_TRAVELERS':
                handleShowTravelersCommand($chat_id);
                break;
            case 'ADD_TRAVELER_LIST':
                handleAddTravelerListCommand($chat_id);
                break;
            case 'SHOW_TRAVELER_LISTS':
                handleShowTravelerListsCommand($chat_id);
                break;
            case 'REMOVE_TRAVELER':
                handleRemoveTravelerCommand($chat_id, $text);
                clearUserState($chat_id);
                break;
            case 'REMOVE_LIST_OF_TRAVELER':
                handleRemoveTravelerListCommand($chat_id, $text);
                clearUserState($chat_id);
                break;
            case 'REMOVE_TRIP':
                handleRemoveTripCommand($chat_id, $text);
                break;
            case 'SET_TRIP_ROUTE':
                handleSetTripRoute($chat_id, $text);
                break;
            case 'SET_TRIP_DATE':
                handleSetTripDate($chat_id, $text);
                break;
            case 'SET_TRIP_RETURN_DATE':
                handleSetTripReturnDate($chat_id, $text);
                break;
            case 'SET_TRIP_COUNT':
                handleSetTripCount($chat_id, $text);
                break;
            case 'SET_TRIP_TYPE':
                handleSetTripType($chat_id, $text);
                break;
            case 'SET_TRIP_COUPE':
                handleSetTripCoupe($chat_id, $text);
                break;
            case 'SET_TRIP_FILTER':
                handleSetTripFilter($chat_id, $text);
                break;
            case 'SET_TRAVELER_FIRST_NAME':
                handleSetTravelerFirstName($chat_id, $text);
                break;
            case 'SET_TRAVELER_LAST_NAME':
                handleSetTravelerLastName($chat_id, $text);
                break;
            case 'SET_TRAVELER_NATIONAL_CODE':
                handleSetTravelerNationalCode($chat_id, $text);
                break;
            case 'SET_TRAVELER_PASSENGER_TYPE':
                handleSetTravelerPassengerType($chat_id, $text);
                break;
            case 'SET_TRAVELER_GENDER':
                handleSetTravelerGender($chat_id, $text);
                break;
            case 'SET_TRAVELER_SERVICES':
                handleSetTravelerServices($chat_id, $text);
                break;
            case 'SET_TRAVELER_WHEELCHAIR':
                handleSetTravelerWheelchair($chat_id, $text);
                break;
            case 'SET_TRAVELER_LIST_NAME':
                handleSetTravelerListName($chat_id, $text);
                break;
            case 'SET_TRAVELER_LIST_MEMBERS':
                handleSetTravelerListMembers($chat_id, $text);
                break;
            case 'SET_PRIVATE_INFO':
                showPrivateInfo($chat_id);
                // handleSetPrivateInfo($chat_id, $text);
                break;
            case 'SEND_BROADCAST_MESSAGE':
                broadcastMessage($text, $adminChatId);
                clearUserState($chat_id);
                break;
            case 'GET_BROADCASTMESSAGE':
                if ($chat_id == $adminChatId) {
                    sendMessage($adminChatId, "لطفاً پیام مورد نظر برای ارسال همگانی را وارد کنید:");
                    setUserState($chat_id, 'SEND_BROADCAST_MESSAGE');
                }
                break;
            case 'awaiting_name':
                $db = initDatabase();

                $stmt = $db->prepare("SELECT chat_id FROM private_info WHERE chat_id = :chat_id");
                $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
                $result = $stmt->execute();

                if ($result->fetchArray(SQLITE3_ASSOC)) {
                    $stmt = $db->prepare("UPDATE private_info SET person_name = :person_name WHERE chat_id = :chat_id");
                } else {
                    $stmt = $db->prepare("INSERT INTO private_info (chat_id, person_name, phone_number, email) VALUES (:chat_id, :person_name, '', '')");
                }

                $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
                $stmt->bindValue(':person_name', $text, SQLITE3_TEXT);
                $stmt->execute();
                $db->close(); // بستن دیتابیس بعد از عملیات

                setUserState($chat_id, 'awaiting_phone');
                sendMessage($chat_id, "📞 حالا لطفاً شماره تلفن خود را وارد کنید:");
                break;

            case 'awaiting_phone':
                $db = initDatabase();

                $stmt = $db->prepare("UPDATE private_info SET phone_number = :phone WHERE chat_id = :chat_id");
                $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
                $stmt->bindValue(':phone', $text, SQLITE3_TEXT);

                try {
                    $stmt->execute();
                    $db->close(); // بستن دیتابیس
                } catch (Exception $e) {
                    sendMessage($chat_id, "❌ خطایی رخ داد، لطفاً دوباره تلاش کنید.", getMainMenuKeyboard($chat_id));
                    break;
                }

                setUserState($chat_id, 'awaiting_email');
                sendMessage($chat_id, "📧 لطفاً ایمیل خود را وارد کنید (یا بنویسید ندارم):");
                break;

            case 'awaiting_email':
                $db = initDatabase();

                if ($text !== "/skip") {
                    $stmt = $db->prepare("UPDATE private_info SET email = :email WHERE chat_id = :chat_id");
                    $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
                    $stmt->bindValue(':email', $text, SQLITE3_TEXT);

                    try {
                        $stmt->execute();
                        $db->close(); // بستن دیتابیس
                    } catch (Exception $e) {
                        sendMessage($chat_id, "❌ خطایی رخ داد، لطفاً دوباره تلاش کنید.", getMainMenuKeyboard($chat_id));
                        break;
                    }
                }

                clearUserState($chat_id);
                sendMessage($chat_id, "✅ اطلاعات شما با موفقیت ثبت شد.", getMainMenuKeyboard($chat_id));
                break;

            default:
                sendMessage($chat_id, "در حال پردازش درخواست شما...");
        }
    }

}



function showPrivateInfo($chat_id)
{
    $db = initDatabase(); // مقداردهی دیتابیس
    try {
        // دریافت اطلاعات کاربر از دیتابیس
        $stmt = $db->prepare("SELECT person_name, phone_number, email FROM private_info WHERE chat_id = :chat_id");
        $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
        $result = $stmt->execute();
        $user_info = $result->fetchArray(SQLITE3_ASSOC);

        if ($user_info) {
            // اگر اطلاعات موجود باشد
            $message = "✅ اطلاعات شخصی شما:\n";
            $message .= "\n👤 نام و نام خانوادگی: " . htmlspecialchars($user_info['person_name']);
            $message .= "\n📞 شماره تلفن: " . htmlspecialchars($user_info['phone_number']);
            $message .= "\n📧 ایمیل: " . ($user_info['email'] ? htmlspecialchars($user_info['email']) : '—');

            $keyboard = [
                "inline_keyboard" => [
                    [["text" => "✏️ ویرایش اطلاعات", "callback_data" => "edit_private_info"]]
                ]
            ];
        } else {
            // اگر اطلاعاتی وجود نداشته باشد
            $message = "ℹ️ اطلاعات شخصی شما ثبت نشده است.";
            $keyboard = [
                "inline_keyboard" => [
                    [["text" => "➕ افزودن اطلاعات", "callback_data" => "add_private_info"]]
                ]
            ];
        }

        // ارسال پیام به کاربر با دکمه مربوطه
        sendMessage($chat_id, $message, $keyboard);

    } catch (Exception $e) {
        sendMessage($chat_id, "⚠️ خطا در خواندن اطلاعات: " . $e->getMessage(), null);
        error_log("خطا در خواندن اطلاعات شخصی: " . $e->getMessage());
    }
}



function handleCallbackQuery($callback_query)
{
    $chat_id = $callback_query['message']['chat']['id'];
    $data = $callback_query['data'];
    $message_id = $callback_query['message']['message_id'];
    // file_put_contents('debug.log', "Callback Query received: " . print_r($callback_query, true) . "\n", FILE_APPEND);

    if ($data === 'add_traveler') {
        // Start the traveler addition process
        handleAddTravelerCommand($chat_id);
    } elseif ($data === 'remove_traveler') {
        // Fetch the list of travelers
        $travelers = listTravelers($chat_id);
        if (empty($travelers)) {
            sendMessage($chat_id, "شما هیچ مسافری برای حذف ندارید.");
            return;
        }
        // Create inline buttons for each traveler
        $inlineKeyboard = ['inline_keyboard' => []];
        foreach ($travelers as $traveler) {
            $inlineKeyboard['inline_keyboard'][] = [
                ['text' => "{$traveler['first_name']} {$traveler['last_name']}", 'callback_data' => "remove_traveler_{$traveler['id']}"]
            ];
        }
        // Send the message with the inline buttons
        editMessage($chat_id, $message_id, "لطفاً مسافری که می‌خواهید حذف کنید را انتخاب کنید:", $inlineKeyboard);
        // sendMessage($chat_id, "لطفاً مسافری که می‌خواهید حذف کنید را انتخاب کنید:", $inlineKeyboard);
    } elseif (strpos($data, 'remove_traveler_') === 0) {
        // Extract the traveler ID from the callback data
        $traveler_id = str_replace('remove_traveler_', '', $data);
        // Call the function to remove the traveler
        removeTraveler($chat_id, $traveler_id);
        // Notify the user
        editMessage($chat_id, $message_id,"مسافر با موفقیت حذف شد.", getMainMenuKeyboard($chat_id));
        // sendMessage($chat_id, "مسافر با موفقیت حذف شد.", getMainMenuKeyboard($chat_id));
    } elseif ($data === 'add_traveler_list') {
        // Start the traveler list addition process
        handleAddTravelerListCommand($chat_id);
    } elseif ($data === 'remove_list_of_traveler') {
        // Fetch the list of traveler lists
        $lists = listTravelerLists($chat_id);
        if (empty($lists)) {
            sendMessage($chat_id, "شما هیچ لیست مسافری برای حذف ندارید.");
            return;
        }
        // Create inline buttons for each traveler list
        $inlineKeyboard = ['inline_keyboard' => []];
        foreach ($lists as $list) {
            $inlineKeyboard['inline_keyboard'][] = [
                ['text' => $list['name'], 'callback_data' => "remove_selected_list_of_traveler_{$list['id']}"]
            ];
        }
        // Send the message with the inline buttons
        sendMessage($chat_id, "لطفاً لیست مسافری که می‌خواهید حذف کنید را انتخاب کنید:", $inlineKeyboard);
    } elseif (strpos($data, 'remove_selected_list_of_traveler_') === 0) {
        // Extract the traveler list ID from the callback data
        $list_id = str_replace('remove_selected_list_of_traveler_', '', $data);
        // Call the function to remove the traveler list
        removeTravelerList($chat_id, $list_id);
        // Notify the user
        // sendMessage($chat_id, "لیست مسافران با موفقیت حذف شد.");
    } elseif (strpos($data, 'approve_user_') === 0) {
        // Extract the chat_id from the callback data
        $user_chat_id = str_replace('approve_user_', '', $data);
        // Call the function to approve the user
        approveUser($user_chat_id);
        // Notify the admin
        sendMessage($chat_id, "کاربر با شناسه $user_chat_id تأیید شد.");
    } elseif (strpos($data, 'india_user_') === 0) {
        // Extract the chat_id from the callback data
        $user_chat_id = str_replace('india_user_', '', $data);
        // sendMessage($chat_id, text: "Hello dear! \n👉 If you are in India, check out this bot: \n[India Ticket Finder Bot](https://t.me/india_ticket_finder_bot)");
        handleIndianUser($user_chat_id);
    } elseif ($data === 'add_trip') {
        // Start the trip addition process
        handleSetTripCommand($chat_id);
    } elseif ($data === 'remove_trip') {
        // Fetch the list of trips
        $trips = getUserTrips($chat_id);
        if (empty($trips)) {
            sendMessage($chat_id, "شما هیچ سفری برای حذف ندارید.");
            return;
        }
        // Create inline buttons for each trip
        $inlineKeyboard = ['inline_keyboard' => []];
        foreach ($trips as $trip) {
            $inlineKeyboard['inline_keyboard'][] = [
                ['text' => "سفر  " . translateRoute($trip['route']) . " (" . toPersianNumbers($trip['date']) . ")", 'callback_data' => "remove_trip_{$trip['id']}"]
            ];
        }
        // Send the message with the inline buttons
        sendMessage($chat_id, "لطفاً سفری که می‌خواهید حذف کنید را انتخاب کنید:", $inlineKeyboard);
    } elseif (strpos($data, 'remove_trip_') === 0) {
        // Extract the trip ID from the callback data
        $trip_id = str_replace('remove_trip_', '', $data);
        // Call the function to remove the trip
        removeUserTrip($chat_id, $trip_id);
        // Notify the user
        // sendMessage($chat_id, "سفر با موفقیت حذف شد.");
    } elseif ($data === 'add_traveler_to_list') {
        // Start the process to add travelers to a list
        handleAddTravelerToListCommand($chat_id);
    } elseif (strpos($data, 'add_traveler_to_list_') === 0) {
        // Extract the list ID from the callback data
        $list_id = str_replace('add_traveler_to_list_', '', $data);
        // Fetch the list of travelers
        $travelers = listTravelers($chat_id);
        if (empty($travelers)) {
            sendMessage($chat_id, "شما هیچ مسافری برای افزودن به لیست ندارید.", getMainMenuKeyboard($chat_id));
            return;
        }
        // Create inline buttons for each traveler
        $inlineKeyboard = ['inline_keyboard' => []];
        foreach ($travelers as $traveler) {
            $inlineKeyboard['inline_keyboard'][] = [
                ['text' => "{$traveler['first_name']} {$traveler['last_name']}", 'callback_data' => "add_selected_traveler_to_list_{$list_id}_{$traveler['id']}"]
            ];
        }
        // Send the message with the inline buttons
        sendMessage($chat_id, "لطفاً مسافری که می‌خواهید به لیست اضافه کنید را انتخاب کنید:", $inlineKeyboard);
    } elseif (strpos($data, 'add_selected_traveler_to_list_') === 0) {
        // Extract the list ID and traveler ID from the callback data
        $parts = explode('_', $data);
        $list_id = $parts[5];
        $traveler_id = $parts[6];
        // Call the function to add the traveler to the list
        addTravelerToList($chat_id, $list_id, $traveler_id);
        // Notify the user
        sendMessage($chat_id, "مسافر با موفقیت به لیست اضافه شد.", getMainMenuKeyboard($chat_id));
    } elseif (strpos($data, 'trip_type_') === 0) {
        // Handle trip type selection
        $type = str_replace('trip_type_', '', $data);
        handleSetTripType($chat_id, $type);
    } elseif (strpos($data, 'coupe_') === 0) {
        // Handle coupe preference selection
        $coupe = str_replace('coupe_', '', $data);
        handleSetTripCoupe($chat_id, $coupe);
    } elseif (strpos($data, 'traveler_gender_') === 0) {
        $traveller_gender = str_replace('traveler_gender_', '', $data);
        handleSetTravelerGender($chat_id, $traveller_gender);
    } elseif (strpos($data, 'traveler_type_') === 0) {
        $traveler_type = str_replace('traveler_type_', '', $data);
        handleSetTravelerPassengerType($chat_id, $traveler_type);
    } elseif (strpos($data, 'wheelchair_') === 0) {
        $wheelchair = str_replace('wheelchair_', '', $data);
        handleSetTravelerWheelchair($chat_id, $wheelchair);
    } elseif (strpos($data, 'reserve_list_') === 0) {
        // اضافه کردن لاگ
        // file_put_contents('debug.log', "Received callback: " . $data . "\n", FILE_APPEND);
        handleListReservation($data, $chat_id);
    } elseif (strpos($data, 'food_') === 0) {
        // مدیریت انتخاب غذا
        handleFoodSelection($data, $chat_id, $callback_query['id']);
    } elseif (strpos($data, 'select_food_') === 0) {
        handleFoodSelection($data, $chat_id, $callback_query['id']);
    } elseif ($data === "add_private_info") {
        startAddingPrivateInfo($chat_id);
    } elseif ($data === "edit_private_info") {
        startAddingPrivateInfo($chat_id);
    } elseif (strpos($data, 'trip_route_') === 0) {
        $trip_route = str_replace('trip_route_', '', $data);
        handleSetTripRoute($chat_id, $trip_route);
    } else {
        // اگر callback ناشناخته بود
        answerCallbackQuery($callback_query['id'], "درخواست نامعتبر!");
    }
}

function handleStartCommand($chat_id, $update)
{
    $username = getUsernameFromMessage($update['message']);
    $username = escapeMarkdownV2($username);
    registerUser($chat_id, $username);

    $keyboard = getMainMenuKeyboard($chat_id);
    sendMessage($chat_id, "به ربات پیداکننده بلیط قطار خوش آمدید! لطفاً یکی از گزینه‌های زیر را انتخاب کنید:", $keyboard);
}

function handleIndianUser($chat_id)
{
    sendMessage($chat_id, text: "Hello dear! \n👉 If you are in India, check out this bot: \n[India Ticket Finder Bot](https://t.me/india_ticket_finder_bot)");
}
function handleApproveCommand($chat_id, $text)
{
    $parts = explode(' ', $text);
    if (isset($parts[1])) {
        approveUser($parts[1]);
        sendMessage($chat_id, "کاربر {$parts[1]} تأیید شد.");
    }
}

function handleRemoveTripCommand($chat_id, $text)
{
    if (isset($text) && is_numeric($text)) {
        $trip_id = (int) $text;
        removeUserTrip($chat_id, $trip_id);
        // sendMessage($chat_id, "سفر با ID {$trip_id} حذف شد.");
    } else {
        // sendMessage($chat_id, "لطفاً ID سفر را به درستی وارد کنید. مثال: /removetrip 1");
    }
}

function handleHelpCommand($chat_id)
{
    $helpText = "راهنمای دستورات:\n\n"
        . "*مدیریت مسافران:*\n"
        . "/addtraveler - افزودن مسافر\n"
        . "/showtravelers - نمایش لیست مسافران\n"
        . "/removetraveler شماره - حذف مسافر\n\n"
        . "*مدیریت لیست‌های مسافران:*\n"
        . "/addtravelerlist - ایجاد لیست جدید\n"
        . "/showtravelerlists - نمایش همه لیست‌ها\n"
        . "/removetravelerlist شماره - حذف لیست\n\n"
        . "*مدیریت سفرها:*\n"
        . "/settrip - تنظیم سفر\n"
        . "/showtrips - نمایش سفرها\n"
        . "/removetrip شماره - حذف سفر";
    sendMessage($chat_id, $helpText);
}

function handleSetTripCommand($chat_id)
{
    $inlineKeyboard = [
        'inline_keyboard' => [
            [
                // دکمه ثبت مسیر (با callback_data)
                ['text' => 'مشهد به تهران', 'callback_data' => 'trip_route_mashhad-tehran'],
                // دکمه جستجو مسیر (با فعال کردن اینلاین در همین چت)
                ['text' => 'جستجوی مسیر', 'switch_inline_query_current_chat' => '']
            ],
            [
                ['text' => 'لیست کامل همه‌ی شهرها', 'web_app' => ['url' => 'https://botstorage.s3.ir-thr-at1.arvanstorage.ir/bale-route.html']]
            ]
        ]
    ];

    setUserState($chat_id, 'SET_TRIP_ROUTE');
    sendMessage($chat_id, "لطفاً مسیر سفر را وارد کنید (لطفا از گزینه‌ی جستجوی مسیر استفاده کنید یا فرمت نوشتن مسیر را دقت داشته باشید. مثال: tehran-mashhad یا تهران-مشهد):", $inlineKeyboard);
}


function handleShowTripsCommand($chat_id)
{
    $trips = getUserTrips($chat_id);

    // Initialize the inline keyboard with the "افزودن سفر" button
    $inlineKeyboard = [
        'inline_keyboard' => [
            [
                ['text' => 'افزودن سفر', 'callback_data' => 'add_trip']
            ]
        ]
    ];

    if (empty($trips)) {
        sendMessage($chat_id, "شما هیچ سفری ثبت نکرده‌اید. برای تنظیم سفر روی گزینه‌ی تنظیم سفر کلیک کنید.", getMainMenuKeyboard($chat_id));
        return;
    }

    $message = "لیست سفرهای شما:\n";
    foreach ($trips as $trip) {
        $message .= "کد سفر: {$trip['id']}\n"
            . "مسیر: " . translateRoute($trip['route']) . "\n"
            . "تاریخ رفت: \u{200E}{$trip['date']}\n" // اعمال RLE برای درست شدن جهت تاریخ
            . "نوع بلیط: " . getTripType($trip['type']) . "\n"
            . "کوپه دربست: " . getTripCoupe($trip['coupe']) . "\n"
            . "-----------------------\n";
    }

    // Add the "حذف سفر" button if $trips is not empty
    $inlineKeyboard['inline_keyboard'][] = [
        ['text' => 'حذف سفر', 'callback_data' => 'remove_trip']
    ];

    sendMessage($chat_id, $message, $inlineKeyboard);
}

function handleWebAppData($chat_id, $route, $date)
{
    $temp_data = getUserState($chat_id)['temp_data'];
    $temp_data['route'] = $route;
    $temp_data['date'] = toEnglishNumbers($date);
    $temp_data['return_date'] = $date;
    setUserState($chat_id, 'SET_TRIP_COUNT', $temp_data);
    sendMessage($chat_id, "لطفاً تعداد بلیط‌ها را وارد کنید (مثال: 1):");
}

function handleSetTripRoute($chat_id, $text)
{
    $route = findRoute($text);
    setUserState($chat_id, 'SET_TRIP_DATE', ['route' => $route]);
    sendMessage($chat_id, "لطفاً تاریخ رفت را وارد کنید (مثال: \u{200E}۱۴۰۳-۱۲-۲۳):");
}

function handleSetTripDate($chat_id, $text)
{
    $date = $text;
    $temp_data = getUserState($chat_id)['temp_data'];
    $temp_data['date'] = $date;
    $temp_data['return_date'] = $date;
    setUserState($chat_id, 'SET_TRIP_COUNT', $temp_data);
    sendMessage($chat_id, "لطفاً تعداد بلیط‌ها را وارد کنید (مثال: 1):");
}


function handleSetTripReturnDate($chat_id, $text)
{
    $return_date = $text;
    $temp_data = getUserState($chat_id)['temp_data'];
    $temp_data['return_date'] = $return_date;
    setUserState($chat_id, 'SET_TRIP_COUNT', $temp_data);
    sendMessage($chat_id, "لطفاً تعداد بلیط‌ها را وارد کنید (مثال: 1):");
}

function handleSetTripCount($chat_id, $text)
{
    if (!is_numeric($text)) {
        sendMessage($chat_id, "لطفاً یک عدد معتبر وارد کنید (مثال: 1):");
        return;
    }
    $count = (int) $text;
    $temp_data = getUserState($chat_id)['temp_data'];
    $temp_data['count'] = $count;
    setUserState($chat_id, 'SET_TRIP_TYPE', $temp_data);
    // sendMessage($chat_id, "لطفاً نوع بلیط را وارد کنید (0: معمولی, 1: ویژه):");
    // Send inline keyboard for trip type selection
    $inlineKeyboard = [
        'inline_keyboard' => [
            [
                ['text' => 'معمولی', 'callback_data' => 'trip_type_0'],
                ['text' => 'ویژه‌ی برادران', 'callback_data' => 'trip_type_1'],
                ['text' => 'ویژه‌ی خواهران', 'callback_data' => 'trip_type_2']
            ]
        ]
    ];

    sendMessage($chat_id, "لطفاً نوع بلیط را انتخاب کنید:", $inlineKeyboard);
}

function handleSetTripType($chat_id, $text)
{
    // If the text is numeric, it means the user clicked on an inline button
    if (is_numeric($text)) {
        $type = (int) $text;
        $temp_data = getUserState($chat_id)['temp_data'];
        $temp_data['type'] = $type;
        setUserState($chat_id, 'SET_TRIP_COUPE', $temp_data);
        handleSetTripCoupe($chat_id, ''); // Move to the next step
        // Send inline keyboard for coupe preference
        $inlineKeyboard = [
            'inline_keyboard' => [
                [
                    ['text' => 'بله', 'callback_data' => 'coupe_1'],
                    ['text' => 'خیر', 'callback_data' => 'coupe_0']
                ]
            ]
        ];

        sendMessage($chat_id, "آیادرخواست کوپه‌ی دربست دارید؟", $inlineKeyboard);
        return;
    }
}

function handleSetTripCoupe($chat_id, $text)
{
    // If the text is numeric, it means the user clicked on an inline button
    if (is_numeric($text)) {
        $coupe = (int) $text;
        $temp_data = getUserState($chat_id)['temp_data'];
        $temp_data['coupe'] = $coupe;
        setUserState($chat_id, 'SET_TRIP_FILTER', $temp_data);
        handleSetTripFilter($chat_id, '0'); // Move to the next step
        return;
    }

}

function handleSetTripFilter($chat_id, $text)
{
    if (!is_numeric($text)) {
        sendMessage($chat_id, "لطفاً یک عدد معتبر وارد کنید (مثال: 0):");
        return;
    }
    $filter = (int) $text;
    $temp_data = getUserState($chat_id)['temp_data'];
    $temp_data['filter'] = $filter;
    saveUserTrip($chat_id, $temp_data);
    sendMessage($chat_id, "اطلاعات سفر شما با موفقیت ثبت شد.", getMainMenuKeyboard($chat_id));
    processUserTrips($chat_id);
    clearUserState($chat_id);
}

function handleAddTravelerCommand($chat_id)
{
    setUserState($chat_id, 'SET_TRAVELER_FIRST_NAME');
    sendMessage($chat_id, "لطفاً نام مسافر (نام کوچک) را وارد کنید:");
}

function handleSetTravelerFirstName($chat_id, $text)
{
    $first_name = $text;
    setUserState($chat_id, 'SET_TRAVELER_LAST_NAME', ['first_name' => $first_name]);
    sendMessage($chat_id, "لطفاً نام‌خانوادگی مسافر را وارد کنید:");
}

function handleSetTravelerLastName($chat_id, $text)
{
    $last_name = $text;
    $temp_data = getUserState($chat_id)['temp_data'];
    $temp_data['last_name'] = $last_name;
    setUserState($chat_id, 'SET_TRAVELER_NATIONAL_CODE', $temp_data);
    sendMessage($chat_id, "لطفاً کد ملی مسافر را وارد کنید:");
}

function handleSetTravelerNationalCode($chat_id, $text)
{
    $national_code = $text;
    $temp_data = getUserState($chat_id)['temp_data'];
    $temp_data['national_code'] = $national_code;
    setUserState($chat_id, 'SET_TRAVELER_PASSENGER_TYPE', $temp_data);
    // Send inline keyboard for passenger type
    $inlineKeyboard = [
        'inline_keyboard' => [
            [
                ['text' => 'بزرگسال', 'callback_data' => 'traveler_type_0'],
                ['text' => 'کودک', 'callback_data' => 'traveler_type_1'],
                ['text' => 'نوزاد', 'callback_data' => 'traveler_type_2']
            ]
        ]
    ];
    sendMessage($chat_id, "لطفاً بازه‌ی سنی مسافر را انتخاب کنید:", $inlineKeyboard);
}

function handleSetTravelerPassengerType($chat_id, $text)
{
    if (!is_numeric($text)) {
        sendMessage($chat_id, "لطفاً یک عدد معتبر وارد کنید (مثال: 1):");
        return;
    }
    $passenger_type = (int) $text;
    $temp_data = getUserState($chat_id)['temp_data'];
    $temp_data['passenger_type'] = $passenger_type;
    setUserState($chat_id, 'SET_TRAVELER_GENDER', $temp_data);
    // Send inline keyboard for gender
    $inlineKeyboard = [
        'inline_keyboard' => [
            [
                ['text' => 'آقا', 'callback_data' => 'traveler_gender_1'],
                ['text' => 'خانم', 'callback_data' => 'traveler_gender_2']
            ]
        ]
    ];
    sendMessage($chat_id, "لطفاً جنسیت مسافر را انتخاب کنید:", $inlineKeyboard);
}

function handleSetTravelerGender($chat_id, $text)
{
    if (!is_numeric($text)) {
        sendMessage($chat_id, "لطفاً یک عدد معتبر وارد کنید (مثال: 1):");
        return;
    }
    $gender = (int) $text;
    $temp_data = getUserState($chat_id)['temp_data'];
    $temp_data['gender'] = $gender;
    // setUserState($chat_id, 'SET_TRAVELER_SERVICES', $temp_data);
    // sendMessage($chat_id, "لطفاً خدمات مورد نیاز را وارد کنید (مثال: [] یا [\"service1\",\"service2\"]):");
    setUserState($chat_id, 'SET_TRAVELER_WHEELCHAIR', $temp_data);
    // Send inline keyboard for wheelchair preference
    $inlineKeyboard = [
        'inline_keyboard' => [
            [
                ['text' => 'خیر', 'callback_data' => 'wheelchair_0'],
                ['text' => 'بله', 'callback_data' => 'wheelchair_1']
            ]
        ]
    ];

    sendMessage($chat_id, "لطفاً نیاز به ویلچر را انتخاب کنید:", $inlineKeyboard);
}

function handleSetTravelerServices($chat_id, $text)
{
    $services = json_decode($text, true) ?? [];
    $temp_data = getUserState($chat_id)['temp_data'];
    $temp_data['services'] = $services;
}

function handleSetTravelerWheelchair($chat_id, $text)
{
    if (!is_numeric($text)) {
        sendMessage($chat_id, "لطفاً یک عدد معتبر وارد کنید (مثال: 0):");
        return;
    }
    $wheelchair = (int) $text;
    $temp_data = getUserState($chat_id)['temp_data'];
    $temp_data['wheelchair'] = $wheelchair;
    try {
        addTraveler($chat_id, $temp_data);
        $typeText = getPassengerTypeText($temp_data['passenger_type']);
        $genderText = getGenderText($temp_data['gender']);
        sendMessage($chat_id, "مسافر *{$temp_data['first_name']} {$temp_data['last_name']}* با مشخصات زیر اضافه شد:\n"
            . "نوع مسافر: $typeText\n"
            . "جنسیت: $genderText\n"
            . "کد ملی: {$temp_data['national_code']}\n"
            . "نیاز به ویلچر: " . ($temp_data['wheelchair'] ? "بله" : "خیر"));
        handleShowTravelersCommand($chat_id);
    } catch (Exception $e) {
        sendMessage($chat_id, "خطا در ثبت مسافر. لطفاً دوباره تلاش کنید.");
    }
    clearUserState($chat_id);
}

function handleAddTravelerListCommand($chat_id)
{
    setUserState($chat_id, 'SET_TRAVELER_LIST_NAME');
    sendMessage($chat_id, "لطفاً نام لیست مسافران را وارد کنید:");
}

function handleSetTravelerListName($chat_id, $text)
{
    $list_name = $text;
    try {
        createTravelerList($chat_id, $list_name);
        sendMessage($chat_id, "لیست مسافران *{$list_name}* با موفقیت ایجاد شد.", getMainMenuKeyboard($chat_id));
        clearUserState($chat_id);
    } catch (Exception $e) {
        sendMessage($chat_id, "خطا در ایجاد لیست مسافران. لطفاً مطمئن شوید همه شماره‌های مسافران معتبر هستند.", null);
    }
}

function handleSetTravelerListMembers($chat_id, $text)
{
    $traveler_ids = array_map('intval', explode(',', $text));
    $temp_data = getUserState($chat_id)['temp_data'];
}

// handleSetPrivateInfo($chat_id, $text)
// {

// }

function handleShowTravelersCommand($chat_id)
{
    $travelers = listTravelers($chat_id);

    // Initialize the inline keyboard with the "افزودن مسافر" button
    $inlineKeyboard = [
        'inline_keyboard' => [
            [
                ['text' => 'افزودن مسافر', 'callback_data' => 'add_traveler']
            ]
        ]
    ];

    if (empty($travelers)) {
        sendMessage($chat_id, "شما هنوز هیچ مسافری ثبت نکرده‌اید.", $inlineKeyboard);
        return;
    }

    $message = "لیست مسافران شما:\n\n";
    foreach ($travelers as $index => $traveler) {
        $typeText = getPassengerTypeText($traveler['passenger_type']);
        $genderText = getGenderText($traveler['gender']);
        $message .= "*" . ($index + 1) . "* {$traveler['first_name']} {$traveler['last_name']}\n"
            . "نوع: $typeText | جنسیت: $genderText\n"
            . "کد ملی: {$traveler['national_code']}\n"
            . "───────────────\n";
    }

    // Add the "حذف مسافر" button if $travelers is not empty
    $inlineKeyboard['inline_keyboard'][] = [
        ['text' => 'حذف مسافر', 'callback_data' => 'remove_traveler']
    ];

    sendMessage($chat_id, $message, $inlineKeyboard);
}
function handleShowTravelerListsCommand($chat_id)
{
    $lists = listTravelerLists($chat_id);

    // Initialize the inline keyboard with the "افزودن لیست مسافر" button
    $inlineKeyboard = [
        'inline_keyboard' => [
            [
                ['text' => 'افزودن لیست مسافر', 'callback_data' => 'add_traveler_list']
            ]
        ]
    ];

    if (empty($lists)) {
        sendMessage($chat_id, "شما هنوز هیچ لیست مسافری ایجاد نکرده‌اید.", $inlineKeyboard);
        return;
    }

    $message = "لیست‌های مسافران شما:\n\n";
    foreach ($lists as $index => $list) {
        $message .= "*" . ($index + 1) . ".* {$list['name']}\n" // نمایش ایندکس (شروع از ۱)
            . "تعداد مسافران: {$list['member_count']}\n"
            . "مسافران: {$list['members']}\n"
            . "───────────────\n";
    }


    // Add the "حذف لیست مسافر" and "افزودن مسافر به لیست" buttons if $lists is not empty
    $inlineKeyboard['inline_keyboard'][] = [
        ['text' => 'حذف لیست مسافر', 'callback_data' => 'remove_list_of_traveler']
    ];
    $inlineKeyboard['inline_keyboard'][] = [
        ['text' => 'افزودن مسافر به لیست', 'callback_data' => 'add_traveler_to_list']
    ];

    sendMessage($chat_id, $message, $inlineKeyboard);
}

function handleAddTravelerToListCommand($chat_id)
{
    // Fetch the list of traveler lists
    $lists = listTravelerLists($chat_id);
    if (empty($lists)) {
        sendMessage($chat_id, "شما هیچ لیست مسافری برای افزودن مسافر ندارید.");
        return;
    }

    // Create inline buttons for each traveler list
    $inlineKeyboard = ['inline_keyboard' => []];
    foreach ($lists as $list) {
        $inlineKeyboard['inline_keyboard'][] = [
            ['text' => $list['name'], 'callback_data' => "add_traveler_to_list_{$list['id']}"]
        ];
    }

    // Send the message with the inline buttons
    sendMessage($chat_id, "لطفاً لیستی که می‌خواهید مسافر به آن اضافه کنید را انتخاب کنید:", $inlineKeyboard);
}

function handleRemoveTravelerCommand($chat_id, $text)
{
    $parts = explode(' ', $text);
    if (isset($parts[1]) && is_numeric($parts[1])) {
        removeTraveler($chat_id, (int) $parts[1]);
        sendMessage($chat_id, "مسافر با کد {$parts[1]} حذف شد.");
    } else {
        sendMessage($chat_id, "فرمت دستور صحیح نیست. مثال:\n/removetraveler 1");
    }
}

function handleRemoveTravelerListCommand($chat_id, $text)
{
    $parts = explode(' ', $text);
    if (isset($parts[1]) && is_numeric($parts[1])) {
        removeTravelerList($chat_id, (int) $parts[1]);
        sendMessage($chat_id, "لیست مسافران با کد {$parts[1]} حذف شد.");
    } else {
        sendMessage($chat_id, "فرمت دستور صحیح نیست. مثال:\n/removetravelerlist 1");
    }
}

// Show all of trips for a user
function showUserTrips($chat_id)
{
    $trips = getUserTrips($chat_id);
    if (empty($trips)) {
        sendMessage($chat_id, "شما هیچ سفری ثبت نکرده‌اید.", getMainMenuKeyboard($chat_id));
        return;
    }

    $message = "لیست سفرهای شما:\n";
    foreach ($trips as $trip) {
        $message .= "کد: {$trip['id']}\n"
            . "مسیر: " . translateRoute($trip['route']) . "\n"
            . "تاریخ رفت: {$trip['date']}\n"
            . "نوع: " . getTripType($trip['type']) . "\n"
            . "کوپه دربست: " . getTripCoupe($trip['coupe']) . "\n"
            . "-----------------------\n";
    }
    sendMessage($chat_id, $message);
}


function getTripType($type)
{
    $typeMap = [
        0 => "معمولی",
        1 => "ویژه‌ی برادران",
        2 => "ویژه‌ی خواهران"
    ];

    return $typeMap[$type] ?? "نامشخص"; // مقدار پیش‌فرض برای مقادیر نامعتبر
}

function getTripCoupe($type)
{
    $typeMap = [
        0 => "خیر",
        1 => "بله"
    ];

    return $typeMap[$type] ?? "نامشخص"; // مقدار پیش‌فرض برای مقادیر نامعتبر
}


// Remove a trip for a user
function removeUserTrip($chat_id, $trip_id)
{
    $db = initDatabase();
    $stmt = $db->prepare("DELETE FROM user_trips WHERE id = :id AND chat_id = :chat_id");
    $stmt->bindValue(':id', $trip_id, SQLITE3_INTEGER);
    $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
    $result = $stmt->execute();

    if ($db->changes() > 0) {
        sendMessage($chat_id, "سفر با کد: $trip_id با موفقیت حذف شد.");
    } else {
        sendMessage($chat_id, "سفری با این کد پیدا نشد یا شما اجازه حذف آن را ندارید.");
    }
}

// تابع اضافه کردن مسافر جدید
function addTraveler($chat_id, $data)
{
    $db = initDatabase();
    $stmt = $db->prepare("INSERT INTO travelers (
        chat_id, first_name, last_name, national_code, 
        passenger_type, gender, services, wheelchair
    ) VALUES (
        :chat_id, :first_name, :last_name, :national_code,
        :passenger_type, :gender, :services, :wheelchair
    )");

    $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
    $stmt->bindValue(':first_name', $data['first_name'], SQLITE3_TEXT);
    $stmt->bindValue(':last_name', $data['last_name'], SQLITE3_TEXT);
    $stmt->bindValue(':national_code', $data['national_code'], SQLITE3_TEXT);
    $stmt->bindValue(':passenger_type', $data['passenger_type'], SQLITE3_INTEGER);
    $stmt->bindValue(':gender', $data['gender'], SQLITE3_INTEGER);
    $stmt->bindValue(':services', json_encode($data['services'] ?? []), SQLITE3_TEXT);
    $stmt->bindValue(':wheelchair', $data['wheelchair'] ?? 0, SQLITE3_INTEGER);

    return $stmt->execute();
}

// تابع نمایش لیست مسافران
function listTravelers($chat_id)
{
    $db = initDatabase();
    $stmt = $db->prepare("SELECT * FROM travelers WHERE chat_id = :chat_id ORDER BY created_at DESC");
    $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
    $result = $stmt->execute();

    $travelers = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $travelers[] = $row;
    }
    return $travelers;
}

// تابع ایجاد لیست جدید مسافران
function createTravelerList($chat_id, $name)
{
    $db = initDatabase();
    $db->exec('BEGIN TRANSACTION');

    try {
        // ایجاد لیست جدید
        $stmt = $db->prepare("INSERT INTO traveler_lists (chat_id, name) VALUES (:chat_id, :name)");
        $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->execute();

        $db->exec('COMMIT');
        return true;
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

// تابع نمایش لیست‌های مسافران
function listTravelerLists($chat_id)
{
    $db = initDatabase();

    try {
        // ابتدا لیست‌ها را می‌گیریم
        $stmt = $db->prepare("
            SELECT id, name, members
            FROM traveler_lists
            WHERE chat_id = :chat_id
            ORDER BY created_at DESC
        ");

        $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
        $result = $stmt->execute();

        $lists = [];
        while ($list = $result->fetchArray(SQLITE3_ASSOC)) {
            $members = json_decode($list['members'], true) ?: [];
            $member_info = [];

            if (!empty($members)) {
                // گرفتن اطلاعات مسافران
                $placeholders = str_repeat('?,', count($members) - 1) . '?';
                $query = "
                    SELECT 
                        id,
                        first_name,
                        last_name,
                        passenger_type
                    FROM travelers
                    WHERE id IN ($placeholders)
                    AND chat_id = ?
                ";

                $stmt2 = $db->prepare($query);
                $index = 1;
                foreach ($members as $member_id) {
                    $stmt2->bindValue($index++, $member_id, SQLITE3_INTEGER);
                }
                $stmt2->bindValue($index, $chat_id, SQLITE3_TEXT);

                $result2 = $stmt2->execute();

                while ($member = $result2->fetchArray(SQLITE3_ASSOC)) {
                    $type_text = '';
                    switch ($member['passenger_type']) {
                        case 0:
                            $type_text = 'بزرگسال';
                            break;
                        case 1:
                            $type_text = 'کودک';
                            break;
                        case 2:
                            $type_text = 'نوزاد';
                            break;
                    }
                    $member_info[] = $member['first_name'] . ' ' . $member['last_name'] . ' (' . $type_text . ')';
                }
            }

            $list['member_count'] = count($members);
            $list['members'] = empty($member_info) ? 'هیچ مسافری در این لیست وجود ندارد' : implode(' | ', $member_info);

            $lists[] = $list;
        }

        return $lists;

    } catch (Exception $e) {
        return [];
    }
}

// اول جدول موقت برای ذخیره انتخاب غذاها رو می‌سازیم
function createTemporaryFoodTable()
{
    $db = initDatabase();
    $db->exec("
        CREATE TABLE IF NOT EXISTS temporary_food_selections (
            chat_id TEXT,
            list_id INTEGER,
            passenger_index INTEGER,
            food_id TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (chat_id, list_id, passenger_index)
        )
    ");
}

// ذخیره موقت انتخاب غذا
function saveTemporaryFoodSelection($chat_id, $list_id, $passenger_index, $food_id)
{
    $db = initDatabase();
    try {
        createTemporaryFoodTable();

        $stmt = $db->prepare("
            INSERT OR REPLACE INTO temporary_food_selections 
                (chat_id, list_id, passenger_index, food_id)
            VALUES 
                (:chat_id, :list_id, :passenger_index, :food_id)
        ");

        $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
        $stmt->bindValue(':list_id', $list_id, SQLITE3_INTEGER);
        $stmt->bindValue(':passenger_index', $passenger_index, SQLITE3_INTEGER);
        $stmt->bindValue(':food_id', $food_id, SQLITE3_TEXT);

        return $stmt->execute();
    } catch (Exception $e) {
        // در صورت خطا لاگ کنیم
        error_log("Error in saveTemporaryFoodSelection: " . $e->getMessage());
        return false;
    }
}

// بررسی اینکه آیا همه مسافران غذایشان را انتخاب کرده‌اند
function isAllFoodSelected($chat_id, $list_id)
{
    $db = initDatabase();
    try {
        // اول تعداد مسافران لیست را می‌گیریم
        $stmt = $db->prepare("
            SELECT members
            FROM traveler_lists
            WHERE id = :list_id AND chat_id = :chat_id
        ");

        $stmt->bindValue(':list_id', $list_id, SQLITE3_INTEGER);
        $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);

        $result = $stmt->execute();
        $list = $result->fetchArray(SQLITE3_ASSOC);

        if (!$list) {
            return false;
        }

        $members = json_decode($list['members'], true) ?: [];
        $total_members = count($members);

        // حالا تعداد انتخاب‌های غذا را می‌شماریم
        $stmt = $db->prepare("
            SELECT COUNT(*) as selected_count
            FROM temporary_food_selections
            WHERE chat_id = :chat_id AND list_id = :list_id
        ");

        $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
        $stmt->bindValue(':list_id', $list_id, SQLITE3_INTEGER);

        $result = $stmt->execute();
        $count = $result->fetchArray(SQLITE3_ASSOC);

        // اگر تعداد انتخاب‌ها برابر تعداد مسافران باشد، یعنی همه انتخاب کرده‌اند
        return $count['selected_count'] === $total_members;

    } catch (Exception $e) {
        error_log("Error in isAllFoodSelected: " . $e->getMessage());
        return false;
    }
}

// گرفتن لیست مسافران با غذاهای انتخاب شده
function getTravelersWithFood($chat_id, $list_id)
{
    $db = initDatabase();
    try {
        // اول اطلاعات مسافران را می‌گیریم
        $travelers = getTravelersFromList($list_id, $chat_id);

        if (empty($travelers)) {
            return [];
        }

        // حالا غذاهای انتخاب شده را می‌گیریم
        $stmt = $db->prepare("
            SELECT passenger_index, food_id
            FROM temporary_food_selections
            WHERE chat_id = :chat_id AND list_id = :list_id
        ");

        $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
        $stmt->bindValue(':list_id', $list_id, SQLITE3_INTEGER);

        $result = $stmt->execute();

        $food_selections = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $food_selections[$row['passenger_index']] = $row['food_id'];
        }

        // ترکیب اطلاعات مسافران با غذاهای انتخاب شده
        foreach ($travelers as $index => &$traveler) {
            $traveler['food_id'] = $food_selections[$index] ?? null;

            // اگر غذایی انتخاب نشده باشد، غذای پیش‌فرض (بدون غذا) را انتخاب می‌کنیم
            if (!$traveler['food_id']) {
                $traveler['food_id'] = "642444"; // کد غذای "بدون غذا"
            }
        }

        return $travelers;

    } catch (Exception $e) {
        error_log("Error in getTravelersWithFood: " . $e->getMessage());
        return [];
    }
}

// پاک کردن اطلاعات موقت
function clearTemporaryFoodSelections($chat_id, $list_id)
{
    $db = initDatabase();
    try {
        $stmt = $db->prepare("
            DELETE FROM temporary_food_selections
            WHERE chat_id = :chat_id AND list_id = :list_id
        ");

        $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
        $stmt->bindValue(':list_id', $list_id, SQLITE3_INTEGER);

        return $stmt->execute();

    } catch (Exception $e) {
        error_log("Error in clearTemporaryFoodSelections: " . $e->getMessage());
        return false;
    }
}

// پاک کردن انتخاب‌های قدیمی (می‌تونه به صورت کرون جاب اجرا بشه)
function cleanupOldFoodSelections($hours = 24)
{
    $db = initDatabase();
    try {
        $stmt = $db->prepare("
            DELETE FROM temporary_food_selections
            WHERE created_at < datetime('now', :hours || ' hours')
        ");

        $stmt->bindValue(':hours', "-$hours", SQLITE3_TEXT);

        return $stmt->execute();

    } catch (Exception $e) {
        error_log("Error in cleanupOldFoodSelections: " . $e->getMessage());
        return false;
    }
}

// یک تابع کمکی برای گرفتن قیمت غذا از کد آن
function getFoodPrice($food_id, $ticket_id, $passenger_count)
{
    $foodOptions = getFoodOptions($ticket_id, $passenger_count);

    foreach ($foodOptions as $option) {
        if ($option['id'] === $food_id) {
            // استخراج قیمت از عنوان غذا (مثال: "چلوکباب ----قیمت: ٢,٠٨٠,٠٠٠ ریال")
            if (preg_match('/قیمت:\s*([\d,]+)\s*ریال/', $option['title'], $matches)) {
                return (int) str_replace(',', '', $matches[1]);
            }
            return 0;
        }
    }

    return 0;
}


function getTravelersFromList($list_id, $chat_id)
{
    $db = initDatabase();
    try {
        // ابتدا اطلاعات لیست را می‌گیریم
        $stmt = $db->prepare("
            SELECT members
            FROM traveler_lists
            WHERE id = :list_id AND chat_id = :chat_id
        ");
        $stmt->bindValue(':list_id', $list_id, SQLITE3_INTEGER);
        $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
        $result = $stmt->execute();
        $list = $result->fetchArray(SQLITE3_ASSOC);

        if (!$list) {
            return [];
        }

        $members = json_decode($list['members'], true) ?: [];
        if (empty($members)) {
            return [];
        }

        // حالا اطلاعات مسافران را می‌گیریم
        $placeholders = str_repeat('?,', count($members) - 1) . '?';
        $query = "
            SELECT
                id,
                first_name,
                last_name,
                national_code,
                gender,
                passenger_type
            FROM travelers
            WHERE id IN ($placeholders)
            AND chat_id = ?
        ";

        $stmt = $db->prepare($query);
        $index = 1;
        foreach ($members as $member_id) {
            $stmt->bindValue($index++, $member_id, SQLITE3_INTEGER);
        }
        $stmt->bindValue($index, $chat_id, SQLITE3_TEXT);

        $result = $stmt->execute();

        $travelers = [];
        while ($traveler = $result->fetchArray(SQLITE3_ASSOC)) {
            $travelers[] = $traveler;
        }

        return $travelers;
    } catch (Exception $e) {
        return [];
    }
}

function getFoodOptions($ticketId, $passengerCount)
{
    $url = "https://ghasedak24.com/train/reservation/{$ticketId}/0/{$passengerCount}-0-0/0";
    // file_put_contents('debug.log', "Requesting URL: $url\n", FILE_APPEND);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'accept: text/html,application/xhtml+xml,application/xml;q=0.9',
        'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    // file_put_contents('debug.log', "HTTP Code: $httpCode\n", FILE_APPEND);
    if ($error) {
        file_put_contents('debug.log', "CURL Error: $error\n", FILE_APPEND);
    }

    if ($httpCode !== 200 || empty($response)) {
        file_put_contents('debug.log', "Failed to get response\n", FILE_APPEND);
        return [];
    }

    // لاگ کردن پاسخ برای بررسی
    // file_put_contents('debug.log', "Response sample: " . substr($response, 0, 500) . "\n", FILE_APPEND);

    // استخراج گزینه‌های غذا
    preg_match_all('/<option[^>]*value="(\d+)"[^>]*>\s*([^<]+?)\s*<\/option>/u', $response, $matches);

    // file_put_contents('debug.log', "Matches found: " . json_encode($matches, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

    $foodOptions = [];
    if (!empty($matches[1]) && !empty($matches[2])) {
        for ($i = 0; $i < count($matches[1]); $i++) {
            $foodOptions[] = [
                'id' => trim($matches[1][$i]),
                'title' => trim(preg_replace('/\s+/', ' ', $matches[2][$i])) // حذف فاصله‌های اضافی
            ];
        }
    }

    // file_put_contents('debug.log', "Final food options: " . json_encode($foodOptions, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    return $foodOptions;
}


function makeReservation($ticketId, $passengers, $user, $coupe)
{
    $url = "https://ghasedak24.com/train/reservation/{$ticketId}/0";

    // تبدیل اطلاعات مسافران به فرمت مورد نیاز
    $formattedPassengers = [];
    foreach ($passengers as $index => $passenger) {
        $formattedPassengers[] = [
            'id' => $index + 1,
            'depFoodPrice' => 0, // این مقدار باید بر اساس غذای انتخابی آپدیت شود
            'retFoodPrice' => 0,
            'sexType' => $passenger['gender'],
            'name' => $passenger['first_name'],
            'family' => $passenger['last_name'],
            'nationalCode' => $passenger['national_code'],
            'food' => $passenger['food_id'],
            'return_food' => '',
            'ageType' => $passenger['passenger_type'] == 0 ? 'adult' : ($passenger['passenger_type'] == 1 ? 'child' : 'infant'),
            'isForeign' => false,
            'isWheelchairOrdered' => false,
            'errors' => []
        ];
    }

    $postData = [
        'passengers' => $formattedPassengers,
        'user' => [
            'fullName' => $user['fullName'],
            'email' => $user['email'],
            'mobileNumber' => '0' . $user['mobileNumber']
        ],
        'coupe' => $coupe,
        'safarmarketId' => ''
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'accept: application/json',
        'content-type: application/json',
        'origin: https://ghasedak24.com',
        'referer: https://ghasedak24.com/train/reservation/',
        'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36'
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

function modifyTicketMessage($message, $userTrip, $ticket, $lists)
{
    $keyboard = [
        [
            ['text' => 'تهیه‌ی بلیط', 'url' => 'https://ghasedak24.com/train/reservation/' . $ticket['id'] . '/0/' . $userTrip['count'] . '-0-0/' . $userTrip['coupe']]
        ]
    ];

    // اضافه کردن دکمه برای هر لیست
    foreach ($lists as $list) {
        $member_count = toPersianNumbers($list['member_count']);
        $keyboard[] = [
            [
                'text' => "رزرو برای لیست {$list['name']} ({$member_count} نفر)",
                'callback_data' => "reserve_list_{$list['id']}_{$ticket['id']}_{$userTrip['coupe']}"
            ]
        ];
    }

    $replyMarkup = ['inline_keyboard' => $keyboard];

    return [
        'message' => $message,
        'reply_markup' => $replyMarkup
    ];
}

// تابع اصلی برای شروع فرآیند رزرو با لیست
function handleListReservation($callback_data, $chat_id)
{
    // file_put_contents('debug.log', "Starting handleListReservation with data: " . $callback_data . "\n", FILE_APPEND);

    // استخراج شناسه لیست و بلیط
    $parts = explode('_', $callback_data);
    if (count($parts) !== 5) {
        file_put_contents('debug.log', "Invalid callback data format\n", FILE_APPEND);
        return "⚠️ خطا: فرمت داده نامعتبر است";
    }

    $list_id = $parts[2];
    $ticket_id = $parts[3];
    $coupe_data = $parts[4];

    // دریافت لیست مسافران
    $travelers = getTravelersFromList($list_id, $chat_id);
    if (empty($travelers)) {
        return "⚠️ خطا در دریافت اطلاعات مسافران";
    }


    // ذخیره اطلاعات در سشن برای استفاده بعدی
    saveReservationSession($chat_id, [
        'list_id' => $list_id,
        'ticket_id' => $ticket_id,
        'current_passenger_index' => 0,
        'total_passengers' => count(value: $travelers),
        'coupe' => $coupe_data
    ]);

    // دریافت گزینه‌های غذا
    $foodOptions = getFoodOptions($ticket_id, count($travelers));
    if (empty($foodOptions)) {
        return completeReservation($chat_id);
    }

    // نمایش منوی انتخاب غذا برای اولین مسافر
    return showFoodSelectionForPassenger($chat_id, $travelers[0], $foodOptions, 0);
}

// تابع نمایش منوی انتخاب غذا برای هر مسافر
function showFoodSelectionForPassenger($chat_id, $traveler, $foodOptions, $index)
{
    $keyboard = [];
    foreach ($foodOptions as $food) {
        $keyboard[] = [['text' => $food['title'], 'callback_data' => "select_food_{$index}_{$food['id']}"]];
    }

    // file_put_contents('debug.log', "Generated keyboard: " . print_r($keyboard, true) . "\n", FILE_APPEND);

    $message = "🍽 لطفاً غذای {$traveler['first_name']} {$traveler['last_name']} را انتخاب کنید:";
    return sendMessage($chat_id, $message, ['inline_keyboard' => $keyboard]);
}
// تابع مدیریت انتخاب غذا
function handleFoodSelection($callback_data, $chat_id, $callback_query_id = null)
{
    // file_put_contents('debug.log', "Received callback_data in handleFoodSelection: " . print_r($callback_data, true) . "\n", FILE_APPEND);

    $parts = explode('_', $callback_data);
    // file_put_contents('debug.log', "Parts: " . print_r($parts, true) . "\n", FILE_APPEND);

    $passenger_index = $parts[2];
    $food_id = $parts[3];

    $session = getReservationSession($chat_id);
    // file_put_contents('debug.log', "Session data: " . print_r($session, true) . "\n", FILE_APPEND);

    if (!$session) {
        sendMessage($chat_id, "⚠️ خطا: اطلاعات یافت نشد");
        return;
    }

    $total_passengers = $session['total_passengers'];
    $next_index = intval($passenger_index) + 1;

    // ذخیره انتخاب غذا
    saveTemporaryFoodSelection($chat_id, $session['list_id'], $passenger_index, $food_id);

    if ($next_index < $total_passengers) {
        // هنوز مسافران دیگری باقی مانده‌اند
        $travelers = getTravelersFromList($session['list_id'], $chat_id);
        $foodOptions = getFoodOptions($session['ticket_id'], $total_passengers);

        if ($callback_query_id) {
            answerCallbackQuery($callback_query_id, "✅ غذا برای مسافر " . ($passenger_index + 1) . " ثبت شد");
        }

        // ارسال پیام تأیید به کاربر
        sendMessage($chat_id, "✅ غذا برای مسافر " . ($passenger_index + 1) . " ثبت شد");

        // نمایش منوی غذا برای مسافر بعدی
        return showFoodSelectionForPassenger($chat_id, $travelers[$next_index], $foodOptions, $next_index);
    } else {
        // همه مسافران غذای خود را انتخاب کرده‌اند
        sendMessage($chat_id, "✅ غذای همه مسافران ثبت شد. در حال تکمیل رزرو...");
        return completeReservation($chat_id);
    }
}

// تابع تکمیل رزرو
function completeReservation($chat_id)
{
    $session = getReservationSession($chat_id);
    $user = getPrivateInfo($chat_id);
    $travelers = getTravelersWithFood($chat_id, $session['list_id']);

    $result = makeReservation($session['ticket_id'], $travelers, $user, $session['coupe']);

    $keyboard = [
        [
            ['text' => 'پرداخت و تهیه‌ی بلیط', 'url' => 'https://ghasedak24.com/train/confirm/' . $result['rsid']]
        ]
    ];

    $replyMarkup = ['inline_keyboard' => $keyboard];

    if ($result['status'] === 'success') {
        $message = "خب خب خب ...\n"
            . "خدا رو شکر تونستیم اطلاعات شما رو ثبت کنیم 🤲😊\n"
            . "حالا کافیه برای پرداخت هزینه‌ی بلیط و نهایی شدن خرید، از گزینه‌ی پایین این پیام استفاده کنین 👇\n"
            . "دقت کنین که تا پرداخت نشه، بلیطی به نام شما صادر نمی‌شه 🙈";
    } else {
        $message = "ای وای! نتونستیم اطلاعاتتون رو \n"
            . "لطفاً دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.";
    }

    // پاک کردن اطلاعات موقت و سشن
    clearTemporaryFoodSelections($chat_id, $session['list_id']);
    clearReservationSession($chat_id);

    return sendMessage($chat_id, $message, $replyMarkup);
}

// توابع کمکی برای مدیریت سشن
function saveReservationSession($chat_id, $data)
{
    $db = initDatabase();
    $stmt = $db->prepare("
        INSERT OR REPLACE INTO reservation_sessions 
        (chat_id, session_data, created_at)
        VALUES (:chat_id, :session_data, datetime('now'))
    ");
    $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
    $stmt->bindValue(':session_data', json_encode($data), SQLITE3_TEXT);
    $stmt->execute();
}

function getReservationSession($chat_id)
{
    $db = initDatabase();
    $stmt = $db->prepare("
        SELECT session_data 
        FROM reservation_sessions 
        WHERE chat_id = :chat_id
    ");
    $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row ? json_decode($row['session_data'], true) : null;
}

function clearReservationSession($chat_id)
{
    $db = initDatabase();
    $stmt = $db->prepare("
        DELETE FROM reservation_sessions 
        WHERE chat_id = :chat_id
    ");
    $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
    $stmt->execute();
}

function answerCallbackQuery($callback_query_id, $text, $show_alert = false)
{
    global $telegram_api;

    $url = $telegram_api . "/answerCallbackQuery";

    $postData = [
        'callback_query_id' => $callback_query_id,
        'text' => $text,
        'show_alert' => $show_alert
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

// برای راحتی کار، یک تابع هم برای آپدیت پیام‌های قبلی می‌سازیم
function editMessage($chat_id, $message_id, $text, $reply_markup = null, $isPersian = true)
{
    $botToken = $GLOBALS['botToken'];
    $url = "https://tapi.bale.ai/bot$botToken/editMessageText";

    if ($isPersian) {
        $text = toPersianNumbers($text);
    }

    $postData = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text,
        'parse_mode' => 'Markdown'
    ];

    if ($reply_markup !== null) {
        $postData['reply_markup'] = json_encode($reply_markup);
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

    $response = curl_exec($ch);
    curl_close($ch);

    $responseArray = json_decode($response, true);
    return $responseArray['ok'] ?? false;
}

// تابع کمکی برای تبدیل نوع مسافر به متن
function getPassengerTypeText($type)
{
    switch ($type) {
        case 0:
            return "بزرگسال";
        case 1:
            return "کودک";
        case 2:
            return "نوزاد";
        default:
            return "نامشخص";
    }
}

// تابع کمکی برای تبدیل جنسیت به متن
function getGenderText($gender)
{
    switch ($gender) {
        case 1:
            return "آقا";
        case 2:
            return "خانم";
        default:
            return "نامشخص";
    }
}

// Remove traveler function
function removeTraveler($chat_id, $traveler_id)
{
    $db = initDatabase();
    $stmt = $db->prepare("DELETE FROM travelers WHERE id = :id AND chat_id = :chat_id");
    $stmt->bindValue(':id', $traveler_id, SQLITE3_INTEGER);
    $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
    $result = $stmt->execute();

    if ($db->changes() > 0) {
        sendMessage($chat_id, "مسافر با شماره $traveler_id با موفقیت حذف شد.", getMainMenuKeyboard($chat_id));
    } else {
        sendMessage($chat_id, "مسافری با این شماره یافت نشد یا شما اجازه حذف آن را ندارید.");
    }
}

// Remove traveler list function
function removeTravelerList($chat_id, $list_id)
{
    $db = initDatabase();
    $stmt = $db->prepare("DELETE FROM traveler_lists WHERE id = :id AND chat_id = :chat_id");
    $stmt->bindValue(':id', $list_id, SQLITE3_INTEGER);
    $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
    $result = $stmt->execute();

    if ($db->changes() > 0) {
        sendMessage($chat_id, "لیست مسافران با موفقیت حذف شد.", getMainMenuKeyboard($chat_id));
    } else {
        sendMessage($chat_id, "لیستی با این شماره یافت نشد یا شما اجازه حذف آن را ندارید.");
    }
}

function getMainMenuKeyboard($chat_id)
{
    $keyboard = [
        [['text' => 'تنظیم سفر', 'web_app' => ['url' => 'https://botstorage.s3.ir-thr-at1.arvanstorage.ir/bale-route.html']], ['text' => 'نمایش سفرها']],
        [['text' => 'مسافران سابق'], ['text' => 'لیست‌های مسافران']],
        [['text' => 'اطلاعات شخصی']]
    ];

    // اگر کاربر ادمین است دکمه ارسال پیام همگانی اضافه می‌شود
    if ($chat_id == $GLOBALS['adminChatId']) {
        $keyboard[] = [['text' => 'ارسال پیام همگانی']];
    }

    return [
        'keyboard' => $keyboard,
        'resize_keyboard' => true,
        'one_time_keyboard' => false
    ];
}


function addTravelerToList($chat_id, $list_id, $traveler_id)
{
    $db = initDatabase();

    try {
        // برای دیباگ، ابتدا مقادیر ورودی را چک می‌کنیم
        error_log("Adding traveler $traveler_id to list $list_id for chat $chat_id");

        // اول چک می‌کنیم که لیست وجود داره و متعلق به این chat_id هست
        $stmt = $db->prepare("
            SELECT members 
            FROM traveler_lists 
            WHERE id = :list_id AND chat_id = :chat_id
        ");

        $stmt->bindValue(':list_id', $list_id, SQLITE3_INTEGER);
        $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);

        $result = $stmt->execute();
        $list = $result->fetchArray(SQLITE3_ASSOC);

        if (!$list) {
            error_log("List not found or not belonging to this chat_id");
            return false;
        }

        // چک می‌کنیم که مسافر وجود داره و متعلق به این chat_id هست
        $stmt = $db->prepare("
            SELECT id 
            FROM travelers 
            WHERE id = :traveler_id AND chat_id = :chat_id
        ");

        $stmt->bindValue(':traveler_id', $traveler_id, SQLITE3_INTEGER);
        $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);

        $result = $stmt->execute();
        $traveler = $result->fetchArray(SQLITE3_ASSOC);

        if (!$traveler) {
            error_log("Traveler not found or not belonging to this chat_id");
            return false;
        }

        // آرایه اعضای فعلی را می‌گیریم
        $current_members = json_decode($list['members'] ?: '[]', true);
        error_log("Current members: " . print_r($current_members, true));

        // چک می‌کنیم که مسافر قبلاً در لیست نباشد
        if (in_array($traveler_id, $current_members)) {
            error_log("Traveler already in list");
            return 'duplicate';
        }

        // اضافه کردن مسافر جدید به آرایه
        $current_members[] = (int) $traveler_id;
        $new_members_json = json_encode($current_members);
        error_log("New members array: " . $new_members_json);

        // آپدیت لیست
        $stmt = $db->prepare("
            UPDATE traveler_lists 
            SET members = :members 
            WHERE id = :list_id AND chat_id = :chat_id
        ");

        $stmt->bindValue(':members', $new_members_json, SQLITE3_TEXT);
        $stmt->bindValue(':list_id', $list_id, SQLITE3_INTEGER);
        $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);

        $result = $stmt->execute();

        if ($db->changes() > 0) {
            error_log("Successfully added traveler to list");
            return true;
        } else {
            error_log("No changes made to database");
            return false;
        }

    } catch (Exception $e) {
        error_log("Error in addTravelerToList: " . $e->getMessage());
        return false;
    }
}

function startAddingPrivateInfo($chat_id)
{
    setUserState($chat_id, 'awaiting_name');
    sendMessage($chat_id, "📝 لطفاً نام و نام خانوادگی خود را وارد کنید:");
}

function startEditingPrivateInfo($chat_id)
{
    $db = initDatabase();
    $stmt = $db->prepare("SELECT person_name, phone_number, email FROM private_info WHERE chat_id = :chat_id");
    $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
    $result = $stmt->execute();
    $user_info = $result->fetchArray(SQLITE3_ASSOC);

    if ($user_info) {
        $message = "✏️ اطلاعات فعلی شما:\n";
        $message .= "\n👤 نام: " . htmlspecialchars($user_info['person_name']);
        $message .= "\n📞 شماره تلفن: " . htmlspecialchars($user_info['phone_number']);
        $message .= "\n📧 ایمیل: " . ($user_info['email'] ? htmlspecialchars($user_info['email']) : '—');
        $message .= "\n\n📝 لطفاً نام خود را وارد کنید:";

        setUserState($chat_id, 'awaiting_name');
        sendMessage($chat_id, $message);
    } else {
        sendMessage($chat_id, "⚠️ شما هیچ اطلاعاتی برای ویرایش ندارید. لطفاً ابتدا اطلاعات خود را ثبت کنید.");
    }
}


function getPrivateInfo($chat_id)
{
    $db = initDatabase();

    $stmt = $db->prepare("SELECT person_name, email, phone_number FROM private_info WHERE chat_id = :chat_id");
    $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);

    $result = $stmt->execute();
    $userData = $result->fetchArray(SQLITE3_ASSOC);

    $db->close();

    return [
        'fullName' => isset($userData['person_name']) ? $userData['person_name'] : '',
        'email' => isset($userData['email']) ? $userData['email'] : '',
        'mobileNumber' => isset($userData['phone_number']) ? $userData['phone_number'] : ''
    ];
}

function handleInlineQuery($inlineQuery)
{
    $query = strtolower($inlineQuery['query']);
    $queryId = $inlineQuery['id'];

    // دریافت مسیرها از فایل JSON (به فرض نام فایل شما train_cities.json است)
    $available_routes = getAvailableRoutesFromJson('train_cities.json');

    $results = [];

    foreach ($available_routes as $route_key => $route_name) {
        if (
            empty($query) ||
            stripos($route_key, $query) !== false ||
            stripos($route_name, $query) !== false
        ) {

            // در اینجا پیام ارسالی شامل کد مسیر (مثلاً tehran-ahvaz) است.
            $command_text = $route_key;

            $results[] = [
                'type' => 'article',
                'id' => uniqid(),
                'title' => $route_name,
                'description' => "کد مسیر: $route_key",
                'input_message_content' => [
                    'message_text' => $command_text
                ]
            ];
        }
    }

    $data = [
        'inline_query_id' => $queryId,
        'results' => json_encode($results),
        'cache_time' => 5000
    ];

    $botToken = $GLOBALS['botToken'];
    $url = "https://tapi.bale.ai/bot$botToken/answerInlineQuery";

    // استفاده از cURL برای ارسال درخواست به صورت POST
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log("cURL error: " . curl_error($ch));
    }
    curl_close($ch);

    return $response;
}


function getAvailableRoutesFromJson($jsonPath)
{
    // خواندن محتویات فایل JSON
    $jsonContent = file_get_contents($jsonPath);

    // اگر فایل شامل "var train_cities =" هست، اون قسمت رو حذف می‌کنیم
    if (strpos($jsonContent, 'var train_cities =') !== false) {
        $jsonContent = str_replace('var train_cities =', '', $jsonContent);
    }
    // حذف فاصله‌ها و سمیکالن انتهایی در صورت وجود
    $jsonContent = trim($jsonContent, " \t\n\r\0\x0B;");
    $jsonContent = rtrim($jsonContent, ';');

    // تبدیل محتویات به آرایه
    $cities = json_decode($jsonContent, true);
    if (!$cities) {
        // در صورت بروز خطا در تبدیل JSON، می‌توان لاگ یا خطا داد
        return [];
    }

    $routes = [];
    // ایجاد مسیرها به صورت داینامیک (هر ترکیب دو شهر)
    foreach ($cities as $from) {
        foreach ($cities as $to) {
            if ($from['code'] !== $to['code']) {
                // کد مسیر به صورت "from-to"
                $routeKey = $from['code'] . '-' . $to['code'];
                // عنوان مسیر با استفاده از نام‌های نمایشی (text)
                $routeName = $from['text'] . ' به ' . $to['text'];
                $routes[$routeKey] = $routeName;
            }
        }
    }
    return $routes;
}

// تابع ارسال پیام به همه کاربرهای تأیید شده
function broadcastMessage($message, $chat_id)
{
    if ($chat_id == $GLOBALS['adminChatId']) {
        // اتصال به دیتابیس اصلی برای گرفتن کاربران تایید شده
        $db = initDatabase();

        // دریافت chat_id های تایید شده
        $stmt = $db->query("SELECT chat_id FROM users");

        // اتصال به دیتابیس صف پیام‌ها
        $queueDb = initQueueDatabase();  // اتصال به دیتابیس جدید
        while ($row = $stmt->fetchArray(SQLITE3_ASSOC)) {
            $chatId = $row['chat_id'];

            // درج پیام‌ها به جدول صف در دیتابیس جدید
            $insert = $queueDb->prepare("INSERT INTO message_queue (chat_id, message, sent) VALUES (:chat_id, :message, 0)");
            $insert->bindValue(':chat_id', $chatId, SQLITE3_TEXT);  // تغییر نوع به SQLITE3_TEXT چون chat_id یک رشته است
            $insert->bindValue(':message', $message, SQLITE3_TEXT);
            $insert->execute();
        }

        // بستن دیتابیس‌ها
        $db->close();
        $queueDb->close();

        // پیام تایید به ادمین
        sendMessage($chat_id, "پیام‌ها در صف ارسال قرار گرفتند و به مرور ارسال خواهند شد.");
    }
}



function translateRoute($route)
{
    // خواندن داده از فایل JSON
    $jsonFile = "train_cities_large.json";
    if (!file_exists($jsonFile)) {
        return "JSON file not found";
    }

    $jsonData = file_get_contents($jsonFile);
    $cities = json_decode($jsonData, true);

    // تبدیل آرایه به associative array برای جستجوی سریع‌تر
    $cityMap = [];
    foreach ($cities as $city) {
        $cityMap[$city['code']] = $city['text'];
    }

    // جدا کردن مبدا و مقصد
    $parts = explode('-', $route);
    if (count($parts) !== 2) {
        return "Invalid route format";
    }

    $from = $parts[0];
    $to = $parts[1];

    // جایگزینی نام‌های فارسی
    $fromFa = $cityMap[$from] ?? $from;
    $toFa = $cityMap[$to] ?? $to;

    return "$fromFa به $toFa";
}

function findRoute($route)
{

    $jsonFile = "train_cities_large.json";

    // خواندن داده از فایل JSON
    if (!file_exists($jsonFile)) {
        return "JSON file not found";
    }

    $jsonData = file_get_contents($jsonFile);
    $cities = json_decode($jsonData, true);

    // تبدیل آرایه به associative array برای جستجوی سریع‌تر
    $cityMap = [];
    foreach ($cities as $city) {
        $cityMap[$city['text']] = $city['code'];
    }

    // جدا کردن مبدا و مقصد
    $parts = explode('-', $route);
    if (count($parts) !== 2) {
        return "Invalid route format";
    }

    $fromFa = $parts[0];
    $toFa = $parts[1];

    // جایگزینی نام‌های کد شهر
    $from = $cityMap[$fromFa] ?? $fromFa;
    $to = $cityMap[$toFa] ?? $toFa;

    return "$from-$to";
}

?>