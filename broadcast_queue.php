<?php

require_once __DIR__.'/train_follows.php';

/** Upgrade the existing queue in place; pending rows and sent history are preserved. */
function ensureBroadcastQueueSchema(SQLite3 $db): void
{
    if(!$db->exec('CREATE TABLE IF NOT EXISTS message_queue(id INTEGER PRIMARY KEY AUTOINCREMENT,chat_id TEXT NOT NULL,message TEXT NOT NULL,sent INTEGER DEFAULT 0,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)')){throw new RuntimeException($db->lastErrorMsg());}
    $definitions=['broadcast_id'=>'INTEGER','part_index'=>'INTEGER NOT NULL DEFAULT 0','status'=>"TEXT NOT NULL DEFAULT 'pending'",
        'attempts'=>'INTEGER NOT NULL DEFAULT 0','next_attempt_at'=>'INTEGER NOT NULL DEFAULT 0','claim_token'=>'TEXT',
        'claimed_at'=>'INTEGER','last_attempt_at'=>'INTEGER','sent_at'=>'INTEGER','error_code'=>'INTEGER NOT NULL DEFAULT 0','error'=>"TEXT NOT NULL DEFAULT ''"];
    if(!$db->exec('BEGIN IMMEDIATE')){throw new RuntimeException($db->lastErrorMsg());}
    try {
        // Another process may have completed the migration while we waited.
        $columns=[];$rows=$db->query('PRAGMA table_info(message_queue)');while($row=$rows->fetchArray(SQLITE3_ASSOC)){$columns[]=$row['name'];}$rows->finalize();
        foreach($definitions as $name=>$definition){if(!in_array($name,$columns,true)&&!$db->exec("ALTER TABLE message_queue ADD COLUMN $name $definition")){throw new RuntimeException($db->lastErrorMsg());}}
        foreach([
            'CREATE TABLE IF NOT EXISTS broadcasts(id INTEGER PRIMARY KEY AUTOINCREMENT,request_key TEXT UNIQUE,message_hash TEXT NOT NULL,recipient_count INTEGER NOT NULL,part_count INTEGER NOT NULL,created_at INTEGER NOT NULL)',
            'CREATE UNIQUE INDEX IF NOT EXISTS queue_broadcast_recipient_part ON message_queue(broadcast_id,chat_id,part_index)',
            'CREATE INDEX IF NOT EXISTS queue_ready ON message_queue(sent,status,next_attempt_at,id)',
            'CREATE INDEX IF NOT EXISTS queue_chat_order ON message_queue(chat_id,sent,status,id)',
        ] as $sql){if(!$db->exec($sql)){throw new RuntimeException($db->lastErrorMsg());}}
        if(!$db->exec('COMMIT')){throw new RuntimeException($db->lastErrorMsg());}
    }catch(Throwable $error){$db->exec('ROLLBACK');throw $error;}
}

/** Keep raw admin text and URLs. Limit by UTF-16 units, including emoji. */
function broadcastMessageParts(string $message): array
{
    if(trim($message)===''){throw new InvalidArgumentException('متن پیام خالی است.');}
    $characters=preg_split('//u',$message,-1,PREG_SPLIT_NO_EMPTY);
    if($characters===false){throw new InvalidArgumentException('متن پیام معتبر نیست.');}
    $parts=[];$part='';$units=0;
    foreach($characters as $character){
        $size=ord($character[0])>=240?2:1;
        if($units+$size>4000){$parts[]=$part;$part='';$units=0;}
        $part.=$character;$units+=$size;
    }
    if($part!==''){$parts[]=$part;}
    return $parts;
}

final class BroadcastQueue
{
    private SQLite3 $db;
    private $clock;
    private $wait;

    public function __construct(SQLite3 $db,?callable $clock=null,?callable $wait=null)
    {
        $this->db=$db;$this->clock=$clock??static fn()=>microtime(true);
        $this->wait=$wait??static fn($seconds)=>usleep((int)ceil($seconds*1000000));
        ensureBroadcastQueueSchema($db);
    }

