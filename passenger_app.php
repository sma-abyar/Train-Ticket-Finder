<?php

require_once __DIR__.'/traveler_list_storage.php';

function peopleVersion(array $value): string
{
    return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}

function peopleText($value, int $limit, string $label): string
{
    if (!is_string($value) || !preg_match('/^.{1,'.$limit.'}$/us', trim($value)) || preg_match('/[\x00-\x1f\x7f]/u', $value)) {
        throw new InvalidArgumentException($label.' معتبر وارد کنید.',400);
    }
    return trim($value);
}

function peopleTraveler(array $row): array
{
    $data=['id'=>(int)$row['id'],'first_name'=>$row['first_name'],'last_name'=>$row['last_name'],'national_code'=>$row['national_code'],
        'passenger_type'=>(int)$row['passenger_type'],'gender'=>(int)$row['gender'],'wheelchair'=>(bool)$row['wheelchair']];
    $data['version']=peopleVersion($data+['services'=>$row['services']??null]);
    return $data;
}

/** Read the address book in batches. Never expose another account's members. */
function peopleSnapshot(SQLite3 $db, string $chat): array
{
    $travelers=[];$map=[];
    $rows=followSql($db,'SELECT * FROM travelers WHERE chat_id=:chat ORDER BY id DESC',[':chat'=>$chat]);
    while($row=$rows->fetchArray(SQLITE3_ASSOC)){$person=peopleTraveler($row);$travelers[]=$person;$map[$person['id']]=$person;}$rows->finalize();
    $jsonMembers=travelerListsHaveJsonMembers($db);
    $lists=[];$rows=followSql($db,'SELECT * FROM traveler_lists WHERE chat_id=:chat ORDER BY id DESC',[':chat'=>$chat]);
    while($row=$rows->fetchArray(SQLITE3_ASSOC)){$lists[(int)$row['id']]=['id'=>(int)$row['id'],'name'=>$row['name'],'member_ids'=>$jsonMembers?normalizedTravelerMemberIds($row['members']):[]];}$rows->finalize();
    if(!$jsonMembers){
        $rows=followSql($db,'SELECT m.list_id,m.traveler_id FROM traveler_list_members m JOIN traveler_lists l ON l.id=m.list_id WHERE l.chat_id=:chat ORDER BY m.traveler_id',[':chat'=>$chat]);
        while($row=$rows->fetchArray(SQLITE3_ASSOC)){$lists[(int)$row['list_id']]['member_ids'][]=(int)$row['traveler_id'];}$rows->finalize();
    }
    foreach($lists as &$list){
        $list['member_ids']=array_values(array_filter(array_unique($list['member_ids']),static fn($id)=>isset($map[$id])));
        $list['seat_count']=count(array_filter($list['member_ids'],static fn($id)=>$map[$id]['passenger_type']!==2));
        $list['version']=peopleVersion([$list['name'],$list['member_ids']]);
    }unset($list);
    $contact=followSql($db,'SELECT person_name,phone_number,email FROM private_info WHERE chat_id=:chat LIMIT 1',[':chat'=>$chat])->fetchArray(SQLITE3_ASSOC)?:null;
    $version=peopleVersion($contact??[]);
    return ['travelers'=>$travelers,'lists'=>array_values($lists),'contact'=>['person_name'=>$contact['person_name']??'',
        'phone_number'=>bookingMobileNumber((string)($contact['phone_number']??'')), 'email'=>in_array(trim((string)($contact['email']??'')),['ندارم','/skip'],true)?'':($contact['email']??''),'version'=>$version]];
}

function peopleOwn(array $snapshot, string $kind, int $id, $version): array
{
    foreach($snapshot[$kind] as $row){
        if($row['id']===$id){
            if(!is_string($version)||!hash_equals($row['version'],$version)){throw new RuntimeException('اطلاعات تغییر کرده است؛ فهرست را تازه کن و دوباره ویرایش کن.',409);}
            return $row;
        }
    }
    throw new RuntimeException('این مورد در فهرست شما نیست.',404);
}

