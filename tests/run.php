<?php

require_once __DIR__ . '/../ticket_search.php';
require_once __DIR__ . '/../expired_trips.php';
require_once __DIR__ . '/../trip_preferences.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../trip.php';

set_error_handler(static function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function expect($actual, $expected, string $message = ''): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true)
            . '\nActual: ' . var_export($actual, true));
    }
}

function sampleTrip(): array
{
    return [
        'id' => 1, 'chat_id' => '101', 'route' => 'tehran-mashhad', 'date' => '1405-08-20',
        'return_date' => '1405-08-20', 'count' => 1, 'type' => 0, 'coupe' => 0, 'filter' => 0,
        'no_counting_notif' => 0, 'no_ticket_notif' => 0, 'bad_data_notif' => 0,
    ];
}

function tripDb(string $path = ':memory:'): SQLite3
{
    $db = new SQLite3($path);
    $db->enableExceptions(true);
    $db->busyTimeout(5000);
    $db->exec('CREATE TABLE IF NOT EXISTS user_trips (
        id INTEGER PRIMARY KEY AUTOINCREMENT, chat_id TEXT NOT NULL, route TEXT NOT NULL, date TEXT NOT NULL
    )');
    return $db;
}

function addTrip(SQLite3 $db, string $date, string $chat = '101'): int
{
    $stmt = $db->prepare('INSERT INTO user_trips (chat_id, route, date) VALUES (:chat, :route, :date)');
    $stmt->bindValue(':chat', $chat, SQLITE3_TEXT);
    $stmt->bindValue(':route', 'tehran-mashhad', SQLITE3_TEXT);
    $stmt->bindValue(':date', $date, SQLITE3_TEXT);
    $stmt->execute();
    return $db->lastInsertRowID();
}

function testNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-11-10 12:00:00', new DateTimeZone('Asia/Tehran'));
}

$tests = [];

$tests['100 matching subscriptions make one upstream request'] = static function () {
    $calls = 0;
    $batch = new TicketSearchBatch(static function ($payload) use (&$calls) {
        $calls++;
        expect($payload['count'], 1);
        return ['data' => ['status' => 'success', 'data' => ['departure' => [['id' => 'ticket-1']]]]];
    });
    for ($i = 1; $i <= 100; $i++) {
        $trip = sampleTrip();
        $trip['id'] = $i;
        $trip['chat_id'] = (string) (100 + $i);
        $trip['no_ticket_notif'] = $i % 2;
        $result = $batch->get($trip);
        expect($result['data']['data']['departure'][0]['id'], 'ticket-1');
    }
    expect($calls, 1);
};

$tests['only inventory parameters separate searches; counts and local preferences share results'] = static function () {
    $payloads = [];
    $batch = new TicketSearchBatch(static function ($payload) use (&$payloads) {
        $payloads[] = $payload;
        return ['request' => count($payloads)];
    });
    $original = sampleTrip();
    expect($batch->get($original)['request'], 1);
    $changes = ['route' => 'mashhad-tehran', 'date' => '1405-08-21',
        'type' => 1, 'coupe' => 1, 'filter' => 1];
    foreach ($changes as $field => $value) {
        $trip = $original;
        $trip[$field] = $value;
        $batch->get($trip);
        expect($batch->get($trip), ['request' => count($payloads)]);
    }
    expect(count($payloads), 6);
    $trip = $original;
    $trip['count'] = 4;
    $trip['allow_partial_capacity'] = 1;
    $trip['search_filters'] = ['companies'=>['فدک'], 'min_price'=>500000];
    $trip['wheelchair'] = 1;
    $trip['return_date'] = '1405-09-01'; // Existing API payload always sends an empty return date.
    expect($batch->get($trip)['request'], 1);
    expect($payloads[0]['count'], 1);
    expect(array_key_exists('wheelchair', $payloads[0]), false);
};

$tests['capacity is checked per subscriber and lower capacity needs explicit consent'] = static function () {
    $ticket = ['counting_en'=>2];
    $trip = sampleTrip();
    expect(ticketMatchesCapacity($ticket, $trip), true);
    $trip['count'] = 4;
    expect(ticketMatchesCapacity($ticket, $trip), false);
    $trip['allow_partial_capacity'] = 1;
    expect(ticketMatchesCapacity($ticket, $trip), true);
    expect(ticketMatchesCapacity(['counting_en'=>0], $trip), false);
    expect(ticketMatchesCapacity([], $trip), false);
    expect(ticketMatchesCapacity(['counting_en'=>'۲'], $trip), true);
};

