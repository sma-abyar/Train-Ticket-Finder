<?php

/** Shared, bounded Telegram delivery. No message text, credentials or buttons in logs. */
final class TelegramMessageSender
{
    private const CHAT_INTERVAL = 1.05;
    private const GLOBAL_INTERVAL = 0.04;
    private SQLite3 $db;
    private $request;
    private $clock;
    private $wait;
    private $log;
    private array $blocked = [];
    private array $lastOutcome=['sent'=>false,'code'=>503,'retry_after'=>0];

    public function lastOutcome(): array { return $this->lastOutcome; }

    public function __construct(SQLite3 $db, callable $request, ?callable $clock = null, ?callable $wait = null, ?callable $log = null)
    {
        $this->db = $db;
        $this->request = $request;
        $this->clock = $clock ?? static fn()=>microtime(true);
        $this->wait = $wait ?? static fn(float $seconds)=>usleep((int)ceil($seconds*1000000));
        $this->log = $log ?? static fn(array $event)=>error_log('Telegram delivery: '.json_encode($event, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        ensureTelegramDeliveryTables($db);
    }

    public function send(array $payload, array $context = []): bool
    {
        $chat = (string)$payload['chat_id'];
        if (isset($this->blocked[$chat])) {
            $this->record($chat, $context, false, 403, 'chat_unavailable');
            return false;
        }
        for ($attempt=0; $attempt<3; $attempt++) {
            if (!$this->acquire($chat)) {
                $this->record($chat, $context, false, 429, 'retry_after_pending');
                return false;
            }
            try { $result = ($this->request)($payload); }
            catch (Throwable $error) { $result=['http'=>0,'data'=>null]; }
            $data = $result['data'] ?? null;
            $http = (int)($result['http'] ?? 0);
            if ($http >= 200 && $http < 300 && is_array($data) && ($data['ok'] ?? false) === true) {
                $this->record($chat, $context, true, 0, '');
                return true;
            }
            $code = (int)($data['error_code'] ?? $http);
            if ($code < 400) { $code = $http === 0 ? 503 : 502; }
            $description = is_string($data['description'] ?? null) ? $data['description'] : 'transport_or_invalid_response';
            if ($attempt < 2 && $code === 400 && isset($payload['parse_mode']) && stripos($description, 'parse') !== false
                && (stripos($description, 'entit') !== false || stripos($description, 'tag') !== false)) {
                unset($payload['parse_mode']); // A rejected formatted request was not delivered.
                continue;
            }
            if ($code === 429) {
                $retry = max(1, (int)($data['parameters']['retry_after'] ?? 1));
                $this->cooldown($chat, $retry);
                if ($retry <= 2 && $attempt < 2) { continue; }
            }
            if ($code === 403) { $this->blocked[$chat] = true; }
            $this->record($chat, $context, false, $code, $description, (int)($result['errno'] ?? 0),$code===429?$retry:0);
            return false;
        }
        $this->record($chat, $context, false, 429, 'retry_limit');
        return false;
    }

    /** Reserve only a ready slot. Waiting on one chat never reserves the global future. */
    private function acquire(string $chat): bool
    {
        for ($attempt=0; $attempt<5; $attempt++) {
            if (!$this->db->exec('BEGIN IMMEDIATE')) { throw new RuntimeException($this->db->lastErrorMsg()); }
            try {
                $now = ($this->clock)();
                $row = followSql($this->db, 'SELECT MAX(not_before) AS next_at FROM telegram_send_limits WHERE bucket IN (:chat,\'global\')', [':chat'=>'chat:'.$chat])->fetchArray(SQLITE3_ASSOC);
                $delay = max(0, (float)($row['next_at'] ?? 0)-$now);
                if ($delay < 0.001) {
                    foreach (['chat:'.$chat=>self::CHAT_INTERVAL, 'global'=>self::GLOBAL_INTERVAL] as $bucket=>$interval) {
                        followSql($this->db, 'INSERT INTO telegram_send_limits VALUES (:bucket,:next) ON CONFLICT(bucket) DO UPDATE SET not_before=excluded.not_before', [':bucket'=>$bucket, ':next'=>(string)($now+$interval)]);
                    }
                    $this->db->exec('COMMIT');
                    return true;
                }
                $this->db->exec('COMMIT');
                if ($delay > 2.05) { return false; } // Long flood waits retry on the next cron cycle.
                ($this->wait)($delay+0.001);
            } catch (Throwable $error) { $this->db->exec('ROLLBACK'); throw $error; }
        }
        return false;
    }

    private function cooldown(string $chat, int $seconds): void
    {
        foreach(['chat:'.$chat,'global'] as $bucket){
            followSql($this->db, 'INSERT INTO telegram_send_limits VALUES (:bucket,:next) ON CONFLICT(bucket) DO UPDATE SET not_before=MAX(not_before,excluded.not_before)', [':bucket'=>$bucket, ':next'=>(string)(($this->clock)()+$seconds)]);
        }
    }

    private function record(string $chat, array $context, bool $sent, int $code, string $description, int $errno = 0,int $retryAfter=0): void
    {
        if(!$sent && $code===429 && $retryAfter===0){
            $limit=followSql($this->db,'SELECT MAX(not_before) AS next_at FROM telegram_send_limits WHERE bucket IN (:chat,\'global\')',[':chat'=>'chat:'.$chat])->fetchArray(SQLITE3_ASSOC);
            $retryAfter=max(1,(int)ceil((float)($limit['next_at']??0)-($this->clock)()));
        }
        $this->lastOutcome=['sent'=>$sent,'code'=>$sent?0:$code,'retry_after'=>$retryAfter];
        // Store a bounded reason; never curl_error(), request URLs, initData or text.
        $description = substr(preg_replace('~https?://\S+|\b\d{5,}:[A-Za-z0-9_-]+~u', '[redacted]', $description) ?? 'invalid_error', 0, 350);
        $event = ['trip_id'=>(int)($context['trip_id'] ?? 0), 'kind'=>(string)($context['kind'] ?? 'message'),
            'code'=>$code, 'reason'=>$description, 'curl_errno'=>$errno];
        if (!$sent && $description !== 'retry_after_pending') { ($this->log)($event); }
        if (!isset($context['trip_id'])) { return; }
        $key = (string)($context['train_key'] ?? '');
        followSql($this->db, 'INSERT INTO telegram_ticket_delivery (trip_id,train_key,kind,chat_id,last_attempt_at,last_success_at,error_code,error,attempts)
            VALUES (:id,:key,:kind,:chat,:now,:success,:code,:error,1)
            ON CONFLICT(trip_id,train_key,kind) DO UPDATE SET last_attempt_at=excluded.last_attempt_at,
            last_success_at=COALESCE(excluded.last_success_at,last_success_at),error_code=excluded.error_code,error=excluded.error,attempts=attempts+1', [
            ':id'=>$event['trip_id'], ':key'=>$key, ':kind'=>$event['kind'], ':chat'=>$chat,
            ':now'=>(int)($this->clock)(), ':success'=>$sent ? (int)($this->clock)() : null, ':code'=>$code, ':error'=>$description,
        ]);
    }
}

function ensureTelegramDeliveryTables(SQLite3 $db): void
{
    foreach ([
        'CREATE TABLE IF NOT EXISTS telegram_send_limits (bucket TEXT PRIMARY KEY,not_before REAL NOT NULL)',
        'CREATE TABLE IF NOT EXISTS telegram_ticket_delivery (trip_id INTEGER NOT NULL,train_key TEXT NOT NULL,kind TEXT NOT NULL,chat_id TEXT NOT NULL,last_attempt_at INTEGER NOT NULL,last_success_at INTEGER,error_code INTEGER NOT NULL,error TEXT NOT NULL,attempts INTEGER NOT NULL,PRIMARY KEY(trip_id,train_key,kind))',
        'CREATE TABLE IF NOT EXISTS ticket_poll_status (trip_id INTEGER PRIMARY KEY,checked_at INTEGER NOT NULL,inventory_ok INTEGER NOT NULL,eligible_count INTEGER NOT NULL,sent_count INTEGER NOT NULL,failed_count INTEGER NOT NULL,suppressed_count INTEGER NOT NULL)',
        'CREATE TABLE IF NOT EXISTS ticket_worker_status (id INTEGER PRIMARY KEY CHECK(id=1),started_at INTEGER,finished_at INTEGER,error TEXT)',
        'CREATE TABLE IF NOT EXISTS ticket_trip_runs (trip_id INTEGER PRIMARY KEY,started_at INTEGER NOT NULL,finished_at INTEGER,error TEXT,claim_token TEXT,claimed_at INTEGER)',
        'CREATE TABLE IF NOT EXISTS ticket_reservation_callbacks (token TEXT PRIMARY KEY,chat_id TEXT NOT NULL,list_id INTEGER NOT NULL,ticket_id TEXT NOT NULL,coupe INTEGER NOT NULL,expires_at INTEGER NOT NULL)',
        'CREATE TRIGGER IF NOT EXISTS delete_ticket_delivery AFTER DELETE ON user_trips BEGIN DELETE FROM telegram_ticket_delivery WHERE trip_id=OLD.id; DELETE FROM ticket_poll_status WHERE trip_id=OLD.id; END',
        'CREATE TRIGGER IF NOT EXISTS delete_ticket_trip_runs AFTER DELETE ON user_trips BEGIN DELETE FROM ticket_trip_runs WHERE trip_id=OLD.id; END',
    ] as $sql) { if (!$db->exec($sql)) { throw new RuntimeException($db->lastErrorMsg()); } }
    $columns=[];$rows=$db->query('PRAGMA table_info(ticket_trip_runs)');while($row=$rows->fetchArray(SQLITE3_ASSOC)){$columns[]=$row['name'];}$rows->finalize();
    if(array_diff(['claim_token','claimed_at'],$columns)){
        if(!$db->exec('BEGIN IMMEDIATE')){throw new RuntimeException($db->lastErrorMsg());}
        try{
            $columns=[];$rows=$db->query('PRAGMA table_info(ticket_trip_runs)');while($row=$rows->fetchArray(SQLITE3_ASSOC)){$columns[]=$row['name'];}$rows->finalize();
            foreach(['claim_token'=>'TEXT','claimed_at'=>'INTEGER']as$name=>$type){if(!in_array($name,$columns,true)&&!$db->exec("ALTER TABLE ticket_trip_runs ADD COLUMN $name $type")){throw new RuntimeException($db->lastErrorMsg());}}
            if(!$db->exec('COMMIT')){throw new RuntimeException($db->lastErrorMsg());}
        }catch(Throwable $error){$db->exec('ROLLBACK');throw $error;}
    }
}

function claimTicketTripRun(SQLite3 $db,int $tripId,?int $now=null): ?string
{
    $now=$now??time();$token=bin2hex(random_bytes(16));
    followSql($db,'INSERT INTO ticket_trip_runs(trip_id,started_at,claim_token,claimed_at)VALUES(:id,:now,:token,:now)
        ON CONFLICT(trip_id) DO UPDATE SET started_at=excluded.started_at,finished_at=NULL,error=NULL,claim_token=excluded.claim_token,claimed_at=excluded.claimed_at
        WHERE ticket_trip_runs.claimed_at IS NULL OR ticket_trip_runs.claimed_at<:expired',[
        ':id'=>$tripId,':now'=>$now,':token'=>$token,':expired'=>$now-300]);
    return $db->changes()===1?$token:null;
}

