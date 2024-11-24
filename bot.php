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
loadEnv(__DIR__ . '/data.env');

// Use .env values
$GLOBALS['botToken'] = $_ENV['BOT_TOKEN'];
$adminChatId = $_ENV['ADMIN_CHAT_ID'];
$url = $_ENV['URL'];
$dbPath = $_ENV['DB_PATH'];

$jsonHeaders = $_ENV['HEADERS'];

// Initialize $headers array
$headers = [];

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
    $db->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, chat_id TEXT UNIQUE, approved INTEGER DEFAULT 0)");
    $db->exec("CREATE TABLE IF NOT EXISTS user_trips (
    id INTEGER PRIMARY KEY AUTOINCREMENT, -- id for trips
    chat_id TEXT NOT NULL, -- id for users
    route TEXT NOT NULL, -- route
    date TEXT NOT NULL, -- date
    return_date TEXT, -- return date
    count INTEGER NOT NULL, -- ticket count
    type INTEGER, -- type of chair (normal/men/women)
    coupe INTEGER, -- buy a coupe
    filter INTEGER, -- filters
    no_counting_notif INTEGER, -- error of no capacity
    no_ticket_notif INTEGER, -- error of no train
    bad_data_notif INTEGER -- error of data
)");
    return $db;
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
    $db = initDatabase();
    $stmt = $db->prepare("INSERT OR IGNORE INTO users (chat_id) VALUES (:chat_id)");
    $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
    $stmt->execute();
    sendMessage($GLOBALS['adminChatId'], "کاربر جدید با مشخصات: $username\nبرای تأیید دستور زیر را ارسال کنید:\n/approve $chat_id");
}

// approve new user
function approveUser($chat_id)
{
    $db = initDatabase();
    $stmt = $db->prepare("UPDATE users SET approved = 1 WHERE chat_id = :chat_id");
    $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
    $stmt->execute();
    sendMessage($chat_id, "شما تأیید شدید!");
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
                        . "💺 *نوع بلیط*: {$postFields['type']}\n"
                        . "🗓 *تاریخ حرکت*: {$ticket['jdate_fa']}\n"
                        . "⏰ *زمان حرکت*: {$ticket['time']}\n"
                        . "📊 *ظرفیت باقی‌مانده*: {$ticket['counting']}\n"
                        . "💰 *قیمت*: {$ticket['cost_title']} ریال\n";
                    $replyMarkup = [
                        'inline_keyboard' => [
                            [
                                ['text' => 'تهیه‌ی بلیط', 'url' => 'https://ghasedak24.com/train/reservation/' . $ticket['id'] . '/0/' . $userTrip['count'] . '-0-0/' . $userTrip['coupe']]
                            ]
                        ]
                    ];
                    sendMessage($userTrip['chat_id'], $message, $replyMarkup);
                }
            }
            if (!$found && ($userTrip['no_counting_notif'] == 0)) {
                sendMessage($userTrip['chat_id'], "*دیر رسیدی خوشگله!*\nهیچ قطاری برای تاریخ {$userTrip['date']} در مسیر {$route_title} صندلی خالی نداره.\n حالا توکل به خدا، صبر کن شاید موجود شد😊😉");
                updateNotificationStatus($userTrip['id'], 'no_counting_notif');
            }
        } elseif ($userTrip['no_ticket_notif'] == 0) {
            sendMessage($userTrip['chat_id'], "*این مملکت درست نمی‌شه!*\n هیچ قطاری برای تاریخ {$userTrip['date']} در مسیر {$userTrip['route']} وجود نداره.\nاگر چیزی ثبت شد (به شرط حیات) خبرت می‌‌کنیم 😎");
            updateNotificationStatus($userTrip['id'], 'no_ticket_notif');
        }
    } elseif ($userTrip['bad_data_notif'] == 0) {
        // sendMessage($userTrip['chat_id'], "⚠️ احتمالا راه آهن قطع شده\nدرست بشه به کارمون ادامه می‌دیم");
        updateNotificationStatus($userTrip['id'], 'bad_data_notif');
    } else {
        sendMessage($userTrip['chat_id'], "خیلی اوضاع خیطه 😬 \nبه ادمین یه ندا بده ❤");
    }

}