$tests['company, train type, departure time and price filters are local and combined'] = static function () {
    $ticket = ['counting_en'=>2, 'owner_name'=>'بن ریل', 'iscompartment'=>1,
        'compartment_capacity'=>4, 'time'=>'۲۲:۳۰', 'cost'=>915050, 'cost_title'=>'۹,۱۵۰,۵۰۰'];
    $trip = sampleTrip();
    $trip['search_filters'] = ['companies'=>['بن‌ریل'], 'train_types'=>['compartment_4'],
        'departure_from'=>'18:00', 'departure_to'=>'06:00', 'min_price'=>900000, 'max_price'=>1000000];
    expect(ticketMatchesLocalFilters($ticket, $trip), true);
    foreach (['owner_name'=>'فدک', 'compartment_capacity'=>6, 'time'=>'۱۲:۰۰', 'cost'=>1000001] as $key => $value) {
        $other = $ticket;
        $other[$key] = $value;
        expect(ticketMatchesLocalFilters($other, $trip), false, $key);
    }
    $trip['search_filters'] = ['train_types'=>['seated', 'compartment_6']];
    $ticket['iscompartment'] = 0;
    expect(ticketMatchesLocalFilters($ticket, $trip), true);
};

$tests['price uses API toman values and does not confuse formatted rial amounts'] = static function () {
    $trip = sampleTrip();
    $trip['search_filters'] = ['min_price'=>3590000, 'max_price'=>3590000];
    expect(ticketMatchesLocalFilters(['cost'=>3590000, 'cost_title'=>'۳۵,۹۰۰,۰۰۰'], $trip), true);
    expect(ticketMatchesLocalFilters(['cost'=>35900000], $trip), false);
    expect(ticketMatchesLocalFilters([], $trip), false);
};

$tests['time ranges support Persian digits, boundaries, midnight and whole days'] = static function () {
    $trip = sampleTrip();
    $trip['search_filters'] = ['departure_from'=>'۰۸:۰۰', 'departure_to'=>'۱۸:۰۰'];
    expect(ticketMatchesLocalFilters(['time'=>'۰۸:۰۰'], $trip), true);
    expect(ticketMatchesLocalFilters(['time'=>'۱۸:۰۰'], $trip), false);
    expect(ticketMatchesLocalFilters(['time'=>'07:59'], $trip), false);
    $trip['search_filters'] = ['departure_from'=>'18:00', 'departure_to'=>'06:00'];
    expect(ticketMatchesLocalFilters(['time'=>'00:00'], $trip), true);
    expect(ticketMatchesLocalFilters(['time'=>'06:00'], $trip), false);
    $trip['search_filters'] = ['departure_from'=>'00:00', 'departure_to'=>'24:00'];
    expect(ticketMatchesLocalFilters(['time'=>'23:59'], $trip), true);
    expect(ticketMatchesLocalFilters(['time'=>'unknown'], $trip), false);
};

$tests['invalid filter settings are rejected rather than silently ignored'] = static function () {
    foreach ([['train_types'=>['unknown']], ['departure_from'=>'25:00', 'departure_to'=>'08:00'],
        ['min_price'=>-1], ['min_price'=>200, 'max_price'=>100], ['surprise'=>'value']] as $filters) {
        $failed = false;
        try { normalizeTripFilters($filters); } catch (InvalidArgumentException $error) { $failed = true; }
        expect($failed, true);
    }
};

$tests['upgrading an existing database preserves trips and defaults to sufficient capacity'] = static function () {
    $db = tripDb();
    $id = addTrip($db, '1405-08-20');
    ensureTripPreferencesSchema($db);
    ensureTripPreferencesSchema($db);
    $row = $db->querySingle('SELECT * FROM user_trips', true);
    expect($row['id'], $id);
    expect($row['date'], '1405-08-20');
    expect($row['allow_partial_capacity'], 0);
    expect(tripFilters($row), []);
};

