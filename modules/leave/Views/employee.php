<?php
/**
 * HR view of one employee's leave: balances of a year, the ledger behind them, corrections, requests.
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $emp @var int $year @var list<array<string, mixed>> $types @var list<array{type: array<string, mixed>, b: array<string, mixed>}> $balances
 * @var list<array<string, mixed>> $ledger @var list<array<string, mixed>> $requests @var array<string, string> $errors @var array<string, string> $old
 */
use Blue\Core\I18n\DateFormat;
use Blue\Modules\Leave\LeaveService;

include __DIR__ . '/_status.php';
$loc = (string) ($v['locale'] ?? 'th');
$day = static fn (string $d): string => DateFormat::day($d, $loc);
$name = static fn (array $r): string => $loc === 'en' && ($r['name_en'] ?? $r['type_en'] ?? '') !== '' ? (string) ($r['name_en'] ?? $r['type_en']) : (string) ($r['name_th'] ?? $r['type_th']);
$u = static fn (float $x): string => LeaveService::units($x);
$beYear = $loc === 'th' ? $year + 543 : $year;
$typeOptions = ['' => '—'];
foreach ($types as $ty) {
    $typeOptions[(string) $ty['public_id']] = $name($ty);
}
?>
<?= $ui('page-header', ['title' => (string) $title, 'subtitle' => $emp['employee_code'] . ' · ' . $t('leave.balances', ['year' => (string) $beYear]),
    'actions' => $ui('button', ['label' => $t('leave.admin_title'), 'href' => $url('/leave/admin#balances'), 'variant' => 'ghost', 'icon' => 'arrow-back-up'])]) ?>
<?php if (isset($errors['_form'])): ?><?= $ui('alert', ['tone' => 'danger', 'text' => $errors['_form']]) ?><?php endif; ?>
<nav class="row" aria-label="<?= $e($t('leave.year_nav')) ?>">
  <?= $ui('button', ['label' => $t('leave.prev_year'), 'href' => $url('/leave/admin/employee/' . $emp['public_id'] . '?year=' . ($year - 1)), 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'chevron-left']) ?>
  <?= $ui('button', ['label' => $t('leave.next_year'), 'href' => $url('/leave/admin/employee/' . $emp['public_id'] . '?year=' . ($year + 1)), 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'chevron-right']) ?>
</nav>
<section class="card stack-sm" aria-labelledby="eb-h">
  <h2 id="eb-h" class="card__title"><?= $e($t('leave.balances', ['year' => (string) $beYear])) ?></h2>
  <div class="table-wrap" role="region" aria-labelledby="eb-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('leave.field.type')) ?></th><th scope="col"><?= $e($t('leave.col.granted')) ?></th><th scope="col"><?= $e($t('leave.col.opening')) ?></th>
        <th scope="col"><?= $e($t('leave.col.carried')) ?></th><th scope="col"><?= $e($t('leave.col.adjusted')) ?></th><th scope="col"><?= $e($t('leave.col.taken')) ?></th>
        <th scope="col"><?= $e($t('leave.col.pending')) ?></th><th scope="col"><?= $e($t('leave.col.available')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($balances as ['type' => $ty, 'b' => $b]): ?>
        <tr>
          <td data-label="<?= $e($t('leave.field.type')) ?>"><?= $e($name($ty)) ?><?= $b['rule'] === null ? ' · <span class="text-muted">' . $e($t('leave.no_policy_short')) . '</span>' : '' ?></td>
          <td data-label="<?= $e($t('leave.col.granted')) ?>"><?= $e($b['tracked'] ? $u($b['granted']) : '—') ?></td>
          <td data-label="<?= $e($t('leave.col.opening')) ?>"><?= $e($u($b['opening'])) ?></td>
          <td data-label="<?= $e($t('leave.col.carried')) ?>"><?= $e($u($b['carried'])) ?></td>
          <td data-label="<?= $e($t('leave.col.adjusted')) ?>"><?= $e($u($b['adjusted'])) ?></td>
          <td data-label="<?= $e($t('leave.col.taken')) ?>"><?= $e($u($b['taken'])) ?></td>
          <td data-label="<?= $e($t('leave.col.pending')) ?>"><?= $e($u($b['pending'])) ?></td>
          <td data-label="<?= $e($t('leave.col.available')) ?>"><strong><?= $e($b['tracked'] ? $u($b['available']) : $t('leave.not_tracked')) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="card stack-sm" aria-labelledby="ea-h">
  <h2 id="ea-h" class="card__title"><?= $e($t('leave.adjust')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('leave.adjust_help')) ?></p>
  <form method="post" action="<?= $e($url('/leave/admin/employee/' . $emp['public_id'] . '/adjust')) ?>" class="stack-sm" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>"><input type="hidden" name="year" value="<?= $year ?>">
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'type', 'type' => 'select', 'label' => $t('leave.field.type'), 'required' => true, 'value' => (string) ($old['type'] ?? ''), 'options' => $typeOptions, 'error' => $errors['type'] ?? null]) ?>
      <?= $ui('field', ['name' => 'units', 'label' => $t('leave.field.adjust_units'), 'required' => true, 'value' => (string) ($old['units'] ?? ''), 'inputmode' => 'decimal', 'error' => $errors['units'] ?? null, 'help' => $t('leave.adjust_units_help')]) ?>
      <?= $ui('field', ['name' => 'reason', 'label' => $t('leave.field.reason'), 'required' => true, 'value' => (string) ($old['reason'] ?? ''), 'error' => $errors['reason'] ?? null]) ?>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('leave.adjust_submit'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'pencil']) ?></div>
  </form>
