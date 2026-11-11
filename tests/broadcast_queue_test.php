<?php
require_once __DIR__.'/../broadcast_queue.php';

function broadcastFixture(?SQLite3 $db=null): array
{
    $db=$db??new SQLite3(':memory:');$db->enableExceptions(true);
    $clock=(object)['now'=>1000.0];
    $queue=new BroadcastQueue($db,static fn()=>$clock->now,static function($delay)use($clock){$clock->now+=$delay;});
    return [$queue,$db,$clock];
}

$tests['broadcast recipients enqueue atomically and webhook retries are idempotent']=static function(){
    [$queue,$db]=broadcastFixture();
    $db->exec("CREATE TRIGGER refuse_recipient BEFORE INSERT ON message_queue WHEN NEW.chat_id='202' BEGIN SELECT RAISE(ABORT,'test rollback'); END");
    try{$queue->enqueue('message',['101','202'],'event-1');throw new LogicException('Expected rollback');}catch(Exception $error){if($error instanceof LogicException)throw $error;}
    expect((int)$db->querySingle('SELECT COUNT(*) FROM message_queue'),0);expect((int)$db->querySingle('SELECT COUNT(*) FROM broadcasts'),0);
    $db->exec('DROP TRIGGER refuse_recipient');
    $first=$queue->enqueue('message',['101','202','101'],'event-1');$retry=$queue->enqueue('message',['101','202','303'],'event-1');
    expect($first['recipients'],2);expect($retry['created'],false);expect($retry['id'],$first['id']);expect((int)$db->querySingle('SELECT COUNT(*) FROM message_queue'),2);
};

$tests['broadcast Unicode chunks preserve text emoji and URLs without Telegram length overflow']=static function(){
    $text=str_repeat('سلام 👨‍👩‍👧‍👦 ۱۲۳ ',500).'https://example.test/123?q=456';$parts=broadcastMessageParts($text);
    expect(implode('',$parts),$text);expect(count($parts)>1,true);
    foreach($parts as $part){$units=0;foreach(preg_split('//u',$part,-1,PREG_SPLIT_NO_EMPTY)as$c){$units+=ord($c[0])>=240?2:1;}expect($units<=4000,true);}
};

$tests['legacy queue rows and sent history survive schema migration']=static function(){
    $db=new SQLite3(':memory:');$db->exec('CREATE TABLE message_queue(id INTEGER PRIMARY KEY,chat_id TEXT NOT NULL,message TEXT NOT NULL,sent INTEGER DEFAULT 0,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $db->exec("INSERT INTO message_queue(id,chat_id,message,sent)VALUES(1,'101','old pending',0),(2,'202','old sent',1)");
    [$queue]=broadcastFixture($db);$sent=[];
    $queue->drain(static function($chat,$text)use(&$sent){$sent[]=$text;return ['sent'=>true,'code'=>0];},static fn()=>true);
    expect($sent,['old pending']);expect((int)$db->querySingle('SELECT COUNT(*) FROM message_queue WHERE sent=1'),2);
};

$tests['broadcast drain reaches every recipient across bounded cron runs and preserves chunk order']=static function(){
    [$queue,$db,$clock]=broadcastFixture();$recipients=array_map('strval',range(101,120));$text=str_repeat('ب',4100);
    $queue->enqueue($text,$recipients,'many');$sent=[];
    for($i=0;$i<20;$i++){$queue->drain(static function($chat,$part)use(&$sent){$sent[$chat]=($sent[$chat]??'').$part;return ['sent'=>true,'code'=>0];},static fn()=>true,3,10);$clock->now+=300;}
    expect(count($sent),20);foreach($recipients as$chat){expect(hash('sha256',$sent[$chat]),hash('sha256',$text));}
    expect((int)$queue->latestSummary()['sent'],20);expect((int)$queue->latestSummary()['pending'],0);
};

$tests['transient broadcast errors back off without losing anyone or blocking other recipients']=static function(){
    [$queue,$db,$clock]=broadcastFixture();$queue->enqueue('retry',['101','202','303']);$calls=[];
    $queue->drain(static function($chat)use(&$calls){$calls[]=$chat;return $chat==='101'?['sent'=>false,'code'=>503]:['sent'=>true,'code'=>0];},static fn()=>true);
    expect($calls,['101','202','303']);expect((int)$queue->latestSummary()['pending'],1);expect((int)$queue->latestSummary()['sent'],2);
    $clock->now+=6;$queue->drain(static fn()=>['sent'=>true,'code'=>0],static fn()=>true);
    expect((int)$queue->latestSummary()['sent'],3);
};

$tests['blocked recipients and revoked approvals are explicit terminal states for their campaign']=static function(){
    [$queue]=broadcastFixture();$queue->enqueue(str_repeat('ب',4100),['101','202','303']);$calls=[];
    $queue->drain(static function($chat)use(&$calls){$calls[]=$chat;return $chat==='101'?['sent'=>false,'code'=>403]:['sent'=>true,'code'=>0];},static fn($chat)=>$chat!=='202');
    $summary=$queue->latestSummary();expect((int)$summary['failed'],1);expect((int)$summary['skipped'],1);expect((int)$summary['sent'],1);
    expect((int)$queue->backlogSummary()['pending'],0);expect((int)$queue->backlogSummary()['failed'],2,'A two-part failure counts as one recipient but two queued messages');
    expect(count(array_filter($calls,static fn($c)=>$c==='101')),1);expect(in_array('202',$calls,true),false);
};

$tests['queue leases exclude concurrent workers and recover after a crash']=static function(){
    [$queue,$db,$clock]=broadcastFixture();$queue->enqueue('lease',['101']);$first=$queue->claim();expect(is_array($first),true);expect($queue->claim(),null);
    $clock->now+=301;$second=$queue->claim();expect(is_array($second),true);expect($first['claim_token']!==$second['claim_token'],true);
    expect($queue->complete($first,['sent'=>true,'code'=>0]),false);expect($queue->complete($second,['sent'=>true,'code'=>0]),true);
    expect($queue->claim(),null);
};

$tests['flood wait and invalid bot credentials pause a batch while preserving all recipients']=static function(){
    foreach([429,401]as$code){
        [$queue,$db,$clock]=broadcastFixture();$queue->enqueue('wait',['101','202','303']);$calls=0;
        $queue->drain(static function()use(&$calls,$code){$calls++;return ['sent'=>false,'code'=>$code,'retry_after'=>60];},static fn()=>true);
        expect($calls,1);expect((int)$queue->latestSummary()['pending'],3);
        $clock->now+=61;$queue->drain(static fn()=>['sent'=>true,'code'=>0],static fn()=>true);expect((int)$queue->latestSummary()['sent'],3);
    }
};
