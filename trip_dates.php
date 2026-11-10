<?php

/**
 * Borkowski's Jalali year calculation, ported from jalaali-js v1.2.8.
 * https://github.com/jalaali/jalaali-js/tree/v1.2.8
 * Copyright (c) 2020 Behrang Norouzinia. MIT; see licenses/jalaali-js.txt.
 */
function tripJalaliYearInfo(int $year): array
{
    static $cache = [];
    if (isset($cache[$year])) {
        return $cache[$year];
    }
    $breaks = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210,
        1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];
    if ($year < -61 || $year >= 3178) {
        throw new InvalidArgumentException('Unsupported Jalali year');
    }
    $gregorianYear = $year + 621;
    $leapJ = -14;
    $previous = $breaks[0];
    foreach (array_slice($breaks, 1) as $next) {
        $jump = $next - $previous;
        if ($year < $next) {
            break;
        }
        $leapJ += intdiv($jump, 33) * 8 + intdiv($jump % 33, 4);
        $previous = $next;
    }
    $n = $year - $previous;
    $leapJ += intdiv($n, 33) * 8 + intdiv($n % 33 + 3, 4);
    if ($jump % 33 === 4 && $jump - $n === 4) {
        $leapJ++;
    }
    $leapG = intdiv($gregorianYear, 4)
        - intdiv((intdiv($gregorianYear, 100) + 1) * 3, 4) - 150;
    $march = 20 + $leapJ - $leapG;
    if ($jump - $n < 6) {
        $n = $n - $jump + intdiv($jump + 4, 33) * 33;
    }
    $leap = (($n + 1) % 33 - 1) % 4;
    if ($leap === -1) {
        $leap = 4;
    }
    return $cache[$year] = ['year' => $gregorianYear, 'march' => $march, 'leap' => $leap];
}

/** Return null for malformed dates: uncertain data must never be deleted. */
function tripDepartureDate(string $value): ?DateTimeImmutable
{
    static $digits = null;
    $digits = $digits ?? array_combine(
        preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY),
        str_split('01234567890123456789')
    );
    $value = strtr($value, $digits);
    $value = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $value);
    if ($value === null || !preg_match('~^(\d{4})[-/](\d{1,2})[-/](\d{1,2})$~D', trim($value), $parts)) {
        return null;
    }
    [$year, $month, $day] = array_map('intval', array_slice($parts, 1));
    if ($year < 1000) {
        return null;
    }
    $timezone = new DateTimeZone('Asia/Tehran');
    // Existing bot input is Jalali (14xx); also accept explicit modern Gregorian dates.
    if ($year >= 1900) {
        return checkdate($month, $day, $year)
            ? new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day), $timezone)
            : null;
    }
    $info = tripJalaliYearInfo($year);
    $monthLength = $month <= 6 ? 31 : ($month <= 11 ? 30 : ($info['leap'] === 0 ? 30 : 29));
    if ($month < 1 || $month > 12 || $day < 1 || $day > $monthLength) {
        return null;
    }
    $newYear = new DateTimeImmutable(sprintf('%04d-03-%02d', $info['year'], $info['march']), $timezone);
    $offset = ($month - 1) * 31 - intdiv($month, 7) * ($month - 7) + $day - 1;
    return $newYear->modify("+$offset days");
}

function isTripExpired(string $date, ?DateTimeImmutable $now = null): bool
{
    $departure = tripDepartureDate($date);
    $today = ($now ?? new DateTimeImmutable('now'))
        ->setTimezone(new DateTimeZone('Asia/Tehran'))->setTime(0, 0);
    return $departure !== null && $departure < $today;
}
