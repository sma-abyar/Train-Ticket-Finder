<?php

require_once __DIR__ . '/train_follows.php';

function followCatalogForTrip(SQLite3 $db, array $trip, bool $refresh = false): ?array
{
    $catalog = loadTrainCatalog($db, $trip);
    if ($catalog !== null && (!$refresh || time() - $catalog['updated_at'] < 30)) {
        return $catalog;
    }
    global $dbPath;
    $lock = fopen($dbPath . '.catalog.lock', 'c');
    if (!$lock) {
        throw new RuntimeException('فهرست قطارها فعلاً قابل دریافت نیست.', 503);
    }
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            return $catalog; // A concurrent catalog refresh is already in progress.
        }
        $latest = loadTrainCatalog($db, $trip);
        if ($latest !== null && time() - $latest['updated_at'] < 30) {
            return $latest;
        }
        requestTicketSearch(TicketSearchBatch::payload($trip));
        return loadTrainCatalog($db, $trip) ?? $catalog;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function followPickerData(SQLite3 $db, array $trip, bool $refresh = false): array
{
    $catalog = followCatalogForTrip($db, $trip, $refresh);
    $first = $catalog['tickets'][0] ?? [];
    $routeTitle = isset($first['from_title'], $first['to_title']) ? $first['from_title'] . ' به ' . $first['to_title'] : $trip['route'];
    $options = trainFollowOptions($trip, $catalog);
    $approved = (bool)followSql($db,'SELECT 1 FROM users WHERE chat_id=:chat AND approved=1',[':chat'=>(string)$trip['chat_id']])->fetchArray(SQLITE3_NUM);
    $tickets = [];
    foreach ($catalog['tickets'] ?? [] as $ticket) { $tickets[trainFollowKey($ticket)] = $ticket; }
    $deliveries = [];
    $result = followSql($db,'SELECT * FROM telegram_ticket_delivery WHERE trip_id=:id AND kind=\'ticket\'',[':id'=>(int)$trip['id']]);
    while ($row=$result->fetchArray(SQLITE3_ASSOC)) { $deliveries[$row['train_key']]=$row; }
    foreach ($options as &$option) {
        $eligibility = isset($tickets[$option['key']]) ? ticketNotificationEligibility($trip,$tickets[$option['key']],$approved)
            : ['eligible'=>false,'reason'=>'missing','label'=>'این قطار در فهرست فعلی فروشنده نیست.'];
        $option += $eligibility;
        $delivery = $deliveries[$option['key']] ?? null;
        $option['last_sent_at'] = $delivery['last_success_at'] ?? null;
        $option['send_error_code'] = $delivery ? (int)$delivery['error_code'] : null;
    }
    unset($option);
    $poll = followSql($db,'SELECT * FROM ticket_poll_status WHERE trip_id=:id',[':id'=>(int)$trip['id']])->fetchArray(SQLITE3_ASSOC);
    $worker = $db->querySingle('SELECT started_at,finished_at,error FROM ticket_worker_status WHERE id=1',true);
    return [
        'trip_id'=>(int) $trip['id'], 'route'=>$trip['route'], 'date'=>$trip['date'],
        'route_title'=>$routeTitle,
        'mode'=>tripFollowMode($trip), 'revision'=>(int) $trip['follow_revision'],
        'selected'=>array_column(tripFollowedTrains($trip), 'key'),
        'excluded'=>array_column(tripExcludedTrains($trip), 'key'),
        'options'=>$options, 'updated_at'=>$catalog['updated_at'] ?? null,
        'last_poll'=>$poll ?: null, 'worker'=>$worker ?: null,
        'stale'=>$catalog === null || time()-$catalog['updated_at'] > 120,
    ];
}

