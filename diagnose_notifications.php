<?php
// Read-only CLI diagnostics. Never exposes tokens, chat IDs, passengers or message text.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
chdir(__DIR__);
define('TRAIN_TICKET_BOT_LIBRARY_ONLY',true);
try {
    require __DIR__.'/bot.php';
    $db=new SQLite3($GLOBALS['dbPath'],SQLITE3_OPEN_READONLY);
    $db->busyTimeout(5000);
    $has=static fn(string $table)=>(bool)followSql($db,'SELECT 1 FROM sqlite_master WHERE type=\'table\' AND name=:name',[':name'=>$table])->fetchArray(SQLITE3_NUM);
    $worker=$has('ticket_worker_status')?$db->querySingle('SELECT * FROM ticket_worker_status WHERE id=1',true):null;
    $summary=['server_time'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Tehran')))->format(DATE_ATOM),
        'database_exists'=>true,'worker'=>$worker ?: null,'cron'=>ticketWorkerHealth(),'approved_users'=>(int)$db->querySingle('SELECT COUNT(*) FROM users WHERE approved=1'),
        'active_trips'=>0,'enabled_approved_trips'=>0,
        'last_poll_at'=>$has('ticket_poll_status')?$db->querySingle('SELECT MAX(checked_at) FROM ticket_poll_status'):null,
        'last_telegram_acceptance_at'=>$has('telegram_ticket_delivery')?$db->querySingle('SELECT MAX(last_success_at) FROM telegram_ticket_delivery'):null,
        'delivery_errors'=>[]];
    if($has('telegram_ticket_delivery')) {
        $errors=$db->query('SELECT error_code,COUNT(*) AS affected_trains,MAX(last_attempt_at) AS last_attempt_at FROM telegram_ticket_delivery WHERE error_code<>0 GROUP BY error_code');
        while($row=$errors->fetchArray(SQLITE3_ASSOC))$summary['delivery_errors'][]=$row;
    }
    $rows=$db->query('SELECT t.*,COALESCE(u.approved,0) AS approved FROM user_trips t LEFT JOIN users u ON t.chat_id=u.chat_id');
    $args=getopt('', ['trip:']);$target=isset($args['trip'])?(int)$args['trip']:null;$detail=null;
    while ($trip=$rows->fetchArray(SQLITE3_ASSOC)) {
        if (!isTripExpired($trip['date'])) {$summary['active_trips']++;if($trip['approved'] && tripFollowMode($trip)!=='none')$summary['enabled_approved_trips']++;}
        if ($target!==null && (int)$trip['id']===$target) {
            $catalog=loadTrainCatalog($db,$trip);
            $detail=['trip_id'=>$target,'route'=>$trip['route'],'date'=>$trip['date'],'count'=>(int)$trip['count'],'approved'=>(bool)$trip['approved'],
                'expired'=>isTripExpired($trip['date']),'mode'=>tripFollowMode($trip),'partial_capacity'=>(bool)$trip['allow_partial_capacity'],'filters'=>tripFilters($trip),
                'catalog_updated_at'=>$catalog['updated_at']??null,'last_poll'=>$has('ticket_poll_status')?followSql($db,'SELECT * FROM ticket_poll_status WHERE trip_id=:id',[':id'=>$target])->fetchArray(SQLITE3_ASSOC):null,'trains'=>[]];
            foreach ($catalog['tickets']??[] as $ticket) {$detail['trains'][]=['number'=>$ticket['train_number']??null,'time'=>$ticket['time']??null,'capacity'=>ticketAvailableSeats($ticket)]+ticketNotificationEligibility($trip,$ticket,(bool)$trip['approved']);}
            $detail['delivery']=[];
            if ($has('telegram_ticket_delivery')) {
                $delivery=followSql($db,'SELECT kind,last_attempt_at,last_success_at,error_code,error,attempts FROM telegram_ticket_delivery WHERE trip_id=:id',[':id'=>$target]);
                while ($row=$delivery->fetchArray(SQLITE3_ASSOC)) {$detail['delivery'][]=$row;}
            }
        }
    }
    if($target!==null)$summary['trip']=$detail??['error'=>'trip_not_found'];
    echo json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR).PHP_EOL;
} catch(Throwable $error) {
    fwrite(STDERR,'Notification diagnostics failed ('.get_class($error).'). Verify the existing .env, database path and SQLite access.'.PHP_EOL);
    exit(1);
}
