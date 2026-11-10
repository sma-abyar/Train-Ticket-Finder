<?php

require_once __DIR__ . '/trip_dates.php';

function miniAppCities(): array
{
    return json_decode(file_get_contents(__DIR__ . '/train_cities.json'), true, 512, JSON_THROW_ON_ERROR);
}

function miniAppTrip(array $trip): array
{
    $cities = array_column(miniAppCities(), 'text', 'code');
    $parts = explode('-', $trip['route'], 2);
    return [
        'id'=>(int)$trip['id'], 'route'=>$trip['route'],
        'route_title'=>($cities[$parts[0]] ?? $parts[0]) . ' به ' . ($cities[$parts[1] ?? ''] ?? ($parts[1] ?? '')),
        'date'=>$trip['date'], 'count'=>(int)$trip['count'], 'type'=>(int)$trip['type'],
        'coupe'=>(bool)$trip['coupe'], 'allow_partial_capacity'=>(bool)$trip['allow_partial_capacity'],
        'search_filters'=>tripFilters($trip), 'mode'=>tripFollowMode($trip), 'revision'=>(int)$trip['follow_revision'],
    ];
}

function miniAppValidateTrip(array $input): array
{
    $required = ['origin','destination','date','count','type','coupe','allow_partial_capacity','search_filters'];
    if (array_diff($required, array_keys($input)) || array_diff(array_keys($input), $required)) {
        throw new InvalidArgumentException('اطلاعات سفر کامل نیست.', 400);
    }
    $codes = array_column(miniAppCities(), 'code');
    if (!in_array($input['origin'], $codes, true) || !in_array($input['destination'], $codes, true)
        || $input['origin'] === $input['destination']) {
        throw new InvalidArgumentException('مبدأ و مقصد معتبر و متفاوت انتخاب کنید.', 400);
    }
    if (!is_int($input['count']) || $input['count'] < 1 || $input['count'] > 12
        || !is_int($input['type']) || !in_array($input['type'], [0,1,2], true)
        || !is_bool($input['coupe']) || !is_bool($input['allow_partial_capacity'])) {
        throw new InvalidArgumentException('تعداد مسافر یا گزینه‌های سفر معتبر نیست.', 400);
    }
    if (!is_string($input['date'])) {
        throw new InvalidArgumentException('تاریخ معتبر انتخاب کنید.', 400);
    }
    $date = trainEnglishDigits($input['date']);
    $departure = preg_match('/^1[0-8]\d{2}-\d{2}-\d{2}$/D', $date) ? tripDepartureDate($date) : null;
    if (!$departure || isTripExpired($date)) {
        throw new InvalidArgumentException('تاریخ باید شمسی و از امروز به بعد باشد.', 400);
    }
    $filters = miniAppValidateFilters($input['search_filters']);
    return ['route'=>$input['origin'].'-'.$input['destination'], 'date'=>$date,
        'count'=>$input['count'], 'type'=>$input['type'], 'coupe'=>(int)$input['coupe'],
        'allow_partial_capacity'=>(int)$input['allow_partial_capacity'], 'search_filters'=>$filters];
}

function miniAppValidateFilters($filters): array
{
    if (!is_array($filters)) {
        throw new InvalidArgumentException('فیلترها معتبر نیستند.', 400);
    }
    foreach (['companies','train_types'] as $key) {
        if (isset($filters[$key]) && !is_array($filters[$key])) {
            throw new InvalidArgumentException('گزینه‌های فیلتر معتبر نیستند.', 400);
        }
    }
    foreach (['departure_from','departure_to'] as $key) {
        if (isset($filters[$key]) && !is_string($filters[$key])) {
            throw new InvalidArgumentException('ساعت معتبر نیست.', 400);
        }
    }
    foreach (['min_price','max_price'] as $key) {
        if (isset($filters[$key]) && (!is_int($filters[$key]) || $filters[$key] < 0)) {
            throw new InvalidArgumentException('قیمت معتبر نیست.', 400);
        }
    }
    try { return normalizeTripFilters($filters); }
    catch (InvalidArgumentException $error) { throw new InvalidArgumentException($error->getMessage(), 400); }
}

