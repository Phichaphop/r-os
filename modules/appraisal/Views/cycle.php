<?php
/**
 * HR: one cycle — details (editable while draft), open / close, progress by status, average by unit, rating spread,
 * open appeals, the people (with evaluator) and export.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $c @var list<array<string, mixed>> $roster @var array{status: array<string, int>, units: list<array{unit: string, people: int, avg: float|null}>, bands: array<string, int>, appeals: int} $sum
 * @var string $lePid @var array<string, string> $formOptions @var array{sites: array<string, string>, departments: array<string, string>} $audOptions
 * @var array{sites?: list<string>, departments?: list<string>, groups?: string} $audIn @var string $audText @var array<string, string> $errors @var array<string, mixed> $old
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$draft = $c['status'] === 'draft';
$cur = ['code' => $c['code'], 'name' => $c['name'], 'period' => $c['period'], 'form' => $c['form_pid'], 'self_due' => $c['self_due'], 'manager_due' => $c['manager_due'],
    'final_due' => $c['final_due'], 'results_on' => $c['results_on'], 'final_review' => (string) $c['final_review']];
$cv = static fn (string $k, string $d = ''): string => $old !== [] ? (is_scalar($old[$k] ?? null) ? (string) $old[$k] : '') : (string) ($cur[$k] ?? $d);
if ($old !== []) {
    $audIn = ['sites' => (array) ($old['sites'] ?? []), 'departments' => (array) ($old['departments'] ?? []), 'groups' => (string) ($old['groups'] ?? '')];
}
$num = static fn (mixed $n): string => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
$total = array_sum($sum['bands']);
?>
<?= $ui('page-header', ['title' => $c['code'] . ' · ' . $c['name'], 'subtitle' => $t('appraisal.cycle_status.' . $c['status']),
    'crumbs' => [[$t('appraisal.admin_title'), $url('/appraisal/admin?le=' . rawurlencode($lePid))], [(string) $c['code'], null]],
    'actions' => $roster !== [] ? $ui('button', ['label' => $t('appraisal.export'), 'href' => $url('/appraisal/admin/c/' . $c['public_id'] . '/export'), 'variant' => 'secondary', 'icon' => 'download'])
        . $ui('button', ['label' => 'CSV', 'href' => $url('/appraisal/admin/c/' . $c['public_id'] . '/export?format=csv'), 'variant' => 'ghost', 'attrs' => ['aria-label' => $t('appraisal.export_csv')]]) : '']) ?>
<?php if ($draft): ?>
<section class="card stack" aria-labelledby="ac-o">
  <h2 id="ac-o" class="card__title"><?= $e($t('appraisal.open_title')) ?></h2>
  <p class="text-sm"><?= $e($t('appraisal.open_help', ['aud' => $audText])) ?></p>
  <form method="post" action="<?= $e($url('/appraisal/admin/c/' . $c['public_id'] . '/open')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('appraisal.open_cycle'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></form>
</section>
<section class="card stack" aria-labelledby="ac-e">
  <h2 id="ac-e" class="card__title"><?= $e($t('appraisal.edit_cycle')) ?></h2>
  <form method="post" action="<?= $e($url('/appraisal/admin/c/' . $c['public_id'])) ?>" class="stack" novalidate>
    <?= $csrf ?>
    <?php include __DIR__ . '/_cycle_fields.php'; ?>
    <div class="row"><?= $ui('button', ['label' => $t('appraisal.save'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
  </form>
</section>
<?php else: ?>
<section class="card stack" aria-labelledby="ac-s">
  <h2 id="ac-s" class="card__title"><?= $e($t('appraisal.progress')) ?></h2>
  <ul class="funnel">
    <?php foreach ($sum['status'] as $s => $n): if ($s === 'final_review' && (int) $c['final_review'] !== 1) { continue; } ?><li><strong><?= (int) $n ?></strong> <?= $e($t('appraisal.status.' . $s)) ?></li><?php endforeach; ?>
    <li><strong><?= (int) $sum['appeals'] ?></strong> <?= $e($t('appraisal.open_appeals')) ?></li>
  </ul>
  <dl class="facts">
    <div><dt><?= $e($t('appraisal.field.form')) ?></dt><dd><?= $e((string) $c['form_code']) ?> · <?= $e($t('appraisal.frozen')) ?></dd></div>
    <div><dt><?= $e($t('employees.audience')) ?></dt><dd><?= $e($audText) ?></dd></div>
    <div><dt><?= $e($t('appraisal.field.manager_due')) ?></dt><dd><?= $e($date((string) $c['manager_due'], false)) ?></dd></div>
    <div><dt><?= $e($t('appraisal.field.results_on')) ?></dt><dd><?= $e($date((string) $c['results_on'], false)) ?></dd></div>
  </dl>
  <?php if ($c['status'] === 'open'): ?>
  <form method="post" action="<?= $e($url('/appraisal/admin/c/' . $c['public_id'] . '/close')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('appraisal.close_cycle'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'lock']) ?></form>
  <?php endif; ?>
</section>
<div class="grid grid--2">
  <section class="card stack-sm" aria-labelledby="ac-u">
    <h2 id="ac-u" class="card__title"><?= $e($t('appraisal.by_unit')) ?></h2>
    <div class="table-wrap" role="region" aria-labelledby="ac-u" tabindex="0">
      <table class="table"><thead><tr><th scope="col"><?= $e($t('appraisal.col.unit')) ?></th><th scope="col"><?= $e($t('appraisal.col.people')) ?></th><th scope="col"><?= $e($t('appraisal.col.avg')) ?></th></tr></thead>
        <tbody><?php foreach ($sum['units'] as $u): ?><tr><th scope="row"><?= $e($u['unit']) ?></th><td><?= (int) $u['people'] ?></td><td><?= $e($num($u['avg'])) ?></td></tr><?php endforeach; ?></tbody></table>
    </div>
  </section>
  <section class="card stack-sm" aria-labelledby="ac-b">
    <h2 id="ac-b" class="card__title"><?= $e($t('appraisal.spread')) ?></h2>
    <?php if ($total === 0): ?><p class="text-sm text-muted"><?= $e($t('appraisal.no_results_yet')) ?></p><?php else: ?>
    <ul class="plain-list stack-sm">
      <?php foreach ($sum['bands'] as $label => $n): ?><li><span class="d-block text-sm"><?= $e((string) $label) ?> · <?= (int) $n ?> (<?= $e((string) round($n * 100 / $total)) ?>%)</span>
        <meter class="mp-meter" min="0" max="<?= (int) $total ?>" value="<?= (int) $n ?>" aria-label="<?= $e((string) $label) ?>"></meter></li><?php endforeach; ?>
    </ul>
    <p class="text-sm text-muted"><?= $e($t('appraisal.spread_help')) ?></p>
    <?php endif; ?>
  </section>
</div>
<section class="card stack-sm" aria-labelledby="ac-r">
  <h2 id="ac-r" class="card__title"><?= $e($t('appraisal.people')) ?></h2>
  <?php if ($roster === []): ?><p class="text-sm text-muted"><?= $e($t('appraisal.none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="ac-r" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('appraisal.col.name')) ?></th><th scope="col"><?= $e($t('appraisal.col.unit')) ?></th><th scope="col"><?= $e($t('appraisal.col.manager')) ?></th><th scope="col"><?= $e($t('appraisal.col.status')) ?></th><th scope="col"><?= $e($t('appraisal.col.final')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($roster as $x): ?>
        <tr><th scope="row" data-label="<?= $e($t('appraisal.col.name')) ?>"><a href="<?= $e($url('/appraisal/a/' . $x['public_id'])) ?>"><?= $e($x['employee_code'] . ' · ' . $x['name_th']) ?></a></th>
          <td data-label="<?= $e($t('appraisal.col.unit')) ?>"><?= $e((string) ($x['dept_code'] ?? '—')) ?></td>
          <td data-label="<?= $e($t('appraisal.col.manager')) ?>"><?= $e((string) ($x['manager_name'] ?? $t('appraisal.nobody'))) ?></td>
          <td data-label="<?= $e($t('appraisal.col.status')) ?>"><?= $ui('badge', ['tone' => in_array($x['status'], ['finalized', 'acknowledged'], true) ? 'success' : 'warning', 'label' => $t('appraisal.status.' . $x['status'])]) ?><?= (int) $x['appeal'] === 1 && $x['appeal_status'] === 'open' ? ' ' . $ui('badge', ['tone' => 'danger', 'label' => $t('appraisal.appeal_open_short')]) : '' ?></td>
          <td data-label="<?= $e($t('appraisal.col.final')) ?>"><?= $e($num($x['final_score'])) ?><?= $x['rating_label'] !== null ? ' · ' . $e((string) $x['rating_label']) : '' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>
