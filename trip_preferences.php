<?php

/** Add preferences without losing existing trips; safe for concurrent upgrades. */
function ensureTripPreferencesSchema(SQLite3 $db): void
{
    $columns = static function () use ($db): array {
        $result = $db->query('PRAGMA table_info(user_trips)');
        $names = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $names[] = $row['name'];
        }
        $result->finalize();
        return $names;
    };
    $required = ['allow_partial_capacity', 'search_filters', 'follow_mode', 'followed_trains', 'excluded_trains', 'follow_revision'];
    if (!array_diff($required, $columns())) {
        return;
    }
    if (!$db->exec('BEGIN IMMEDIATE')) {
        throw new RuntimeException($db->lastErrorMsg());
    }
    try {
        // Recheck after acquiring the lock: another process may have upgraded it.
        $existing = $columns();
        foreach ([
            'allow_partial_capacity' => 'INTEGER NOT NULL DEFAULT 0',
            'search_filters' => "TEXT NOT NULL DEFAULT '{}'",
            'follow_mode' => "TEXT NOT NULL DEFAULT 'all'",
            'followed_trains' => "TEXT NOT NULL DEFAULT '[]'",
            'excluded_trains' => "TEXT NOT NULL DEFAULT '[]'",
            'follow_revision' => 'INTEGER NOT NULL DEFAULT 0',
        ] as $name => $definition) {
            if (!in_array($name, $existing, true) && !$db->exec("ALTER TABLE user_trips ADD COLUMN $name $definition")) {
                throw new RuntimeException($db->lastErrorMsg());
            }
        }
        if (!$db->exec('COMMIT')) {
            throw new RuntimeException($db->lastErrorMsg());
        }
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

function trainEnglishDigits(string $text): string
{
    return strtr($text, [
        '۰'=>'0', '۱'=>'1', '۲'=>'2', '۳'=>'3', '۴'=>'4', '۵'=>'5', '۶'=>'6', '۷'=>'7', '۸'=>'8', '۹'=>'9',
        '٠'=>'0', '١'=>'1', '٢'=>'2', '٣'=>'3', '٤'=>'4', '٥'=>'5', '٦'=>'6', '٧'=>'7', '٨'=>'8', '٩'=>'9',
    ]);
}

function trainTimeMinutes(string $time, bool $allowEndOfDay = false): ?int
{
    $time = trainEnglishDigits(trim($time));
    if (!preg_match('/^(\d{1,2}):(\d{2})$/D', $time, $parts)) {
        return null;
    }
    $hour = (int) $parts[1];
    $minute = (int) $parts[2];
    if ($allowEndOfDay && $hour === 24 && $minute === 0) {
        return 1440;
    }
    return $hour < 24 && $minute < 60 ? $hour * 60 + $minute : null;
}

function normalizeTripFilters(array $filters): array
{
    $allowed = ['companies', 'train_types', 'departure_from', 'departure_to', 'min_price', 'max_price'];
    if (array_diff(array_keys($filters), $allowed)) {
        throw new InvalidArgumentException('فیلتر ناشناخته است.');
    }
    $normalized = [];
    foreach (['companies', 'train_types'] as $key) {
        if (!empty($filters[$key])) {
            if (!is_array($filters[$key]) || count($filters[$key]) > 12) {
                throw new InvalidArgumentException('تعداد گزینه‌های فیلتر نامعتبر است.');
            }
            $items = [];
            foreach ($filters[$key] as $item) {
                if (!is_string($item) || trim($item) === '' || strlen($item) > 200) {
                    throw new InvalidArgumentException('گزینهٔ فیلتر نامعتبر است.');
                }
                $items[] = trim($item);
            }
            $normalized[$key] = array_values(array_unique($items));
        }
    }
    if (array_diff($normalized['train_types'] ?? [], ['compartment_4', 'compartment_6', 'seated'])) {
        throw new InvalidArgumentException('نوع قطار نامعتبر است.');
    }
    if (isset($filters['departure_from']) || isset($filters['departure_to'])) {
        $from = trainTimeMinutes((string) ($filters['departure_from'] ?? ''));
        $to = trainTimeMinutes((string) ($filters['departure_to'] ?? ''), true);
        if ($from === null || $to === null) {
            throw new InvalidArgumentException('ساعت را به شکل 08:00-18:00 وارد کنید.');
        }
        $normalized['departure_from'] = sprintf('%02d:%02d', intdiv($from, 60), $from % 60);
        $normalized['departure_to'] = sprintf('%02d:%02d', intdiv($to, 60), $to % 60);
    }
    foreach (['min_price', 'max_price'] as $key) {
        if (isset($filters[$key])) {
            $value = trainEnglishDigits((string) $filters[$key]);
            if (!ctype_digit($value) || strlen($value) > 12) {
                throw new InvalidArgumentException('قیمت باید یک عدد صحیح و به تومان باشد.');
            }
            $normalized[$key] = (int) $value;
        }
    }
    if (isset($normalized['min_price'], $normalized['max_price']) && $normalized['min_price'] > $normalized['max_price']) {
        throw new InvalidArgumentException('حداقل قیمت نباید بیشتر از حداکثر باشد.');
    }
    return $normalized;
}

function tripFilters(array $trip): array
{
    $filters = $trip['search_filters'] ?? [];
    if (is_string($filters)) {
        $filters = json_decode($filters, true, 512, JSON_THROW_ON_ERROR);
    }
    if (!is_array($filters)) {
        throw new InvalidArgumentException('فیلترهای ذخیره‌شده نامعتبرند.');
    }
    return normalizeTripFilters($filters);
}

function ticketAvailableSeats(array $ticket): int
{
    $value = trainEnglishDigits((string) ($ticket['counting_en'] ?? ''));
    return ctype_digit($value) ? (int) $value : 0;
}

function ticketMatchesCapacity(array $ticket, array $trip): bool
{
    $available = ticketAvailableSeats($ticket);
    return $available > 0 && ($available >= max(1, (int) $trip['count'])
        || (int) ($trip['allow_partial_capacity'] ?? 0) === 1);
}

function ticketMatchesLocalFilters(array $ticket, array $trip): bool
{
    try {
        $filters = tripFilters($trip);
    } catch (Throwable $error) {
        error_log('Invalid train trip filters for trip ' . ($trip['id'] ?? '?'));
        return false;
    }
    if (!empty($filters['companies'])) {
        $name = static fn($value) => preg_replace('/[\s\x{200C}]+/u', '', strtr($value, ['ي'=>'ی', 'ك'=>'ک']));
        if (!in_array($name($ticket['owner_name'] ?? ''), array_map($name, $filters['companies']), true)) {
            return false;
        }
    }
    if (!empty($filters['train_types'])) {
        $type = (int) ($ticket['iscompartment'] ?? -1);
        $category = $type === 1 ? 'compartment_' . (int) ($ticket['compartment_capacity'] ?? 0)
            : ($type === 0 ? 'seated' : 'unknown');
        if (!in_array($category, $filters['train_types'], true)) {
            return false;
        }
    }
    if (isset($filters['departure_from'])) {
        $time = trainTimeMinutes((string) ($ticket['time'] ?? ''));
        $from = trainTimeMinutes($filters['departure_from']);
        $to = trainTimeMinutes($filters['departure_to'], true);
        // Bounds include the start and exclude the end. Crossing midnight is supported.
        if ($time === null || ($from < $to ? !($time >= $from && $time < $to)
            : ($from > $to ? !($time >= $from || $time < $to) : false))) {
            return false;
        }
    }
    if (isset($filters['min_price']) || isset($filters['max_price'])) {
        // Current Ghasedak API `cost` is discounted price per person in toman;
        // `cost_title` is formatted in rial and must not be used for this comparison.
        $price = $ticket['cost'] ?? null;
        if (!is_numeric($price) || (float) $price < 0
            || (isset($filters['min_price']) && (float) $price < $filters['min_price'])
            || (isset($filters['max_price']) && (float) $price > $filters['max_price'])) {
            return false;
        }
    }
    return true;
}

function tripFilterSummary(array $trip): string
{
    $filters = tripFilters($trip);
    $lines = ['ظرفیت کمتر از تعداد درخواستی: ' . (!empty($trip['allow_partial_capacity']) ? 'نمایش داده شود' : 'نمایش داده نشود')];
    $labels = ['compartment_4'=>'کوپه‌ای ۴ نفره', 'compartment_6'=>'کوپه‌ای ۶ نفره', 'seated'=>'سالنی/اتوبوسی'];
    $lines[] = 'نوع قطار: ' . (empty($filters['train_types']) ? 'همه' : implode('، ', array_map(static fn($key) => $labels[$key], $filters['train_types'])));
    $lines[] = 'شرکت ریلی: ' . (empty($filters['companies']) ? 'همه' : implode('، ', $filters['companies']));
    $lines[] = 'ساعت حرکت: ' . (isset($filters['departure_from']) ? $filters['departure_from'] . ' تا ' . $filters['departure_to'] : 'همه');
    $lines[] = 'قیمت هر نفر (تومان): ' . (isset($filters['min_price']) || isset($filters['max_price'])
        ? ($filters['min_price'] ?? 0) . ' تا ' . ($filters['max_price'] ?? 'بدون سقف') : 'همه');
    return implode("\n", $lines);
}
