<?php
/**
 * Executive dashboard: filters (company, month, site, department), one card per live KPI with status, time, previous
 * month, parts (to reconcile), 6-month trend, definition and source links; refresh, export, snapshot.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var list<array<string, mixed>> $cards @var array<string, mixed> $st @var string $qs @var array<string, string> $months (YYYY-MM => label)
 * @var array{sites: array<string, string>, departments: array<string, string>} $opts @var bool $canManage @var array<string, string> $errors
 */
include __DIR__ . '/_fmt.php';
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$hidden = $csrf;
foreach (['le' => $st['lePid'], 'site' => $st['site'], 'dept' => $st['dept'], 'month' => $st['month']] as $k => $val) {
    $hidden .= '<input type="hidden" name="' . $k . '" value="' . $e((string) $val) . '">';
}
$f = $st['f'];
$monthLabel = static fn (string $m): string => $months[$m] ?? $m;
$actions = $ui('button', ['label' => $t('dashboard.export'), 'href' => $url('/dashboard/export?' . $qs), 'variant' => 'secondary', 'icon' => 'download'])
    . $ui('button', ['label' => 'CSV', 'href' => $url('/dashboard/export?' . $qs . '&format=csv'), 'variant' => 'ghost', 'attrs' => ['aria-label' => $t('dashboard.export_csv')]])
    . $ui('button', ['label' => $t('dashboard.snapshots_title'), 'href' => $url('/dashboard/snapshots?le=' . rawurlencode($st['lePid'])), 'variant' => 'ghost', 'icon' => 'history'])
    . ($canManage ? $ui('button', ['label' => $t('dashboard.kpis_title'), 'href' => $url('/dashboard/kpis?le=' . rawurlencode($st['lePid'])), 'variant' => 'ghost', 'icon' => 'list-check']) : '');
