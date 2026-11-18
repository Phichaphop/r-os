<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, mixed>|null $me @var list<array{type: array<string, mixed>, b: array<string, mixed>}> $balances @var int $year
 * @var list<array<string, mixed>> $requests @var string $today @var bool $isManager @var bool $isHr @var bool $canManage
 */
use Blue\Core\I18n\DateFormat;
use Blue\Modules\Leave\LeaveService;

include __DIR__ . '/_status.php';
$loc = (string) ($v['locale'] ?? 'th');
$day = static fn (string $d): string => DateFormat::day($d, $loc);
$typeName = static fn (array $r): string => $loc === 'en' && ($r['type_en'] ?? $r['name_en'] ?? '') !== '' ? (string) ($r['type_en'] ?? $r['name_en']) : (string) ($r['type_th'] ?? $r['name_th']);
$beYear = $loc === 'th' ? $year + 543 : $year;
$actions = '';
if ($me !== null) {
    $actions .= $ui('button', ['label' => $t('leave.new'), 'href' => $url('/leave/new'), 'variant' => 'primary', 'icon' => 'plus']);
}
if ($isManager || $isHr) {
    $actions .= ' ' . $ui('button', ['label' => $t('leave.team_title'), 'href' => $url('/leave/team'), 'variant' => 'secondary', 'icon' => 'calendar-event']);
}
if ($canManage) {
    $actions .= ' ' . $ui('button', ['label' => $t('leave.admin_title'), 'href' => $url('/leave/admin'), 'variant' => 'ghost', 'icon' => 'settings']);
}
?>
<?= $ui('page-header', ['title' => $t('leave.title'), 'subtitle' => $t('leave.subtitle'), 'actions' => $actions]) ?>

<?php if ($me === null): ?>
  <?= $ui('alert', ['tone' => 'info', 'title' => $t('leave.no_employee_title'), 'text' => $t('leave.error.no_employee')]) ?>
<?php else: ?>
<section class="stack-sm" aria-labelledby="lb-h">
  <div class="row row--between">
    <h2 id="lb-h" class="hub-group__title"><?= $e($t('leave.balances', ['year' => (string) $beYear])) ?></h2>
    <nav class="row" aria-label="<?= $e($t('leave.year_nav')) ?>">
      <?= $ui('button', ['label' => $t('leave.prev_year'), 'href' => $url('/leave?year=' . ($year - 1)), 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'chevron-left']) ?>
      <?= $ui('button', ['label' => $t('leave.next_year'), 'href' => $url('/leave?year=' . ($year + 1)), 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'chevron-right']) ?>
    </nav>
  </div>
  <?php if ($balances === []): ?>
    <p class="text-sm text-muted"><?= $e($t('leave.no_types')) ?></p>
  <?php else: ?>
  <ul class="leave-cards" role="list">
    <?php foreach ($balances as ['type' => $ty, 'b' => $b]): ?>
    <li class="card leave-card">
      <div class="leave-card__head">
        <span class="leave-card__name"><?= $e($typeName($ty)) ?></span>
        <?php if ((int) $ty['is_statutory'] === 1): ?><?= $ui('badge', ['tone' => 'info', 'icon' => 'shield-check', 'label' => $t('leave.statutory')]) ?><?php endif; ?>
      </div>
      <?php if ($b['rule'] === null): ?>
        <div class="leave-card__value leave-card__value--muted"><?= $e($t('leave.no_policy_short')) ?></div>
        <div class="leave-card__meta"><?= $e($t('leave.no_policy_help')) ?></div>
      <?php elseif (!$b['tracked']): ?>
        <div class="leave-card__value leave-card__value--muted"><?= $e($t('leave.not_tracked')) ?></div>
        <div class="leave-card__meta"><?= $e($t('leave.used', ['n' => LeaveService::units($b['taken'])])) ?><?= $b['pending'] > 0 ? ' · ' . $e($t('leave.pending_n', ['n' => LeaveService::units($b['pending'])])) : '' ?></div>
      <?php else: ?>
        <div class="leave-card__value"><?= $e(LeaveService::units($b['available'])) ?> <span class="leave-card__unit"><?= $e($t('leave.days_left')) ?></span></div>
        <div class="leave-card__meta">
          <?= $e($t('leave.entitled', ['n' => LeaveService::units($b['granted'] + $b['opening'] + $b['carried'] + $b['adjusted'])])) ?> · <?= $e($t('leave.used', ['n' => LeaveService::units($b['taken'])])) ?>
          <?= $b['pending'] > 0 ? ' · ' . $e($t('leave.pending_n', ['n' => LeaveService::units($b['pending'])])) : '' ?>
        </div>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

<section class="card stack-sm" aria-labelledby="lr-h">
  <h2 id="lr-h" class="card__title"><?= $e($t('leave.my_requests')) ?></h2>
  <?php if ($requests === []): ?>
    <p class="text-sm text-muted"><?= $e($t('leave.no_requests')) ?></p>
  <?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="lr-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('leave.field.type')) ?></th><th scope="col"><?= $e($t('leave.field.dates')) ?></th><th scope="col"><?= $e($t('leave.field.units')) ?></th><th scope="col"><?= $e($t('leave.field.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($requests as $q): ?>
        <tr>
          <td data-label="<?= $e($t('leave.field.type')) ?>"><a href="<?= $e($url('/leave/r/' . $q['public_id'])) ?>"><?= $e($typeName($q)) ?></a></td>
          <td data-label="<?= $e($t('leave.field.dates')) ?>"><?= $e($q['start_date'] === $q['end_date'] ? $day((string) $q['start_date']) : $day((string) $q['start_date']) . ' – ' . $day((string) $q['end_date'])) ?><?= $q['part'] !== 'full' ? ' (' . $e($t('leave.part.' . $q['part'])) . ')' : '' ?></td>
          <td data-label="<?= $e($t('leave.field.units')) ?>"><?= $e($t('leave.days', ['n' => LeaveService::units((float) $q['units'])])) ?></td>
          <td data-label="<?= $e($t('leave.field.status')) ?>"><?= $leaveStatus((string) $q['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>
