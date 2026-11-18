<?php
/**
 * Shared view helpers of M35. Defines:
 *   $shChip(?array $shift, bool $withTime = true): string   coloured chip for a shift (null = not planned)
 *   $swapStatus(string $status): string                     badge
 *   $wd(string $ymd): string                                 short weekday name
 * @var callable $ui @var callable $t @var callable $e @var string $loc
 */
$shChip = static function (?array $s, bool $withTime = true) use ($e, $t): string {
    if ($s === null) {
        return '<span class="shift-chip shift-chip--none">' . $e($t('shifts.not_planned')) . '</span>';
    }
    if ($s['off']) {
        return '<span class="shift-chip shift-chip--off">' . $e($t('shifts.off')) . '</span>';
    }
    return '<span class="shift-chip shift-chip--' . $e((string) $s['tone']) . '"><strong>' . $e((string) $s['code']) . '</strong>'
        . ($withTime ? ' ' . $e($s['start'] . '–' . $s['end']) . ($s['overnight'] ? ' <span class="shift-chip__next">' . $e($t('shifts.next_day')) . '</span>' : '') : '') . '</span>';
};
$swapStatus = static function (string $s) use ($ui, $t): string {
    [$tone, $icon] = [
        'pending_partner' => ['warning', 'hourglass'], 'pending' => ['warning', 'hourglass'], 'approved' => ['success', 'circle-check'],
        'declined' => ['neutral', 'x'], 'rejected' => ['danger', 'circle-x'], 'cancelled' => ['neutral', 'x'], 'failed' => ['danger', 'alert-triangle'],
    ][$s] ?? ['neutral', 'info-circle'];
    return $ui('badge', ['tone' => $tone, 'icon' => $icon, 'label' => $t('shifts.swap_status.' . $s)]);
};
$wd = static function (string $ymd) use ($t): string {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
    return $d === false ? '' : $t('shifts.wd.' . $d->format('w'));
};
