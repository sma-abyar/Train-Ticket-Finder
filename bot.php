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
    // جدول مسافران
    $db->exec("CREATE TABLE IF NOT EXISTS travelers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    chat_id TEXT NOT NULL,
    first_name TEXT NOT NULL,
    last_name TEXT NOT NULL,
    national_code TEXT NOT NULL,
    passenger_type INTEGER NOT NULL, -- 1: بزرگسال، 2: کودک، 3: نوزاد
    gender INTEGER NOT NULL, -- 1: آقا، 2: خانم
    services TEXT, -- JSON array of services
    wheelchair INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

    // جدول لیست‌های مسافران
    $db->exec("CREATE TABLE IF NOT EXISTS traveler_lists (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    chat_id TEXT NOT NULL,
    name TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

    // جدول ارتباطی بین لیست‌ها و مسافران
    $db->exec("CREATE TABLE IF NOT EXISTS traveler_list_members (
    list_id INTEGER,
    traveler_id INTEGER,
    FOREIGN KEY (list_id) REFERENCES traveler_lists(id) ON DELETE CASCADE,
    FOREIGN KEY (traveler_id) REFERENCES travelers(id) ON DELETE CASCADE,
    PRIMARY KEY (list_id, traveler_id)
)");

    // Add user_states table
    $db->exec("CREATE TABLE IF NOT EXISTS user_states (
    chat_id TEXT PRIMARY KEY,
    current_state TEXT NOT NULL,
    temp_data TEXT,  -- JSON string for temporary data
    last_update DATETIME DEFAULT CURRENT_TIMESTAMP
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
    $db = initDatabase();
    $stmt = $db->prepare("INSERT OR IGNORE INTO users (chat_id) VALUES (:chat_id)");
    $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
    $stmt->execute();

    // Create an inline keyboard with an "Approve User" button
    $inlineKeyboard = [
        'inline_keyboard' => [
            [
                ['text' => 'تأیید کاربر', 'callback_data' => "approve_user_$chat_id"]
            ]
        ]
    ];

    // Send the message to the admin with the inline button
    sendMessage($GLOBALS['adminChatId'], "کاربر جدید با مشخصات: $username\nبرای تأیید، دکمه زیر را کلیک کنید:", $inlineKeyboard);
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

// Add the REMOVE_TRIP state to the state handling logic
$update = json_decode(file_get_contents('php://input'), true);
if (isset($update['callback_query'])) {
    handleCallbackQuery($update['callback_query']);
} elseif (isset($update['message'])) {
    $chat_id = $update['message']['chat']['id'];
    $text = $update['message']['text'];

    // Handle cancel button
    if ($text === 'لغو') {
        clearUserState($chat_id);
        sendMessage($chat_id, "عملیات لغو شد.", getMainMenuKeyboard());
        return;
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
        case 'نمایش مسافران':
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
            setUserState($chat_id, 'REMOVE_TRAVELER_LIST');
            sendMessage($chat_id, "لطفاً شماره لیست را برای حذف وارد کنید (مثال: 1):");
            break;
        case 'حذف سفر':
            setUserState($chat_id, 'REMOVE_TRIP');
            showUserTrips($chat_id);
            sendMessage($chat_id, "لطفاً شماره سفر را برای حذف وارد کنید (مثال: 1):");
            break;
        default:
            if (!$userState || !isset($userState['current_state'])) {
                sendMessage($chat_id, "دستور نامعتبر است. برای مشاهده راهنما از دستور /help استفاده کنید.");
            }
    }

    // Handle state-based interactions
    $userState = getUserState($chat_id);
    if ($userState && isset($userState['current_state'])) {
        switch ($userState['current_state']) {
            case 'START':
                handleStartCommand($chat_id, $username, $update);
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
            case 'REMOVE_TRAVELER_LIST':
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
            default:
                sendMessage($chat_id, "در حال پردازش درخواست شما...");
        }
    }

}

function handleCallbackQuery($callback_query)
{
    $chat_id = $callback_query['message']['chat']['id'];
    $data = $callback_query['data'];

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
        sendMessage($chat_id, "لطفاً مسافری که می‌خواهید حذف کنید را انتخاب کنید:", $inlineKeyboard);
    } elseif (strpos($data, 'remove_traveler_') === 0) {
        // Extract the traveler ID from the callback data
        $traveler_id = str_replace('remove_traveler_', '', $data);
        // Call the function to remove the traveler
        removeTraveler($chat_id, $traveler_id);
        // Notify the user
        sendMessage($chat_id, "مسافر با موفقیت حذف شد.");
    } elseif ($data === 'add_traveler_list') {
        // Start the traveler list addition process
        handleAddTravelerListCommand($chat_id);
    } elseif ($data === 'remove_traveler_list') {
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
                ['text' => $list['name'], 'callback_data' => "remove_traveler_list_{$list['id']}"]
            ];
        }
        // Send the message with the inline buttons
        sendMessage($chat_id, "لطفاً لیست مسافری که می‌خواهید حذف کنید را انتخاب کنید:", $inlineKeyboard);
    } elseif (strpos($data, 'remove_traveler_list_') === 0) {
        // Extract the traveler list ID from the callback data
        $list_id = str_replace('remove_traveler_list_', '', $data);
        // Call the function to remove the traveler list
        removeTravelerList($chat_id, $list_id);
        // Notify the user
        sendMessage($chat_id, "لیست مسافران با موفقیت حذف شد.");
    } elseif (strpos($data, 'approve_user_') === 0) {
        // Extract the chat_id from the callback data
        $user_chat_id = str_replace('approve_user_', '', $data);
        // Call the function to approve the user
        approveUser($user_chat_id);
        // Notify the admin
        sendMessage($chat_id, "کاربر با شناسه $user_chat_id تأیید شد.");
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
                ['text' => "سفر به {$trip['route']} ({$trip['date']})", 'callback_data' => "remove_trip_{$trip['id']}"]
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
        sendMessage($chat_id, "سفر با موفقیت حذف شد.");
    }
}