function peopleWriteMembers(SQLite3 $db, string $chat, int $list, array $members): void
{
    if(travelerListsHaveJsonMembers($db)){
        followSql($db,'UPDATE traveler_lists SET members=:members WHERE id=:id AND chat_id=:chat',[':members'=>json_encode(array_values($members),JSON_THROW_ON_ERROR),':id'=>$list,':chat'=>$chat]);
    }else{
        followSql($db,'DELETE FROM traveler_list_members WHERE list_id=:id',[':id'=>$list]);
        foreach($members as $id){followSql($db,'INSERT INTO traveler_list_members(list_id,traveler_id)VALUES(:list,:person)',[':list'=>$list,':person'=>$id]);}
    }
}

/** Member changes invalidate only this account's unfinished local food/booking draft. */
function peopleResetBooking(SQLite3 $db, string $chat, array $lists): void
{
    if(!$lists)return;
    $has=static fn($table)=>(bool)followSql($db,'SELECT 1 FROM sqlite_master WHERE type=\'table\' AND name=:name',[':name'=>$table])->fetchArray(SQLITE3_NUM);
    if($has('temporary_food_selections'))foreach($lists as $id){followSql($db,'DELETE FROM temporary_food_selections WHERE chat_id=:chat AND list_id=:id',[':chat'=>$chat,':id'=>$id]);}
    if($has('reservation_sessions')){
        $row=followSql($db,'SELECT session_data FROM reservation_sessions WHERE chat_id=:chat',[':chat'=>$chat])->fetchArray(SQLITE3_ASSOC);
        $session=json_decode($row['session_data']??'null',true);
        if(is_array($session)&&in_array((int)($session['list_id']??0),$lists,true))followSql($db,'DELETE FROM reservation_sessions WHERE chat_id=:chat',[':chat'=>$chat]);
    }
}

function peopleMutation(SQLite3 $db, string $chat, string $action, array $body): array
{
    $key=$body['request_id']??null;
    if(!is_string($key)||!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$key)){throw new InvalidArgumentException('شناسهٔ ذخیره معتبر نیست.',400);}
    $payload=array_diff_key($body,array_flip(['init_data','request_id','action']));
    $hash=peopleVersion([$action,$payload]);
    $db->exec('CREATE TABLE IF NOT EXISTS people_requests(chat_id TEXT NOT NULL,request_id TEXT NOT NULL,action TEXT NOT NULL,payload_hash TEXT NOT NULL,result TEXT NOT NULL,created_at INTEGER NOT NULL,PRIMARY KEY(chat_id,request_id))');
    if(!$db->exec('BEGIN IMMEDIATE'))throw new RuntimeException($db->lastErrorMsg());
    try{
        $old=followSql($db,'SELECT * FROM people_requests WHERE chat_id=:chat AND request_id=:key',[':chat'=>$chat,':key'=>$key])->fetchArray(SQLITE3_ASSOC);
        if($old){
            if(!hash_equals($old['payload_hash'],$hash))throw new RuntimeException('این درخواست تغییر کرده است؛ دوباره از فرم تازه ثبت کن.',409);
            $result=json_decode($old['result'],true,512,JSON_THROW_ON_ERROR);
            if(empty($result['deleted'])&&in_array($result['kind'],['traveler','list'],true)){
                $table=$result['kind']==='traveler'?'travelers':'traveler_lists';
                if(!followSql($db,'SELECT 1 FROM '.$table.' WHERE id=:id AND chat_id=:chat',[':id'=>$result['id'],':chat'=>$chat])->fetchArray(SQLITE3_NUM))throw new RuntimeException('این مورد قبلاً ثبت و سپس حذف شده است؛ فهرست را تازه کنید.',409);
            }
        }else{
            $snapshot=peopleSnapshot($db,$chat);
            $result=peopleApplyMutation($db,$chat,$action,$payload,$snapshot);
            followSql($db,'INSERT INTO people_requests VALUES(:chat,:key,:action,:hash,:result,:now)',[
                ':chat'=>$chat,':key'=>$key,':action'=>$action,':hash'=>$hash,':result'=>json_encode($result,JSON_THROW_ON_ERROR),':now'=>time()]);
        }
        if(!$db->exec('COMMIT'))throw new RuntimeException($db->lastErrorMsg());
    }catch(Throwable $error){$db->exec('ROLLBACK');throw $error;}
    return peopleSnapshot($db,$chat)+['mutation'=>$result,'replayed'=>(bool)$old];
}