</section>

<section class="card stack-sm" aria-labelledby="el-h">
  <h2 id="el-h" class="card__title"><?= $e($t('leave.ledger')) ?></h2>
  <?php if ($ledger === []): ?><p class="text-sm text-muted"><?= $e($t('leave.ledger_empty')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="el-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('leave.field.type')) ?></th><th scope="col"><?= $e($t('leave.col.entry')) ?></th><th scope="col"><?= $e($t('leave.col.date')) ?></th>
        <th scope="col"><?= $e($t('leave.field.units')) ?></th><th scope="col"><?= $e($t('leave.col.note')) ?></th><th scope="col"><?= $e($t('leave.col.recorded')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($ledger as $l): ?>
        <tr>
          <td data-label="<?= $e($t('leave.field.type')) ?>"><?= $e($name($l)) ?></td>
          <td data-label="<?= $e($t('leave.col.entry')) ?>"><?= $e($t('leave.entry.' . $l['entry_type'])) ?><?= $l['statutory_units'] !== null ? ' <span class="text-sm text-muted">(' . $e($t('leave.statutory_share', ['n' => $u((float) $l['statutory_units'])])) . ')</span>' : '' ?></td>
          <td data-label="<?= $e($t('leave.col.date')) ?>"><?= $e($day((string) $l['entry_date'])) ?></td>
          <td data-label="<?= $e($t('leave.field.units')) ?>"><?= (float) $l['units'] > 0 ? '+' : '' ?><?= $e($u((float) $l['units'])) ?></td>
          <td data-label="<?= $e($t('leave.col.note')) ?>"><?php if ($l['source_type'] === 'lv_request'): ?><a href="<?= $e($url('/leave/r/' . $l['source_id'])) ?>"><?= $e($t('leave.open_request')) ?></a><?php else: ?><?= $e((string) ($l['note'] ?? '—')) ?><?php endif; ?></td>
          <td data-label="<?= $e($t('leave.col.recorded')) ?>"><?= $e($date((string) $l['created_at'])) ?><?= ($l['actor_name'] ?? null) !== null ? ' · ' . $e((string) $l['actor_name']) : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<section class="card stack-sm" aria-labelledby="er-h">
  <h2 id="er-h" class="card__title"><?= $e($t('leave.requests_of')) ?></h2>
  <?php if ($requests === []): ?><p class="text-sm text-muted"><?= $e($t('leave.no_requests')) ?></p><?php else: ?>
  <ul class="stack-sm" role="list">
    <?php foreach ($requests as $q): ?>
      <li><a href="<?= $e($url('/leave/r/' . $q['public_id'])) ?>"><?= $e($name($q)) ?> · <?= $e($q['start_date'] === $q['end_date'] ? $day((string) $q['start_date']) : $day((string) $q['start_date']) . ' – ' . $day((string) $q['end_date'])) ?> · <?= $e($t('leave.days', ['n' => $u((float) $q['units'])])) ?></a> <?= $leaveStatus((string) $q['status']) ?></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