    public function enqueue(string $message,array $recipients,?string $requestKey=null): array
    {
        $parts=broadcastMessageParts($message);$recipients=array_values(array_unique(array_map('strval',$recipients)));
        $hash=hash('sha256',$message);$key=$requestKey===null?null:hash('sha256',$requestKey);
        if(!$this->db->exec('BEGIN IMMEDIATE')){throw new RuntimeException($this->db->lastErrorMsg());}
        try {
            if($key!==null){
                $old=followSql($this->db,'SELECT id,message_hash,recipient_count,part_count FROM broadcasts WHERE request_key=:key',[':key'=>$key])->fetchArray(SQLITE3_ASSOC);
                if($old){if($old['message_hash']!==$hash){throw new RuntimeException('شناسهٔ ارسال با متن متفاوت تکرار شده است.');}
                    if(!$this->db->exec('COMMIT')){throw new RuntimeException($this->db->lastErrorMsg());}return ['id'=>(int)$old['id'],'recipients'=>(int)$old['recipient_count'],'parts'=>(int)$old['part_count'],'created'=>false];}
            }
            followSql($this->db,'INSERT INTO broadcasts(request_key,message_hash,recipient_count,part_count,created_at)VALUES(:key,:hash,:recipients,:parts,:now)',[
                ':key'=>$key,':hash'=>$hash,':recipients'=>count($recipients),':parts'=>count($parts),':now'=>(int)($this->clock)()]);
            $id=(int)$this->db->lastInsertRowID();
            $insert=$this->db->prepare('INSERT INTO message_queue(chat_id,message,broadcast_id,part_index)VALUES(:chat,:message,:broadcast,:part)');
            foreach($recipients as $chat){foreach($parts as $index=>$part){
                $insert->reset();$insert->bindValue(':chat',$chat,SQLITE3_TEXT);$insert->bindValue(':message',$part,SQLITE3_TEXT);
                $insert->bindValue(':broadcast',$id,SQLITE3_INTEGER);$insert->bindValue(':part',$index,SQLITE3_INTEGER);
                $result=$insert->execute();if(!$result){throw new RuntimeException($this->db->lastErrorMsg());}$result->finalize();
            }}
            if(!$this->db->exec('COMMIT')){throw new RuntimeException($this->db->lastErrorMsg());}return ['id'=>$id,'recipients'=>count($recipients),'parts'=>count($parts),'created'=>true];
        }catch(Throwable $error){$this->db->exec('ROLLBACK');throw $error;}
    }

    /** Atomic five-minute lease; never hold a transaction across a network call. */
    public function claim(): ?array
    {
        $now=(int)($this->clock)();if(!$this->db->exec('BEGIN IMMEDIATE')){throw new RuntimeException($this->db->lastErrorMsg());}
        try {
            followSql($this->db,"UPDATE message_queue SET status='pending',claim_token=NULL,claimed_at=NULL WHERE sent=0 AND status='sending' AND (claimed_at IS NULL OR claimed_at<:expired)",[':expired'=>$now-300]);
            $row=followSql($this->db,"SELECT q.* FROM message_queue q WHERE q.sent=0 AND q.status='pending' AND q.next_attempt_at<=:now
                AND NOT EXISTS(SELECT 1 FROM message_queue earlier WHERE earlier.chat_id=q.chat_id AND earlier.id<q.id AND earlier.sent=0 AND earlier.status IN('pending','sending'))
                ORDER BY q.next_attempt_at,q.id LIMIT 1",[':now'=>$now])->fetchArray(SQLITE3_ASSOC);
            if($row){
                $row['claim_token']=bin2hex(random_bytes(16));
                followSql($this->db,"UPDATE message_queue SET status='sending',claim_token=:token,claimed_at=:now WHERE id=:id",[':token'=>$row['claim_token'],':now'=>$now,':id'=>(int)$row['id']]);
            }
            if(!$this->db->exec('COMMIT')){throw new RuntimeException($this->db->lastErrorMsg());}return $row?:null;
        }catch(Throwable $error){$this->db->exec('ROLLBACK');throw $error;}
    }

    public function complete(array $row,array $outcome): bool
    {
        $now=(int)($this->clock)();$sent=($outcome['sent']??false)===true;$code=(int)($outcome['code']??503);
        $status=$sent?'sent':(!empty($outcome['skip'])?'skipped':(in_array($code,[400,403],true)?'failed':'pending'));
        $retry=max((int)($outcome['retry_after']??0),min(3600,5*(2**min(10,(int)$row['attempts']))));
        if(!$this->db->exec('BEGIN IMMEDIATE')){throw new RuntimeException($this->db->lastErrorMsg());}
        try {
            followSql($this->db,'UPDATE message_queue SET status=:status,sent=:sent,sent_at=:success,attempts=attempts+1,last_attempt_at=:now,
                next_attempt_at=:next,error_code=:code,error=:error,claim_token=NULL,claimed_at=NULL WHERE id=:id AND claim_token=:token',[
                ':status'=>$status,':sent'=>(int)$sent,':success'=>$sent?$now:null,':now'=>$now,':next'=>$status==='pending'?$now+$retry:0,
                ':code'=>$sent?0:$code,':error'=>$sent?'':(!empty($outcome['skip'])?'approval_revoked':($status==='failed'?'telegram_rejected':'retry_pending')),
                ':id'=>(int)$row['id'],':token'=>$row['claim_token']]);
            $changed=$this->db->changes()===1;
            if($changed){
                if(in_array($status,['failed','skipped'],true)){
                    followSql($this->db,"UPDATE message_queue SET status=:status,error_code=:code,error=:error WHERE sent=0 AND status='pending' AND chat_id=:chat AND broadcast_id IS :broadcast",[
                        ':status'=>$status,':code'=>$code,':error'=>$status==='skipped'?'approval_revoked':'recipient_delivery_failed',':chat'=>(string)$row['chat_id'],':broadcast'=>$row['broadcast_id']]);
                }else{
                    followSql($this->db,"UPDATE message_queue SET next_attempt_at=MAX(next_attempt_at,:next) WHERE chat_id=:chat AND sent=0 AND status='pending'",[
                        ':next'=>$sent?$now+2:$now+$retry,':chat'=>(string)$row['chat_id']]);
                }
            }
            if(!$this->db->exec('COMMIT')){throw new RuntimeException($this->db->lastErrorMsg());}return $changed;
        }catch(Throwable $error){$this->db->exec('ROLLBACK');throw $error;}
    }

