<?php
require_once __DIR__.'/../passenger_app.php';

function peopleFixture(bool $json=false): SQLite3
{
    $db=(new Database(':memory:'))->initDatabase();$db->enableExceptions(true);
    $db->exec('CREATE TABLE private_info(chat_id TEXT,person_name TEXT,phone_number INTEGER,email TEXT);
        CREATE TABLE reservation_sessions(chat_id TEXT PRIMARY KEY,session_data TEXT);
        CREATE TABLE temporary_food_selections(chat_id TEXT,list_id INTEGER,passenger_index INTEGER,food_id TEXT);
        INSERT INTO travelers(id,chat_id,first_name,last_name,national_code,passenger_type,gender,services,wheelchair)VALUES
        (1,\'101\',\'First\',\'Person\',\'0012345678\',0,1,\'["legacy"]\',0),
        (2,\'202\',\'Foreign\',\'Person\',\'0099999999\',0,2,\'[]\',0),
        (3,\'101\',\'Small\',\'Person\',\'0012345679\',2,2,\'[]\',0);
        INSERT INTO traveler_lists(id,chat_id,name)VALUES(11,\'101\',\'Family\'),(22,\'202\',\'Foreign list\');');
    if($json){$db->exec('ALTER TABLE traveler_lists ADD COLUMN members TEXT DEFAULT \'[]\';UPDATE traveler_lists SET members=\'[1,3,2,999]\' WHERE id=11;UPDATE traveler_lists SET members=\'[2]\' WHERE id=22');}
    else{$db->exec('INSERT INTO traveler_list_members VALUES(11,1),(11,3),(11,2),(11,999),(22,2)');}
    return $db;
}
function personInput(): array {return ['first_name'=>'Test','last_name'=>'Traveler','national_code'=>'۰۰۱۲۳۴۵۶۸۰','passenger_type'=>1,'gender'=>1,'wheelchair'=>true];}
function peopleRequest(string $key,array $payload): array {return ['request_id'=>str_repeat($key,32)]+$payload;}

$tests['passenger snapshots preserve both legacy schemas without exposing foreign members']=static function(){
    foreach([false,true]as$json){$db=peopleFixture($json);$data=peopleSnapshot($db,'101');expect(count($data['travelers']),2);expect(count($data['lists']),1);expect($data['lists'][0]['member_ids'],[1,3]);expect($data['lists'][0]['seat_count'],1);expect(str_contains(json_encode($data),'Foreign'),false);}
};
$tests['passengers create idempotently and edit with ownership and content versions']=static function(){
    $db=peopleFixture();$input=personInput();$body=peopleRequest('a',['traveler'=>$input]);$first=peopleMutation($db,'101','people_save_traveler',$body);$id=$first['mutation']['id'];
    expect(peopleMutation($db,'101','people_save_traveler',$body)['replayed'],true);expect(count(peopleSnapshot($db,'101')['travelers']),3);
    expect(peopleMutation($db,'101','people_save_traveler',peopleRequest('b',['traveler'=>$input]))['mutation']['id'],$id);
    $person=array_column($first['travelers'],null,'id')[1];$edit=peopleRequest('c',['id'=>1,'version'=>$person['version'],'traveler'=>array_intersect_key($person,$input)]);$edit['traveler']['last_name']='Changed';
    peopleMutation($db,'101','people_save_traveler',$edit);expect($db->querySingle('SELECT services FROM travelers WHERE id=1'),'["legacy"]');
    assertFollowError(static fn()=>peopleMutation($db,'101','people_save_traveler',array_replace($edit,['request_id'=>str_repeat('d',32)])),409);
    assertFollowError(static fn()=>peopleMutation($db,'202','people_save_traveler',$edit),404);
    foreach([['national_code'=>[]],['national_code'=>'12'],['passenger_type'=>3],['gender'=>0],['wheelchair'=>1],['first_name'=>'']]as$invalid){assertFollowError(static fn()=>peopleMutation($db,'101','people_save_traveler',peopleRequest('z',['traveler'=>array_replace($input,$invalid)])),400);}
};
$tests['list editing validates every member and preserves the selected storage format']=static function(){
    foreach([false,true]as$json){$db=peopleFixture($json);$list=peopleSnapshot($db,'101')['lists'][0];
        $body=peopleRequest('a',['id'=>11,'version'=>$list['version'],'name'=>'Renamed','member_ids'=>[3,1,3]]);
        $result=peopleMutation($db,'101','people_save_list',$body);expect($result['lists'][0]['name'],'Renamed');expect($result['lists'][0]['member_ids'],[1,3]);
        assertFollowError(static fn()=>peopleMutation($db,'101','people_save_list',peopleRequest('b',['name'=>'Bad','member_ids'=>[2]])),400);
        assertFollowError(static fn()=>peopleMutation($db,'101','people_save_list',peopleRequest('b',['name'=>'Bad','member_ids'=>['1']])),400);
        assertFollowError(static fn()=>peopleMutation($db,'101','people_save_list',array_replace($body,['request_id'=>str_repeat('c',32)])),409);
        $empty=peopleMutation($db,'101','people_save_list',peopleRequest('e',['name'=>'Empty','member_ids'=>[]]));expect($empty['lists'][0]['member_ids'],[]);
    }
};
$tests['passenger deletion detaches owned lists and invalidates only affected booking drafts']=static function(){
    foreach([false,true]as$json){$db=peopleFixture($json);$snapshot=peopleSnapshot($db,'101');$person=array_column($snapshot['travelers'],null,'id')[1];
        $db->exec('INSERT INTO reservation_sessions VALUES(\'101\',\'{"list_id":11}\'),(\'202\',\'{"list_id":22}\');INSERT INTO temporary_food_selections VALUES(\'101\',11,0,\'food\'),(\'202\',22,0,\'food\')');
        $result=peopleMutation($db,'101','people_delete_traveler',peopleRequest('a',['id'=>1,'version'=>$person['version']]));expect($result['lists'][0]['member_ids'],[3]);expect($result['lists'][0]['seat_count'],0);
        expect((int)$db->querySingle("SELECT COUNT(*) FROM reservation_sessions WHERE chat_id='101'"),0);expect((int)$db->querySingle("SELECT COUNT(*) FROM temporary_food_selections WHERE chat_id='202'"),1);expect((int)$db->querySingle('SELECT COUNT(*) FROM travelers WHERE id=2'),1);
        $list=$result['lists'][0];peopleMutation($db,'101','people_delete_list',peopleRequest('b',['id'=>11,'version'=>$list['version']]));expect(count(peopleSnapshot($db,'101')['travelers']),1);expect(count(peopleSnapshot($db,'202')['lists']),1);
    }
};
$tests['ambiguous passenger mutations replay without resurrecting deleted records']=static function(){
    $db=peopleFixture();$body=peopleRequest('a',['traveler'=>personInput()]);$result=peopleMutation($db,'101','people_save_traveler',$body);$person=array_column($result['travelers'],null,'id')[$result['mutation']['id']];
    peopleMutation($db,'101','people_delete_traveler',peopleRequest('b',['id'=>$person['id'],'version'=>$person['version']]));
    assertFollowError(static fn()=>peopleMutation($db,'101','people_save_traveler',$body),409);
    $body['traveler']['first_name']='Other';assertFollowError(static fn()=>peopleMutation($db,'101','people_save_traveler',$body),409);expect(count(peopleSnapshot($db,'101')['travelers']),2);
};
$tests['booking contact data normalize legacy phone numbers and respect account versions']=static function(){
    $db=peopleFixture();$contact=peopleSnapshot($db,'101')['contact'];$body=peopleRequest('a',['version'=>$contact['version'],'person_name'=>'Test Buyer','phone_number'=>'+۹۸۹۱۲۳۴۵۶۷۸۹','email'=>'']);
    $result=peopleMutation($db,'101','people_save_contact',$body);expect($result['contact']['phone_number'],'09123456789');expect(peopleMutation($db,'101','people_save_contact',$body)['replayed'],true);
    assertFollowError(static fn()=>peopleMutation($db,'101','people_save_contact',array_replace($body,['request_id'=>str_repeat('b',32)])),409);expect(peopleSnapshot($db,'202')['contact']['person_name'],'');
    expect(bookingMobileNumber('9123456789'),'09123456789');expect(bookingMobileNumber('00989123456789'),'09123456789');
};
