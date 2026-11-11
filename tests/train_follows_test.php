<?php

require_once __DIR__ . '/../train_follows.php';
require_once __DIR__ . '/../telegram_webapp_auth.php';

function followTicket(array $changes = []): array
{
    return array_replace([
        'id'=>'inventory-1', 'train_number_en'=>188, 'train_number'=>'۱۸۸', 'time'=>'۱۱:۴۰',
        'owner'=>25, 'owner_name'=>'فدک', 'wagon_type'=>503, 'wagon_name'=>'فدک اکونومی',
        'source'=>'raja', 'counting_en'=>0, 'cost'=>3590000, 'iscompartment'=>1, 'compartment_capacity'=>4,
    ], $changes);
}

function followFixture(array $changes = []): array
{
    $db = (new Database(':memory:'))->initDatabase();
    $data = array_replace(sampleTrip(), [
        'date'=>(new DateTimeImmutable('tomorrow', new DateTimeZone('Asia/Tehran')))->format('Y-m-d'),
    ], $changes);
    (new Trip($db))->save('101', $data);
    $trip = $db->querySingle('SELECT * FROM user_trips', true);
    storeTrainCatalog($db, TicketSearchBatch::payload($trip), [followTicket()]);
    return [$db, $trip];
}

function assertFollowError(callable $action, int $code): void
{
    $caught = null;
    try { $action(); } catch (Throwable $error) { $caught = $error->getCode(); }
    expect($caught, $code);
}

function signedMiniAppData(array $fields, string $token = 'TEST'): string
{
    ksort($fields, SORT_STRING);
    $check = [];
    foreach ($fields as $key => $value) { $check[] = "$key=$value"; }
    $fields['hash'] = hash_hmac('sha256', implode("\n", $check), hash_hmac('sha256', $token, 'WebAppData', true));
    return http_build_query($fields);
}

$tests['train identity survives inventory IDs, price and capacity changes but distinguishes services'] = static function () {
    $ticket = followTicket();
    $key = trainFollowKey($ticket);
    expect(trainFollowKey(followTicket(['id'=>'new-id', 'cost'=>1000000, 'counting_en'=>8])), $key);
    expect(trainFollowKey(followTicket(['train_number_en'=>'188', 'time'=>'11:40'])), $key);
    foreach (['time'=>'12:40', 'owner'=>26, 'wagon_type'=>504, 'train_number_en'=>189] as $field=>$value) {
        expect(trainFollowKey(followTicket([$field=>$value])) === $key, false, $field);
    }
};

$tests['the catalog includes sold-out services and remembers temporarily missing trains'] = static function () {
    [$db, $trip] = followFixture();
    $options = trainFollowOptions($trip, loadTrainCatalog($db, $trip));
    expect(count($options), 1);
    expect($options[0]['capacity'], 0);
    $trip = saveTrainFollows($db, (int) $trip['id'], '101', 'selected', [$options[0]['key']], 0);
    storeTrainCatalog($db, TicketSearchBatch::payload($trip), []);
    $options = trainFollowOptions($trip, loadTrainCatalog($db, $trip));
    expect(count($options), 1);
    expect($options[0]['missing'], true);
    expect(ticketIsFollowed(followTicket(['id'=>'replacement']), $trip), true);
    expect(ticketIsFollowed(followTicket(['train_number_en'=>999]), $trip), false);
};

$tests['catalogs are shared across passenger counts and local filters but not dates or quotas'] = static function () {
    [$db, $trip] = followFixture();
    $other = $trip;
    $other['count'] = 12;
    $other['search_filters'] = ['companies'=>['رجا']];
    expect(loadTrainCatalog($db, $other)['tickets'], loadTrainCatalog($db, $trip)['tickets']);
    $other['type'] = 2;
    expect(loadTrainCatalog($db, $other), null);
    $other = $trip;
    $other['date'] = '2099-01-01';
    expect(loadTrainCatalog($db, $other), null);
};