    public function drain(callable $send,callable $approved,int $maxMessages=1000,float $seconds=45): array
    {
        $deadline=($this->clock)()+$seconds;$count=['sent'=>0,'failed'=>0,'deferred'=>0,'skipped'=>0];$processed=0;
        while($processed<$maxMessages && ($this->clock)()<$deadline){
            $row=$this->claim();
            if(!$row){
                $next=$this->db->querySingle("SELECT MIN(q.next_attempt_at) FROM message_queue q WHERE q.sent=0 AND q.status='pending'
                    AND NOT EXISTS(SELECT 1 FROM message_queue e WHERE e.chat_id=q.chat_id AND e.id<q.id AND e.sent=0 AND e.status IN('pending','sending'))");
                $delay=$next===null?3:max(0,(float)$next-($this->clock)());
                if($delay>0 && $delay<=2.05 && ($this->clock)()+$delay<$deadline){($this->wait)($delay+.001);continue;}
                break;
            }
            try {
                $outcome=$approved((string)$row['chat_id'])?$send((string)$row['chat_id'],$row['message']):['sent'=>false,'code'=>0,'skip'=>true];
            }catch(Throwable $error){$outcome=['sent'=>false,'code'=>503];}
            $this->complete($row,$outcome);$processed++;
            if(!empty($outcome['sent'])){$count['sent']++;}
            elseif(!empty($outcome['skip'])){$count['skipped']++;}
            elseif(in_array((int)($outcome['code']??503),[400,403],true)){$count['failed']++;}
            else{$count['deferred']++;}
            if(in_array((int)($outcome['code']??0),[401,429],true)){break;}
        }
        return $count;
    }

    public function latestSummary(): ?array
    {
        $campaign=$this->db->querySingle('SELECT id,recipient_count,part_count,created_at FROM broadcasts ORDER BY id DESC LIMIT 1',true);
        if(!$campaign){return null;}
        $counts=['sent'=>0,'failed'=>0,'skipped'=>0,'pending'=>0];
        $rows=followSql($this->db,"SELECT chat_id,SUM(CASE WHEN sent=1 THEN 1 ELSE 0 END)AS sent_parts,
            SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END)AS failures,SUM(CASE WHEN status='skipped' THEN 1 ELSE 0 END)AS skipped_parts,
            COUNT(*)AS total FROM message_queue WHERE broadcast_id=:id GROUP BY chat_id",[':id'=>(int)$campaign['id']]);
        while($row=$rows->fetchArray(SQLITE3_ASSOC)){
            $state=(int)$row['sent_parts']===(int)$row['total']?'sent':((int)$row['failures']>0?'failed':((int)$row['skipped_parts']>0?'skipped':'pending'));
            $counts[$state]++;
        }
        $errors=[];$rows=followSql($this->db,'SELECT error_code,COUNT(DISTINCT chat_id)AS recipients FROM message_queue WHERE broadcast_id=:id AND error_code<>0 GROUP BY error_code',[':id'=>(int)$campaign['id']]);
        while($row=$rows->fetchArray(SQLITE3_ASSOC)){$errors[]=$row;}
        return $campaign+$counts+['errors'=>$errors];
    }

    public function backlogSummary(): array
    {
        return $this->db->querySingle("SELECT COALESCE(SUM(CASE WHEN sent=0 AND status IN('pending','sending') THEN 1 ELSE 0 END),0)AS pending,
            COALESCE(SUM(CASE WHEN sent=0 AND status='failed' THEN 1 ELSE 0 END),0)AS failed FROM message_queue",true);
    }
}
