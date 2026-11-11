<?php
require_once __DIR__.'/cron_runtime.php';
requireLocalPhpCronRuntime();
chdir(__DIR__);
$lock=fopen(__DIR__.'/message-queue.lock','c');
if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)){exit;}
try {
    define('TRAIN_TICKET_BOT_LIBRARY_ONLY',true);
    require __DIR__.'/bot.php';
    $queue=new BroadcastQueue(initQueueDatabase());
    $usersDb=initDatabase();
    $approved=$usersDb->prepare('SELECT 1 FROM users WHERE chat_id=:chat AND approved=1');
    $queue->drain(static function(string $chat,string $text): array {
        $outcome=null;
        sendMessage($chat,$text,null,false,null,[],$outcome);
        return $outcome;
    },static function(string $chat)use($approved): bool {
        $approved->reset();$approved->bindValue(':chat',$chat,SQLITE3_TEXT);
        $result=$approved->execute();$isApproved=(bool)$result->fetchArray(SQLITE3_NUM);$result->finalize();
        return $isApproved;
    });
}catch(Throwable $error){
    writeCronError('Message queue failed: '.get_class($error).PHP_EOL);exit(1);
}finally{
    flock($lock,LOCK_UN);fclose($lock);
}