function handleStartCommand($chat_id, $username, $update)
{
    $username = getUsernameFromMessage($update['message']);
    $username = escapeMarkdownV2($username);
    registerUser($chat_id, $username);
    $keyboard = getMainMenuKeyboard();
    sendMessage($chat_id, "به ربات پیداکننده بلیط قطار خوش آمدید! لطفاً یکی از گزینه‌های زیر را انتخاب کنید:", $keyboard);
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
    setUserState($chat_id, 'SET_TRIP_ROUTE');
    sendMessage($chat_id, "لطفاً مسیر سفر را وارد کنید (مثال: tehran-mashhad):");
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
        sendMessage($chat_id, "شما هیچ سفری ثبت نکرده‌اید.", $inlineKeyboard);
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

    // Add the "حذف سفر" button if $trips is not empty
    $inlineKeyboard['inline_keyboard'][] = [
        ['text' => 'حذف سفر', 'callback_data' => 'remove_trip']
    ];

    sendMessage($chat_id, $message, $inlineKeyboard);
}

function handleSetTripRoute($chat_id, $text)
{
    $route = $text;
    setUserState($chat_id, 'SET_TRIP_DATE', ['route' => $route]);
    sendMessage($chat_id, "لطفاً تاریخ رفت را وارد کنید (مثال: 1403-11-02):");
}

function handleSetTripDate($chat_id, $text)
{
    $date = $text;
    $temp_data = getUserState($chat_id)['temp_data'];
    $temp_data['date'] = $date;
    setUserState($chat_id, 'SET_TRIP_RETURN_DATE', $temp_data);
    sendMessage($chat_id, "لطفاً تاریخ برگشت را وارد کنید (اگر یک طرفه است، خالی بگذارید):");
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
    sendMessage($chat_id, "لطفاً نوع بلیط را وارد کنید (0: معمولی, 1: ویژه):");
}