$tests['the class-based storage path also preserves capacity and filter choices'] = static function () {
    $database = new Database(':memory:');
    $db = $database->initDatabase();
    $trip = sampleTrip();
    $trip['allow_partial_capacity'] = 1;
    $trip['search_filters'] = ['companies'=>['فدک'], 'max_price'=>2000000];
    $store = new Trip($db);
    $store->save('101', $trip);
    $saved = $store->getTrips('101');
    expect(count($saved), 1);
    expect($saved[0]['count'], 1);
    expect($saved[0]['allow_partial_capacity'], 1);
    expect(tripFilters($saved[0]), $trip['search_filters']);
};

$tests['failed results are shared but a later cycle retries'] = static function () {
    $calls = 0;
    $request = static function () use (&$calls) { $calls++; return null; };
    $batch = new TicketSearchBatch($request);
    expect($batch->get(sampleTrip()), null);
    expect($batch->get(sampleTrip()), null);
    expect($calls, 1);
    $next = new TicketSearchBatch($request);
    $next->get(sampleTrip());
    expect($calls, 2);
};

$tests['Jalali conversion covers Nowruz, leap days and Persian/Arabic digits'] = static function () {
    $cases = [
        '1399-01-01' => '2020-03-20', '1399-12-30' => '2021-03-20',
        '1400-01-01' => '2021-03-21', '1403-12-30' => '2025-03-20',
        '1404-01-01' => '2025-03-21', '1405-08-19' => '2026-11-10',
        '۱۴۰۵/۸/۱۹' => '2026-11-10', '١٤٠٥-٠٨-١٩' => '2026-11-10',
        "\u{200E}1405-08-19" => '2026-11-10', '2024-02-29' => '2024-02-29',
    ];
    foreach ($cases as $input => $output) {
        expect(tripDepartureDate($input)?->format('Y-m-d'), $output, $input);
    }
};

$tests['malformed dates are preserved instead of guessed'] = static function () {
    foreach (['', 'yesterday', '1400-12-30', '1405-07-31', '1405-13-01',
        '1405-00-01', '1405-01-00', '2025-02-29', '0000-01-01', '1405-01-01 junk'] as $date) {
        expect(tripDepartureDate($date), null, $date);
        expect(isTripExpired($date, testNow()), false, $date);
    }
};

$tests['expiration follows Tehran midnight and retains today'] = static function () {
    expect(isTripExpired('1405-08-18', testNow()), true);
    expect(isTripExpired('1405-08-19', testNow()), false);
    expect(isTripExpired('1405-08-20', testNow()), false);
    expect(isTripExpired('2026-11-09', new DateTimeImmutable('2026-11-09T20:29:59Z')), false);
    expect(isTripExpired('2026-11-09', new DateTimeImmutable('2026-11-09T20:30:00Z')), true);
    expect(isTripExpired('1399-12-30', new DateTimeImmutable('2021-03-21T12:00:00+03:30')), true);
    expect(isTripExpired('1400-01-01', new DateTimeImmutable('2021-03-21T12:00:00+03:30')), false);
};

$tests['cleanup removes only past trips and sends each notification once'] = static function () {
    $db = tripDb();
    $oldId = addTrip($db, '1405-08-18');
    addTrip($db, '۱۴۰۵/۸/۱۹');
    addTrip($db, '1405-08-20');
    addTrip($db, 'invalid');
    $sent = [];
    $cleanup = new ExpiredTripCleanup($db, static function ($chat, $message) use (&$sent) {
        $sent[] = [$chat, $message]; return true;
    });
    expect($cleanup->run(null, testNow()), 1);
    expect($cleanup->run(null, testNow()), 0);
    expect($db->querySingle('SELECT COUNT(*) FROM user_trips'), 3);
    expect(count($sent), 1);
    expect($sent[0][0], '101');
    expect(str_contains($sent[0][1], "شماره $oldId"), true);
    expect(str_contains($sent[0][1], '1405-08-18'), true);
    expect(str_contains($sent[0][1], 'tehran-mashhad'), true);
};

