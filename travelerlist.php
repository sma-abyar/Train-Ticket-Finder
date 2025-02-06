<?php

class TravelerList {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function create($chat_id, $name, $traveler_ids) {
        $this->db->exec('BEGIN TRANSACTION');

        try {
            $stmt = $this->db->prepare("INSERT INTO traveler_lists (chat_id, name) VALUES (:chat_id, :name)");
            $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
            $stmt->bindValue(':name', $name, SQLITE3_TEXT);
            $stmt->execute();

            $list_id = $this->db->lastInsertRowID();

            $stmt = $this->db->prepare("INSERT INTO traveler_list_members (list_id, traveler_id) VALUES (:list_id, :traveler_id)");
            foreach ($traveler_ids as $traveler_id) {
                $stmt->bindValue(':list_id', $list_id, SQLITE3_INTEGER);
                $stmt->bindValue(':traveler_id', $traveler_id, SQLITE3_INTEGER);
                $stmt->execute();
            }

            $this->db->exec('COMMIT');
            return true;
        } catch (Exception $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    public function list($chat_id) {
        $stmt = $this->db->prepare("
            SELECT 
                tl.id,
                tl.name,
                GROUP_CONCAT(t.first_name || ' ' || t.last_name) as members,
                COUNT(tlm.traveler_id) as member_count
            FROM traveler_lists tl
            LEFT JOIN traveler_list_members tlm ON tl.id = tlm.list_id
            LEFT JOIN travelers t ON tlm.traveler_id = t.id
            WHERE tl.chat_id = :chat_id
            GROUP BY tl.id
            ORDER BY tl.created_at DESC
        ");
        $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
        $result = $stmt->execute();

        $lists = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $lists[] = $row;
        }
        return $lists;
    }

    public function remove($chat_id, $list_id) {
        $stmt = $this->db->prepare("DELETE FROM traveler_lists WHERE id = :id AND chat_id = :chat_id");
        $stmt->bindValue(':id', $list_id, SQLITE3_INTEGER);
        $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
        $stmt->execute();
    }
}