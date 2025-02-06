<?php

class User {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function register($chat_id, $username) {
        $stmt = $this->db->prepare("INSERT OR IGNORE INTO users (chat_id) VALUES (:chat_id)");
        $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
        $stmt->execute();
    }

    public function approve($chat_id) {
        $stmt = $this->db->prepare("UPDATE users SET approved = 1 WHERE chat_id = :chat_id");
        $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
        $stmt->execute();
    }

    public function getApprovedUsers() {
        $result = $this->db->query("SELECT chat_id FROM users WHERE approved = 1");
        $users = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $users[] = $row['chat_id'];
        }
        return $users;
    }
}