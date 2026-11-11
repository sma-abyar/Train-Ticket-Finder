<?php

/** Read both historical list formats without changing stored passenger data. */
function travelerListsHaveJsonMembers(SQLite3 $db): bool
{
    $columns=$db->query('PRAGMA table_info(traveler_lists)');
    while ($column=$columns->fetchArray(SQLITE3_ASSOC)) {
        if ($column['name']==='members') { $columns->finalize(); return true; }
    }
    $columns->finalize();
    return false;
}

function normalizedTravelerMemberIds($json): array
{
    $members=is_string($json)?json_decode($json,true):null;
    // Objects/scalars are not a passenger list. Never count or iterate them.
    if (!is_array($members) || array_values($members)!==$members) { return []; }
    $ids=[];
    foreach ($members as $id) {
        if ((is_int($id) && $id>0) || (is_string($id) && ctype_digit($id) && strlen($id)<19 && (int)$id>0)) { $ids[]=(int)$id; }
    }
    return array_values(array_unique($ids));
}

function ownedTravelerMemberIds(SQLite3 $db, int $listId, string $chat): array
{
    if (travelerListsHaveJsonMembers($db)) {
        $list=followSql($db,'SELECT members FROM traveler_lists WHERE id=:list AND chat_id=:chat',[':list'=>$listId,':chat'=>$chat])->fetchArray(SQLITE3_ASSOC);
        return $list?normalizedTravelerMemberIds($list['members']):[];
    }
    $rows=followSql($db,'SELECT m.traveler_id FROM traveler_list_members m
        JOIN traveler_lists l ON l.id=m.list_id JOIN travelers t ON t.id=m.traveler_id
        WHERE l.id=:list AND l.chat_id=:chat AND t.chat_id=:chat ORDER BY m.traveler_id',[':list'=>$listId,':chat'=>$chat]);
    $ids=[];while ($row=$rows->fetchArray(SQLITE3_ASSOC)) { $ids[]=(int)$row['traveler_id']; }
    return array_values(array_unique($ids));
}
