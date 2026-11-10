<?php

require_once __DIR__ . '/trip_preferences.php';

function sendTripFilterMessage($chatId, string $message, ?array $keyboard = null): void
{
    sendMessage($chatId, $message, $keyboard, true, null);
}

function showTripFilterMenu($chatId): void
{
    $trip = getUserState($chatId)['temp_data'];
    setUserState($chatId, 'SET_TRIP_FILTERS', $trip);
    sendTripFilterMessage($chatId, "فیلترهای سفر شما:\n" . tripFilterSummary($trip)
        . "\n\nفیلترها را تغییر بدهید یا همین حالا ثبت کنید.", ['inline_keyboard'=>[
        [['text'=>'نمایش ظرفیت کمتر', 'callback_data'=>'trip_filter_partial']],
        [['text'=>'نوع قطار', 'callback_data'=>'trip_filter_types'], ['text'=>'شرکت ریلی', 'callback_data'=>'trip_filter_companies']],
        [['text'=>'ساعت حرکت', 'callback_data'=>'trip_filter_time'], ['text'=>'قیمت هر نفر', 'callback_data'=>'trip_filter_price']],
        [['text'=>'پاک کردن فیلترهای تکمیلی', 'callback_data'=>'trip_filter_reset']],
        [['text'=>'ثبت و شروع جست‌وجو', 'callback_data'=>'trip_filter_save']],
        [['text'=>'ثبت و انتخاب قطارهای هشدار', 'callback_data'=>'trip_filter_save_follow']],
        [['text'=>'انصراف', 'callback_data'=>'trip_filter_cancel']],
    ]]);
}

function showTripTrainTypes($chatId): void
{
    $trip = getUserState($chatId)['temp_data'];
    $selected = tripFilters($trip)['train_types'] ?? [];
    $rows = [];
    foreach (['compartment_4'=>'کوپه‌ای ۴ نفره', 'compartment_6'=>'کوپه‌ای ۶ نفره', 'seated'=>'سالنی/اتوبوسی'] as $key => $label) {
        if ($key === 'seated' && !empty($trip['coupe'])) {
            continue;
        }
        $rows[] = [['text'=>(in_array($key, $selected, true) ? '✅ ' : '') . $label, 'callback_data'=>'trip_filter_type_' . $key]];
    }
    $rows[] = [['text'=>'همهٔ انواع', 'callback_data'=>'trip_filter_type_all']];
    $rows[] = [['text'=>'بازگشت به فیلترها', 'callback_data'=>'trip_filter_menu']];
    sendTripFilterMessage($chatId, 'می‌توانید چند نوع قطار را انتخاب کنید؛ انتخاب خالی یعنی همه.', ['inline_keyboard'=>$rows]);
}

function handleTripFilterCallback($chatId, string $data): bool
{
    if (preg_match('/^trip_settings_(\d+)$/D', $data, $matches)) {
        $db = initDatabase();
        $stmt = $db->prepare('SELECT * FROM user_trips WHERE id = :id AND chat_id = :chat_id');
        $stmt->bindValue(':id', (int) $matches[1], SQLITE3_INTEGER);
        $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
        $trip = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$trip) {
            sendTripFilterMessage($chatId, 'این سفر در فهرست سفرهای شما نیست.');
            return true;
        }
        $trip['editing_trip_id'] = $trip['id'];
        setUserState($chatId, 'SET_TRIP_FILTERS', $trip);
        showTripFilterMenu($chatId);
        return true;
    }
    if (strpos($data, 'trip_filter_') !== 0) {
        return false;
    }
    $state = getUserState($chatId);
    if (!in_array($state['current_state'] ?? '', ['SET_TRIP_FILTERS', 'SET_TRIP_FILTER_COMPANIES', 'SET_TRIP_FILTER_TIME', 'SET_TRIP_FILTER_PRICE'], true)) {
        return true; // Old keyboards must not change a saved/cancelled draft.
    }
    $trip = $state['temp_data'];
    $filters = tripFilters($trip);
    if ($data === 'trip_filter_cancel') {
        clearUserState($chatId);
        sendTripFilterMessage($chatId, 'تنظیم فیلترها لغو شد.');
    } elseif ($data === 'trip_filter_save' || $data === 'trip_filter_save_follow') {
        saveTripFilterDraft($chatId, $trip, $data === 'trip_filter_save_follow');
    } elseif ($data === 'trip_filter_types') {
        showTripTrainTypes($chatId);
    } elseif (strpos($data, 'trip_filter_type_') === 0) {
        $key = substr($data, strlen('trip_filter_type_'));
        if (!in_array($key, ['all', 'compartment_4', 'compartment_6', 'seated'], true)
            || ($key === 'seated' && !empty($trip['coupe']))) {
            return true;
        }
        $selected = $filters['train_types'] ?? [];
        $filters['train_types'] = $key === 'all' ? [] : (in_array($key, $selected, true)
            ? array_values(array_diff($selected, [$key])) : array_merge($selected, [$key]));
        $trip['search_filters'] = normalizeTripFilters($filters);
        setUserState($chatId, 'SET_TRIP_FILTERS', $trip);
        showTripTrainTypes($chatId);
    } elseif (in_array($data, ['trip_filter_companies', 'trip_filter_time', 'trip_filter_price'], true)) {
        $prompts = [
            'trip_filter_companies' => ['SET_TRIP_FILTER_COMPANIES', "نام شرکت‌های ریلی را مطابق قاصدک۲۴ وارد کنید و چند نام را با ویرگول جدا کنید.\nمثال: فدک، بن ریل\nبرای برداشتن این فیلتر بنویسید: همه"],
            'trip_filter_time' => ['SET_TRIP_FILTER_TIME', "بازهٔ ساعت حرکت را به شکل 08:00-18:00 وارد کنید.\nبرای شب تا صبح: 18:00-06:00\nبرای برداشتن این فیلتر بنویسید: همه"],
            'trip_filter_price' => ['SET_TRIP_FILTER_PRICE', "بازهٔ قیمت هر نفر را به تومان وارد کنید.\nمثال: 500000-2000000\nبرای برداشتن این فیلتر بنویسید: همه"],
        ];
        [$next, $prompt] = $prompts[$data];
        setUserState($chatId, $next, $trip);
        sendTripFilterMessage($chatId, $prompt, ['inline_keyboard'=>[[['text'=>'بازگشت به فیلترها', 'callback_data'=>'trip_filter_menu']]]]);
    } else {
        if ($data === 'trip_filter_partial') {
            $trip['allow_partial_capacity'] = empty($trip['allow_partial_capacity']) ? 1 : 0;
        } elseif ($data === 'trip_filter_reset') {
            $trip['search_filters'] = [];
        } elseif ($data !== 'trip_filter_menu') {
            return true;
        }
        setUserState($chatId, 'SET_TRIP_FILTERS', $trip);
        showTripFilterMenu($chatId);
    }
    return true;
}

