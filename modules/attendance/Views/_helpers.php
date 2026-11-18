<?php
/**
 * Shared view helpers of M34. Defines:
 *   $attStatus(string $status): string  badge
 *   $attTime(?string $utc, string $tz): string  local HH:MM or —
 *   $attMin(?int $minutes): string  H:MM or —
 * @var callable $ui @var callable $t
 */
$attStatus = static function (string $s) use ($ui, $t): string {
    [$tone, $icon, $key] = [
        'ok' => ['success', 'circle-check', 'attendance.status.ok'], 'late' => ['warning', 'clock', 'attendance.status.late'],
        'early' => ['warning', 'clock', 'attendance.status.early'], 'late_early' => ['warning', 'clock', 'attendance.status.late_early'],
        'missing_in' => ['danger', 'alert-triangle', 'attendance.status.missing_in'], 'missing_out' => ['danger', 'alert-triangle', 'attendance.status.missing_out'],
        'absent' => ['danger', 'circle-x', 'attendance.status.absent'], 'in_progress' => ['info', 'hourglass', 'attendance.status.in_progress'],
        'leave' => ['info', 'calendar-event', 'attendance.status.leave'], 'half_leave' => ['info', 'calendar-event', 'attendance.status.half_leave'],
        'off' => ['neutral', 'home', 'attendance.status.off'], 'off_worked' => ['warning', 'briefcase', 'attendance.status.off_worked'],
        'no_calendar' => ['warning', 'alert-circle', 'attendance.status.no_calendar'], 'no_data' => ['neutral', 'info-circle', 'attendance.status.no_data'],
    ][$s] ?? ['neutral', 'info-circle', 'attendance.status.unknown'];
    return $ui('badge', ['tone' => $tone, 'icon' => $icon, 'label' => $t($key)]);
};
$corrStatus = static function (string $s) use ($ui, $t): string {
    [$tone, $icon, $key] = [
        'pending' => ['warning', 'hourglass', 'attendance.cstatus.pending'], 'approved' => ['success', 'circle-check', 'attendance.cstatus.approved'],
        'returned' => ['warning', 'arrow-back-up', 'attendance.cstatus.returned'], 'rejected' => ['danger', 'circle-x', 'attendance.cstatus.rejected'],
        'cancelled' => ['neutral', 'x', 'attendance.cstatus.cancelled'],
    ][$s] ?? ['neutral', 'info-circle', 'attendance.status.unknown'];
    return $ui('badge', ['tone' => $tone, 'icon' => $icon, 'label' => $t($key)]);
};
$attTime = static function (?string $utc, string $tz): string {
    if ($utc === null || $utc === '') {
        return '—';
    }
    $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', substr($utc, 0, 19), new \DateTimeZone('UTC'));
    return $d === false ? '—' : $d->setTimezone(new \DateTimeZone($tz))->format('H:i');
};
$attMin = static fn (mixed $m): string => $m === null ? '—' : sprintf('%d:%02d', intdiv((int) $m, 60), (int) $m % 60);
$kindKey = static fn (string $k): string => match ($k) {
    'missing_in' => 'attendance.kind.missing_in', 'missing_out' => 'attendance.kind.missing_out', 'wrong_time' => 'attendance.kind.wrong_time', default => 'attendance.kind.other',
};
/** $periodStatus(string $status, ?string $exportedAt): string  badge of an attendance period (DEC-66) */
$periodStatus = static function (string $s, ?string $exportedAt = null) use ($ui, $t): string {
    [$tone, $icon, $key] = [
        'open' => ['neutral', 'lock-open', 'attendance.pstatus.open'], 'pending' => ['warning', 'hourglass', 'attendance.pstatus.pending'],
        'finalized' => ['success', 'lock', 'attendance.pstatus.finalized'], 'reopen_pending' => ['warning', 'arrow-back-up', 'attendance.pstatus.reopen_pending'],
    ][$s] ?? ['neutral', 'info-circle', 'attendance.status.unknown'];
    return $ui('badge', ['tone' => $tone, 'icon' => $icon, 'label' => $t($key)])
        . ($s === 'finalized' && $exportedAt !== null ? ' ' . $ui('badge', ['tone' => 'info', 'icon' => 'send', 'label' => $t('attendance.pstatus.exported')]) : '');
};