function trainMiniAppUrl(string $view = 'create', ?int $tripId = null): ?string
{
    $base = trim((string) ($_ENV['MINI_APP_URL'] ?? ''));
    if ($base === '') {
        $legacy = trim((string) ($_ENV['ALERTS_WEB_APP_URL'] ?? ''));
        $base = preg_replace('~telegram-alerts\.php(?:\?.*)?$~', 'telegram-app.php', $legacy);
        if ($base === $legacy) { return null; }
    }
    if (filter_var($base, FILTER_VALIDATE_URL) === false || strtolower(parse_url($base, PHP_URL_SCHEME) ?? '') !== 'https') {
        return null;
    }
    // A configured URL must point to this feature's PHP entry point, not a user-supplied host.
    return $base . (strpos($base, '?') === false ? '?' : '&') . http_build_query(['view'=>$view] + ($tripId === null ? [] : ['trip_id'=>$tripId]));
}

function followMiniAppUrl(int $tripId): ?string
{
    return trainMiniAppUrl('alerts', $tripId);
}

function trainTripManagementKeyboard(int $tripId): array
{
    return ['inline_keyboard'=>[
        [['text'=>'انتخاب قطارهای هشدار این سفر', 'callback_data'=>'train_follow_' . $tripId]],
        [['text'=>'فیلترهای سفر', 'callback_data'=>'trip_settings_' . $tripId],
            ['text'=>'مدیریت هشدارهای سفرها', 'callback_data'=>'train_follow_trips']],
    ]];
}

function processSingleUserTrip($chatId, int $tripId): void
{
    $db = initDatabase();
    $trip = followSql($db, 'SELECT * FROM user_trips WHERE id=:id AND chat_id=:chat',
        [':id'=>$tripId, ':chat'=>(string) $chatId])->fetchArray(SQLITE3_ASSOC);
    if ($trip) {
        processTicketTripSafely($trip,new TicketSearchBatch('requestTicketSearch'));
    }
}

function showTrainFollowPicker($chatId, int $tripId): void
{
    $db = initDatabase();
    try {
        $trip = ownedFollowTrip($db, $tripId, (string) $chatId);
        $url = followMiniAppUrl($tripId);
        if ($url !== null) {
            sendMessage($chatId, "قطارهای سفر {$trip['date']} را برای هشدار انتخاب کنید. قطارهای ناموجود هم قابل انتخاب‌اند.\nانتخاب‌ها بعداً قابل ویرایش‌اند.", [
                'inline_keyboard'=>[
                    [['text'=>'انتخاب قطارهای هشدار', 'web_app'=>['url'=>$url]]],
                    [['text'=>'انتخاب داخل گفتگو', 'callback_data'=>'train_follow_native_' . $tripId]],
                    [['text'=>'فیلترهای سفر', 'callback_data'=>'trip_settings_' . $tripId],
                        ['text'=>'مدیریت هشدارهای سفرها', 'callback_data'=>'train_follow_trips']],
                ]
            ], true, null);
            return;
        }
        startNativeTrainFollowPicker($chatId, $trip);
    } catch (Throwable $error) {
        sendMessage($chatId, in_array($error->getCode(), [400,404,409,503], true)
            ? $error->getMessage() : 'باز کردن تنظیمات هشدار انجام نشد؛ دوباره تلاش کنید.', null, true, null);
    }
}

function startNativeTrainFollowPicker($chatId, array $trip): void
{
    $db = initDatabase();
    $data = followPickerData($db, $trip);
    setUserState($chatId, 'EDIT_TRAIN_FOLLOWS', $data + ['nonce'=>bin2hex(random_bytes(4)), 'page'=>0]);
    renderNativeTrainFollowPicker($chatId);
}

