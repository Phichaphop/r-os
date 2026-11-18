<?php
/**
 * A KPI's definition as the dictionary keeps it: measure, formula, source, unit, filters it honours, owner, refresh,
 * thresholds, and the version history.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $k @var array<string, mixed>|null $m @var list<array<string, mixed>> $versions @var string $lePid
 */
include __DIR__ . '/_fmt.php';
$unit = (string) ($m['unit'] ?? 'count');
?>
<?= $ui('page-header', ['title' => (string) $k['name_th'], 'subtitle' => $k['code'] . ' · ' . $k['name_en'], 'crumbs' => [[$t('dashboard.title'), $url('/dashboard?le=' . rawurlencode($lePid))], [(string) $k['code'], null]]]) ?>
<section class="card stack-sm" aria-labelledby="dd-d">
  <h2 id="dd-d" class="card__title"><?= $e($t('dashboard.definition')) ?> · v<?= (int) $k['version'] ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('dashboard.field.status')) ?></dt><dd><?= $ui('badge', ['tone' => $k['status'] === 'active' ? 'success' : 'neutral', 'label' => $t('dashboard.kpi_status.' . $k['status'])]) ?></dd></div>
    <?php if ($k['purpose'] !== null): ?><div><dt><?= $e($t('dashboard.field.purpose')) ?></dt><dd class="pre-line"><?= $e((string) $k['purpose']) ?></dd></div><?php endif; ?>
    <div><dt><?= $e($t('dashboard.col.formula')) ?></dt><dd><?= $e($m !== null ? $t((string) $m['formula']) : $t('dashboard.note.no_measure')) ?></dd></div>
    <div><dt><?= $e($t('dashboard.field.source')) ?></dt><dd><?= $e($m !== null ? $t((string) $m['source']) : '—') ?> <code class="text-sm"><?= $e((string) $k['measure']) ?></code></dd></div>
    <div><dt><?= $e($t('dashboard.col.unit')) ?></dt><dd><?= $e($t('dashboard.unit.' . $unit)) ?><?= !empty($m['point']) ? ' · ' . $e($t('dashboard.as_of_now')) : '' ?></dd></div>
    <div><dt><?= $e($t('dashboard.field.dims')) ?></dt><dd><?= $e($m !== null && $m['dims'] !== [] ? implode(', ', array_map(static fn (string $d): string => $t('dashboard.dim.' . $d), (array) $m['dims'])) : $t('dashboard.dim.none')) ?></dd></div>
    <div><dt><?= $e($t('dashboard.field.owner')) ?></dt><dd><?= $e((string) ($k['owner_name'] ?? '—')) ?></dd></div>
    <div><dt><?= $e($t('dashboard.field.refresh_minutes')) ?></dt><dd><?= $e($t('dashboard.refresh.' . (int) $k['refresh_minutes'])) ?></dd></div>
    <div><dt><?= $e($t('dashboard.field.direction')) ?></dt><dd><?= $e($t('dashboard.direction.' . $k['direction'])) ?></dd></div>
    <div><dt><?= $e($t('dashboard.field.thresholds')) ?></dt><dd><?= $e($k['warn_at'] === null && $k['alert_at'] === null ? $t('dashboard.no_thresholds') : $t('dashboard.thresholds_are', ['warn' => $fmt($k['warn_at'], $unit), 'alert' => $fmt($k['alert_at'], $unit)])) ?></dd></div>
  </dl>
</section>
<section class="card stack-sm" aria-labelledby="dd-v">
  <h2 id="dd-v" class="card__title"><?= $e($t('dashboard.versions')) ?></h2>
  <ul class="plain-list stack-sm">
    <?php foreach ($versions as $ver): $d = (array) json_decode((string) $ver['definition_json'], true); ?>
      <li><strong>v<?= (int) $ver['version'] ?></strong> · <?= $e((string) ($ver['actor'] ?? '—')) ?> <span class="text-sm text-muted"><?= $e($date((string) $ver['changed_at'])) ?></span>
        <span class="d-block text-sm"><?= $e(($d['name_th'] ?? '') . ($ver['reason'] !== null ? ' · ' . $ver['reason'] : '')) ?></span></li>
    <?php endforeach; ?>
  </ul>
</section>
