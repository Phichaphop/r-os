<?php
/**
 * One manpower plan: status + actions, plan vs actual by line, what-if against the active plan, lines editor (draft), snapshot.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $plan @var list<array<string, mixed>> $lines @var array<string, mixed> $report @var array<string, mixed>|null $compare
 * @var array<string, mixed>|null $activePlan @var array<string, mixed>|null $snapshot @var array<string, string> $depts @var list<string> $titles
 * @var array<string, string> $errors @var array<string, mixed> $old
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$base = '/manpower/p/' . $plan['public_id'];
$st = (string) $plan['status'];
$num = static fn (float|int|null $n): string => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 1), '0'), '.');
$money = static fn (?float $n): string => $n === null ? '—' : number_format($n, 0);
$btn = static fn (string $action, string $label, string $variant, string $icon) => '<form method="post" action="' . $e($url($base . '/' . $action)) . '">' . $csrf . $ui('button', ['label' => $label, 'type' => 'submit', 'variant' => $variant, 'icon' => $icon]) . '</form>';
$actions = '';
if ($plan['can_manage']) {
    $actions .= $st === 'draft' ? $btn('submit', $t('manpower.submit'), 'primary', 'send') : '';
    $actions .= $st === 'active' ? $btn('revise', $t('manpower.revise'), 'secondary', 'pencil') . $btn('close', $t('manpower.close'), 'ghost', 'lock') : '';
}
$actions .= $ui('button', ['label' => $t('manpower.export'), 'href' => $url($base . '/export'), 'variant' => 'ghost', 'icon' => 'download']);
$cmp = [];
if ($compare !== null) {
    foreach ($compare['lines'] as $x) {
        $cmp[$x['dept'] . '|' . $x['title']] = $x['planned'];
    }
}
$rows = $old !== [] ? array_values((array) ($old['lines'] ?? [])) : array_map(static fn (array $l): array => ['department' => (string) ($l['dept_pid'] ?? ''), 'position_title' => (string) $l['position_title'],
    'headcount' => rtrim(rtrim((string) $l['headcount'], '0'), '.'), 'monthly_cost' => $l['monthly_cost'] !== null ? (string) (float) $l['monthly_cost'] : '', 'months' => (string) $l['months'], 'note' => (string) ($l['note'] ?? '')], $lines);
for ($i = 0; $i < 5; $i++) {
    $rows[] = ['department' => '', 'position_title' => '', 'headcount' => '', 'monthly_cost' => '', 'months' => '', 'note' => ''];
}
?>
<?= $ui('page-header', ['title' => (string) $plan['title'], 'subtitle' => $plan['fiscal_year'] . ' · ' . $t('manpower.version_n', ['n' => (string) $plan['version_no']]) . ' · ' . $t('manpower.status.' . $st),
    'crumbs' => [[$t('manpower.title'), $url('/manpower')], [(string) $plan['fiscal_year'] . ' v' . $plan['version_no'], null]], 'actions' => $actions]) ?>
<?php if ($st === 'active'): ?><?= $ui('alert', ['tone' => 'info', 'text' => $t('manpower.active_help', ['by' => (string) ($plan['approved_name'] ?? '—'), 'at' => $plan['approved_at'] !== null ? $date((string) $plan['approved_at']) : '—'])]) ?><?php endif; ?>
<?php if ($st === 'review'): ?><?= $ui('alert', ['tone' => 'info', 'text' => $t('manpower.review_help')]) ?><?php endif; ?>
<?php if ($compare !== null && $activePlan !== null): ?><?= $ui('alert', ['tone' => 'info', 'text' => $t('manpower.whatif_help', ['n' => (string) $activePlan['version_no']])]) ?><?php endif; ?>
<section class="card stack-sm" aria-labelledby="mp-r">
  <h2 id="mp-r" class="card__title"><?= $e($t('manpower.vs_actual', ['d' => $date((string) $report['date'], false)])) ?></h2>
  <?php if ($report['lines'] === []): ?><p class="text-sm text-muted"><?= $e($t('manpower.no_lines')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="mp-r" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('manpower.col.line')) ?></th><th scope="col"><?= $e($t('manpower.col.planned')) ?></th><?php if ($compare !== null): ?><th scope="col"><?= $e($t('manpower.col.active_plan')) ?></th><?php endif; ?>
        <th scope="col"><?= $e($t('manpower.col.actual')) ?></th><th scope="col"><?= $e($t('manpower.col.joining')) ?></th><th scope="col"><?= $e($t('manpower.col.open')) ?></th><th scope="col"><?= $e($t('manpower.col.pending')) ?></th>
        <th scope="col"><?= $e($t('manpower.col.variance')) ?></th><th scope="col"><?= $e($t('manpower.col.cost')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($report['lines'] as $x): ?>
        <tr><th scope="row" data-label="<?= $e($t('manpower.col.line')) ?>"><?= $e(($x['dept'] !== '' ? $x['dept'] : $t('manpower.all_units')) . ' · ' . ($x['title'] !== '' ? $x['title'] : $t('manpower.all_titles'))) ?></th>
          <td data-label="<?= $e($t('manpower.col.planned')) ?>"><?= $e($num($x['planned'])) ?></td>
          <?php if ($compare !== null): $was = $cmp[$x['dept'] . '|' . $x['title']] ?? null; ?><td data-label="<?= $e($t('manpower.col.active_plan')) ?>"><?= $e($num($was)) ?><?= $was !== null && $was !== $x['planned'] ? ' (' . $e(($x['planned'] > $was ? '+' : '') . $num($x['planned'] - $was)) . ')' : '' ?></td><?php endif; ?>
          <td data-label="<?= $e($t('manpower.col.actual')) ?>"><?= (int) $x['actual'] ?></td>
          <td data-label="<?= $e($t('manpower.col.joining')) ?>"><?= (int) $x['joining'] ?></td>
          <td data-label="<?= $e($t('manpower.col.open')) ?>"><?= (int) $x['open'] ?></td>
          <td data-label="<?= $e($t('manpower.col.pending')) ?>"><?= (int) $x['pending'] ?></td>
          <td data-label="<?= $e($t('manpower.col.variance')) ?>"><?= $x['variance'] < 0 ? $ui('badge', ['tone' => 'danger', 'label' => $num($x['variance'])]) : $e($num($x['variance'])) ?></td>
          <td data-label="<?= $e($t('manpower.col.cost')) ?>"><?= $e($money($x['cost'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><th scope="row"><?= $e($t('manpower.total')) ?></th><td><?= $e($num($report['totals']['planned'])) ?></td><?php if ($compare !== null): ?><td><?= $e($num($compare['totals']['planned'])) ?></td><?php endif; ?>
        <td><?= (int) $report['totals']['actual'] ?></td><td><?= (int) $report['totals']['joining'] ?></td><td><?= (int) $report['totals']['open'] ?></td><td><?= (int) $report['totals']['pending'] ?></td><td></td><td><?= $e($money((float) $report['totals']['cost'])) ?></td></tr></tfoot>
    </table>
  </div>
  <p class="text-sm text-muted"><?= $e($t('manpower.definitions')) ?></p>
  <?php endif; ?>
  <?php if ($report['unplanned'] !== []): ?>
    <details><summary class="text-sm"><?= $e($t('manpower.unplanned', ['n' => (string) array_sum(array_column($report['unplanned'], 'heads'))])) ?></summary>
      <ul class="plain-list text-sm"><?php foreach ($report['unplanned'] as $u): ?><li><?= $e($u['dept'] . ' · ' . $u['title'] . ' — ' . $u['heads']) ?></li><?php endforeach; ?></ul></details>
  <?php endif; ?>
</section>
<?php if ($plan['can_manage'] && $st === 'draft'): ?>
<section class="card stack" aria-labelledby="mp-e">
  <h2 id="mp-e" class="card__title"><?= $e($t('manpower.edit')) ?></h2>
  <?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'text' => implode(' ', array_unique($errors))]) ?><?php endif; ?>
  <form method="post" action="<?= $e($url($base)) ?>" class="stack" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'title', 'label' => $t('manpower.field.title'), 'required' => true, 'value' => $old !== [] ? (string) ($old['title'] ?? '') : (string) $plan['title'], 'error' => $errors['title'] ?? null]) ?>
    <?= $ui('field', ['name' => 'assumptions', 'type' => 'textarea', 'label' => $t('manpower.field.assumptions'), 'value' => $old !== [] ? (string) ($old['assumptions'] ?? '') : (string) ($plan['assumptions'] ?? ''), 'help' => $t('manpower.assumptions_help')]) ?>
    <p class="text-sm text-muted"><?= $e($t('manpower.lines_help')) ?></p>
    <div class="table-wrap" role="region" aria-label="<?= $e($t('manpower.lines')) ?>" tabindex="0">
      <table class="table mp-lines">
        <thead><tr><th scope="col"><?= $e($t('manpower.field.unit')) ?></th><th scope="col"><?= $e($t('manpower.field.position_title')) ?></th><th scope="col"><?= $e($t('manpower.field.headcount')) ?></th>
          <th scope="col"><?= $e($t('manpower.field.monthly_cost')) ?></th><th scope="col"><?= $e($t('manpower.field.months')) ?></th><th scope="col"><?= $e($t('manpower.field.note')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $i => $row): $bad = isset($errors['line_' . $i]); ?>
          <tr<?= $bad ? ' class="is-invalid"' : '' ?>>
            <td><label class="sr-only" for="ml-d<?= $i ?>"><?= $e($t('manpower.field.unit')) ?></label><select class="select" id="ml-d<?= $i ?>" name="lines[<?= $i ?>][department]"<?= $bad ? ' aria-invalid="true"' : '' ?>><option value=""><?= $e($t('manpower.all_units')) ?></option>
              <?php foreach ($depts as $pid => $code): ?><option value="<?= $e($pid) ?>"<?= (string) ($row['department'] ?? '') === $pid ? ' selected' : '' ?>><?= $e($code) ?></option><?php endforeach; ?></select></td>
            <td><label class="sr-only" for="ml-t<?= $i ?>"><?= $e($t('manpower.field.position_title')) ?></label><input class="input" id="ml-t<?= $i ?>" name="lines[<?= $i ?>][position_title]" value="<?= $e((string) ($row['position_title'] ?? '')) ?>" placeholder="<?= $e($t('manpower.all_titles')) ?>"></td>
            <td><label class="sr-only" for="ml-h<?= $i ?>"><?= $e($t('manpower.field.headcount')) ?></label><input class="input" id="ml-h<?= $i ?>" name="lines[<?= $i ?>][headcount]" inputmode="decimal" value="<?= $e((string) ($row['headcount'] ?? '')) ?>"></td>
            <td><label class="sr-only" for="ml-c<?= $i ?>"><?= $e($t('manpower.field.monthly_cost')) ?></label><input class="input input--money" id="ml-c<?= $i ?>" name="lines[<?= $i ?>][monthly_cost]" inputmode="decimal" value="<?= $e((string) ($row['monthly_cost'] ?? '')) ?>"></td>
            <td><label class="sr-only" for="ml-m<?= $i ?>"><?= $e($t('manpower.field.months')) ?></label><input class="input" id="ml-m<?= $i ?>" name="lines[<?= $i ?>][months]" inputmode="numeric" value="<?= $e((string) ($row['months'] ?? '')) ?>" placeholder="12"></td>
            <td><label class="sr-only" for="ml-n<?= $i ?>"><?= $e($t('manpower.field.note')) ?></label><input class="input" id="ml-n<?= $i ?>" name="lines[<?= $i ?>][note]" value="<?= $e((string) ($row['note'] ?? '')) ?>"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($titles !== []): ?><p class="text-sm text-muted"><?= $e($t('manpower.titles_in_use')) ?> <?= $e(implode(' · ', array_slice($titles, 0, 30))) ?></p><?php endif; ?>
    <div class="row"><?= $ui('button', ['label' => $t('manpower.save'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
  </form>
  <form method="post" action="<?= $e($url($base . '/withdraw')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('manpower.withdraw'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'trash']) ?></form>
</section>
<?php else: ?>
<section class="card stack-sm" aria-labelledby="mp-a">
  <h2 id="mp-a" class="card__title"><?= $e($t('manpower.field.assumptions')) ?></h2>
  <p class="pre-line"><?= $e((string) ($plan['assumptions'] ?? '—')) ?></p>
</section>
<?php endif; ?>
<?php if ($snapshot !== null): ?>
<details class="card stack-sm">
  <summary class="card__title"><?= $e($t('manpower.snapshot', ['d' => $date((string) $plan['snapshot_at'])])) ?></summary>
  <ul class="plain-list text-sm"><?php foreach ((array) ($snapshot['lines'] ?? []) as $x): ?><li><?= $e((($x['dept'] ?? '') !== '' ? $x['dept'] : $t('manpower.all_units')) . ' · ' . (($x['title'] ?? '') !== '' ? $x['title'] : $t('manpower.all_titles')) . ' — ' . $t('manpower.snapshot_line', ['p' => $num((float) ($x['planned'] ?? 0)), 'a' => (string) ($x['actual'] ?? 0)])) ?></li><?php endforeach; ?></ul>
</details>
<?php endif; ?>
