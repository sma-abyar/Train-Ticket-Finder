<?php

require_once __DIR__ . '/ticket_search.php';
require_once __DIR__ . '/trip_preferences.php';
require_once __DIR__ . '/trip_dates.php';

function followSql(SQLite3 $db, string $sql, array $values = []): SQLite3Result
{
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->lastErrorMsg());
    }
    foreach ($values as $name => $value) {
        $stmt->bindValue($name, $value, $value === null ? SQLITE3_NULL : (is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT));
    }
    $result = $stmt->execute();
    if (!$result) {
        throw new RuntimeException($db->lastErrorMsg());
    }
    return $result;
}

function ensureTrainFollowTables(SQLite3 $db): void
{
    foreach ([
        'CREATE TABLE IF NOT EXISTS train_search_catalog (
            search_key TEXT PRIMARY KEY, tickets TEXT NOT NULL, known_trains TEXT NOT NULL, updated_at INTEGER NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS train_follow_delivery (
            trip_id INTEGER NOT NULL, train_key TEXT NOT NULL,
            stage INTEGER NOT NULL DEFAULT 0, pending INTEGER NOT NULL DEFAULT 0,
            claim_token TEXT, claimed_at INTEGER,
            PRIMARY KEY (trip_id, train_key)
        )',
        'CREATE TABLE IF NOT EXISTS train_initial_searches (
            search_key TEXT PRIMARY KEY, claim_token TEXT, claimed_at INTEGER, updated_at INTEGER NOT NULL, failed_at INTEGER
        )',
        'CREATE TRIGGER IF NOT EXISTS delete_train_follow_delivery AFTER DELETE ON user_trips
            BEGIN DELETE FROM train_follow_delivery WHERE trip_id = OLD.id; END',
    ] as $sql) {
        if (!$db->exec($sql)) {
            throw new RuntimeException($db->lastErrorMsg());
        }
    }
}

function tripFollowMode(array $trip): string
{
    $mode = $trip['follow_mode'] ?? 'all';
    return in_array($mode, ['all', 'except', 'selected', 'none'], true) ? $mode : 'none';
}

function tripFollowedTrains(array $trip): array
{
    return tripTrainChoices($trip, 'followed_trains');
}

function tripExcludedTrains(array $trip): array
{
    return tripTrainChoices($trip, 'excluded_trains');
}

function tripTrainChoices(array $trip, string $field): array
{
    $selected = $trip[$field] ?? [];
    if (is_string($selected)) {
        $selected = json_decode($selected, true);
    }
    if (!is_array($selected)) {
        return [];
    }
    return array_values(array_filter($selected, static fn($item) => is_array($item)
        && isset($item['key']) && is_string($item['key']) && preg_match('/^[a-f0-9]{64}$/D', $item['key'])));
}

