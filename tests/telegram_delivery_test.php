<?php
require_once __DIR__.'/../telegram_delivery.php';

function deliveryFixture(callable $request): array
{
    [$db,$trip]=followFixture();
    $clock=(object)['now'=>1000.0,'waits'=>[],'logs'=>[]];
    $sender=new TelegramMessageSender($db,$request,static fn()=>$clock->now,
        static function($seconds)use($clock){$clock->waits[]=$seconds;$clock->now+=$seconds;},
        static function($event)use($clock){$clock->logs[]=$event;});
    return [$sender,$db,$trip,$clock];
}

$tests['Telegram sender spaces one chat and the global rate without long blocking'] = static function(){
    $requests=[];
    [$sender,$db,$trip,$clock]=deliveryFixture(static function($payload)use(&$requests){$requests[]=$payload;return ['http'=>200,'data'=>['ok'=>true]];});
    expect($sender->send(['chat_id'=>'101','text'=>'one']),true);
    expect($sender->send(['chat_id'=>'101','text'=>'two']),true);
    expect($clock->now>=1001.05,true);
    expect($sender->send(['chat_id'=>'202','text'=>'three']),true);
    expect($clock->now<1001.2,true,'A second chat should not inherit the first chat cooldown');
    expect(count($requests),3);
};

$tests['Telegram 429 cooldown is durable, bounded and retries without false success'] = static function(){
    $calls=0;
    [$sender,$db,$trip,$clock]=deliveryFixture(static function()use(&$calls){$calls++;return ['http'=>429,'data'=>['ok'=>false,'error_code'=>429,'description'=>'Too Many Requests','parameters'=>['retry_after'=>30]]];});
    $context=['trip_id'=>(int)$trip['id'],'train_key'=>'train','kind'=>'ticket'];
    expect($sender->send(['chat_id'=>'101','text'=>'not logged'],$context),false);
    expect($sender->send(['chat_id'=>'101','text'=>'not logged'],$context),false);
    expect($calls,1);expect($clock->waits,[]);
    expect((int)$db->querySingle('SELECT error_code FROM telegram_ticket_delivery'),429);
    expect($db->querySingle('SELECT last_success_at FROM telegram_ticket_delivery'),null);
    expect(strpos(json_encode($clock->logs),'not logged'),false);
    $clock->now+=31;
    $accepted=new TelegramMessageSender($db,static fn()=>['http'=>200,'data'=>['ok'=>true]],static fn()=>$clock->now,static fn($seconds)=>null,static fn($event)=>null);
    expect($accepted->send(['chat_id'=>'101','text'=>'retry'],$context),true);
    expect((int)$db->querySingle('SELECT error_code FROM telegram_ticket_delivery'),0);
    expect((int)$db->querySingle('SELECT last_success_at FROM telegram_ticket_delivery'),1031);
};

$tests['Telegram short flood wait and rejected formatting retry safely'] = static function(){
    $calls=[];
    [$sender,$db,$trip,$clock]=deliveryFixture(static function($payload)use(&$calls){$calls[]=$payload;
        if(count($calls)===1)return ['http'=>429,'data'=>['error_code'=>429,'parameters'=>['retry_after'=>1]]];
        if(count($calls)===2)return ['http'=>400,'data'=>['error_code'=>400,'description'=>"Bad Request: can't parse entities"]];
        return ['http'=>200,'data'=>['ok'=>true]];
    });
    expect($sender->send(['chat_id'=>'101','text'=>'name_with_[symbols','parse_mode'=>'Markdown']),true);
    expect(count($calls),3);expect(isset($calls[2]['parse_mode']),false);expect($clock->now<1003,true);
};

$tests['Telegram failures do not masquerade as acceptance and blocked chats do not stall others'] = static function(){
    $calls=0;
    [$sender,$db,$trip,$clock]=deliveryFixture(static function($payload)use(&$calls){$calls++;return $payload['chat_id']==='101'
        ? ['http'=>403,'data'=>['error_code'=>403,'description'=>'Forbidden: bot was blocked']]
        : ['http'=>200,'data'=>['ok'=>true]];});
    expect($sender->send(['chat_id'=>'101','text'=>'a']),false);expect($sender->send(['chat_id'=>'101','text'=>'b']),false);
    expect($sender->send(['chat_id'=>'202','text'=>'c']),true);expect($calls,2);
    [$broken,$brokenDb,$brokenTrip]=deliveryFixture(static fn()=>['http'=>0,'errno'=>28,'data'=>null]);
    expect($broken->send(['chat_id'=>'101','text'=>'timeout'],['trip_id'=>(int)$brokenTrip['id'],'kind'=>'ticket']),false);
    expect((int)$brokenDb->querySingle('SELECT error_code FROM telegram_ticket_delivery'),503);
    [$invalid]=deliveryFixture(static fn()=>['http'=>500,'data'=>['ok'=>true]]);
    expect($invalid->send(['chat_id'=>'101','text'=>'invalid']),false);
};