$tests['malformed successes are distinguished from a valid empty train list'] = static function () {
    expect(trainSearchTickets(['data'=>['status'=>'success','data'=>['departure'=>[]]]]), []);
    expect(trainSearchTickets(['data'=>['status'=>'success']]), null);
    expect(trainSearchTickets(['data'=>['status'=>'success','data'=>['departure'=>['broken']]]]), null);
    expect(trainSearchTickets(null), null);
};

$tests['empty selection disables alerts and never silently enables all trains'] = static function () {
    [$db, $trip] = followFixture();
    $saved = saveTrainFollows($db, (int) $trip['id'], '101', 'selected', [], 0);
    expect(tripFollowMode($saved), 'none');
    expect(ticketIsFollowed(followTicket(), $saved), false);
    expect(tripFollowMode([]), 'all'); // Upgrade preserves old subscriptions.
};

$tests['muting one train keeps every other and future train enabled and supports restoring it'] = static function () {
    [$db, $trip] = followFixture();
    $id = (int) $trip['id'];
    $key = trainFollowKey(followTicket());
    $muted = setTrainMessagePreference($db, $id, '101', substr($key,0,24), false);
    expect(tripFollowMode($muted), 'except');
    expect(ticketIsFollowed(followTicket(), $muted), false);
    expect(ticketIsFollowed(followTicket(['train_number_en'=>999]), $muted), true);
    expect(tripFollowRevisionCurrent($db, $trip), false);
    $again = setTrainMessagePreference($db, $id, '101', substr($key,0,24), false);
    expect(count(tripExcludedTrains($again)), 1);
    $restored = setTrainMessagePreference($db, $id, '101', substr($key,0,24), true);
    expect(tripFollowMode($restored), 'all');
    expect(ticketIsFollowed(followTicket(), $restored), true);
    expect(tripExcludedTrains($restored), []);
};

$tests['mute and restore respect a selected-only list instead of enabling unrelated trains'] = static function () {
    [$db, $trip] = followFixture();
    $one = followTicket();
    $two = followTicket(['train_number_en'=>189]);
    storeTrainCatalog($db, TicketSearchBatch::payload($trip), [$one,$two]);
    $id = (int) $trip['id'];
    saveTrainFollows($db,$id,'101','selected',[trainFollowKey($one),trainFollowKey($two)],0);
    $saved = setTrainMessagePreference($db,$id,'101',substr(trainFollowKey($one),0,24),false);
    expect(tripFollowMode($saved),'selected');
    expect(ticketIsFollowed($one,$saved),false);
    expect(ticketIsFollowed($two,$saved),true);
    $saved = setTrainMessagePreference($db,$id,'101',substr(trainFollowKey($two),0,24),false);
    expect(tripFollowMode($saved),'none');
    $saved = setTrainMessagePreference($db,$id,'101',substr(trainFollowKey($one),0,24),true);
    expect(ticketIsFollowed($one,$saved),true);
    expect(ticketIsFollowed($two,$saved),false);
    assertFollowError(static fn()=>setTrainMessagePreference($db,$id,'202',substr(trainFollowKey($one),0,24),false),404);
};

$tests['except mode persists validated exclusions without freezing the currently known train list'] = static function () {
    [$db, $trip] = followFixture();
    $key = trainFollowKey(followTicket());
    $saved = saveTrainFollows($db,(int)$trip['id'],'101','except',[],0,[$key]);
    expect(array_column(tripExcludedTrains($saved),'key'),[$key]);
    expect(ticketIsFollowed(followTicket(['train_number_en'=>999]),$saved),true);
    assertFollowError(static fn()=>saveTrainFollows($db,(int)$trip['id'],'101','except',[],1,[str_repeat('f',64)]),400);
    $all = saveTrainFollows($db,(int)$trip['id'],'101','except',[],1,[]);
    expect(tripFollowMode($all),'all');
};