function completeTicketTripRun(SQLite3 $db,int $tripId,string $token,?string $error=null): void
{
    followSql($db,'UPDATE ticket_trip_runs SET finished_at=:now,error=:error,claim_token=NULL,claimed_at=NULL WHERE trip_id=:id AND claim_token=:token',[
        ':now'=>time(),':error'=>$error,':id'=>$tripId,':token'=>$token]);
}

function ticketReservationCallback(SQLite3 $db, string $chat, int $list, string $ticket, int $coupe): string
{
    $legacy = "reserve_list_{$list}_{$ticket}_{$coupe}";
    if (strlen($legacy) <= 64 && strpos($ticket, '_') === false) { return $legacy; }
    $token = substr(hash('sha256', json_encode([$chat,$list,$ticket,$coupe], JSON_THROW_ON_ERROR)),0,32);
    followSql($db, 'INSERT INTO ticket_reservation_callbacks VALUES (:token,:chat,:list,:ticket,:coupe,:expires)
        ON CONFLICT(token) DO UPDATE SET expires_at=excluded.expires_at WHERE expires_at<:refresh', [
        ':token'=>$token, ':chat'=>$chat, ':list'=>$list, ':ticket'=>$ticket, ':coupe'=>$coupe, ':expires'=>time()+86400, ':refresh'=>time()+82800,
    ]);
    return 'reserve_token_'.$token;
}