?>
<?= $ui('page-header', ['title' => $t('dashboard.title'), 'subtitle' => $t('dashboard.subtitle'), 'actions' => $actions]) ?>
<?php if (count($st['companies']) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('dashboard.pick_company')) ?>">
  <?php foreach ($st['companies'] as $pid => $code): ?><a href="<?= $e($url('/dashboard?le=' . rawurlencode($pid))) ?>"<?= $pid === $st['lePid'] ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<form method="get" action="<?= $e($url('/dashboard')) ?>" class="card dash-filter" aria-label="<?= $e($t('dashboard.filters')) ?>">
  <input type="hidden" name="le" value="<?= $e($st['lePid']) ?>">
  <?php $mopts = array_slice($months, 0, 13, true); ?>
  <?= $ui('field', ['name' => 'month', 'type' => 'select', 'label' => $t('dashboard.field.month'), 'value' => $st['month'], 'options' => $mopts]) ?>
  <?= $ui('field', ['name' => 'site', 'type' => 'select', 'label' => $t('dashboard.field.site'), 'value' => $st['site'], 'options' => ['' => $t($f['whole'] ? 'dashboard.all' : 'dashboard.all_mine')] + $opts['sites']]) ?>
  <?= $ui('field', ['name' => 'dept', 'type' => 'select', 'label' => $t('dashboard.field.dept'), 'value' => $st['dept'], 'options' => ['' => $t($f['whole'] ? 'dashboard.all' : 'dashboard.all_mine')] + $opts['departments']]) ?>
  <div class="dash-filter__go"><?= $ui('button', ['label' => $t('dashboard.show'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'filter']) ?></div>
</form>
<?php if (!$f['whole']): ?><?= $ui('alert', ['tone' => 'info', 'text' => $t('dashboard.scope_note')]) ?><?php endif; ?>
<?php if ($cards === []): ?>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t($canManage ? 'dashboard.no_kpis_manage' : 'dashboard.no_kpis')]) ?>
<?php else: ?>
<ul class="dash-grid" aria-label="<?= $e($t('dashboard.cards')) ?>">
  <?php foreach ($cards as $c): $k = $c['k']; $val = $c['v']; $unit = (string) ($c['m']['unit'] ?? 'count');
      $lvl = $c['level']; $diff = $c['prev'] !== null && $val['value'] !== null ? (float) $val['value'] - (float) $c['prev'] : null; ?>
  <li class="card dash-card<?= $lvl !== null ? ' is-' . $e($lvl) : '' ?>">
    <div class="dash-card__head">
      <h2 class="dash-card__title"><?= $e((string) $k['name_th']) ?></h2>
      <?= $ui('badge', ['tone' => $statusTone((string) $val['status']), 'label' => $t('dashboard.status.' . $val['status'])]) ?>
    </div>
    <p class="dash-card__value"><?= $e($fmt($val['value'], $unit)) ?></p>
    <?php if ($lvl !== null): ?><p class="dash-card__level"><?= $ui('icon', ['name' => 'alert-triangle', 'size' => 'sm']) ?> <?= $e($t('dashboard.level.' . $lvl)) ?></p><?php endif; ?>
    <p class="text-sm text-muted"><?= $e($c['point'] ? $t('dashboard.as_of_now') : $t('dashboard.range', ['from' => $date((string) $c['from'], false), 'to' => $date((string) $c['to'], false)])) ?></p>
    <?php if ($diff !== null): ?><p class="text-sm"><?= $e($t('dashboard.vs_prev', ['v' => $fmt($c['prev'], $unit), 'd' => ($diff > 0 ? '+' : '') . $fmt($diff, $unit === 'percent' ? 'percent' : 'count')])) ?></p><?php endif; ?>
    <?php if ($val['parts'] !== []): ?><p class="text-sm text-muted"><?= $e(implode(' · ', array_map(static fn (string $pk, mixed $pv): string => $t($pk) . ' ' . $fmt($pv, 'count'), array_keys($val['parts']), $val['parts']))) ?></p><?php endif; ?>
    <?php if ($val['note'] !== null): ?><p class="text-sm"><?= $e($t((string) $val['note'])) ?></p><?php endif; ?>
    <?php if (count($c['trend']) > 1): $max = max(array_map(static fn (array $p): float => abs((float) ($p['value'] ?? 0)), $c['trend'])) ?: 1.0;
        $label = $t('dashboard.trend') . ': ' . implode(', ', array_map(static fn (array $p): string => $monthLabel($p['month']) . ' ' . $fmt($p['value'], $unit), $c['trend'])); ?>
      <svg class="dash-spark" viewBox="0 0 120 32" role="img" aria-label="<?= $e($label) ?>" focusable="false">
        <?php foreach ($c['trend'] as $i => $p): $h = $p['value'] === null ? 0 : max(1, round(abs((float) $p['value']) / $max * 30, 1)); ?><rect class="dash-spark__bar<?= $i === count($c['trend']) - 1 ? ' is-now' : '' ?>" x="<?= $i * 20 + 2 ?>" y="<?= 31 - $h ?>" width="16" height="<?= $h ?>" rx="2"></rect><?php endforeach; ?>
      </svg>
    <?php endif; ?>
    <p class="dash-card__foot text-sm text-muted">
      <?= $val['computed_at'] !== null ? $e($t('dashboard.updated', ['t' => $date((string) $val['computed_at'])])) . ' · ' : '' ?><?= $e($t('dashboard.owner_is', ['name' => (string) ($k['owner_name'] ?? '—')])) ?> · v<?= (int) $k['version'] ?>
    </p>
    <p class="dash-card__links text-sm"><a href="<?= $e($url('/dashboard/k/' . $k['public_id'])) ?>"><?= $e($t('dashboard.definition')) ?></a><?php if ($c['drill'] !== null): ?> · <a href="<?= $e($url($c['drill'])) ?>"><?= $e($t('dashboard.drill')) ?></a><?php endif; ?></p>
  </li>
  <?php endforeach; ?>
</ul>
<p class="text-sm text-muted"><?= $e($t('dashboard.footnote')) ?></p>
<div class="grid grid--2">
  <form method="post" action="<?= $e($url('/dashboard/refresh')) ?>" class="card stack-sm"><?= $hidden ?>
    <h2 class="card__title"><?= $e($t('dashboard.refresh_title')) ?></h2>
    <p class="text-sm text-muted"><?= $e($t('dashboard.refresh_help')) ?></p>
    <div class="row"><?= $ui('button', ['label' => $t('dashboard.refresh'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'refresh']) ?></div>
  </form>
  <form method="post" action="<?= $e($url('/dashboard/snapshots')) ?>" class="card stack-sm" novalidate><?= $hidden ?>
    <h2 class="card__title"><?= $e($t('dashboard.snapshot_title')) ?></h2>
    <?= $ui('field', ['name' => 'note', 'label' => $t('dashboard.field.note'), 'required' => true, 'help' => $t('dashboard.snapshot_help'), 'error' => $errors['note'] ?? null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('dashboard.snapshot'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'database']) ?></div>
  </form>
</div>
<?php endif; ?>
