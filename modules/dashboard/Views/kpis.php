<?php
/**
 * KPI dictionary of a company: the list, and the form to add / edit (a live KPI changes by new version with a reason).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $rows @var array<string, mixed>|null $edit
 * @var array<string, array<string, mixed>> $catalog @var array<string, string> $measures @var list<array<string, mixed>> $versions
 * @var array<string, string> $errors @var array<string, mixed> $old
 */
$num = static fn (mixed $n): string => $n === null || $n === '' ? '' : rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
$cur = $old !== [] ? $old : ($edit !== null ? ['code' => $edit['code'], 'measure' => $edit['measure'], 'name_th' => $edit['name_th'], 'name_en' => $edit['name_en'], 'purpose' => $edit['purpose'],
    'direction' => $edit['direction'], 'warn_at' => $num($edit['warn_at']), 'alert_at' => $num($edit['alert_at']), 'refresh_minutes' => (string) $edit['refresh_minutes'],
    'owner' => $edit['owner_username'], 'status' => $edit['status'], 'sort_order' => (string) $edit['sort_order']] : ['refresh_minutes' => '60', 'status' => 'draft', 'direction' => 'none', 'sort_order' => '100']);
$val = static fn (string $k, string $d = ''): string => is_scalar($cur[$k] ?? null) ? (string) $cur[$k] : $d;
$opt = static function (string $prefix, array $keys) use ($t): array {
    $o = [];
    foreach ($keys as $k) {
        $o[(string) $k] = $t($prefix . $k);
    }
    return $o;
};
?>
<?= $ui('page-header', ['title' => $t('dashboard.kpis_title'), 'subtitle' => $t('dashboard.kpis_subtitle'), 'crumbs' => [[$t('dashboard.title'), $url('/dashboard?le=' . rawurlencode($lePid))], [$t('dashboard.kpis_title'), null]]]) ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('dashboard.pick_company')) ?>">
  <?php foreach ($companies as $pid => $code): ?><a href="<?= $e($url('/dashboard/kpis?le=' . rawurlencode($pid))) ?>"<?= $pid === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<section class="card stack-sm" aria-labelledby="dk-l">
  <h2 id="dk-l" class="card__title"><?= $e($t('dashboard.kpis')) ?></h2>
  <?php if ($rows === []): ?><p class="text-sm text-muted"><?= $e($t('dashboard.none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="dk-l" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('dashboard.col.kpi')) ?></th><th scope="col"><?= $e($t('dashboard.col.measure')) ?></th><th scope="col"><?= $e($t('dashboard.field.owner')) ?></th><th scope="col"><?= $e($t('dashboard.col.version')) ?></th><th scope="col"><?= $e($t('dashboard.field.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $x): ?>
        <tr><th scope="row" data-label="<?= $e($t('dashboard.col.kpi')) ?>"><a href="<?= $e($url('/dashboard/kpis/' . $x['public_id'])) ?>"><?= $e($x['code'] . ' · ' . $x['name_th']) ?></a></th>
          <td data-label="<?= $e($t('dashboard.col.measure')) ?>"><?= $e($measures[(string) $x['measure']] ?? (string) $x['measure']) ?></td>
          <td data-label="<?= $e($t('dashboard.field.owner')) ?>"><?= $e((string) ($x['owner_name'] ?? '—')) ?></td>
          <td data-label="<?= $e($t('dashboard.col.version')) ?>">v<?= (int) $x['version'] ?></td>
          <td data-label="<?= $e($t('dashboard.field.status')) ?>"><?= $ui('badge', ['tone' => $x['status'] === 'active' ? 'success' : ($x['status'] === 'draft' ? 'info' : 'neutral'), 'label' => $t('dashboard.kpi_status.' . $x['status'])]) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<section class="card stack" aria-labelledby="dk-f">
  <h2 id="dk-f" class="card__title"><?= $e($edit !== null ? $t('dashboard.kpi_edit', ['code' => (string) $edit['code'], 'v' => (string) $edit['version']]) : $t('dashboard.kpi_new')) ?></h2>
  <?php if ($edit !== null && $edit['status'] !== 'draft'): ?><?= $ui('alert', ['tone' => 'info', 'text' => $t('dashboard.new_version_note')]) ?><?php endif; ?>
  <form method="post" action="<?= $e($url($edit !== null ? '/dashboard/kpis/' . $edit['public_id'] : '/dashboard/kpis')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>"><input type="hidden" name="le" value="<?= $e($lePid) ?>">
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'code', 'label' => $t('dashboard.field.code'), 'required' => true, 'value' => $val('code'), 'placeholder' => 'HEADCOUNT', 'error' => $errors['code'] ?? null]) ?>
      <?= $ui('field', ['name' => 'name_th', 'label' => $t('dashboard.field.name_th'), 'required' => true, 'value' => $val('name_th'), 'error' => $errors['name_th'] ?? null]) ?>
      <?= $ui('field', ['name' => 'name_en', 'label' => $t('dashboard.field.name_en'), 'required' => true, 'value' => $val('name_en'), 'error' => $errors['name_en'] ?? null]) ?>
    </div>
    <?= $ui('field', ['name' => 'measure', 'type' => 'select', 'label' => $t('dashboard.field.measure'), 'required' => true, 'value' => $val('measure'), 'options' => ['' => '—'] + $measures, 'help' => $t('dashboard.measure_help'), 'error' => $errors['measure'] ?? null]) ?>
    <?php if (isset($catalog[$val('measure')])): $mm = $catalog[$val('measure')]; ?>
      <p class="text-sm"><strong><?= $e($t('dashboard.col.formula')) ?>:</strong> <?= $e($t((string) $mm['formula'])) ?></p>
    <?php endif; ?>
    <details class="text-sm"><summary><?= $e($t('dashboard.all_formulas')) ?></summary>
      <dl class="facts"><?php foreach ($catalog as $code => $mm): ?><div><dt><?= $e($t((string) $mm['label'])) ?></dt><dd><?= $e($t((string) $mm['formula'])) ?> · <?= $e($t('dashboard.unit.' . $mm['unit'])) ?></dd></div><?php endforeach; ?></dl>
    </details>
    <?= $ui('field', ['name' => 'purpose', 'type' => 'textarea', 'label' => $t('dashboard.field.purpose'), 'value' => $val('purpose')]) ?>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'owner', 'label' => $t('dashboard.field.owner_username'), 'value' => $val('owner'), 'autocomplete' => 'off', 'help' => $t('dashboard.owner_help'), 'error' => $errors['owner'] ?? null]) ?>
      <?= $ui('field', ['name' => 'refresh_minutes', 'type' => 'select', 'label' => $t('dashboard.field.refresh_minutes'), 'value' => $val('refresh_minutes', '60'), 'options' => $opt('dashboard.refresh.', \Blue\Modules\Dashboard\DashboardService::REFRESH), 'error' => $errors['refresh_minutes'] ?? null]) ?>
      <?= $ui('field', ['name' => 'status', 'type' => 'select', 'label' => $t('dashboard.field.status'), 'value' => $val('status', 'draft'), 'options' => $opt('dashboard.kpi_status.', ['draft', 'active', 'retired'])]) ?>
      <?= $ui('field', ['name' => 'direction', 'type' => 'select', 'label' => $t('dashboard.field.direction'), 'value' => $val('direction', 'none'), 'options' => $opt('dashboard.direction.', ['none', 'up', 'down']), 'error' => $errors['direction'] ?? null]) ?>
      <?= $ui('field', ['name' => 'warn_at', 'label' => $t('dashboard.field.warn_at'), 'inputmode' => 'decimal', 'value' => $val('warn_at'), 'error' => $errors['warn_at'] ?? null]) ?>
      <?= $ui('field', ['name' => 'alert_at', 'label' => $t('dashboard.field.alert_at'), 'inputmode' => 'decimal', 'value' => $val('alert_at'), 'help' => $t('dashboard.thresholds_help'), 'error' => $errors['alert_at'] ?? null]) ?>
      <?= $ui('field', ['name' => 'sort_order', 'label' => $t('dashboard.field.sort_order'), 'inputmode' => 'numeric', 'value' => $val('sort_order', '100')]) ?>
    </div>
    <?php if ($edit !== null && $edit['status'] !== 'draft'): ?>
      <?= $ui('field', ['name' => 'reason', 'label' => $t('dashboard.field.reason'), 'required' => true, 'value' => $val('reason'), 'error' => $errors['reason'] ?? null]) ?>
    <?php endif; ?>
    <div class="row"><?= $ui('button', ['label' => $t('dashboard.save'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
  </form>
</section>
<?php if ($versions !== []): ?>
<section class="card stack-sm" aria-labelledby="dk-v">
  <h2 id="dk-v" class="card__title"><?= $e($t('dashboard.versions')) ?></h2>
  <ul class="plain-list stack-sm">
    <?php foreach ($versions as $ver): ?><li><strong>v<?= (int) $ver['version'] ?></strong> · <?= $e((string) ($ver['actor'] ?? '—')) ?> <span class="text-sm text-muted"><?= $e($date((string) $ver['changed_at'])) ?></span><?= $ver['reason'] !== null ? '<span class="d-block text-sm">' . $e((string) $ver['reason']) . '</span>' : '' ?></li><?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
