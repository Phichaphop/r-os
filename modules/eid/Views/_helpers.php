<?php
/**
 * Shared view helpers of M13. Defines $eidStatus(string): string badge, $money(int|string units-or-db, string currency): string,
 * $cardFace(array face, ?array qr, bool large): string (the card itself — logo, company, photo, name, code, QR).
 * @var callable $ui @var callable $t @var callable $e @var callable $url @var callable $asset @var array<string, mixed> $v @var string $loc
 */
use Blue\Core\I18n\DateFormat;
use Blue\Core\Support\Money;

$eidStatus = static function (?string $s) use ($ui, $t): string {
    if ($s === null) {
        return $ui('badge', ['tone' => 'neutral', 'icon' => 'info-circle', 'label' => $t('eid.status.none')]);
    }
    [$tone, $icon] = ['active' => ['success', 'circle-check'], 'suspended' => ['warning', 'lock'], 'lost' => ['danger', 'alert-triangle'], 'revoked' => ['danger', 'circle-x'],
        'replaced' => ['neutral', 'refresh'], 'expired' => ['warning', 'hourglass'], 'former' => ['neutral', 'user']][$s] ?? ['neutral', 'info-circle'];
    return $ui('badge', ['tone' => $tone, 'icon' => $icon, 'label' => $t('eid.status.' . $s)]);
};
$money = static fn (int|string $amount, string $cur = ''): string => Money::format(is_int($amount) ? $amount : Money::fromDb($amount)) . ($cur !== '' ? ' ' . $cur : '');
$cardFace = static function (array $f, ?array $qr, bool $large = false) use ($e, $t, $url, $asset, $v, $loc): string {
    $logo = !empty($v['theme']['logo']) ? $url('/brand/logo') . '?v=' . ($v['theme']['version'] ?? '') : $url('/brand/logo') . '?v=' . ($v['theme']['version'] ?? '');
    $initial = mb_strtoupper(mb_substr(trim((string) $f['name']), 0, 1));
    $meta = array_values(array_filter([$f['position'] ?? null, $f['department'] ?? null, $f['site'] ?? null]));
    $active = $f['status'] === 'active';
    $h = '<figure class="eid-card navy-dots on-navy' . ($large ? ' eid-card--large' : '') . ($active ? '' : ' eid-card--void') . '" aria-label="' . $e($t('eid.card_aria', ['name' => (string) $f['name']])) . '">';
    $h .= '<div class="eid-card__top"><img class="eid-card__logo" src="' . $e($logo) . '" alt="" width="34" height="34"><span class="eid-card__company">' . $e((string) $f['company']) . '</span>'
        . '<span class="eid-card__kind">' . $e($t('eid.card_kind')) . '</span></div>';
    $h .= '<div class="eid-card__body"><div class="eid-card__photo">' . ($f['has_photo'] ? '<img src="' . $e($url('/eid/photo/' . $f['card_pid'])) . '" alt="' . $e($t('eid.photo_alt', ['name' => (string) $f['name']])) . '">' : '<span aria-hidden="true">' . $e($initial) . '</span>') . '</div>';
    $h .= '<div class="eid-card__who"><span class="eid-card__name">' . $e((string) $f['name']) . '</span>' . ($f['name_en'] !== '' && $f['name_en'] !== $f['name'] ? '<span class="eid-card__name-en">' . $e((string) $f['name_en']) . '</span>' : '')
        . '<span class="eid-card__code">' . $e((string) $f['code']) . '</span>' . ($meta !== [] ? '<span class="eid-card__meta">' . $e(implode(' · ', $meta)) . '</span>' : '') . '</div>';
    $h .= '<div class="eid-card__qr"' . ($qr !== null ? ' data-eid-qr data-refresh="' . (int) $qr['refresh'] . '"' : '') . '>' . ($qr !== null ? $qr['svg'] : '<span class="eid-card__noqr">' . $e($t('eid.no_qr')) . '</span>') . '</div></div>';
    $h .= '<figcaption class="eid-card__foot"><span class="eid-card__no">' . $e((string) $f['card_no']) . '</span><span>' . $e($f['expires_on'] !== null ? $t('eid.valid_until', ['date' => DateFormat::day((string) $f['expires_on'], $loc)]) : $t('eid.no_expiry')) . '</span>'
        . '<span class="eid-card__state">' . $e($t('eid.status.' . $f['status'])) . '</span></figcaption></figure>';
    return $h;
};