$tests['follow edits validate ownership, known train keys and concurrent revisions'] = static function () {
    [$db, $trip] = followFixture();
    $id = (int) $trip['id'];
    $key = trainFollowKey(followTicket());
    assertFollowError(static fn() => saveTrainFollows($db, $id, '202', 'selected', [$key], 0), 404);
    assertFollowError(static fn() => saveTrainFollows($db, $id, '101', 'selected', [str_repeat('a',64)], 0), 400);
    $saved = saveTrainFollows($db, $id, '101', 'selected', [$key], 0);
    expect($saved['follow_revision'], 1);
    assertFollowError(static fn() => saveTrainFollows($db, $id, '101', 'all', [], 0), 409);
    expect($db->querySingle('SELECT follow_mode FROM user_trips'), 'selected');
};

$tests['a train selected before a catalog refresh can still be saved after it disappears'] = static function () {
    [$db, $trip] = followFixture();
    $key = trainFollowKey(followTicket());
    storeTrainCatalog($db, TicketSearchBatch::payload($trip), []);
    $saved = saveTrainFollows($db, (int) $trip['id'], '101', 'selected', [$key], 0);
    expect(array_column(tripFollowedTrains($saved), 'key'), [$key]);
};

$tests['selected trains alert on availability transitions and not on steady polls'] = static function () {
    [$db, $trip] = followFixture();
    $key = trainFollowKey(followTicket());
    $trip = saveTrainFollows($db, (int) $trip['id'], '101', 'selected', [$key], 0);
    $alerts = new TrainFollowAlerts($db);
    $alerts->observe($trip, [followTicket()]);
    expect($alerts->claim($trip, $key), null);
    $available = followTicket(['counting_en'=>2]);
    $alerts->observe($trip, [$available]);
    $token = $alerts->claim($trip, $key);
    expect(is_string($token), true);
    expect($alerts->claim($trip, $key), null); // Concurrent delivery is already claimed.
    $alerts->complete((int) $trip['id'], $key, $token, true);
    $alerts->observe($trip, [followTicket(['counting_en'=>5, 'id'=>'new-inventory'])]);
    expect($alerts->claim($trip, $key), null);
    $alerts->observe($trip, []); // Full successful list no longer contains this service.
    $alerts->observe($trip, [$available]);
    expect(is_string($alerts->claim($trip, $key)), true);
};

$tests['partial availability and sufficient capacity are separate useful alert events'] = static function () {
    [$db, $trip] = followFixture(['count'=>4, 'allow_partial_capacity'=>1]);
    $key = trainFollowKey(followTicket());
    $trip = saveTrainFollows($db, (int) $trip['id'], '101', 'selected', [$key], 0);
    $alerts = new TrainFollowAlerts($db);
    foreach ([1, 2, 4, 5, 2, 4] as $index=>$count) {
        $alerts->observe($trip, [followTicket(['counting_en'=>$count])]);
        $token = $alerts->claim($trip, $key);
        expect(is_string($token), in_array($index, [0,2,5], true), 'capacity ' . $count);
        if ($token !== null) $alerts->complete((int) $trip['id'], $key, $token, true);
    }
};

$tests['capacity and local filters still gate followed-service notifications'] = static function () {
    [$db, $trip] = followFixture(['count'=>4, 'search_filters'=>['max_price'=>4000000]]);
    $key = trainFollowKey(followTicket());
    $trip = saveTrainFollows($db, (int) $trip['id'], '101', 'selected', [$key], 0);
    $alerts = new TrainFollowAlerts($db);
    $alerts->observe($trip, [followTicket(['counting_en'=>3])]);
    expect($alerts->claim($trip, $key), null);
    $alerts->observe($trip, [followTicket(['counting_en'=>4, 'cost'=>5000000])]);
    expect($alerts->claim($trip, $key), null);
    $alerts->observe($trip, [followTicket(['counting_en'=>4])]);
    expect(is_string($alerts->claim($trip, $key)), true);
};

