<?php

class Trip {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function save($chat_id, $data) {
        $stmt = $this->db->prepare("INSERT INTO user_trips (chat_id, route, date, return_date, count, type, coupe, filter, no_counting_notif, no_ticket_notif, bad_data_notif) 
                          VALUES (:chat_id, :route, :date, :return_date, :count, :type, :coupe, :filter, :no_counting_notif, :no_ticket_notif, :bad_data_notif)");
        $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
        $stmt->bindValue(':route', $data['route'], SQLITE3_TEXT);
        $stmt->bindValue(':date', $data['date'], SQLITE3_TEXT);
        $stmt->bindValue(':return_date', $data['return_date'], SQLITE3_TEXT);
        $stmt->bindValue(':count', $data['count'], SQLITE3_INTEGER);
        $stmt->bindValue(':type', $data['type'], SQLITE3_INTEGER);
        $stmt->bindValue(':coupe', $data['coupe'], SQLITE3_INTEGER);
        $stmt->bindValue(':filter', $data['filter'], SQLITE3_INTEGER);
        $stmt->bindValue(':no_counting_notif', 0, SQLITE3_INTEGER);
        $stmt->bindValue(':no_ticket_notif', 0, SQLITE3_INTEGER);
        $stmt->bindValue(':bad_data_notif', 0, SQLITE3_INTEGER);
        $stmt->execute();
    }

    public function getTrips($chat_id) {
        $stmt = $this->db->prepare("SELECT * FROM user_trips WHERE chat_id = :chat_id");
        $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
        $result = $stmt->execute();

        $trips = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $trips[] = $row;
        }
        return $trips;
    }

    public function remove($chat_id, $trip_id) {
        $stmt = $this->db->prepare("DELETE FROM user_trips WHERE id = :id AND chat_id = :chat_id");
        $stmt->bindValue(':id', $trip_id, SQLITE3_INTEGER);
        $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
        $stmt->execute();
    }
}