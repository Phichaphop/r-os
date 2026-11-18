<?php
/**
 * Shared view helpers of M57. Defines $gpsStatus(string): string badge, $gpsFlags(string): string, $gpsWhen(array): string,
 * $gpsMap(array punch): string (inline SVG: check-in point circle, reading + accuracy — no map tiles, nothing leaves the server).
 * @var callable $ui @var callable $t @var callable $e @var string $loc
 */
use Blue\Core\I18n\DateFormat;
use Blue\Modules\Gps\GpsService;

$gpsStatus = static function (string $s) use ($ui, $t): string {
    [$tone, $icon] = ['validated' => ['success', 'circle-check'], 'flagged' => ['warning', 'alert-triangle'], 'approved' => ['success', 'checks'], 'rejected' => ['danger', 'circle-x']][$s] ?? ['neutral', 'info-circle'];
    return $ui('badge', ['tone' => $tone, 'icon' => $icon, 'label' => $t('gps.status.' . $s)]);
};
$gpsFlags = static function (string $flags) use ($t): string {
    $out = [];
    foreach (GpsService::flagList($flags) as $f) {
        $out[] = $t('gps.flag.' . (in_array($f, ['outside', 'accuracy', 'clock', 'offline', 'no_point'], true) ? $f : 'other'));
    }
    return $out === [] ? '—' : implode(' · ', $out);
};
$gpsWhen = static fn (array $p): string => DateFormat::day(substr((string) $p['local_time'], 0, 10), $loc) . ' ' . substr((string) $p['local_time'], 11, 5);
$gpsMap = static function (array $p) use ($e, $t): string {
    $hasPoint = $p['point_lat'] !== null;
    $lat0 = (float) ($hasPoint ? $p['point_lat'] : $p['lat']);
    $lng0 = (float) ($hasPoint ? $p['point_lng'] : $p['lng']);
    $dx = ((float) $p['lng'] - $lng0) * cos(deg2rad($lat0)) * 111320;
    $dy = ((float) $p['lat'] - $lat0) * 110540;
    $radius = $hasPoint ? (float) $p['point_radius'] : 0.0;
    $acc = (float) ($p['accuracy_m'] ?? 0);
    $ext = max(60.0, $radius, abs($dx) + $acc, abs($dy) + $acc) * 1.25;
    $f = static fn (float $n): string => number_format($n, 1, '.', '');
    $label = $hasPoint ? $t('gps.map_label', ['m' => (string) (int) $p['distance_m'], 'point' => (string) $p['point_name']]) : $t('gps.map_label_none');
    $svg = '<svg class="gps-map" viewBox="' . $f(-$ext) . ' ' . $f(-$ext) . ' ' . $f(2 * $ext) . ' ' . $f(2 * $ext) . '" role="img" aria-label="' . $e($label) . '">';
    $svg .= '<line class="gps-map__axis" x1="' . $f(-$ext) . '" y1="0" x2="' . $f($ext) . '" y2="0"/><line class="gps-map__axis" x1="0" y1="' . $f(-$ext) . '" x2="0" y2="' . $f($ext) . '"/>';
    if ($hasPoint) {
        $svg .= '<circle class="gps-map__fence" cx="0" cy="0" r="' . $f($radius) . '"/><circle class="gps-map__centre" cx="0" cy="0" r="' . $f($ext / 60) . '"/>';
    }
    if ($acc > 0) {
        $svg .= '<circle class="gps-map__acc" cx="' . $f($dx) . '" cy="' . $f(-$dy) . '" r="' . $f($acc) . '"/>';
    }
    $svg .= '<circle class="gps-map__pin" cx="' . $f($dx) . '" cy="' . $f(-$dy) . '" r="' . $f($ext / 30) . '"/></svg>';
    return $svg;
};