// update notif state of each trip
function updateNotificationStatus($userTripId, $field)
{
    $db = initDatabase();
    $stmt = $db->prepare("UPDATE user_trips SET $field = 1 WHERE id = :id");
    $stmt->bindValue(':id', $userTripId, SQLITE3_INTEGER);
    $stmt->execute();
}


// Send message to user
function sendMessage($chat_id, $text, $replyMarkup = null)
{
    $botToken = $GLOBALS['botToken'];
    $url = "https://api.telegram.org/bot$botToken/sendMessage";

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
    curl_exec($ch);
    curl_close($ch);
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


function getUsernameFromMessage($message) {
    if (isset($message['from']['username'])) {
        return '@' . $message['from']['username'];
    } elseif (isset($message['from']['id'])) {
        return 'https://t.me/' . $message['from']['id'];
    } else {
        return 'Unknown User';
    }
}

// handle users requests
$update = json_decode(file_get_contents('php://input'), true);
if (isset($update['message'])) {
    $chat_id = $update['message']['chat']['id'];
    $text = $update['message']['text'];

    if ($text === '/start') {
        $username = getUsernameFromMessage($update['message']);
        $username = escapeMarkdownV2($username);
        registerUser($chat_id, $username);
        sendMessage($chat_id, "درخواست شما ارسال شد.");
    } elseif (strpos($text, '/approve') === 0 && $chat_id == $GLOBALS['adminChatId']) {
        $parts = explode(' ', $text);
        if (isset($parts[1])) {
            approveUser($parts[1]);
            sendMessage($chat_id, "کاربر {$parts[1]} تأیید شد.");
        }
    } elseif ($text === '/admintickets') {
        processUserTrips($adminChatId);
    } elseif (strpos($text, '/settrip') === 0) {
        $parts = explode(' ', $text);
        if (count($parts) == 8) {
            $data = [
                'route' => $parts[1],
                'date' => $parts[2],
                'return_date' => $parts[3],
                'count' => (int) $parts[4],
                'type' => (int) $parts[5],
                'coupe' => (int) $parts[6],
                'filter' => (int) $parts[7]
            ];
            saveUserTrip($chat_id, $data);
            sendMessage($chat_id, "اطلاعات سفر شما با موفقیت ثبت شد.");
            processUserTrips($chat_id);
        } else {
            sendMessage($chat_id, "فرمت دستور صحیح نیست. لطفاً همه مقادیر را وارد کنید.");
        }
    } elseif ($text === '/showtrips') {
        showUserTrips($chat_id);
    } elseif (strpos($text, '/removetrip') === 0) {
        $parts = explode(' ', $text);
        if (isset($parts[1]) && is_numeric($parts[1])) {
            $trip_id = (int) $parts[1];
            removeUserTrip($chat_id, $trip_id);
        } else {
            sendMessage($chat_id, "لطفاً ID سفر را به درستی وارد کنید. مثال: /removetrip 1");
        }
    } else {
        sendMessage($chat_id, "دستور نامعتبر است.");
    }
}

// Show all of trips for a user
function showUserTrips($chat_id)
{
    $trips = getUserTrips($chat_id);
    if (empty($trips)) {
        sendMessage($chat_id, "شما هیچ سفری ثبت نکرده‌اید.");
        return;
    }

    $message = "لیست سفرهای شما:\n";
    foreach ($trips as $trip) {
        $message .= "ID: {$trip['id']}\n"
            . "مسیر: {$trip['route']}\n"
            . "تاریخ رفت: {$trip['date']}\n"
            . "نوع: {$trip['type']}\n"
            . "-----------------------\n";
    }
    sendMessage($chat_id, $message);
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
        sendMessage($chat_id, "سفر با ID: $trip_id با موفقیت حذف شد.");
    } else {
        sendMessage($chat_id, "سفری با این ID پیدا نشد یا شما اجازه حذف آن را ندارید.");
    }
}
?>