function renderNativeTrainFollowPicker($chatId, ?int $messageId = null): void
{
    $draft = getUserState($chatId)['temp_data'];
    $draft += ['selected'=>[], 'excluded'=>[]];
    $count = count($draft['options']);
    $page = min(max(0, (int) $draft['page']), max(0, (int) ceil($count / 6) - 1));
    $prefix = 'tf_' . $draft['nonce'] . '_';
    $modeLabel = ['all'=>'همهٔ قطارها', 'except'=>'همه به‌جز حذف‌شده‌ها', 'selected'=>'فقط انتخاب من', 'none'=>'هشدار خاموش'][$draft['mode']];
    $text = "انتخاب قطارهای هشدار — {$draft['date']}\n{$draft['route']}\nحالت فعلی: $modeLabel\n";
    $text .= "برای قطارهای انتخابی فقط هنگام موجودشدن یا کافی‌شدن ظرفیت خبر می‌دهیم. فیلترهای ظرفیت، ساعت و قیمت سفر هم اعمال می‌شوند.\n";
    if ($draft['stale']) {
        $text .= "فهرست مربوط به آخرین بررسی است؛ وضعیت ظرفیت ممکن است تغییر کرده باشد.\n";
    }
    $rows = [];
    foreach (array_slice($draft['options'], $page * 6, 6, true) as $index => $item) {
        $checked = $draft['mode'] === 'all' || ($draft['mode'] === 'except' && !in_array($item['key'], $draft['excluded'], true))
            || ($draft['mode'] === 'selected' && in_array($item['key'], $draft['selected'], true));
        $status = $item['missing'] ? 'فعلاً در فهرست نیست' : ($item['capacity'] > 0 ? $item['capacity'] . ' صندلی' : 'ناموجود');
        $text .= "\n{$item['number']} — {$item['time']} — {$item['wagon']}\n{$item['company']} | $status\n";
        $rows[] = [['text'=>($checked ? '✅ ' : '◻️ ') . $item['number'] . ' | ' . $item['time'], 'callback_data'=>$prefix . 'toggle_' . $index]];
    }
    if ($count === 0) {
        $text .= "\nفعلاً قطاری در فهرست نیست. می‌توانید بعداً فهرست را تازه کنید یا هشدار همهٔ قطارها را فعال کنید.\n";
    }
    $navigation = [];
    if ($page > 0) {
        $navigation[] = ['text'=>'قبلی', 'callback_data'=>$prefix . 'page_' . ($page-1)];
    }
    if (($page+1)*6 < $count) {
        $navigation[] = ['text'=>'بعدی', 'callback_data'=>$prefix . 'page_' . ($page+1)];
    }
    if ($navigation) {
        $rows[] = $navigation;
    }
    $rows[] = [['text'=>'همهٔ قطارها', 'callback_data'=>$prefix.'all'], ['text'=>'خاموش کردن هشدار', 'callback_data'=>$prefix.'none']];
    $rows[] = [['text'=>'فیلترهای سفر', 'callback_data'=>'trip_settings_' . $draft['trip_id']]];
    $rows[] = [['text'=>'مدیریت هشدارهای سفرها', 'callback_data'=>'train_follow_trips']];
    $rows[] = [['text'=>'ثبت انتخاب‌ها', 'callback_data'=>$prefix.'save'], ['text'=>'انصراف', 'callback_data'=>$prefix.'cancel']];
    if ($messageId !== null) {
        editMessage($chatId, $messageId, $text, ['inline_keyboard'=>$rows], true, null);
    } else {
        sendMessage($chatId, $text, ['inline_keyboard'=>$rows], true, null);
    }
}