$tests['failed followed-train notifications retry and abandoned claims recover'] = static function () {
    [$db, $trip] = followFixture();
    $key = trainFollowKey(followTicket());
    $trip = saveTrainFollows($db, (int) $trip['id'], '101', 'selected', [$key], 0);
    $alerts = new TrainFollowAlerts($db);
    $available = followTicket(['counting_en'=>3]);
    $alerts->observe($trip, [$available]);
    $token = $alerts->claim($trip, $key);
    $alerts->complete((int) $trip['id'], $key, $token, false);
    $alerts->observe($trip, [$available]);
    expect(is_string($alerts->claim($trip, $key)), true);
    $db->exec('UPDATE train_follow_delivery SET claimed_at=' . (time()-301));
    expect(is_string($alerts->claim($trip, $key)), true);
};

$tests['unchanged follow choices preserve delivery history and stale workers cannot notify after edits'] = static function () {
    [$db, $trip] = followFixture();
    $key = trainFollowKey(followTicket());
    $trip = saveTrainFollows($db, (int) $trip['id'], '101', 'selected', [$key], 0);
    $alerts = new TrainFollowAlerts($db);
    $available = followTicket(['counting_en'=>3]);
    $alerts->observe($trip, [$available]);
    $token = $alerts->claim($trip, $key);
    $alerts->complete((int) $trip['id'], $key, $token, true);
    $updated = saveTrainFollows($db, (int) $trip['id'], '101', 'selected', [$key], 1);
    $alerts->observe($updated, [$available]);
    expect($alerts->claim($updated, $key), null);
    saveTrainFollows($db, (int) $trip['id'], '101', 'none', [], 2);
    $alerts->observe($trip, [$available]);
    expect($alerts->claim($trip, $key), null);
    expect($db->querySingle('SELECT COUNT(*) FROM train_follow_delivery'), 0);
};

$tests['trip deletion also removes its followed-alert delivery records'] = static function () {
    [$db, $trip] = followFixture();
    $key = trainFollowKey(followTicket());
    $trip = saveTrainFollows($db, (int) $trip['id'], '101', 'selected', [$key], 0);
    (new TrainFollowAlerts($db))->observe($trip, [followTicket(['counting_en'=>2])]);
    $db->exec('DELETE FROM user_trips');
    expect($db->querySingle('SELECT COUNT(*) FROM train_follow_delivery'), 0);
};

$tests['all and except modes notify only on opening or sufficient-capacity transitions']=static function(){
    foreach(['all','except']as$mode){
        [$db,$trip]=followFixture(['count'=>4,'allow_partial_capacity'=>1]);
        $a=followTicket(['counting_en'=>4]);$b=followTicket(['train_number_en'=>189,'time'=>'15:00','counting_en'=>5]);
        storeTrainCatalog($db,TicketSearchBatch::payload($trip),[$a,$b]);$key=trainFollowKey($a);
        if($mode==='except'){$trip=saveTrainFollows($db,(int)$trip['id'],'101','except',[],0,[trainFollowKey($b)]);}
        $alerts=new TrainFollowAlerts($db);$alerts->observe($trip,[$a,$b]);$claim=$alerts->claim($trip,$key);expect(is_string($claim),true);
        $alerts->complete((int)$trip['id'],$key,$claim,true);
        $writes=(int)$db->querySingle('SELECT total_changes()');
        $alerts->observe($trip,[array_replace($a,['counting_en'=>6,'cost'=>3600000]),$b]);
        expect((int)$db->querySingle('SELECT total_changes()'),$writes,'A steady inventory poll should not rewrite delivery state');
        expect($alerts->claim($trip,$key),null);
        $alerts->observe($trip,[array_replace($a,['counting_en'=>2]),$b]);expect($alerts->claim($trip,$key),null);
        $alerts->observe($trip,[$a,$b]);$claim=$alerts->claim($trip,$key);expect(is_string($claim),true);
        $alerts->complete((int)$trip['id'],$key,$claim,true);
        $alerts->observe($trip,[]);$alerts->observe($trip,[$a]);expect(is_string($alerts->claim($trip,$key)),true,'A real reopening still deserves a new alert');
    }
};