function handleSetTripType($chat_id, $text)
{
    if (!is_numeric($text)) {
        sendMessage($chat_id, "لطفاً یک عدد معتبر وارد کنید (مثال: 0):");
        return;
    }
    $type = (int) $text;
    $temp_data = getUserState($chat_id)['temp_data'];
    $temp_data['type'] = $type;
    setUserState($chat_id, 'SET_TRIP_COUPE', $temp_data);
    sendMessage($chat_id, "لطفاً ترجیح کوپه را وارد کنید (0: خیر, 1: بله):");
}

function handleSetTripCoupe($chat_id, $text)
{
    if (!is_numeric($text)) {
        sendMessage($chat_id, "لطفاً یک عدد معتبر وارد کنید (مثال: 0):");
        return;
    }
    $coupe = (int) $text;
    $temp_data = getUserState($chat_id)['temp_data'];
    $temp_data['coupe'] = $coupe;
    setUserState($chat_id, 'SET_TRIP_FILTER', $temp_data);
    sendMessage($chat_id, "لطفاً فیلتر را وارد کنید (0: بدون فیلتر, 1: با فیلتر):");
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
    sendMessage($chat_id, "اطلاعات سفر شما با موفقیت ثبت شد.");
    processUserTrips($chat_id);
    clearUserState($chat_id);
}

function handleAddTravelerCommand($chat_id)
{
    setUserState($chat_id, 'SET_TRAVELER_FIRST_NAME');
    sendMessage($chat_id, "لطفاً نام مسافر را وارد کنید:");
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
    sendMessage($chat_id, "لطفاً نوع مسافر را وارد کنید (1: بزرگسال، 2: کودک، 3: نوزاد):");
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
    sendMessage($chat_id, "لطفاً جنسیت مسافر را وارد کنید (1: آقا، 2: خانم):");
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
    setUserState($chat_id, 'SET_TRAVELER_SERVICES', $temp_data);
    sendMessage($chat_id, "لطفاً خدمات مورد نیاز را وارد کنید (مثال: [] یا [\"service1\",\"service2\"]):");
}

function handleSetTravelerServices($chat_id, $text)
{
    $services = json_decode($text, true) ?? [];
    $temp_data = getUserState($chat_id)['temp_data'];
    $temp_data['services'] = $services;
    setUserState($chat_id, 'SET_TRAVELER_WHEELCHAIR', $temp_data);
    sendMessage($chat_id, "لطفاً نیاز به ویلچر را وارد کنید (0: خیر، 1: بله):");
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
    setUserState($chat_id, 'SET_TRAVELER_LIST_MEMBERS', ['name' => $list_name]);
    sendMessage($chat_id, "لطفاً شماره‌های مسافران را وارد کنید (مثال: 1,2,3,4):");
}

function handleSetTravelerListMembers($chat_id, $text)
{
    $traveler_ids = array_map('intval', explode(',', $text));
    $temp_data = getUserState($chat_id)['temp_data'];
    try {
        createTravelerList($chat_id, $temp_data['name'], $traveler_ids);
        sendMessage($chat_id, "لیست مسافران *{$temp_data['name']}* با موفقیت ایجاد شد.");
    } catch (Exception $e) {
        sendMessage($chat_id, "خطا در ایجاد لیست مسافران. لطفاً مطمئن شوید همه شماره‌های مسافران معتبر هستند.");
    }
    clearUserState($chat_id);
}

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
    foreach ($travelers as $traveler) {
        $typeText = getPassengerTypeText($traveler['passenger_type']);
        $genderText = getGenderText($traveler['gender']);
        $message .= "*{$traveler['id']}.* {$traveler['first_name']} {$traveler['last_name']}\n"
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
    foreach ($lists as $list) {
        $message .= "*{$list['id']}.* {$list['name']}\n"
            . "تعداد مسافران: {$list['member_count']}\n"
            . "مسافران: {$list['members']}\n"
            . "───────────────\n";
    }

    // Add the "حذف لیست مسافر" button if $lists is not empty
    $inlineKeyboard['inline_keyboard'][] = [
        ['text' => 'حذف لیست مسافر', 'callback_data' => 'remove_traveler_list']
    ];

    sendMessage($chat_id, $message, $inlineKeyboard);
}