$tests['failed delivery survives a restart and is retried without restoring expired searches'] = static function () {
    $path = tempnam(sys_get_temp_dir(), 'ticket-test-');
    try {
        $db = tripDb($path);
        addTrip($db, '1405-08-18');
        $failed = new ExpiredTripCleanup($db, static fn() => false);
        expect($failed->run(null, testNow()), 1);
        expect($db->querySingle('SELECT COUNT(*) FROM user_trips'), 0);
        expect($db->querySingle('SELECT COUNT(*) FROM expired_trip_notifications WHERE sent_at IS NULL'), 1);
        unset($failed);
        $db->close();
        $db = tripDb($path);
        $count = 0;
        $retry = new ExpiredTripCleanup($db, static function () use (&$count) { $count++; return true; });
        expect($retry->run(null, testNow()), 0);
        $retry->run(null, testNow());
        expect($count, 1);
        expect($db->querySingle('SELECT COUNT(*) FROM expired_trip_notifications WHERE sent_at IS NULL'), 0);
        unset($retry);
        $db->close();
    } finally {
        unlink($path);
    }
};

$tests['per-user checks cannot delete or notify another user'] = static function () {
    $db = tripDb();
    addTrip($db, '1405-08-18', '101');
    addTrip($db, '1405-08-18', '202');
    $recipients = [];
    $cleanup = new ExpiredTripCleanup($db, static function ($chat) use (&$recipients) {
        $recipients[] = $chat; return true;
    });
    expect($cleanup->run('101', testNow()), 1);
    expect($recipients, ['101']);
    expect($db->querySingle('SELECT chat_id FROM user_trips'), '202');
    expect($cleanup->run(null, testNow()), 1);
    expect($recipients, ['101', '202']);
};

$tests['a database failure rolls back deletion and its notification together'] = static function () {
    $db = tripDb();
    addTrip($db, '1405-08-18');
    $db->exec("CREATE TRIGGER fail_delete BEFORE DELETE ON user_trips BEGIN SELECT RAISE(ABORT, 'test failure'); END");
    $calls = 0;
    $cleanup = new ExpiredTripCleanup($db, static function () use (&$calls) { $calls++; return true; });
    $failed = false;
    try { $cleanup->run(null, testNow()); } catch (Throwable $error) { $failed = true; }
    expect($failed, true);
    expect($db->querySingle('SELECT COUNT(*) FROM user_trips'), 1);
    expect($db->querySingle('SELECT COUNT(*) FROM expired_trip_notifications'), 0);
    expect($calls, 0);
};

$tests['another worker cannot resend a notification while it is being delivered'] = static function () {
    $path = tempnam(sys_get_temp_dir(), 'ticket-lease-');
    try {
        $db = tripDb($path);
        $secondDb = tripDb($path);
        addTrip($db, '1405-08-18');
        $secondCalls = 0;
        $second = new ExpiredTripCleanup($secondDb, static function () use (&$secondCalls) {
            $secondCalls++; return true;
        });
        $firstCalls = 0;
        $first = new ExpiredTripCleanup($db, static function () use (&$firstCalls, $second) {
            $firstCalls++;
            $second->run(null, testNow());
            return true;
        });
        $first->run(null, testNow());
        expect($firstCalls, 1);
        expect($secondCalls, 0);
        unset($first, $second);
        $db->close();
        $secondDb->close();
    } finally {
        unlink($path);
    }
};

$tests['a stale delivery lease is recovered after a worker crash'] = static function () {
    $db = tripDb();
    addTrip($db, '1405-08-18');
    $cleanup = new ExpiredTripCleanup($db, static fn() => false);
    $cleanup->run(null, testNow());
    $db->exec("UPDATE expired_trip_notifications SET claim_token = 'crashed-worker', claimed_at = " . (time() - 301));
    $calls = 0;
    $retry = new ExpiredTripCleanup($db, static function () use (&$calls) { $calls++; return true; });
    $retry->run(null, testNow());
    expect($calls, 1);
};

require __DIR__ . '/train_follows_test.php';
require __DIR__ . '/miniapp_test.php';

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS $name\n";
    } catch (Throwable $error) {
        $failed++;
        fwrite(STDERR, "FAIL $name: {$error->getMessage()}\n");
    }
}
echo count($tests) . " tests, $failed failures\n";
exit($failed === 0 ? 0 : 1);