function handleTrainFollowCallback($chatId, string $data, ?int $messageId = null): bool
{
    if ($data === 'train_follow_trips') {
        showTrainAlertTrips($chatId);
        return true;
    }
    if (preg_match('/^train_(mute|restore)_(\d+)_([a-f0-9]{24})$/D', $data, $matches)) {
        $tripId = (int) $matches[2];
        $enabled = $matches[1] === 'restore';
        $db = initDatabase();
        try {
            setTrainMessagePreference($db, $tripId, (string) $chatId, $matches[3], $enabled);
            $rows = [
                [['text'=>$enabled ? 'دیگه این قطار رو نمی‌خوام' : 'برگرداندن همین قطار',
                    'callback_data'=>'train_' . ($enabled ? 'mute' : 'restore') . '_' . $tripId . '_' . $matches[3]]],
                [['text'=>'انتخاب قطارهای هشدار این سفر', 'callback_data'=>'train_follow_' . $tripId]],
                [['text'=>'فیلترهای سفر', 'callback_data'=>'trip_settings_' . $tripId],
                    ['text'=>'مدیریت هشدارهای سفرها', 'callback_data'=>'train_follow_trips']],
            ];
            sendMessage($chatId, $enabled ? 'هشدار این قطار دوباره فعال شد.' : 'هشدار این قطار برای این سفر خاموش شد. قطارهای دیگر طبق تنظیمات خودشان ادامه دارند.',
                ['inline_keyboard'=>$rows], true, null);
        } catch (Throwable $error) {
            sendMessage($chatId, in_array($error->getCode(), [400,404,409], true) ? $error->getMessage()
                : 'ذخیرهٔ تنظیمات انجام نشد؛ دوباره تلاش کنید.',
                ['inline_keyboard'=>[[['text'=>'قطارهای هشدار این سفر', 'callback_data'=>'train_follow_' . $tripId]]]], true, null);
        }
        return true;
    }
    if (preg_match('/^train_follow_(native_)?(\d+)$/D', $data, $matches)) {
        $id = (int) $matches[2];
        if ($matches[1] === '') {
            showTrainFollowPicker($chatId, $id);
        } else {
            $db = initDatabase();
            try {
                startNativeTrainFollowPicker($chatId, ownedFollowTrip($db, $id, (string) $chatId));
            } catch (Throwable $error) {
                sendMessage($chatId, 'این سفر در فهرست سفرهای فعال شما نیست.', null, true, null);
            }
        }
        return true;
    }
    if (strpos($data, 'tf_') !== 0) {
        return false;
    }
    $state = getUserState($chatId);
    if (($state['current_state'] ?? '') !== 'EDIT_TRAIN_FOLLOWS'
        || !preg_match('/^tf_([a-f0-9]{8})_(toggle_\d+|page_\d+|all|none|save|cancel)$/D', $data, $matches)
        || !hash_equals($state['temp_data']['nonce'], $matches[1])) {
        return true;
    }
    $draft = $state['temp_data'];
    $draft += ['selected'=>[], 'excluded'=>[]];
    $action = $matches[2];
    if ($action === 'cancel') {
        clearUserState($chatId);
        sendMessage($chatId, 'انتخاب قطارها لغو شد؛ تنظیمات ذخیره‌شده تغییر نکرد.', null, true, null);
        return true;
    }
    if ($action === 'save') {
        $db = initDatabase();
        try {
            saveTrainFollows($db, (int) $draft['trip_id'], (string) $chatId, $draft['mode'], $draft['selected'], (int) $draft['revision'], $draft['excluded']);
            clearUserState($chatId);
            sendMessage($chatId, 'تنظیمات هشدار ذخیره شد.', trainTripManagementKeyboard((int) $draft['trip_id']), true, null);
            processSingleUserTrip($chatId, (int) $draft['trip_id']);
        } catch (Throwable $error) {
            sendMessage($chatId, in_array($error->getCode(), [400,404,409], true)
                ? $error->getMessage() : 'ذخیرهٔ انتخاب‌ها انجام نشد؛ دوباره تلاش کنید.', null, true, null);
        }
        return true;
    }
    if (strpos($action, 'toggle_') === 0) {
        $index = (int) substr($action, 7);
        if (!isset($draft['options'][$index])) {
            return true;
        }
        $key = $draft['options'][$index]['key'];
        if ($draft['mode'] === 'all' || $draft['mode'] === 'except') {
            $draft['excluded'] = in_array($key, $draft['excluded'], true)
                ? array_values(array_diff($draft['excluded'], [$key])) : array_merge($draft['excluded'], [$key]);
            $draft['mode'] = $draft['excluded'] ? 'except' : 'all';
        } else {
            $draft['selected'] = in_array($key, $draft['selected'], true)
                ? array_values(array_diff($draft['selected'], [$key])) : array_merge($draft['selected'], [$key]);
            $draft['mode'] = 'selected';
        }
    } elseif (strpos($action, 'page_') === 0) {
        $draft['page'] = (int) substr($action, 5);
    } else {
        $draft['mode'] = $action;
        if ($action === 'all') {
            $draft['excluded'] = [];
        }
    }
    setUserState($chatId, 'EDIT_TRAIN_FOLLOWS', $draft);
    renderNativeTrainFollowPicker($chatId, $messageId);
    return true;
}