function peopleApplyMutation(SQLite3 $db, string $chat, string $action, array $body, array $snapshot): array
{
    $id=$body['id']??null;
    if($id!==null&&(!is_int($id)||$id<1))throw new InvalidArgumentException('شناسه معتبر نیست.',400);
    if($action==='people_save_traveler'){
        $input=$body['traveler']??null;
        if(!is_array($input))throw new InvalidArgumentException('اطلاعات مسافر کامل نیست.',400);
        $data=['first_name'=>peopleText($input['first_name']??null,80,'نام'),'last_name'=>peopleText($input['last_name']??null,100,'نام خانوادگی')];
        if(!is_string($input['national_code']??null))throw new InvalidArgumentException('کد ملی معتبر وارد کنید.',400);
        $code=trainEnglishDigits($input['national_code']);
        if(!preg_match('/^\d{10}$/D',$code))throw new InvalidArgumentException('کد ملی را با ۱۰ رقم وارد کنید.',400);
        if(!is_int($input['passenger_type']??null)||!in_array($input['passenger_type'],[0,1,2],true)||!is_int($input['gender']??null)||!in_array($input['gender'],[1,2],true)||!is_bool($input['wheelchair']??null))throw new InvalidArgumentException('گروه سنی، جنسیت و نیاز به ویلچر را مشخص کنید.',400);
        $data+=['national_code'=>$code,'passenger_type'=>$input['passenger_type'],'gender'=>$input['gender'],'wheelchair'=>$input['wheelchair']];
        $before=$id!==null?peopleOwn($snapshot,'travelers',$id,$body['version']??null):null;
        foreach($snapshot['travelers'] as $person){if($person['national_code']===$code&&$person['id']!==$id){
            $existing=array_intersect_key($person,$data);
            if($id===null&&$existing==$data)return ['kind'=>'traveler','id'=>$person['id']];
            throw new RuntimeException('این کد ملی قبلاً ثبت شده است؛ همان مسافر را ویرایش کنید.',409);
        }}
        $values=[':chat'=>$chat];foreach($data as $name=>$value)$values[':'.$name]=is_bool($value)?(int)$value:$value;
        if($id===null){
            followSql($db,'INSERT INTO travelers(chat_id,first_name,last_name,national_code,passenger_type,gender,wheelchair,services)VALUES(:chat,:first_name,:last_name,:national_code,:passenger_type,:gender,:wheelchair,\'[]\')',$values);$id=(int)$db->lastInsertRowID();
        }else{
            followSql($db,'UPDATE travelers SET first_name=:first_name,last_name=:last_name,national_code=:national_code,passenger_type=:passenger_type,gender=:gender,wheelchair=:wheelchair WHERE id=:id AND chat_id=:chat',$values+[':id'=>$id]);
            if(array_intersect_key($before,$data)!=$data){$affected=[];foreach($snapshot['lists'] as $list)if(in_array($id,$list['member_ids'],true))$affected[]=$list['id'];peopleResetBooking($db,$chat,$affected);}
        }
        return ['kind'=>'traveler','id'=>$id];
    }
    if($action==='people_delete_traveler'){
        if($id===null)throw new InvalidArgumentException('مسافر را انتخاب کنید.',400);
        peopleOwn($snapshot,'travelers',$id,$body['version']??null);$affected=[];
        foreach($snapshot['lists'] as $list)if(in_array($id,$list['member_ids'],true)){$affected[]=$list['id'];peopleWriteMembers($db,$chat,$list['id'],array_values(array_diff($list['member_ids'],[$id])));}
        peopleResetBooking($db,$chat,$affected);followSql($db,'DELETE FROM travelers WHERE id=:id AND chat_id=:chat',[':id'=>$id,':chat'=>$chat]);
        return ['kind'=>'traveler','id'=>$id,'deleted'=>true];
    }
    if($action==='people_save_list'){
        $name=peopleText($body['name']??null,100,'نام لیست');$members=$body['member_ids']??null;
        if(!is_array($members)||array_values($members)!==$members)throw new InvalidArgumentException('اعضای لیست معتبر نیستند.',400);
        $owned=array_column($snapshot['travelers'],null,'id');
        foreach($members as $member)if(!is_int($member)||!isset($owned[$member]))throw new InvalidArgumentException('مسافر انتخاب‌شده در فهرست شما نیست.',400);
        $members=array_values(array_unique($members));sort($members,SORT_NUMERIC);
        $before=$id!==null?peopleOwn($snapshot,'lists',$id,$body['version']??null):null;
        if($id===null){
            foreach($snapshot['lists'] as $list)if($list['name']===$name){$ids=$list['member_ids'];sort($ids,SORT_NUMERIC);if($ids===$members)return ['kind'=>'list','id'=>$list['id']];throw new RuntimeException('لیستی با این نام دارید؛ همان لیست را ویرایش کنید.',409);}
            followSql($db,'INSERT INTO traveler_lists(chat_id,name)VALUES(:chat,:name)',[':chat'=>$chat,':name'=>$name]);$id=(int)$db->lastInsertRowID();
        }else{followSql($db,'UPDATE traveler_lists SET name=:name WHERE id=:id AND chat_id=:chat',[':name'=>$name,':id'=>$id,':chat'=>$chat]);}
        peopleWriteMembers($db,$chat,$id,$members);
        if($before&&$before['member_ids']!==$members)peopleResetBooking($db,$chat,[$id]);
        return ['kind'=>'list','id'=>$id];
    }
    if($action==='people_delete_list'){
        if($id===null)throw new InvalidArgumentException('لیست را انتخاب کنید.',400);
        peopleOwn($snapshot,'lists',$id,$body['version']??null);peopleResetBooking($db,$chat,[$id]);
        if(!travelerListsHaveJsonMembers($db))followSql($db,'DELETE FROM traveler_list_members WHERE list_id=:id',[':id'=>$id]);
        followSql($db,'DELETE FROM traveler_lists WHERE id=:id AND chat_id=:chat',[':id'=>$id,':chat'=>$chat]);
        return ['kind'=>'list','id'=>$id,'deleted'=>true];
    }
    if($action==='people_save_contact'){
        if(!is_string($body['version']??null)||!hash_equals($snapshot['contact']['version'],$body['version']))throw new RuntimeException('اطلاعات تماس تغییر کرده است؛ صفحه را تازه کنید.',409);
        $name=peopleText($body['person_name']??null,160,'نام خریدار');
        if(!is_string($body['phone_number']??null)||!is_string($body['email']??''))throw new InvalidArgumentException('شماره همراه و ایمیل معتبر وارد کنید.',400);
        $phone=bookingMobileNumber(trainEnglishDigits($body['phone_number']));
        if(!preg_match('/^\+?\d{8,15}$/D',$phone))throw new InvalidArgumentException('شمارهٔ همراه معتبر وارد کنید.',400);
        $email=trim((string)($body['email']??''));if(strlen($email)>254||($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)))throw new InvalidArgumentException('ایمیل معتبر وارد کنید یا آن را خالی بگذارید.',400);
        $values=[':chat'=>$chat,':name'=>$name,':phone'=>$phone,':email'=>$email];
        $exists=followSql($db,'SELECT 1 FROM private_info WHERE chat_id=:chat',[':chat'=>$chat])->fetchArray(SQLITE3_NUM);
        followSql($db,$exists?'UPDATE private_info SET person_name=:name,phone_number=:phone,email=:email WHERE chat_id=:chat':'INSERT INTO private_info(chat_id,person_name,phone_number,email)VALUES(:chat,:name,:phone,:email)',$values);
        return ['kind'=>'contact','id'=>0];
    }
    throw new InvalidArgumentException('عملیات مسافران معتبر نیست.',400);
}