function handleRemoveTravelerCommand($chat_id, $text)
{
    $parts = explode(' ', $text);
    if (isset($parts[1]) && is_numeric($parts[1])) {
        removeTraveler($chat_id, (int) $parts[1]);
        sendMessage($chat_id, "مسافر با ID {$parts[1]} حذف شد.");
    } else {
        sendMessage($chat_id, "فرمت دستور صحیح نیست. مثال:\n/removetraveler 1");
    }
}

function handleRemoveTravelerListCommand($chat_id, $text)
{
    $parts = explode(' ', $text);
    if (isset($parts[1]) && is_numeric($parts[1])) {
        removeTravelerList($chat_id, (int) $parts[1]);
        sendMessage($chat_id, "لیست مسافران با ID {$parts[1]} حذف شد.");
    } else {
        sendMessage($chat_id, "فرمت دستور صحیح نیست. مثال:\n/removetravelerlist 1");
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
function createTravelerList($chat_id, $name, $traveler_ids)
{
    $db = initDatabase();
    $db->exec('BEGIN TRANSACTION');

    try {
        // ایجاد لیست جدید
        $stmt = $db->prepare("INSERT INTO traveler_lists (chat_id, name) VALUES (:chat_id, :name)");
        $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->execute();

        $list_id = $db->lastInsertRowID();

        // اضافه کردن مسافران به لیست
        $stmt = $db->prepare("INSERT INTO traveler_list_members (list_id, traveler_id) VALUES (:list_id, :traveler_id)");
        foreach ($traveler_ids as $traveler_id) {
            $stmt->bindValue(':list_id', $list_id, SQLITE3_INTEGER);
            $stmt->bindValue(':traveler_id', $traveler_id, SQLITE3_INTEGER);
            $stmt->execute();
        }

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
    $stmt = $db->prepare("
        SELECT 
            tl.id,
            tl.name,
            GROUP_CONCAT(t.first_name || ' ' || t.last_name) as members,
            COUNT(tlm.traveler_id) as member_count
        FROM traveler_lists tl
        LEFT JOIN traveler_list_members tlm ON tl.id = tlm.list_id
        LEFT JOIN travelers t ON tlm.traveler_id = t.id
        WHERE tl.chat_id = :chat_id
        GROUP BY tl.id
        ORDER BY tl.created_at DESC
    ");

    $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
    $result = $stmt->execute();

    $lists = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $lists[] = $row;
    }
    return $lists;
}

// تابع کمکی برای تبدیل نوع مسافر به متن
function getPassengerTypeText($type)
{
    switch ($type) {
        case 1:
            return "بزرگسال";
        case 2:
            return "کودک";
        case 3:
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
        sendMessage($chat_id, "مسافر با شماره $traveler_id با موفقیت حذف شد.");
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
        sendMessage($chat_id, "لیست مسافران با شماره $list_id با موفقیت حذف شد.");
    } else {
        sendMessage($chat_id, "لیستی با این شماره یافت نشد یا شما اجازه حذف آن را ندارید.");
    }
}

function getMainMenuKeyboard()
{
    return [
        'keyboard' => [
            [['text' => 'تنظیم سفر'], ['text' => 'نمایش سفرها']],
            [
                ['text' => 'نمایش مسافران'],
                ['text' => 'لیست‌های مسافران']
            ],
            // [['text' => 'حذف سفر']],
            [['text' => 'راهنما']]
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => false
    ];
}

?>