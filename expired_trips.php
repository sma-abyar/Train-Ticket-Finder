<?php

require_once __DIR__ . '/trip_dates.php';

/** Delete trips and persist their notifications in the same SQLite transaction. */
final class ExpiredTripCleanup
{
    private SQLite3 $db;
    private $send;

    public function __construct(SQLite3 $db, callable $send)
    {
        $this->db = $db;
        $this->send = $send;
        $this->exec('CREATE TABLE IF NOT EXISTS expired_trip_notifications (
            trip_id INTEGER PRIMARY KEY,
            chat_id TEXT NOT NULL,
            message TEXT NOT NULL,
            claim_token TEXT,
            claimed_at INTEGER,
            sent_at INTEGER
        )');
        $this->exec('CREATE INDEX IF NOT EXISTS expired_trip_notifications_pending
            ON expired_trip_notifications (sent_at, chat_id)');
    }

    public function run(?string $chatId = null, ?DateTimeImmutable $now = null): int
    {
        $removed = $this->removeExpired($chatId, $now);
        $this->deliverPending($chatId);
        return $removed;
    }

    private function exec(string $sql): void
    {
        if (!$this->db->exec($sql)) {
            throw new RuntimeException($this->db->lastErrorMsg());
        }
    }

    private function execute(string $sql, array $values = []): SQLite3Result
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException($this->db->lastErrorMsg());
        }
        foreach ($values as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
        }
        $result = $stmt->execute();
        if (!$result) {
            throw new RuntimeException($this->db->lastErrorMsg());
        }
        return $result;
    }

    private function removeExpired(?string $chatId, ?DateTimeImmutable $now): int
    {
        // No network operations while holding the write lock. A second worker
        // sees the already-deleted rows and cannot enqueue them a second time.
        $this->exec('BEGIN IMMEDIATE');
        try {
            $result = $this->execute(
                'SELECT id, chat_id, route, date FROM user_trips'
                    . ($chatId === null ? '' : ' WHERE chat_id = :chat_id'),
                $chatId === null ? [] : [':chat_id' => $chatId]
            );
            $expired = [];
            while ($trip = $result->fetchArray(SQLITE3_ASSOC)) {
                if (isTripExpired($trip['date'], $now)) {
                    $expired[] = $trip;
                }
            }
            $result->finalize();
            foreach ($expired as $trip) {
                // Escape legacy Markdown characters in user-entered route/date.
                $escape = static fn($text) => strtr($text, [
                    '_' => '\\_', '*' => '\\*', '`' => '\\`', '[' => '\\[',
                ]);
                $message = "🗓 سفر شماره {$trip['id']} به‌صورت خودکار حذف شد، چون تاریخ حرکت آن گذشته است.\n"
                    . 'مسیر: ' . $escape($trip['route']) . "\n"
                    . 'تاریخ حرکت: ' . $escape($trip['date']) . "\n"
                    . 'جست‌وجوی بلیط برای این سفر متوقف شد. برای تاریخ جدید می‌توانید سفر تازه‌ای ثبت کنید.';
                $this->execute(
                    'INSERT INTO expired_trip_notifications (trip_id, chat_id, message)
                     VALUES (:id, :chat_id, :message)',
                    [':id' => (int) $trip['id'], ':chat_id' => $trip['chat_id'], ':message' => $message]
                );
                $this->execute('DELETE FROM user_trips WHERE id = :id', [':id' => (int) $trip['id']]);
            }
            $this->exec('COMMIT');
            return count($expired);
        } catch (Throwable $error) {
            $this->db->exec('ROLLBACK');
            throw $error;
        }
    }

    private function deliverPending(?string $chatId): void
    {
        $result = $this->execute(
            'SELECT trip_id, chat_id, message FROM expired_trip_notifications WHERE sent_at IS NULL'
                . ($chatId === null ? '' : ' AND chat_id = :chat_id') . ' ORDER BY trip_id',
            $chatId === null ? [] : [':chat_id' => $chatId]
        );
        $pending = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $pending[] = $row;
        }
        $result->finalize();
        foreach ($pending as $row) {
            // Atomic lease prevents webhook/cron workers from sending the same
            // pending notification together, and recovers after a crashed worker.
            $token = bin2hex(random_bytes(16));
            $this->execute(
                'UPDATE expired_trip_notifications SET claim_token = :token, claimed_at = :now
                 WHERE trip_id = :id AND sent_at IS NULL
                 AND (claimed_at IS NULL OR claimed_at < :retry_before)',
                [':token' => $token, ':now' => time(), ':id' => (int) $row['trip_id'], ':retry_before' => time() - 300]
            );
            if ($this->db->changes() !== 1) {
                continue;
            }
            $sent = false;
            try {
                $sent = (bool) ($this->send)($row['chat_id'], $row['message']);
            } catch (Throwable $error) {
                error_log('Expired trip notification failed: ' . $error->getMessage());
            }
            $this->execute(
                'UPDATE expired_trip_notifications SET sent_at = ' . ($sent ? ':sent_at' : 'NULL')
                    . ', claim_token = NULL, claimed_at = NULL WHERE trip_id = :id AND claim_token = :token',
                ($sent ? [':sent_at' => time()] : []) + [':id' => (int) $row['trip_id'], ':token' => $token]
            );
        }
    }
}
