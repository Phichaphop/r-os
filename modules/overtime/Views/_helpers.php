<?php
/**
 * Shared view helpers of M33. Defines $otStatus(string): string badge, $hm(?int minutes): string "H:MM" and $otWhen(array row): string.
 * @var callable $ui @var callable $t @var callable $e @var string $loc
 */
use Blue\Core\I18n\DateFormat;

$otStatus = static function (string $s) use ($ui, $t): string {
    [$tone, $icon] = [
        'pending' => ['warning', 'hourglass'], 'returned' => ['warning', 'arrow-back-up'], 'approved' => ['info', 'circle-check'], 'rejected' => ['danger', 'circle-x'],
        'cancelled' => ['neutral', 'x'], 'disputed' => ['danger', 'alert-triangle'], 'verified' => ['success', 'checks'], 'sent' => ['success', 'send'],
    ][$s] ?? ['neutral', 'info-circle'];
    return $ui('badge', ['tone' => $tone, 'icon' => $icon, 'label' => $t('overtime.status.' . $s)]);
};
$hm = static fn (?int $m): string => $m === null ? '—' : intdiv($m, 60) . ':' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT);
$otWhen = static fn (array $r): string => DateFormat::day((string) $r['work_date'], $loc) . ' ' . $r['start_local'] . '–' . $r['end_local'] . ((string) $r['end_local'] <= (string) $r['start_local'] ? ' ' . $t('overtime.next_day') : '');
