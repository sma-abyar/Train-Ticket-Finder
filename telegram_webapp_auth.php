<?php

/** Validate Telegram's signed initData; never trust initDataUnsafe or a posted chat ID. */
function telegramWebAppUser(string $initData, string $botToken, ?int $now = null): string
{
    $now = $now ?? time();
    if ($initData === '' || strlen($initData) > 16384 || $botToken === '') {
        throw new RuntimeException('این صفحه را از دکمهٔ ربات در تلگرام باز کنید.', 401);
    }
    $fields = [];
    foreach (explode('&', $initData) as $pair) {
        $parts = explode('=', $pair, 2);
        $key = urldecode($parts[0]);
        if (count($parts) !== 2 || !preg_match('/^[a-zA-Z0-9_]+$/D', $key) || array_key_exists($key, $fields)) {
            throw new RuntimeException('اطلاعات ورود تلگرام معتبر نیست.', 401);
        }
        $fields[$key] = urldecode($parts[1]);
    }
    $hash = $fields['hash'] ?? '';
    unset($fields['hash']);
    ksort($fields, SORT_STRING);
    $check = [];
    foreach ($fields as $key => $value) {
        $check[] = $key . '=' . $value;
    }
    $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);
    $expected = hash_hmac('sha256', implode("\n", $check), $secret);
    if (!preg_match('/^[a-f0-9]{64}$/D', $hash) || !hash_equals($expected, $hash)) {
        throw new RuntimeException('اطلاعات ورود تلگرام معتبر نیست.', 401);
    }
    $date = $fields['auth_date'] ?? '';
    if (!ctype_digit($date) || (int) $date > $now + 30 || $now - (int) $date > 3600) {
        throw new RuntimeException('مهلت این صفحه تمام شده؛ دوباره از ربات بازش کنید.', 401);
    }
    $user = json_decode($fields['user'] ?? '', true);
    $id = $user['id'] ?? null;
    if ((!is_int($id) && !(is_string($id) && ctype_digit($id))) || (int) $id < 1 || !empty($user['is_bot'])) {
        throw new RuntimeException('کاربر تلگرام مشخص نیست.', 401);
    }
    return (string) $id;
}