/** Inventory IDs and prices can change. Track the concrete dated service instead. */
function trainFollowKey(array $ticket): string
{
    $normalize = static fn($value) => preg_replace('/[\s\x{200C}\x{200E}\x{200F}]+/u', '',
        trainEnglishDigits(strtr(trim((string) $value), ['ي'=>'ی', 'ك'=>'ک'])));
    $number = $normalize($ticket['train_number_en'] ?? $ticket['train_number'] ?? '');
    $time = trainTimeMinutes((string) ($ticket['time'] ?? ''));
    if ($number === '' || $time === null) {
        // Never broaden an unidentified service to every train on the route.
        return hash('sha256', 'inventory-id:' . ($ticket['id'] ?? json_encode($ticket)));
    }
    return hash('sha256', json_encode([
        'number'=>$number, 'minute'=>$time,
        'owner'=>$normalize($ticket['owner'] ?? $ticket['owner_name'] ?? ''),
        'wagon'=>$normalize($ticket['wagon_type'] ?? $ticket['wagon_name'] ?? ''),
        'source'=>$normalize($ticket['source'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

function ticketIsFollowed(array $ticket, array $trip): bool
{
    if (tripFollowMode($trip) === 'all') { return true; }
    return tripFollowsTrainKey(trainFollowKey($ticket), $trip);
}

function tripFollowsTrainKey(string $key, array $trip): bool
{
    $mode = tripFollowMode($trip);
    return $mode === 'all' || ($mode === 'except'
        && !in_array($key, array_column(tripExcludedTrains($trip), 'key'), true)) || ($mode === 'selected'
        && in_array($key, array_column(tripFollowedTrains($trip), 'key'), true));
}

function trainFollowSnapshot(array $ticket): array
{
    return [
        'key'=>trainFollowKey($ticket), 'number'=>(string) ($ticket['train_number'] ?? $ticket['train_number_en'] ?? ''),
        'time'=>(string) ($ticket['time'] ?? ''), 'wagon'=>(string) ($ticket['wagon_name'] ?? ''),
        'company'=>(string) ($ticket['owner_name'] ?? ''),
    ];
}

function trainCatalogKey(array $payload): string
{
    return hash('sha256', http_build_query($payload));
}

function trainSearchTickets($data): ?array
{
    if (!is_array($data) || ($data['data']['status'] ?? '') !== 'success'
        || !isset($data['data']['data']['departure']) || !is_array($data['data']['data']['departure'])) {
        return null;
    }
    $tickets = $data['data']['data']['departure'];
    foreach ($tickets as $ticket) {
        if (!is_array($ticket)) {
            return null;
        }
    }
    return $tickets;
}

function storeTrainCatalog(SQLite3 $db, array $payload, array $tickets): void
{
    if (!$db->exec('BEGIN IMMEDIATE')) {
        throw new RuntimeException($db->lastErrorMsg());
    }
    try {
        $key = trainCatalogKey($payload);
        $previous = followSql($db, 'SELECT known_trains FROM train_search_catalog WHERE search_key=:key', [':key'=>$key])->fetchArray(SQLITE3_ASSOC);
        $known = array_column(json_decode($previous['known_trains'] ?? '[]', true, 512, JSON_THROW_ON_ERROR), null, 'key');
        foreach ($tickets as $ticket) {
            $snapshot = trainFollowSnapshot($ticket);
            $known[$snapshot['key']] = $snapshot;
        }
        followSql($db, 'INSERT INTO train_search_catalog (search_key, tickets, known_trains, updated_at) VALUES (:key, :tickets, :known, :now)
            ON CONFLICT(search_key) DO UPDATE SET tickets=excluded.tickets, known_trains=excluded.known_trains, updated_at=excluded.updated_at', [
            ':key'=>$key, ':tickets'=>json_encode(array_values($tickets), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':known'=>json_encode(array_values($known), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), ':now'=>time(),
        ]);
        followSql($db, 'DELETE FROM train_search_catalog WHERE updated_at<:old', [':old'=>time()-7*86400]);
        if (!$db->exec('COMMIT')) {
            throw new RuntimeException($db->lastErrorMsg());
        }
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

function loadTrainCatalog(SQLite3 $db, array $trip): ?array
{
    $row = followSql($db, 'SELECT tickets, known_trains, updated_at FROM train_search_catalog WHERE search_key=:key',
        [':key'=>trainCatalogKey(TicketSearchBatch::payload($trip))])->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        return null;
    }
    return ['tickets'=>json_decode($row['tickets'], true, 512, JSON_THROW_ON_ERROR),
        'known_trains'=>json_decode($row['known_trains'], true, 512, JSON_THROW_ON_ERROR), 'updated_at'=>(int) $row['updated_at']];
}

/** Share only recent successful inventory; never use historical stock as a live alert. */
function initialTrainSearch(SQLite3 $db, array $trip, callable $request, ?int $now = null): array
{
    $now = $now ?? time();
    $fresh = static function () use ($db, $trip, $now): ?array {
        $catalog = loadTrainCatalog($db, $trip);
        return $catalog && $catalog['updated_at'] <= $now && $now - $catalog['updated_at'] <= 120 ? $catalog : null;
    };
    $cached = static fn(array $catalog): array => ['state'=>'ready', 'source'=>'cache', 'checked_at'=>$catalog['updated_at'],
        'response'=>['data'=>['status'=>'success', 'data'=>['departure'=>$catalog['tickets']]]]];
    if ($catalog = $fresh()) { return $cached($catalog); }
    $payload = TicketSearchBatch::payload($trip);
    $key = trainCatalogKey($payload);
    $failure = followSql($db, 'SELECT failed_at FROM train_initial_searches WHERE search_key=:key', [':key'=>$key])->fetchArray(SQLITE3_ASSOC);
    if (!empty($failure['failed_at']) && $now - (int)$failure['failed_at'] < 30) {
        return ['state'=>'unavailable', 'source'=>'recent_failure', 'checked_at'=>(int)$failure['failed_at'], 'response'=>null];
    }
    $token = bin2hex(random_bytes(16));
    followSql($db, 'INSERT INTO train_initial_searches(search_key,claim_token,claimed_at,updated_at) VALUES(:key,:token,:now,:now)
        ON CONFLICT(search_key) DO UPDATE SET claim_token=excluded.claim_token,claimed_at=excluded.claimed_at,updated_at=excluded.updated_at
        WHERE train_initial_searches.claimed_at IS NULL OR train_initial_searches.claimed_at<:expired',
        [':key'=>$key, ':token'=>$token, ':now'=>$now, ':expired'=>$now-90]);
    if ($db->changes() !== 1) { return ['state'=>'checking']; }
    $failed = false;
    try {
        // A concurrent request may have finished between our first read and claim.
        if ($catalog = $fresh()) { return $cached($catalog); }
        $failed = true;
        $response = $request($payload);
        $tickets = trainSearchTickets($response);
        $failed = $tickets === null;
        if ($tickets !== null) { storeTrainCatalog($db, $payload, $tickets); }
        return ['state'=>$tickets !== null ? 'ready' : 'unavailable', 'source'=>'live', 'checked_at'=>time(), 'response'=>$response];
    } finally {
        followSql($db, 'UPDATE train_initial_searches SET claim_token=NULL,claimed_at=NULL,failed_at=:failure WHERE search_key=:key AND claim_token=:token',
            [':key'=>$key, ':token'=>$token, ':failure'=>$failed ? time() : null]);
        followSql($db, 'DELETE FROM train_initial_searches WHERE claimed_at IS NULL AND updated_at<:old', [':old'=>$now-7*86400]);
    }
}

function ownedFollowTrip(SQLite3 $db, int $tripId, string $chatId): array
{
    $trip = followSql($db, 'SELECT * FROM user_trips WHERE id=:id AND chat_id=:chat', [':id'=>$tripId, ':chat'=>$chatId])
        ->fetchArray(SQLITE3_ASSOC);
    if (!$trip || isTripExpired($trip['date'])) {
        throw new RuntimeException('این سفر در فهرست سفرهای فعال شما نیست.', 404);
    }
    return $trip;
}

/** Include zero-capacity trains and selected services missing from the latest list. */
function trainFollowOptions(array $trip, ?array $catalog): array
{
    $rows = [];
    foreach ($catalog['tickets'] ?? [] as $ticket) {
        $item = trainFollowSnapshot($ticket);
        $item += ['capacity'=>ticketAvailableSeats($ticket), 'price'=>$ticket['cost'] ?? null, 'missing'=>false];
        $rows[$item['key']] = $item;
    }
    foreach (array_merge($catalog['known_trains'] ?? [], tripFollowedTrains($trip), tripExcludedTrains($trip)) as $selected) {
        if (!isset($rows[$selected['key']])) {
            $rows[$selected['key']] = $selected + ['capacity'=>null, 'price'=>null, 'missing'=>true];
        }
    }
    return array_values($rows);
}

function saveTrainFollows(SQLite3 $db, int $tripId, string $chatId, string $mode, array $keys, int $revision, array $excludedKeys = []): array
{
    if (!in_array($mode, ['all', 'except', 'selected', 'none'], true) || count($keys) > 300 || count($excludedKeys) > 300
        || count(array_filter($keys, 'is_string')) !== count($keys)
        || count(array_filter($excludedKeys, 'is_string')) !== count($excludedKeys)) {
        throw new InvalidArgumentException('انتخاب قطارها نامعتبر است.', 400);
    }
    if (!$db->exec('BEGIN IMMEDIATE')) {
        throw new RuntimeException($db->lastErrorMsg());
    }
    try {
        $trip = ownedFollowTrip($db, $tripId, $chatId);
        if ((int) $trip['follow_revision'] !== $revision) {
            throw new RuntimeException('تنظیمات هشدار تغییر کرده است؛ فهرست را دوباره باز کنید.', 409);
        }
        $known = array_column(trainFollowOptions($trip, loadTrainCatalog($db, $trip)), null, 'key');
        $selected = [];
        foreach (array_unique($keys) as $key) {
            if (!isset($known[$key])) {
                throw new InvalidArgumentException('این قطار دیگر در فهرست نیست؛ فهرست را تازه کنید.', 400);
            }
            $selected[] = array_intersect_key($known[$key], array_flip(['key','number','time','wagon','company']));
        }
        $excluded = [];
        foreach (array_unique($excludedKeys) as $key) {
            if (!isset($known[$key])) {
                throw new InvalidArgumentException('این قطار دیگر در فهرست نیست؛ فهرست را تازه کنید.', 400);
            }
            $excluded[] = array_intersect_key($known[$key], array_flip(['key','number','time','wagon','company']));
        }
        if ($mode === 'selected' && !$selected) {
            $mode = 'none'; // Clearing the selection must never enable all alerts.
        }
        if ($mode === 'except' && !$excluded) {
            $mode = 'all';
        }
        followSql($db, 'UPDATE user_trips SET follow_mode=:mode, followed_trains=:selected, excluded_trains=:excluded, follow_revision=follow_revision+1,
            no_counting_notif=0, no_ticket_notif=0, bad_data_notif=0 WHERE id=:id AND chat_id=:chat AND follow_revision=:revision', [
            ':mode'=>$mode, ':selected'=>json_encode($selected, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':excluded'=>json_encode($excluded, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':id'=>$tripId, ':chat'=>$chatId, ':revision'=>$revision,
        ]);
        // Keep delivery history for unchanged choices, but re-arm newly followed
        // trains and a resumed trip. Delete history in the same transaction.
        $newTrip = array_replace($trip, ['follow_mode'=>$mode, 'followed_trains'=>$selected, 'excluded_trains'=>$excluded]);
        $history = followSql($db, 'SELECT train_key FROM train_follow_delivery WHERE trip_id=:id', [':id'=>$tripId]);
        $remove = [];
        while ($row = $history->fetchArray(SQLITE3_ASSOC)) {
            if (!tripFollowsTrainKey($row['train_key'], $trip) || !tripFollowsTrainKey($row['train_key'], $newTrip)) {
                $remove[] = $row['train_key'];
            }
        }
        $history->finalize();
        foreach ($remove as $key) {
            followSql($db, 'DELETE FROM train_follow_delivery WHERE trip_id=:id AND train_key=:key', [':id'=>$tripId, ':key'=>$key]);
        }
        if (!$db->exec('COMMIT')) {
            throw new RuntimeException($db->lastErrorMsg());
        }
        return ownedFollowTrip($db, $tripId, $chatId);
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

/** Idempotent mute/restore from a ticket message, scoped to its owner and trip. */
function setTrainMessagePreference(SQLite3 $db, int $tripId, string $chatId, string $prefix, bool $enabled): array
{
    if (!preg_match('/^[a-f0-9]{24}$/D', $prefix)) {
        throw new InvalidArgumentException('انتخاب قطار نامعتبر است.', 400);
    }
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $trip = ownedFollowTrip($db, $tripId, $chatId);
        $matches = array_values(array_filter(trainFollowOptions($trip, loadTrainCatalog($db, $trip)),
            static fn($item) => str_starts_with($item['key'], $prefix)));
        if (count($matches) !== 1) {
            throw new InvalidArgumentException('لطفاً قطار را از فهرست کامل این سفر انتخاب کنید.', 400);
        }
        $key = $matches[0]['key'];
        $mode = tripFollowMode($trip);
        $selected = array_column(tripFollowedTrains($trip), 'key');
        $excluded = array_column(tripExcludedTrains($trip), 'key');
        $currentlyEnabled = $mode === 'all' || ($mode === 'except' && !in_array($key,$excluded,true))
            || ($mode === 'selected' && in_array($key,$selected,true));
        if ($currentlyEnabled === $enabled) { return $trip; }
        if ($enabled) {
            $excluded = array_values(array_diff($excluded, [$key]));
            if ($mode === 'none' || $mode === 'selected') {
                if ($mode === 'none') { $selected=[]; }
                $mode = 'selected';
                $selected = array_values(array_unique(array_merge($selected, [$key])));
            } elseif ($mode === 'except' && !$excluded) {
                $mode = 'all';
            }
        } elseif ($mode === 'all' || $mode === 'except') {
            $mode = 'except';
            $excluded = array_values(array_unique(array_merge($excluded, [$key])));
        } elseif ($mode === 'selected') {
            $selected = array_values(array_diff($selected, [$key]));
        }
        try {
            return saveTrainFollows($db, $tripId, $chatId, $mode, $selected, (int) $trip['follow_revision'], $excluded);
        } catch (RuntimeException $error) {
            if ($error->getCode() !== 409 || $attempt === 2) {
                throw $error;
            }
        }
    }
    throw new RuntimeException('ذخیرهٔ انتخاب انجام نشد.', 409);
}

function tripFollowRevisionCurrent(SQLite3 $db, array $trip): bool
{
    $row = followSql($db, 'SELECT follow_revision FROM user_trips WHERE id=:id AND chat_id=:chat',
        [':id'=>(int) $trip['id'], ':chat'=>(string) $trip['chat_id']])->fetchArray(SQLITE3_ASSOC);
    return $row && (int) $row['follow_revision'] === (int) ($trip['follow_revision'] ?? 0);
}

/** All enabled modes notify on opening / sufficient-capacity transitions, not steady polls. */
final class TrainFollowAlerts
{
    private SQLite3 $db;

    public function __construct(SQLite3 $db)
    {
        $this->db = $db;
    }

    public function observe(array $trip, array $tickets): void
    {
        $stages = [];
        foreach ($tickets as $ticket) {
            $key = trainFollowKey($ticket);
            if (!tripFollowsTrainKey($key, $trip)) { continue; }
            $stage = ticketMatchesCapacity($ticket, $trip) && ticketMatchesLocalFilters($ticket, $trip)
                ? (ticketAvailableSeats($ticket) >= (int) $trip['count'] ? 2 : 1) : 0;
            $stages[$key] = max($stages[$key] ?? 0, $stage);
        }
        if (!$this->db->exec('BEGIN IMMEDIATE')) {
            throw new RuntimeException($this->db->lastErrorMsg());
        }
        try {
            $current = followSql($this->db, 'SELECT follow_revision, follow_mode FROM user_trips WHERE id=:id', [':id'=>(int) $trip['id']])->fetchArray(SQLITE3_ASSOC);
            if ($current && in_array($current['follow_mode'], ['all','except','selected'], true) && (int) $current['follow_revision'] === (int) $trip['follow_revision']) {
                $history = [];
                $rows = followSql($this->db, 'SELECT train_key,stage,pending FROM train_follow_delivery WHERE trip_id=:id', [':id'=>(int)$trip['id']]);
                while ($row = $rows->fetchArray(SQLITE3_ASSOC)) { $history[$row['train_key']] = $row; }
                $rows->finalize();
                $keys = array_unique(array_merge(array_keys($stages), array_keys($history), array_column(tripFollowedTrains($trip), 'key')));
                foreach ($keys as $key) {
                    $old = $history[$key] ?? null;
                    $before = (int) ($old['stage'] ?? 0);
                    $stage = $stages[$key] ?? 0;
                    $pending = $stage === 0 ? 0 : ($stage > $before ? 1 : (int)($old['pending'] ?? 0));
                    // A steady poll needs no write and must preserve any active lease.
                    if ($old && $before === $stage && (int)$old['pending'] === $pending) { continue; }
                    followSql($this->db, 'INSERT INTO train_follow_delivery (trip_id, train_key, stage, pending) VALUES (:id,:key,:stage,:pending)
                        ON CONFLICT(trip_id,train_key) DO UPDATE SET stage=excluded.stage, pending=excluded.pending,
                        claim_token=CASE WHEN stage=excluded.stage THEN claim_token ELSE NULL END,
                        claimed_at=CASE WHEN stage=excluded.stage THEN claimed_at ELSE NULL END',
                        [':id'=>(int) $trip['id'], ':key'=>$key, ':stage'=>$stage, ':pending'=>$pending]);
                }
            }
            if (!$this->db->exec('COMMIT')) {
                throw new RuntimeException($this->db->lastErrorMsg());
            }
        } catch (Throwable $error) {
            $this->db->exec('ROLLBACK');
            throw $error;
        }
    }

    public function claim(array $trip, string $key): ?string
    {
        $token = bin2hex(random_bytes(16));
        followSql($this->db, 'UPDATE train_follow_delivery SET claim_token=:token, claimed_at=:now
            WHERE trip_id=:id AND train_key=:key AND pending=1 AND stage>0 AND (claimed_at IS NULL OR claimed_at<:retry)
            AND EXISTS (SELECT 1 FROM user_trips WHERE id=:id AND follow_mode IN(\'all\',\'except\',\'selected\') AND follow_revision=:revision)', [
            ':token'=>$token, ':now'=>time(), ':retry'=>time()-300, ':id'=>(int) $trip['id'], ':key'=>$key, ':revision'=>(int) $trip['follow_revision'],
        ]);
        return $this->db->changes() === 1 ? $token : null;
    }

    public function complete(int $tripId, string $key, string $token, bool $sent): void
    {
        followSql($this->db, 'UPDATE train_follow_delivery SET pending=:pending, claim_token=NULL, claimed_at=NULL
            WHERE trip_id=:id AND train_key=:key AND claim_token=:token',
            [':pending'=>$sent ? 0 : 1, ':id'=>$tripId, ':key'=>$key, ':token'=>$token]);
    }
}
