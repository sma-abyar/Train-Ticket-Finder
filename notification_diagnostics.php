<?php

const TICKET_WORKER_HEALTH_PREFIX = "<?php http_response_code(404); exit; ?>\n";

/** A guarded checkpoint also works when cron PHP cannot load SQLite or the bot. */
function ticketWorkerCheckpoint(string $stage, ?Throwable $error=null, bool $finished=false): void
{
    $now=time();
    $GLOBALS['ticketWorkerRun']=array_replace($GLOBALS['ticketWorkerRun']??[
        'started_at'=>$now,'php_version'=>PHP_VERSION,'sapi'=>PHP_SAPI,
        'sqlite'=>extension_loaded('sqlite3'),'curl'=>extension_loaded('curl'),
    ],['stage'=>$stage,'finished_at'=>$finished?$now:null,'error'=>$error?get_class($error):null]);
    $path=__DIR__.'/ticket-worker-health.php';$temp=null;
    try {
        $temp=__DIR__.'/.worker-health-'.bin2hex(random_bytes(6)).'.php';
        $bytes=TICKET_WORKER_HEALTH_PREFIX.json_encode($GLOBALS['ticketWorkerRun'],JSON_THROW_ON_ERROR);
        if (@file_put_contents($temp,$bytes,LOCK_EX)!==false) { @chmod($temp,0644); @rename($temp,$path); }
    } catch (Throwable $ignored) { /* Diagnostics must not stop ticket delivery. */ }
    finally { if ($temp!==null && is_file($temp)) { @unlink($temp); } }
}

function ticketWorkerHealth(): ?array
{
    $path=__DIR__.'/ticket-worker-health.php';
    if (!is_file($path) || !is_readable($path)) { return null; }
    $bytes=file_get_contents($path,false,null,0,16384);
    if (!is_string($bytes) || !str_starts_with($bytes,TICKET_WORKER_HEALTH_PREFIX)) { return null; }
    $data=json_decode(substr($bytes,strlen(TICKET_WORKER_HEALTH_PREFIX)),true);
    return is_array($data)?array_intersect_key($data,array_flip(['started_at','finished_at','stage','error','php_version','sapi','sqlite','curl'])):null;
}

function ticketCronProbeResult(): ?array
{
    $path=__DIR__.'/ticket-cron-probe-result.php';
    if (!is_file($path) || !is_readable($path)) { return null; }
    $bytes=file_get_contents($path,false,null,0,16384);
    if (!is_string($bytes) || !str_starts_with($bytes,TICKET_WORKER_HEALTH_PREFIX)) { return null; }
    $data=json_decode(substr($bytes,strlen(TICKET_WORKER_HEALTH_PREFIX)),true);
    return is_array($data)?array_intersect_key($data,array_flip([
        'checked_at','php_version','sapi','php_binary','sqlite','curl','ea_php84_path',
        'ea_php84_state','ea_php84_executable','worker_file_exists','folder_writable',
    ])):null;
}

/** Caller must first verify trip ownership with signed Telegram initData. No API calls. */
function ticketNotificationDiagnostics(SQLite3 $db, array $trip, ?int $now=null): array
{
    $now=$now??time();$id=(int)$trip['id'];
    $worker=$db->querySingle('SELECT started_at,finished_at,error FROM ticket_worker_status WHERE id=1',true)?:null;
    $health=ticketWorkerHealth();
    $tripRun=followSql($db,'SELECT started_at,finished_at,error FROM ticket_trip_runs WHERE trip_id=:id',[':id'=>$id])->fetchArray(SQLITE3_ASSOC)?:null;
    $poll=followSql($db,'SELECT checked_at,inventory_ok,eligible_count,sent_count,failed_count,suppressed_count FROM ticket_poll_status WHERE trip_id=:id',[':id'=>$id])->fetchArray(SQLITE3_ASSOC)?:null;
    $approved=(bool)followSql($db,'SELECT 1 FROM users WHERE chat_id=:chat AND approved=1',[':chat'=>(string)$trip['chat_id']])->fetchArray(SQLITE3_NUM);
    $delivery=[];$rows=followSql($db,'SELECT kind,last_attempt_at,last_success_at,error_code,attempts FROM telegram_ticket_delivery WHERE trip_id=:id',[':id'=>$id]);
    while ($row=$rows->fetchArray(SQLITE3_ASSOC)) { $delivery[]=$row; }
    $issues=[];
    if (!$approved) { $issues[]='account_pending'; }
    if (isTripExpired($trip['date'])) { $issues[]='trip_expired'; }
    if (tripFollowMode($trip)==='none') { $issues[]='alerts_disabled'; }
    $latest=($health['started_at']??0)>=($worker['started_at']??0)?$health:$worker;
    if (!$latest) { $issues[]='worker_never_ran'; }
    else {
        if (!empty($latest['error'])) { $issues[]='worker_failed'; }
        if ($health && !$health['sqlite']) { $issues[]='cron_sqlite_missing'; }
        if ($health && !$health['curl']) { $issues[]='cron_curl_missing'; }
        if ($health && version_compare($health['php_version'],'8.0','<')) { $issues[]='cron_php_unsupported'; }
        if (empty($latest['finished_at'])) { $issues[]=($now-(int)$latest['started_at']>300)?'worker_stalled':'worker_running'; }
        elseif ($now-(int)$latest['finished_at']>300) { $issues[]='worker_not_recent'; }
    }
    if ($poll && !(bool)$poll['inventory_ok']) { $issues[]='inventory_failed'; }
    if ($poll && (int)$poll['failed_count']>0) { $issues[]='telegram_send_failed'; }
    if ($poll && (int)$poll['suppressed_count']>0) { $issues[]='unchanged_selected_trains'; }
    if(!empty($tripRun['error'])){$issues[]='trip_processing_failed';}
    $catalog=loadTrainCatalog($db,$trip);$trains=[];
    foreach ($catalog['tickets']??[] as $ticket) {
        $trains[]=['number'=>$ticket['train_number']??null,'time'=>$ticket['time']??null,'capacity'=>ticketAvailableSeats($ticket)]
            +ticketNotificationEligibility($trip,$ticket,$approved);
    }
    return ['report_version'=>2,'server_time'=>$now,'web_php'=>PHP_VERSION,'issues'=>$issues,'probe'=>ticketCronProbeResult(),
        'cron'=>$health,'worker'=>$worker,'trip'=>['id'=>$id,'approved'=>$approved,'mode'=>tripFollowMode($trip),
            'required_seats'=>(int)$trip['count'],'partial_capacity'=>(bool)$trip['allow_partial_capacity']],
        'trip_run'=>$tripRun,'last_poll'=>$poll,'catalog_updated_at'=>$catalog['updated_at']??null,'trains'=>$trains,'delivery'=>$delivery];
}
