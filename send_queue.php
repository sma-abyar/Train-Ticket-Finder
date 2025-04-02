<?php
// اتصال به دیتابیس صف پیام‌ها
$queueDb = initQueueDatabase();

// دریافت تمام پیام‌های ارسال نشده
$stmt = $queueDb->query("SELECT id, chat_id, message FROM message_queue WHERE sent = 0");

$messages = [];
while ($row = $stmt->fetchArray(SQLITE3_ASSOC)) {
    $messages[] = $row;
}

// اگر پیام جدیدی نبود، اسکریپت همینجا تموم بشه
if (empty($messages)) {
    $queueDb->close();
    exit;
}

foreach ($messages as $row) {
    $chatId = $row['chat_id'];
    $message = $row['message'];
    $messageId = $row['id'];

    // ارسال پیام
    $success = sendMessage($chatId, $message);

    if ($success) {
        // بروزرسانی وضعیت ارسال در صف پیام‌ها
        $update = $queueDb->prepare("UPDATE message_queue SET sent = 1 WHERE id = :id");
        $update->bindValue(':id', $messageId, SQLITE3_INTEGER);
        $update->execute();
    }

    // مکث 50 میلی‌ثانیه (یعنی هر 1 ثانیه 20 پیام می‌ره)
    usleep(50000);
}

// بستن دیتابیس
$queueDb->close();
?>
