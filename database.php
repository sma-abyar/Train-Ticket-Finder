<?php

class Database {
    private $dbPath;

    public function __construct($dbPath) {
        $this->dbPath = $dbPath;
    }

    public function connect() {
        return new SQLite3($this->dbPath);
    }

    public function initDatabase() {
        $db = $this->connect();
        $db->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, chat_id TEXT UNIQUE, approved INTEGER DEFAULT 0)");
        $db->exec("CREATE TABLE IF NOT EXISTS user_trips (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chat_id TEXT NOT NULL,
            route TEXT NOT NULL,
            date TEXT NOT NULL,
            return_date TEXT,
            count INTEGER NOT NULL,
            type INTEGER,
            coupe INTEGER,
            filter INTEGER,
            no_counting_notif INTEGER,
            no_ticket_notif INTEGER,
            bad_data_notif INTEGER
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS travelers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chat_id TEXT NOT NULL,
            first_name TEXT NOT NULL,
            last_name TEXT NOT NULL,
            national_code TEXT NOT NULL,
            passenger_type INTEGER NOT NULL,
            gender INTEGER NOT NULL,
            services TEXT,
            wheelchair INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS traveler_lists (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chat_id TEXT NOT NULL,
            name TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS traveler_list_members (
            list_id INTEGER,
            traveler_id INTEGER,
            FOREIGN KEY (list_id) REFERENCES traveler_lists(id) ON DELETE CASCADE,
            FOREIGN KEY (traveler_id) REFERENCES travelers(id) ON DELETE CASCADE,
            PRIMARY KEY (list_id, traveler_id)
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS user_states (
            chat_id TEXT PRIMARY KEY,
            current_state TEXT NOT NULL,
            temp_data TEXT,
            last_update DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        return $db;
    }
}