function handleTripFilterInput($chatId, string $text): void
{
    $state = getUserState($chatId);
    $trip = $state['temp_data'];
    $filters = tripFilters($trip);
    $text = trim($text);
    $clear = $text === 'همه';
    try {
        switch ($state['current_state']) {
            case 'SET_TRIP_FILTER_COMPANIES':
                $filters['companies'] = $clear ? [] : preg_split('/[,،\n]+/u', $text);
                break;
            case 'SET_TRIP_FILTER_TIME':
                unset($filters['departure_from'], $filters['departure_to']);
                if (!$clear) {
                    $parts = preg_split('/\s*(?:-|–|تا)\s*/u', trainEnglishDigits($text));
                    if (count($parts) !== 2) {
                        throw new InvalidArgumentException('ساعت را به شکل 08:00-18:00 وارد کنید.');
                    }
                    [$filters['departure_from'], $filters['departure_to']] = $parts;
                }
                break;
            case 'SET_TRIP_FILTER_PRICE':
                unset($filters['min_price'], $filters['max_price']);
                if (!$clear) {
                    $parts = preg_split('/(?:-|–|تا)/u', str_replace([',', '٬', ' '], '', trainEnglishDigits($text)));
                    if (count($parts) !== 2) {
                        throw new InvalidArgumentException('قیمت را به شکل 500000-2000000 و به تومان وارد کنید.');
                    }
                    [$filters['min_price'], $filters['max_price']] = $parts;
                }
                break;
            default:
                showTripFilterMenu($chatId);
                return;
        }
        $trip['search_filters'] = normalizeTripFilters($filters);
        setUserState($chatId, 'SET_TRIP_FILTERS', $trip);
        showTripFilterMenu($chatId);
    } catch (InvalidArgumentException $error) {
        sendTripFilterMessage($chatId, $error->getMessage());
    }
}

function saveTripFilterDraft($chatId, array $trip, bool $openFollows = false): void
{
    $newTripId = null;
    $trip['search_filters'] = tripFilters($trip);
    if (isset($trip['editing_trip_id'])) {
        $db = initDatabase();
        require_once __DIR__ . '/trip_app.php';
        try {
            miniAppPreferences($db, (string)$chatId, ['trip_id'=>(int)$trip['editing_trip_id'],
                'revision'=>(int)($trip['follow_revision'] ?? 0), 'allow_partial_capacity'=>!empty($trip['allow_partial_capacity']), 'search_filters'=>$trip['search_filters']]);
        } catch (Throwable $error) {
            sendTripFilterMessage($chatId, in_array($error->getCode(), [400,404,409], true) ? $error->getMessage() : 'ذخیرهٔ فیلترها انجام نشد؛ دوباره تلاش کنید.');
            return;
        }
    } else {
        $trip['follow_mode'] = 'all';
        $newTripId = saveUserTrip($chatId, $trip);
    }
    clearUserState($chatId);
    sendTripFilterMessage($chatId, 'سفر و فیلترهای شما ثبت شد.', getMainMenuKeyboard($chatId));
    $targetTripId = (int) ($newTripId ?? $trip['editing_trip_id']);
    if ($openFollows) {
        showTrainFollowPicker($chatId, $targetTripId);
    } else {
        processSingleUserTrip($chatId, $targetTripId);
    }
}
