<?php
/**
 * Manpower plans of a company: this year's active plan in short, headcount history, plans and versions, new plan, setting.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $plans @var array<string, mixed>|null $active
 * @var array<string, float|int>|null $summary @var array<string, int> $history @var array{over_budget: string} $settings @var bool $canManage @var int $year
 * @var array<string, string> $errors @var array<string, mixed> $old
 */
$o = static fn (string $k, string $d = ''): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$max = $history !== [] ? max($history) : 0;
$num = static fn (float|int $n): string => rtrim(rtrim(number_format((float) $n, 1), '0'), '.');
?>
<?= $ui('page-header', ['title' => $t('manpower.title'), 'subtitle' => $t('manpower.subtitle')]) ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('manpower.pick_company')) ?>">
  <?php foreach ($companies as $pid => $code): ?><a href="<?= $e($url('/manpower?le=' . rawurlencode($pid))) ?>"<?= $pid === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<section class="card stack-sm" aria-labelledby="mi-a">
  <h2 id="mi-a" class="card__title"><?= $e($t('manpower.this_year', ['year' => (string) $year])) ?></h2>
  <?php if ($active === null || $summary === null): ?>
    <p class="text-sm text-muted"><?= $e($t('manpower.no_active')) ?></p>
  <?php else: ?>
    <ul class="funnel">
      <li><span class="text-sm text-muted"><?= $e($t('manpower.col.planned')) ?></span><strong><?= $e($num($summary['planned'])) ?></strong></li>
      <li><span class="text-sm text-muted"><?= $e($t('manpower.col.actual')) ?></span><strong><?= (int) $summary['actual'] ?></strong></li>
      <li><span class="text-sm text-muted"><?= $e($t('manpower.col.joining')) ?></span><strong><?= (int) $summary['joining'] ?></strong></li>
      <li><span class="text-sm text-muted"><?= $e($t('manpower.col.open')) ?></span><strong><?= (int) $summary['open'] ?></strong></li>
      <li><span class="text-sm text-muted"><?= $e($t('manpower.col.cost')) ?></span><strong><?= $e(number_format((float) $summary['cost'], 0)) ?></strong></li>
    </ul>
    <p><?= $ui('button', ['label' => $t('manpower.open_plan', ['n' => (string) $active['version_no']]), 'href' => $url('/manpower/p/' . $active['public_id']), 'variant' => 'secondary', 'icon' => 'arrow-right']) ?></p>
  <?php endif; ?>
</section>
<section class="card stack-sm" aria-labelledby="mi-h">
  <h2 id="mi-h" class="card__title"><?= $e($t('manpower.history')) ?></h2>
  <?php if ($history === []): ?><p class="text-sm text-muted"><?= $e($t('manpower.no_history')) ?></p><?php else: ?>
  <table class="table mp-history">
    <caption class="sr-only"><?= $e($t('manpower.history')) ?></caption>
    <thead><tr><th scope="col"><?= $e($t('manpower.col.month')) ?></th><th scope="col"><?= $e($t('manpower.col.actual')) ?></th><th scope="col"><span class="sr-only"><?= $e($t('manpower.chart')) ?></span></th></tr></thead>
    <tbody>
    <?php foreach ($history as $m => $n): ?>
      <tr><th scope="row"><?= $e($m) ?></th><td><?= (int) $n ?></td><td><meter class="mp-meter" min="0" max="<?= (int) max(1, $max) ?>" value="<?= (int) $n ?>" aria-label="<?= $e($m . ': ' . $n) ?>"><?= (int) $n ?></meter></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
<section class="card stack-sm" aria-labelledby="mi-p">
  <h2 id="mi-p" class="card__title"><?= $e($t('manpower.plans')) ?></h2>
  <?php if ($plans === []): ?><p class="text-sm text-muted"><?= $e($t('manpower.no_plans')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="mi-p" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('manpower.field.year')) ?></th><th scope="col"><?= $e($t('manpower.field.title')) ?></th><th scope="col"><?= $e($t('manpower.col.status')) ?></th><th scope="col"><?= $e($t('manpower.col.planned')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($plans as $p): ?>
        <tr><th scope="row" data-label="<?= $e($t('manpower.field.year')) ?>"><?= (int) $p['fiscal_year'] ?> · <?= $e($t('manpower.version_n', ['n' => (string) $p['version_no']])) ?></th>
          <td data-label="<?= $e($t('manpower.field.title')) ?>"><a href="<?= $e($url('/manpower/p/' . $p['public_id'])) ?>"><?= $e((string) $p['title']) ?></a></td>
          <td data-label="<?= $e($t('manpower.col.status')) ?>"><?= $ui('badge', ['tone' => $p['status'] === 'active' ? 'success' : (in_array($p['status'], ['draft', 'review'], true) ? 'info' : 'neutral'), 'label' => $t('manpower.status.' . $p['status'])]) ?></td>
          <td data-label="<?= $e($t('manpower.col.planned')) ?>"><?= $e($num((float) $p['heads'])) ?> · <?= $e($t('manpower.lines_n', ['n' => (string) $p['line_count']])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php if ($canManage): ?>
<div class="grid grid--2">
<section class="card stack" aria-labelledby="mi-n">
  <h2 id="mi-n" class="card__title"><?= $e($t('manpower.new_plan')) ?></h2>
  <form method="post" action="<?= $e($url('/manpower')) ?>" class="stack" novalidate><?= $csrf ?>
    <input type="hidden" name="le" value="<?= $e($lePid) ?>">
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'fiscal_year', 'type' => 'select', 'label' => $t('manpower.field.year'), 'value' => $o('fiscal_year', (string) $year), 'options' => [(string) ($year - 1) => (string) ($year - 1), (string) $year => (string) $year, (string) ($year + 1) => (string) ($year + 1), (string) ($year + 2) => (string) ($year + 2)], 'error' => $errors['fiscal_year'] ?? null]) ?>
      <?= $ui('field', ['name' => 'title', 'label' => $t('manpower.field.title'), 'required' => true, 'value' => $o('title'), 'error' => $errors['title'] ?? null]) ?>
    </div>
    <?= $ui('field', ['name' => 'assumptions', 'type' => 'textarea', 'label' => $t('manpower.field.assumptions'), 'value' => $o('assumptions'), 'help' => $t('manpower.assumptions_help')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('manpower.create'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></div>
  </form>
</section>
<section class="card stack" aria-labelledby="mi-s">
  <h2 id="mi-s" class="card__title"><?= $e($t('manpower.setting')) ?></h2>
  <form method="post" action="<?= $e($url('/manpower/settings')) ?>" class="stack" novalidate><?= $csrf ?>
    <input type="hidden" name="le" value="<?= $e($lePid) ?>">
    <?= $ui('field', ['name' => 'over_budget', 'type' => 'select', 'label' => $t('manpower.field.over_budget'), 'value' => $settings['over_budget'], 'options' => ['warn' => $t('manpower.mode.warn'), 'block' => $t('manpower.mode.block')], 'help' => $t('manpower.over_budget_help')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('manpower.save'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
  </form>
</section>
</div>
<?php endif; ?>
