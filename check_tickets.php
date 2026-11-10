<?php

// Run from cron, e.g. php /path/to/Train-Ticket-Finder/check_tickets.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

// A long check must not overlap the next cron invocation on this host.
$lock = fopen(__DIR__ . '/ticket-check.lock', 'c');
if ($lock === false) {
    fwrite(STDERR, "Cannot open the ticket check lock.\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fclose($lock);
    exit(0);
}

$statusDb = null;
try {
    chdir(__DIR__);
    define('TRAIN_TICKET_BOT_LIBRARY_ONLY', true);
    require __DIR__ . '/bot.php';
    $statusDb = initDatabase();
    followSql($statusDb,'INSERT INTO ticket_worker_status VALUES (1,:now,NULL,NULL) ON CONFLICT(id) DO UPDATE SET started_at=excluded.started_at,finished_at=NULL,error=NULL',[':now'=>time()]);
    followSql($statusDb,'DELETE FROM ticket_reservation_callbacks WHERE expires_at<:now',[':now'=>time()]);
    followSql($statusDb,'DELETE FROM telegram_send_limits WHERE not_before<:old',[':old'=>time()-86400]);
    fetchTicketsForAllUsers();
    followSql($statusDb,'UPDATE ticket_worker_status SET finished_at=:now WHERE id=1',[':now'=>time()]);
} catch (Throwable $error) {
    if ($statusDb !== null) {
        try { followSql($statusDb,'UPDATE ticket_worker_status SET finished_at=:now,error=:error WHERE id=1',[':now'=>time(),':error'=>get_class($error)]); }
        catch (Throwable $ignored) { /* Preserve the original cron error. */ }
    }
    fwrite(STDERR, 'Ticket check failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