$tests['mute and mode edits do not re-arm other unchanged enabled trains']=static function(){
    [$db,$trip]=followFixture();$a=followTicket(['counting_en'=>4]);$b=followTicket(['train_number_en'=>189,'time'=>'15:00','counting_en'=>4]);
    storeTrainCatalog($db,TicketSearchBatch::payload($trip),[$a,$b]);$ka=trainFollowKey($a);$kb=trainFollowKey($b);$alerts=new TrainFollowAlerts($db);
    $alerts->observe($trip,[$a,$b]);foreach([$ka,$kb]as$key){$claim=$alerts->claim($trip,$key);$alerts->complete((int)$trip['id'],$key,$claim,true);}
    $trip=setTrainMessagePreference($db,(int)$trip['id'],'101',substr($ka,0,24),false);
    $alerts->observe($trip,[$a,$b]);expect($alerts->claim($trip,$kb),null);
    $trip=setTrainMessagePreference($db,(int)$trip['id'],'101',substr($ka,0,24),true);
    $alerts->observe($trip,[$a,$b]);expect(is_string($alerts->claim($trip,$ka)),true);expect($alerts->claim($trip,$kb),null);
    $trip=saveTrainFollows($db,(int)$trip['id'],'101','selected',[$kb],(int)$trip['follow_revision']);
    $alerts->observe($trip,[$a,$b]);expect($alerts->claim($trip,$kb),null);
};

$tests['independent train switches merge changes and idempotent retries preserve revision'] = static function () {
    [$db,$trip]=followFixture();
    $a=followTicket();$b=followTicket(['id'=>'second','train_number_en'=>189,'train_number'=>'۱۸۹','time'=>'۱۵:۲۰']);
    storeTrainCatalog($db,TicketSearchBatch::payload($trip),[$a,$b]);
    $ka=trainFollowKey($a);$kb=trainFollowKey($b);
    $first=setTrainMessagePreference($db,(int)$trip['id'],'101',substr($ka,0,24),false);
    $second=setTrainMessagePreference($db,(int)$trip['id'],'101',substr($kb,0,24),false);
    expect(count(tripExcludedTrains($second)),2,'A second switch must preserve the first switch');
    $retry=setTrainMessagePreference($db,(int)$trip['id'],'101',substr($kb,0,24),false);
    expect($retry['follow_revision'],$second['follow_revision'],'Retrying the same target state must be a no-op');
    assertFollowError(static fn()=>setTrainMessagePreference($db,(int)$trip['id'],'202',substr($ka,0,24),true),404);
    $paused=saveTrainFollows($db,(int)$trip['id'],'101','none',[$ka],(int)$retry['follow_revision']);
    $enabled=setTrainMessagePreference($db,(int)$trip['id'],'101',substr($kb,0,24),true);
    expect($enabled['follow_mode'],'selected');
    expect(array_column(tripFollowedTrains($enabled),'key'),[$kb],'Turning on one service must not resume old paused choices');
};

$tests['Telegram mini app authentication checks signature, user, timestamp and duplicate fields'] = static function () {
    $now = 1700000000;
    $fields = ['auth_date'=>(string) $now, 'query_id'=>'query', 'user'=>json_encode(['id'=>1234567890123,'first_name'=>'Test']), 'signature'=>'extra-telegram-field'];
    $valid = signedMiniAppData($fields);
    expect(telegramWebAppUser($valid, 'TEST', $now), '1234567890123');
    assertFollowError(static fn() => telegramWebAppUser($valid, 'WRONG', $now), 401);
    assertFollowError(static fn() => telegramWebAppUser($valid . '&user=another', 'TEST', $now), 401);
    assertFollowError(static fn() => telegramWebAppUser($valid, 'TEST', $now+3601), 401);
    assertFollowError(static fn() => telegramWebAppUser($valid, 'TEST', $now-31), 401);
    assertFollowError(static fn() => telegramWebAppUser('', 'TEST', $now), 401);
};