function resolveTicketReservation(SQLite3 $db, string $chat, string $callback): ?array
{
    if (!preg_match('/^reserve_token_([a-f0-9]{32})$/D', $callback, $m)) { return null; }
    $row = followSql($db, 'SELECT list_id,ticket_id,coupe FROM ticket_reservation_callbacks WHERE token=:token AND chat_id=:chat AND expires_at>=:now', [':token'=>$m[1], ':chat'=>$chat, ':now'=>time()])->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

function ticketNotificationEligibility(array $trip, array $ticket, bool $approved): array
{
    if (!$approved) { $reason='approval';$label='پایش پس از تأیید حساب توسط مدیر آغاز می‌شود.'; }
    elseif (!ticketIsFollowed($ticket,$trip)) { $reason='not_followed';$label='در تنظیمات ذخیره‌شده، هشدار این قطار خاموش است.'; }
    elseif (ticketAvailableSeats($ticket)===0) { $reason='sold_out';$label='فعلاً ناموجود است؛ اگر شرایط سفر را پیدا کند خبر می‌دهیم.'; }
    elseif (!ticketMatchesCapacity($ticket,$trip)) { $reason='capacity';$label='ظرفیت کمتر از '.(int)$trip['count'].' نفر است؛ گزینهٔ ظرفیت کمتر برای این سفر خاموش است.'; }
    elseif (!ticketMatchesLocalFilters($ticket,$trip)) { $reason='filters';$label='با فیلتر ساعت، قیمت، شرکت یا نوع قطار این سفر مطابق نیست.'; }
    else { $reason='eligible';$label='مطابق تنظیمات ذخیره‌شدهٔ سفر است؛ ارسال را بررسی خودکار انجام می‌دهد.'; }
    return ['eligible'=>$reason==='eligible','reason'=>$reason,'label'=>$label];
}
