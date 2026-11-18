<?php
/**
 * One person in a run: where each amount comes from (component, quantity, rate, source) and the warnings.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date
 * @var array<string, mixed> $run @var array<string, mixed> $emp @var list<array<string, mixed>> $lines
 */
use Blue\Core\Support\Money;

$m = static fn (mixed $x): string => Money::format(Money::fromDb($x));
$num = static fn (mixed $x): string => $x === null ? '—' : rtrim(rtrim(Money::format(Money::fromDb($x), 4, true), '0'), '.');
?>
<?= $ui('page-header', ['title' => $emp['snap']['code'] . ' · ' . $emp['snap']['name'], 'subtitle' => (string) $run['label'], 'crumbs' => [[$t('payroll.title'), $url('/payroll')], ['#' . $run['run_no'], $url('/payroll/runs/' . $run['public_id'])], [(string) $emp['snap']['code'], null]]]) ?>
<?= $ui('alert', ['tone' => 'info', 'text' => $t('payroll.result_notice')]) ?>
<?php foreach ($emp['warnings'] as $w): $params = array_map('strval', (array) ($w['params'] ?? [])); if (isset($params['method'])) { $params['method'] = $t('payroll.method.' . $params['method']); } if (isset($params['what'])) { $params['what'] = $t('payroll.what.' . $params['what']); } ?>
  <?= $ui('alert', ['tone' => $w['blocking'] ? 'danger' : 'warning', 'text' => $t((string) $w['key'], $params)]) ?>
<?php endforeach; ?>
<section class="card stack-sm" aria-labelledby="rs-l">
  <h2 id="rs-l" class="card__title"><?= $e($t('payroll.lines')) ?></h2>
  <div class="table-wrap" role="region" aria-labelledby="rs-l" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('payroll.field.component')) ?></th><th scope="col"><?= $e($t('payroll.col.kind')) ?></th><th scope="col"><?= $e($t('payroll.col.quantity')) ?></th><th scope="col"><?= $e($t('payroll.col.rate')) ?></th><th scope="col"><?= $e($t('payroll.field.amount')) ?></th><th scope="col"><?= $e($t('payroll.col.source')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($lines as $l): ?>
        <tr><th scope="row" data-label="<?= $e($t('payroll.field.component')) ?>"><?= $e($l['component_code'] . ' · ' . $l['name_th']) ?></th>
          <td data-label="<?= $e($t('payroll.col.kind')) ?>"><?= $e($t('payroll.kind.' . $l['kind'])) ?></td>
          <td data-label="<?= $e($t('payroll.col.quantity')) ?>"><?= $e($num($l['quantity'])) ?></td>
          <td data-label="<?= $e($t('payroll.col.rate')) ?>"><?= $e($num($l['rate'])) ?></td>
          <td data-label="<?= $e($t('payroll.field.amount')) ?>"><?= $e(($l['kind'] === 'deduction' ? '−' : '') . $m($l['amount'])) ?></td>
          <td data-label="<?= $e($t('payroll.col.source')) ?>"><?= $e($t('payroll.source.' . $l['source_type'])) ?><?= $l['note'] !== null && in_array($l['source_type'], ['manual', 'welfare'], true) ? ' — ' . $e((string) $l['note']) : '' ?><?= $l['source_type'] === 'welfare' && $l['source_ref'] !== null ? ' · ' . $e((string) $l['source_ref']) : '' ?><?php if ($l['source_type'] === 'correction' && preg_match('/^was:([-0-9.]+);now:([-0-9.]+)$/', (string) $l['note'], $cm) === 1): ?> — <?= $e($t('payroll.was_now', ['was' => Money::format((int) Money::parse($cm[1], 2)), 'now' => Money::format((int) Money::parse($cm[2], 2))])) ?><?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if ($lines === []): ?><tr><td colspan="6"><?= $ui('states', ['icon' => 'report-money', 'title' => $t('payroll.no_lines')]) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <dl class="facts">
    <div><dt><?= $e($t('payroll.col.gross')) ?></dt><dd><?= $e($m($emp['gross'])) ?></dd></div>
    <div><dt><?= $e($t('payroll.col.deductions')) ?></dt><dd><?= $e($m($emp['deductions'])) ?></dd></div>
    <div><dt><?= $e($t('payroll.col.net')) ?></dt><dd><strong><?= $e($m($emp['net'])) ?></strong></dd></div>
    <div><dt><?= $e($t('payroll.col.employer')) ?></dt><dd><?= $e($m($emp['employer'])) ?></dd></div>
    <div><dt><?= $e($t('payroll.field.days_employed')) ?></dt><dd><?= (int) ($emp['snap']['days_employed'] ?? 0) ?></dd></div>
  </dl>
</section>
