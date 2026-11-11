<?php
require_once __DIR__.'/../traveler_list_storage.php';

$tests['malformed passenger JSON never reaches count or reservation buttons']=static function() {
    foreach (['1','true','"text"','null','{bad','{"id":1}','[null,{},false,-1,0]'] as $value) {
        expect(normalizedTravelerMemberIds($value),[]);
    }
    expect(normalizedTravelerMemberIds('[1,"2",1,"foreign",-1,{},null]'),[1,2]);
};

$tests['legacy relational passenger lists are owned and retain their members']=static function() {
    $db=new SQLite3(':memory:');$db->enableExceptions(true);
    $db->exec('CREATE TABLE traveler_lists(id INTEGER PRIMARY KEY,chat_id TEXT); CREATE TABLE travelers(id INTEGER PRIMARY KEY,chat_id TEXT);
        CREATE TABLE traveler_list_members(list_id INTEGER,traveler_id INTEGER);
        INSERT INTO traveler_lists VALUES(11,\'101\');INSERT INTO travelers VALUES(1,\'101\'),(2,\'202\');
        INSERT INTO traveler_list_members VALUES(11,1),(11,2),(11,999)');
    expect(ownedTravelerMemberIds($db,11,'101'),[1]);
    expect(ownedTravelerMemberIds($db,11,'202'),[]);
    $db->exec('ALTER TABLE traveler_lists ADD COLUMN members TEXT');
    $db->exec('UPDATE traveler_lists SET members=\'[1]\'');
    expect(ownedTravelerMemberIds($db,11,'101'),[1]);
    $db->exec('UPDATE traveler_lists SET members=\'42\'');
    expect(ownedTravelerMemberIds($db,11,'101'),[]);
};