$tests['Telegram flood cooldown is shared across recipients and sender processes']=static function(){
    $calls=0;
    [$sender,$db,$trip,$clock]=deliveryFixture(static function()use(&$calls){$calls++;return ['http'=>429,'data'=>['error_code'=>429,'parameters'=>['retry_after'=>30]]];});
    expect($sender->send(['chat_id'=>'101','text'=>'a']),false);expect($sender->lastOutcome()['retry_after'],30);
    $other=new TelegramMessageSender($db,static function()use(&$calls){$calls++;return ['http'=>200,'data'=>['ok'=>true]];},static fn()=>$clock->now,static fn()=>null,static fn()=>null);
    expect($other->send(['chat_id'=>'202','text'=>'b']),false);expect($calls,1);
    $clock->now+=31;expect($other->send(['chat_id'=>'202','text'=>'b']),true);expect($calls,2);
};

$tests['ticket trip leases exclude concurrent manual checks and recover abandoned runs']=static function(){
    [$sender,$db,$trip]=deliveryFixture(static fn()=>['http'=>200,'data'=>['ok'=>true]]);
    $first=claimTicketTripRun($db,(int)$trip['id'],1000);expect(is_string($first),true);
    expect(claimTicketTripRun($db,(int)$trip['id'],1001),null);
    $second=claimTicketTripRun($db,(int)$trip['id'],1301);expect(is_string($second),true);
    completeTicketTripRun($db,(int)$trip['id'],$first);expect($db->querySingle('SELECT claim_token FROM ticket_trip_runs'),$second);
    completeTicketTripRun($db,(int)$trip['id'],$second);expect($db->querySingle('SELECT claim_token FROM ticket_trip_runs'),null);
};

$tests['long and underscored reservation IDs use short owned callback tokens'] = static function(){
    [$db]=followFixture();ensureTelegramDeliveryTables($db);
    foreach ([str_repeat('A',110),'ticket_with_underscores'] as $ticket){
        $callback=ticketReservationCallback($db,'101',11,$ticket,0);
        expect(strlen($callback)<=64,true);
        expect(resolveTicketReservation($db,'101',$callback)['ticket_id'],$ticket);
        expect(resolveTicketReservation($db,'202',$callback),null);
        expect(ticketReservationCallback($db,'101',11,$ticket,0),$callback);
    }
    expect(ticketReservationCallback($db,'101',11,'ticket-1',0),'reserve_list_11_ticket-1_0');
    $db->exec('UPDATE ticket_reservation_callbacks SET expires_at=0');
    expect(resolveTicketReservation($db,'101',$callback),null);
};

$tests['unsent followed alerts survive a decrease to still-allowed partial capacity'] = static function(){
    [$db,$trip]=followFixture(['count'=>4,'allow_partial_capacity'=>1]);
    $key=trainFollowKey(followTicket());$trip=saveTrainFollows($db,(int)$trip['id'],'101','selected',[$key],0);
    $alerts=new TrainFollowAlerts($db);$alerts->observe($trip,[followTicket(['counting_en'=>4])]);
    $token=$alerts->claim($trip,$key);$alerts->complete((int)$trip['id'],$key,$token,false);
    $alerts->observe($trip,[followTicket(['counting_en'=>2])]);
    expect(is_string($alerts->claim($trip,$key)),true);
};

$tests['Mini App distinguishes raw stock from notification eligibility'] = static function(){
    $trip=sampleTrip()+['allow_partial_capacity'=>0];$trip['count']=4;
    expect(ticketNotificationEligibility($trip,followTicket(['counting_en'=>2]),true)['reason'],'capacity');
    $trip['allow_partial_capacity']=1;
    expect(ticketNotificationEligibility($trip,followTicket(['counting_en'=>2]),true)['eligible'],true);
    expect(ticketNotificationEligibility($trip,followTicket(['counting_en'=>2]),false)['reason'],'approval');
    $trip['search_filters']=['max_price'=>100];
    expect(ticketNotificationEligibility($trip,followTicket(['counting_en'=>4]),true)['reason'],'filters');
    $trip['follow_mode']='none';
    expect(ticketNotificationEligibility($trip,followTicket(['counting_en'=>4]),true)['reason'],'not_followed');
};
