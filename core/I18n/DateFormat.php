<?php

declare(strict_types=1);

namespace Blue\Core\I18n;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Display formatting for dates stored in UTC (P0-05 §6):
 * - converted to the display timezone (site/user — config for now)
 * - Thai UI shows Buddhist Era years: "14 ต.ค. 2569 07:15" (UX-04); English: "14 Oct 2026 07:15"
 * Storage is never affected — this is presentation only.
 */
final class DateFormat
{
    private const TH_MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

    public static function display(string|DateTimeImmutable $utc, string $locale, string $tz = 'Asia/Bangkok', bool $withTime = true): string
    {
        $d = $utc instanceof DateTimeImmutable ? $utc : new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        $d = $d->setTimezone(new DateTimeZone($tz));
        $time = $withTime ? ' ' . $d->format('H:i') : '';
        if ($locale === 'th') {
            return (int) $d->format('j') . ' ' . self::TH_MONTHS[(int) $d->format('n') - 1] . ' ' . ((int) $d->format('Y') + 543) . $time;
        }
        return $d->format('j M Y') . $time;
    }

    private const TH_MONTHS_LONG = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];

    /**
     * A business DATE (Y-m-d, already in the site's calendar — no timezone conversion) for display.
     * $long = full month name, as used in letters ("12 ตุลาคม 2569" / "12 October 2026").
     */
    public static function day(string $ymd, string $locale, bool $long = false): string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new DateTimeZone('UTC'));
        if ($d === false) {
            return $ymd;
        }
        if ($locale === 'th') {
            $months = $long ? self::TH_MONTHS_LONG : self::TH_MONTHS;
            return (int) $d->format('j') . ' ' . $months[(int) $d->format('n') - 1] . ' ' . ((int) $d->format('Y') + 543);
        }
        return $d->format($long ? 'j F Y' : 'j M Y');
    }
}