function tripFollowSummary(array $trip): string
{
    return ['all'=>'هشدار: همهٔ قطارها', 'none'=>'هشدار این سفر خاموش است',
        'except'=>'هشدار: همه به‌جز ' . count(tripExcludedTrains($trip)) . ' قطار خاموش‌شده',
        'selected'=>'هشدار: فقط ' . count(tripFollowedTrains($trip)) . ' قطار انتخابی'][tripFollowMode($trip)];
}

/** One compact entry, regardless of how many trips the account has. */
function sendTripMiniAppEntry($chatId, string $view = 'trips'): bool
{
    $url = trainMiniAppUrl($view);
    if ($url === null) { return false; }
    $active = array_filter(getUserTrips($chatId), static fn($trip)=>!isTripExpired($trip['date']));
    $count = count($active);
    $text = $count ? "سفرهای من · {$count} سفر پیش رو\nمسیرها، فیلترها و قطارهای هشدار را یک‌جا مدیریت کن."
        : "هنوز سفری ثبت نکرده‌ای.\nمسیر و تاریخ اولین سفرت را در مینی‌اپ انتخاب کن.";
    $rows = $count ? [
        [['text'=>$view === 'alerts' ? 'مدیریت هشدارهای سفرها' : 'سفرهای من', 'web_app'=>['url'=>$url]]],
        [['text'=>'سفر تازه', 'web_app'=>['url'=>trainMiniAppUrl('create')]]],
    ] : [[['text'=>'ثبت اولین سفر', 'web_app'=>['url'=>trainMiniAppUrl('create')]]]];
    sendMessage($chatId,$text,['inline_keyboard'=>$rows],true,null);
    return true;
}

function showTrainAlertTrips($chatId): void
{
    if (sendTripMiniAppEntry($chatId,'alerts')) { return; }
    $trips = getUserTrips($chatId);
    $rows = [];
    $db = initDatabase();
    foreach ($trips as $trip) {
        if (isTripExpired($trip['date'])) {
            continue;
        }
        $catalog = loadTrainCatalog($db, $trip);
        $first = $catalog['tickets'][0] ?? [];
        $route = isset($first['from_title'], $first['to_title']) ? $first['from_title'] . ' به ' . $first['to_title'] : $trip['route'];
        $rows[] = [['text'=>$route . ' | ' . $trip['date'] . ' | ' . tripFollowSummary($trip),
            'callback_data'=>'train_follow_' . $trip['id']]];
    }
    $rows[] = [['text'=>'سفرها و فیلترهای من', 'callback_data'=>'show_my_trips'], ['text'=>'افزودن سفر', 'callback_data'=>'add_trip']];
    sendMessage($chatId, count($rows) > 1 ? 'هشدارهای کدام سفر را مدیریت کنیم؟' : 'سفر فعالی ندارید؛ می‌توانید یک سفر تازه ثبت کنید.',
        ['inline_keyboard'=>$rows], true, null);
}

function sendTrainResultsFooter(array $trip): void
{
    sendMessage($trip['chat_id'], 'تنظیم هشدار سفر '.translateRoute($trip['route']).' · '.$trip['date']."\nاگر دنبال قطار خاصی هستید و پیامش نیامده، ممکن است فعلاً موجود نباشد یا با ظرفیت و فیلترهای سفرتان همخوان نباشد.\nاز فهرست کامل این سفر انتخابش کنید تا اگر موجود و مطابق تنظیمات شد، خبرتان کنیم.",
        ['inline_keyboard'=>[
            [['text'=>'انتخاب قطارهای هشدار این سفر', 'callback_data'=>'train_follow_' . $trip['id']]],
            [['text'=>'فیلترهای این سفر', 'callback_data'=>'trip_settings_' . $trip['id']],
                ['text'=>'مدیریت هشدارهای سفرها', 'callback_data'=>'train_follow_trips']],
        ]], true, null);
}