/** One transaction and connection: an ambiguous retry must never create two trips. */
function miniAppCreate(SQLite3 $db, string $chat, array $body): array
{
    $key = $body['request_id'] ?? null;
    if (!is_string($key) || !preg_match('/^[a-zA-Z0-9_-]{16,80}$/D', $key) || !is_array($body['trip'] ?? null)) {
        throw new InvalidArgumentException('شناسهٔ ثبت سفر معتبر نیست.', 400);
    }
    $trip = miniAppValidateTrip($body['trip']);
    $hash = hash('sha256', json_encode($trip, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $db->exec('CREATE TABLE IF NOT EXISTS miniapp_requests (chat_id TEXT NOT NULL, request_id TEXT NOT NULL, payload_hash TEXT NOT NULL, trip_id INTEGER NOT NULL, created_at INTEGER NOT NULL, PRIMARY KEY(chat_id, request_id))');
    if (!$db->exec('BEGIN IMMEDIATE')) { throw new RuntimeException($db->lastErrorMsg()); }
    try {
        $old = followSql($db, 'SELECT * FROM miniapp_requests WHERE chat_id=:chat AND request_id=:key', [':chat'=>$chat, ':key'=>$key])->fetchArray(SQLITE3_ASSOC);
        if ($old) {
            if (!hash_equals($old['payload_hash'], $hash)) { throw new RuntimeException('اطلاعات این درخواست تغییر کرده است؛ ثبت تازه‌ای آغاز کنید.', 409); }
            $saved = followSql($db, 'SELECT * FROM user_trips WHERE id=:id AND chat_id=:chat', [':id'=>$old['trip_id'], ':chat'=>$chat])->fetchArray(SQLITE3_ASSOC);
            if (!$saved) { throw new RuntimeException('این سفر قبلاً ثبت و سپس حذف شده است.', 409); }
            $db->exec('COMMIT');
            return ['trip'=>miniAppTrip($saved), 'created'=>false];
        }
        followSql($db, "INSERT INTO user_trips (chat_id,route,date,return_date,count,type,coupe,filter,no_counting_notif,no_ticket_notif,bad_data_notif,allow_partial_capacity,search_filters,follow_mode) VALUES (:chat,:route,:date,:date,:count,:type,:coupe,0,0,0,0,:partial,:filters,'all')", [
            ':chat'=>$chat, ':route'=>$trip['route'], ':date'=>$trip['date'], ':count'=>$trip['count'], ':type'=>$trip['type'], ':coupe'=>$trip['coupe'], ':partial'=>$trip['allow_partial_capacity'], ':filters'=>json_encode($trip['search_filters'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        $id = $db->lastInsertRowID();
        followSql($db, 'INSERT INTO miniapp_requests VALUES (:chat,:key,:hash,:id,:time)', [':chat'=>$chat, ':key'=>$key, ':hash'=>$hash, ':id'=>$id, ':time'=>time()]);
        $saved = ownedFollowTrip($db, $id, $chat);
        if (!$db->exec('COMMIT')) { throw new RuntimeException($db->lastErrorMsg()); }
        return ['trip'=>miniAppTrip($saved), 'created'=>true];
    } catch (Throwable $error) { $db->exec('ROLLBACK'); throw $error; }
}

function miniAppPreferences(SQLite3 $db, string $chat, array $body): array
{
    if (!is_int($body['trip_id'] ?? null) || !is_int($body['revision'] ?? null)
        || !is_bool($body['allow_partial_capacity'] ?? null)) {
        throw new InvalidArgumentException('اطلاعات تنظیمات معتبر نیست.', 400);
    }
    $filters = miniAppValidateFilters($body['search_filters'] ?? null);
    if (!$db->exec('BEGIN IMMEDIATE')) { throw new RuntimeException($db->lastErrorMsg()); }
    try {
        $trip = ownedFollowTrip($db, $body['trip_id'], $chat);
        if ((int)$trip['follow_revision'] !== $body['revision']) { throw new RuntimeException('تنظیمات تغییر کرده است؛ سفر را دوباره باز کنید.', 409); }
        if (tripFilters($trip) == $filters && (bool)$trip['allow_partial_capacity'] === $body['allow_partial_capacity']) {
            $db->exec('COMMIT');
            return miniAppTrip($trip); // An unchanged save must not re-arm delivered alerts.
        }
        followSql($db, 'UPDATE user_trips SET allow_partial_capacity=:partial,search_filters=:filters,follow_revision=follow_revision+1,no_counting_notif=0,no_ticket_notif=0,bad_data_notif=0 WHERE id=:id AND chat_id=:chat', [':partial'=>(int)$body['allow_partial_capacity'], ':filters'=>json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), ':id'=>$trip['id'], ':chat'=>$chat]);
        followSql($db, 'DELETE FROM train_follow_delivery WHERE trip_id=:id', [':id'=>$trip['id']]);
        $saved = ownedFollowTrip($db, $trip['id'], $chat);
        if (!$db->exec('COMMIT')) { throw new RuntimeException($db->lastErrorMsg()); }
        return miniAppTrip($saved);
    } catch (Throwable $error) { $db->exec('ROLLBACK'); throw $error; }
}

function miniAppDeleteTrip(SQLite3 $db, string $chat, array $body): array
{
    if (!is_int($body['trip_id'] ?? null) || $body['trip_id'] < 1 || !is_int($body['revision'] ?? null)) {
        throw new InvalidArgumentException('اطلاعات سفر معتبر نیست.',400);
    }
    if (!$db->exec('BEGIN IMMEDIATE')) { throw new RuntimeException($db->lastErrorMsg()); }
    try {
        $trip=ownedFollowTrip($db,$body['trip_id'],$chat);
        if ((int)$trip['follow_revision']!==$body['revision']) { throw new RuntimeException('سفر تغییر کرده است؛ فهرست را تازه کن و دوباره تصمیم بگیر.',409); }
        followSql($db,'DELETE FROM user_trips WHERE id=:id AND chat_id=:chat',[':id'=>$body['trip_id'],':chat'=>$chat]);
        if (!$db->exec('COMMIT')) { throw new RuntimeException($db->lastErrorMsg()); }
        return ['deleted'=>true,'trip_id'=>$body['trip_id']];
    }catch(Throwable $error){$db->exec('ROLLBACK');throw $error;}
}
