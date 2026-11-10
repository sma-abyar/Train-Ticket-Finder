<?php
// Compatibility for the original cron command. The worker enforces CLI-only
// access and the same non-overlapping lock regardless of the entry filename.
require __DIR__.'/check_tickets.php';
