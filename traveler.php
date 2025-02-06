<?php

class Traveler {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function add($chat_id, $data) {
        $stmt = $this->db->prepare("INSERT INTO travelers (chat_id, first_name, last_name, national_code, passenger_type, gender, services, wheelchair) 
                          VALUES (:chat_id, :first_name, :last_name, :national_code, :passenger_type, :gender, :services, :wheelchair)");
        $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
        $stmt->bindValue(':first_name', $data['first_name'], SQLITE3_TEXT);
        $stmt->bindValue(':last_name', $data['last_name'], SQLITE3_TEXT);
        $stmt->bindValue(':national_code', $data['national_code'], SQLITE3_TEXT);
        $stmt->bindValue(':passenger_type', $data['passenger_type'], SQLITE3_INTEGER);
        $stmt->bindValue(':gender', $data['gender'], SQLITE3_INTEGER);
        $stmt->bindValue(':services', json_encode($data['services'] ?? []), SQLITE3_TEXT);
        $stmt->bindValue(':wheelchair', $data['wheelchair'] ?? 0, SQLITE3_INTEGER);
        $stmt->execute();
    }

    public function list($chat_id) {
        $stmt = $this->db->prepare("SELECT * FROM travelers WHERE chat_id = :chat_id ORDER BY created_at DESC");
        $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
        $result = $stmt->execute();

        $travelers = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $travelers[] = $row;
        }
        return $travelers;
    }

    public function remove($chat_id, $traveler_id) {
        $stmt = $this->db->prepare("DELETE FROM travelers WHERE id = :id AND chat_id = :chat_id");
        $stmt->bindValue(':id', $traveler_id, SQLITE3_INTEGER);
        $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
        $stmt->execute();
    }
}