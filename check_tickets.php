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

try {
    chdir(__DIR__);
    define('TRAIN_TICKET_BOT_LIBRARY_ONLY', true);
    require __DIR__ . '/bot.php';
    fetchTicketsForAllUsers();
} catch (Throwable $error) {
    fwrite(STDERR, 'Ticket check failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
