<?php
/**
 * This month's birthdays (day and month only) and work anniversaries + a short wish.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array{settings: array<string, mixed>|null, month: string, birthdays: list<array<string, mixed>>, anniversaries: list<array<string, mixed>>, me: array<string, mixed>|null} $board
 * @var array<string, string> $errors @var bool $canManage @var string $today
 */
use Blue\Modules\Employees\EmployeeService;

$s = $board['settings'];
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$loc = (string) ($v['locale'] ?? 'th');
$monthStart = EmployeeService::ymd($board['month'] . '-01');
$fmtMonth = static fn (DateTimeImmutable $d): string => $t('celebrate.month.' . $d->format('n')) . ' ' . ((int) $d->format('Y') + ($loc === 'th' ? 543 : 0));
$prev = $monthStart->modify('-1 month')->format('Y-m');
$next = $monthStart->modify('+1 month')->format('Y-m');
$meId = (int) ($board['me']['id'] ?? 0);
$actions = $ui('button', ['label' => $t('celebrate.me_title'), 'href' => $url('/celebrate/me'), 'variant' => 'secondary', 'icon' => 'user'])
    . ($canManage ? $ui('button', ['label' => $t('celebrate.nav_admin'), 'href' => $url('/celebrate/admin'), 'variant' => 'ghost', 'icon' => 'settings']) : '');
$cards = static function (array $rows, string $kind) use ($e, $t, $ui, $url, $csrf, $meId, $today, $board, $loc): string {
    $out = '<ul class="cel-grid">';
    foreach ($rows as $x) {
        $name = (string) ($x['preferred_name'] ?: ($loc === 'en' && $x['name_en'] ? $x['name_en'] : $x['name_th']));
        $d = EmployeeService::ymd((string) $x['event_date']);
        $isToday = (string) $x['event_date'] === $today;
        $out .= '<li class="card cel-card' . ($isToday ? ' is-today' : '') . '"><div class="cel-card__date" aria-hidden="true"><strong>' . $e($d->format('j')) . '</strong><span>' . $e($t('celebrate.mon.' . $d->format('n'))) . '</span></div>'
            . '<div class="cel-card__body"><p class="cel-card__name">' . $e($name) . ($isToday ? ' ' . $ui('badge', ['tone' => 'success', 'label' => $t('celebrate.today')]) : '') . '</p>'
            . '<p class="text-sm text-muted">' . $e(trim(($x['position_title'] ?? '') . ' · ' . ($x['dept_code'] ?? ''), ' ·')) . '</p>'
            . '<p class="text-sm">' . $e($kind === 'birthday' ? $t('celebrate.birthday_on', ['d' => $d->format('j') . ' ' . $t('celebrate.month.' . $d->format('n'))]) : $t('celebrate.years', ['n' => (string) $x['years']])) . '</p>'
            . '<p class="text-sm text-muted">' . $e($t('celebrate.wish_count', ['n' => (string) $x['wishes']])) . '</p>';
        if ((int) $x['employee_id'] !== $meId && (int) $x['wished'] === 0) {
            $out .= '<details class="cel-card__wish"><summary class="text-sm">' . $e($t('celebrate.send_wish')) . '</summary><form method="post" action="' . $e($url('/celebrate/e/' . $x['public_id'] . '/wish')) . '" class="stack-sm">'
                . $csrf . '<input type="hidden" name="m" value="' . $e($board['month']) . '">'
                . $ui('field', ['name' => 'message', 'id' => 'cw-' . $x['public_id'], 'label' => $t('celebrate.message'), 'required' => true, 'value' => $t('celebrate.preset.' . $kind)])
                . $ui('button', ['label' => $t('celebrate.send'), 'type' => 'submit', 'variant' => 'primary', 'size' => 'sm', 'icon' => 'send']) . '</form></details>';
        } elseif ((int) $x['wished'] > 0) {
            $out .= '<p class="text-sm">' . $ui('icon', ['name' => 'circle-check', 'size' => 'sm']) . ' ' . $e($t('celebrate.wished')) . '</p>';
        }
        $out .= '</div></li>';
    }
    return $out . '</ul>';
};
?>
<?= $ui('page-header', ['title' => $t('celebrate.title'), 'subtitle' => $t('celebrate.subtitle'), 'actions' => $actions]) ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'text' => implode(' ', $errors)]) ?><?php endif; ?>
<?php if ($s === null): ?>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('celebrate.not_employee')]) ?>
<?php elseif (!$s['birthdays_enabled'] && !$s['anniversaries_enabled']): ?>
  <?= $ui('alert', ['tone' => 'info', 'title' => $t('celebrate.off_title'), 'text' => $t('celebrate.off')]) ?>
<?php else: ?>
<nav class="row cel-month" aria-label="<?= $e($t('celebrate.pick_month')) ?>">
  <?= $ui('button', ['label' => $t('celebrate.prev'), 'href' => $url('/celebrate?m=' . $prev), 'variant' => 'ghost', 'icon' => 'chevron-left']) ?>
  <h2 class="cel-month__title"><?= $e($fmtMonth($monthStart)) ?></h2>
  <?= $ui('button', ['label' => $t('celebrate.next'), 'href' => $url('/celebrate?m=' . $next), 'variant' => 'ghost', 'icon' => 'chevron-right']) ?>
</nav>
<?php if ($s['birthdays_enabled']): ?>
<section class="stack-sm" aria-labelledby="cb-h">
  <h2 id="cb-h" class="card__title"><?= $ui('icon', ['name' => 'gift']) ?> <?= $e($t('celebrate.birthdays', ['n' => (string) count($board['birthdays'])])) ?></h2>
  <?= $board['birthdays'] === [] ? '<p class="text-sm text-muted">' . $e($t('celebrate.none')) . '</p>' : $cards($board['birthdays'], 'birthday') ?>
</section>
<?php endif; ?>
<?php if ($s['anniversaries_enabled']): ?>
<section class="stack-sm" aria-labelledby="ca-h">
  <h2 id="ca-h" class="card__title"><?= $ui('icon', ['name' => 'briefcase']) ?> <?= $e($t('celebrate.anniversaries', ['n' => (string) count($board['anniversaries'])])) ?></h2>
  <?= $board['anniversaries'] === [] ? '<p class="text-sm text-muted">' . $e($t('celebrate.none')) . '</p>' : $cards($board['anniversaries'], 'anniversary') ?>
</section>
<?php endif; ?>
<p class="text-sm text-muted"><?= $ui('icon', ['name' => 'lock', 'size' => 'sm']) ?> <?= $e($t('celebrate.privacy_note')) ?> <a href="<?= $e($url('/celebrate/me')) ?>"><?= $e($t('celebrate.hide_mine')) ?></a></p>
<?php endif; ?>
