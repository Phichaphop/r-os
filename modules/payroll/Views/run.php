<?php
/**
 * One payroll run: facts and totals · sources (time period, OT batches) · manual amounts · actions · people with results and warnings · history.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $run @var list<array<string, mixed>> $results @var list<array<string, mixed>> $manual
 * @var array{periods: list<array<string, mixed>>, batches: list<array<string, mixed>>, welfare: list<array<string, mixed>>} $choices @var array<string, mixed>|null $timePeriod
 * @var list<string> $welfareNames
 * @var array<string, string> $people @var array<string, string> $manualComps @var list<array<string, mixed>> $events
 * @var array{published: int, viewed: int, map: array<int, string>} $slips
 * @var bool $canExport @var bool $canPay @var array<string, string> $errors @var array<string, mixed> $old @var string $form
 * @var array<string, mixed>|null $original @var list<array<string, mixed>> $corrections @var list<array<string, mixed>> $reasons @var list<string> $runWarnings
 * @var array<string, mixed>|null $recon @var string $today @var list<array<string, mixed>> $reviews @var string $reviewBlock
 */
use Blue\Core\Support\Money;

$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$o = static fn (string $k, string $d = ''): string => $form !== '' && is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$m = static fn (mixed $x): string => Money::format(Money::fromDb($x));
$status = (string) $run['status'];
$editable = $run['can_prepare'] && in_array($status, ['draft', 'calculated'], true);
$base = '/payroll/runs/' . $run['public_id'];
$warn = static function (array $w) use ($t): string {
    $params = array_map('strval', (array) ($w['params'] ?? []));
    if (isset($params['method'])) {
        $params['method'] = $t('payroll.method.' . $params['method']);
    }
    if (isset($params['what'])) {
        $params['what'] = $t('payroll.what.' . $params['what']);
    }
    return $t((string) $w['key'], $params);
};
$blockingPeople = count(array_filter($results, static fn (array $x): bool => (int) $x['blocking'] === 1));
?>
<?= $ui('page-header', ['title' => (string) $run['label'], 'subtitle' => $run['le_code'] . ' · #' . $run['run_no'] . ' · ' . $t('payroll.run_type.' . $run['run_type']), 'crumbs' => [[$t('payroll.title'), $url('/payroll')], ['#' . $run['run_no'], null]],
    'actions' => $run['run_type'] !== 'regular' ? $ui('badge', ['tone' => $run['run_type'] === 'correction' ? 'warning' : 'info', 'label' => $t('payroll.run_type.' . $run['run_type'])]) : '']) ?>
<?php if ($run['purpose'] !== null): ?><p class="text-sm"><strong><?= $e($t('payroll.field.purpose')) ?>:</strong> <?= $e((string) $run['purpose']) ?></p><?php endif; ?>
<?php if ($run['run_type'] === 'correction' && $original !== null): ?>
  <?= $ui('alert', ['tone' => 'info', 'title' => $t('payroll.correction_of', ['label' => (string) $original['label']]), 'html' => '<a href="' . $e($url('/payroll/runs/' . $original['public_id'])) . '">' . $e($t('payroll.open_original')) . '</a> · ' . $e($t('payroll.correction_help'))]) ?>
<?php elseif ($run['run_type'] === 'off_cycle'): ?>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('payroll.offcycle_help')]) ?>
<?php endif; ?>
<?php if ($reasons !== []): ?>
  <?= $ui('alert', ['tone' => 'warning', 'title' => $t('payroll.needs_correction'), 'text' => implode(' · ', array_map(static fn (array $x): string => $t($x['key'], $x['params']), $reasons))]) ?>
<?php endif; ?>
<?php if ($runWarnings !== [] && in_array($status, ['calculated', 'pending', 'approved'], true)): ?>
  <?= $ui('alert', ['tone' => 'warning', 'title' => $t('payroll.run_warnings'), 'text' => implode(' · ', array_map(static fn (string $k): string => $t('payroll.warn.' . $k), $runWarnings))]) ?>
<?php endif; ?>
<?php if ($status === 'draft' && $run['calc_run_pid'] !== null): ?><?= $ui('alert', ['tone' => 'warning', 'text' => $t('payroll.stale')]) ?><?php endif; ?>
<?php if ($status === 'cancelled'): ?><?= $ui('alert', ['tone' => 'warning', 'text' => $t('payroll.cancelled_because', ['reason' => (string) $run['cancel_reason']])]) ?><?php endif; ?>
<div class="grid grid--2">
  <section class="card stack-sm" aria-labelledby="pr-d">
    <h2 id="pr-d" class="card__title"><?= $e($t('payroll.details')) ?> <?php include __DIR__ . '/_status.php'; ?></h2>
    <dl class="facts">
      <div><dt><?= $e($t('payroll.field.period')) ?></dt><dd><?= $e($date((string) $run['period_start'], false)) ?> – <?= $e($date((string) $run['period_end'], false)) ?></dd></div>
      <div><dt><?= $e($t('payroll.field.pay_date')) ?></dt><dd><?= $e($date((string) $run['pay_date'], false)) ?></dd></div>
      <div><dt><?= $e($t('payroll.field.time')) ?></dt><dd><?= $timePeriod !== null ? $e($date((string) $timePeriod['period_start'], false) . ' – ' . $date((string) $timePeriod['period_end'], false) . ' · ' . $t('payroll.revision_n', ['n' => (string) $timePeriod['revision']])) : $e($t('payroll.no_time_period')) ?></dd></div>
      <div><dt><?= $e($t('payroll.field.ot')) ?></dt><dd><?= $run['ot_ids'] === [] ? $e($t('payroll.no_ot')) : $e(implode(', ', array_map(static fn (array $b): string => 'B' . $b['batch_no'], array_filter($choices['batches'], static fn (array $b): bool => in_array((int) $b['id'], $run['ot_ids'], true))))) ?></dd></div>
      <?php if ($welfareNames !== [] || $choices['welfare'] !== []): ?><div><dt><?= $e($t('payroll.field.welfare')) ?></dt><dd><?= $welfareNames === [] ? $e($t('payroll.no_welfare')) : $e(implode(', ', $welfareNames)) ?></dd></div><?php endif; ?>
      <?php if ($run['calculated_at'] !== null): ?><div><dt><?= $e($t('payroll.field.calculated')) ?></dt><dd><?= $e($date((string) $run['calculated_at'])) ?> · <code class="hash"><?= $e((string) $run['calc_run_pid']) ?></code></dd></div><?php endif; ?>
    </dl>
  </section>
  <section class="card stack-sm" aria-labelledby="pr-t">
    <h2 id="pr-t" class="card__title"><?= $e($t('payroll.totals')) ?></h2>
    <?php if ($status === 'draft'): ?><p class="text-sm text-muted"><?= $e($t('payroll.not_calculated')) ?></p><?php else: ?>
    <dl class="facts">
      <div><dt><?= $e($t('payroll.col.people')) ?></dt><dd><?= (int) $run['employee_count'] ?></dd></div>
      <div><dt><?= $e($t('payroll.col.gross')) ?></dt><dd><?= $e($m($run['gross_total'])) ?></dd></div>
      <div><dt><?= $e($t('payroll.col.deductions')) ?></dt><dd><?= $e($m($run['deduction_total'])) ?></dd></div>
      <div><dt><?= $e($t('payroll.col.net')) ?></dt><dd><strong><?= $e($m($run['net_total'])) ?></strong></dd></div>
      <div><dt><?= $e($t('payroll.col.employer')) ?></dt><dd><?= $e($m($run['employer_total'])) ?></dd></div>
      <div><dt><?= $e($t('payroll.col.warnings')) ?></dt><dd><?= (int) $run['warning_count'] ?><?php if ((int) $run['blocking_count'] > 0): ?> · <?= $ui('badge', ['tone' => 'danger', 'label' => $t('payroll.blocking_n', ['n' => (string) $run['blocking_count']])]) ?><?php endif; ?></dd></div>
    </dl>
    <?php endif; ?>
  </section>
</div>

<?php if ($editable): ?>
<div class="grid grid--2">
<section class="card stack-sm" aria-labelledby="pr-s">
  <h2 id="pr-s" class="card__title"><?= $e($t('payroll.sources')) ?></h2>
  <form method="post" action="<?= $e($url($base . '/sources')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?php $periodOptions = ['' => $t('payroll.no_time_period')]; foreach ($choices['periods'] as $p) { $periodOptions[(string) $p['public_id']] = $p['period_start'] . ' – ' . $p['period_end'] . ' · ' . $t('payroll.revision_n', ['n' => (string) $p['revision']]); } ?>
    <?= $ui('field', ['name' => 'att_period', 'type' => 'select', 'label' => $t('payroll.field.time'), 'value' => (string) ($timePeriod['public_id'] ?? ''), 'options' => $periodOptions, 'help' => $t('payroll.time_help'), 'error' => $errors['att_period'] ?? null]) ?>
    <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('payroll.field.ot')) ?></legend>
      <?php foreach ($choices['batches'] as $b): ?>
        <label class="check"><input type="checkbox" name="ot_batches[]" value="<?= $e((string) $b['public_id']) ?>"<?= in_array((int) $b['id'], $run['ot_ids'], true) ? ' checked' : '' ?>> <?= $e('B' . $b['batch_no'] . ' · ' . $b['period_start'] . ' – ' . $b['period_end'] . ' · ' . $t('payroll.ot_lines', ['n' => (string) $b['line_count'], 'h' => number_format((int) $b['total_minutes'] / 60, 2)])) ?></label>
      <?php endforeach; ?>
      <?php if ($choices['batches'] === []): ?><p class="text-sm text-muted"><?= $e($t('payroll.no_ot_batches')) ?></p><?php endif; ?>
      <?php if (isset($errors['ot_batches'])): ?><p class="field__error"><?= $e($t($errors['ot_batches'])) ?></p><?php endif; ?>
    </fieldset>
    <?php if ($choices['welfare'] !== [] || $run['welfare_ids'] !== []): ?>
    <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('payroll.field.welfare')) ?></legend>
      <?php foreach ($choices['welfare'] as $b): ?>
        <label class="check"><input type="checkbox" name="welfare_batches[]" value="<?= $e((string) $b['public_id']) ?>"<?= in_array((int) $b['id'], $run['welfare_ids'], true) ? ' checked' : '' ?>> <?= $e($b['batch_no'] . ' · ' . $t('payroll.welfare_lines', ['n' => (string) $b['claim_count'], 'total' => $m($b['total_amount'])])) ?></label>
      <?php endforeach; ?>
      <p class="text-sm text-muted"><?= $e($t('payroll.welfare_help')) ?></p>
      <?php if (isset($errors['welfare_batches'])): ?><p class="field__error"><?= $e($t($errors['welfare_batches'])) ?></p><?php endif; ?>
    </fieldset>
    <?php endif; ?>
    <div class="row"><?= $ui('button', ['label' => $t('payroll.save_sources'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
  </form>
</section>
<section class="card stack-sm" aria-labelledby="pr-c">
  <h2 id="pr-c" class="card__title"><?= $e($t('payroll.calculate_title')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('payroll.calculate_help')) ?></p>
  <form method="post" action="<?= $e($url($base . '/calculate')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t($status === 'calculated' ? 'payroll.recalculate' : 'payroll.calculate'), 'type' => 'submit', 'variant' => $status === 'calculated' ? 'secondary' : 'primary', 'icon' => 'calculator']) ?></form>
  <?php if ($status === 'calculated'): ?>
  <form method="post" action="<?= $e($url($base . '/submit')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'note', 'type' => 'textarea', 'label' => $t('payroll.field.submit_note'), 'value' => $o('note')]) ?>
    <?= $ui('button', ['label' => $t('payroll.submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send', 'disabled' => (int) $run['blocking_count'] > 0]) ?>
    <?php if ((int) $run['blocking_count'] > 0): ?><p class="text-sm text-muted"><?= $e($t('payroll.submit_blocked', ['n' => (string) $blockingPeople])) ?></p><?php endif; ?>
  </form>
  <?php endif; ?>
  <details><summary><?= $e($t('payroll.cancel_run')) ?></summary>
    <form method="post" action="<?= $e($url($base . '/cancel')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <?= $ui('field', ['name' => 'reason', 'label' => $t('payroll.field.reason'), 'required' => true, 'value' => $o('reason'), 'error' => $form === 'cancel' ? ($errors['reason'] ?? null) : null]) ?>
      <?= $ui('button', ['label' => $t('payroll.cancel_run'), 'type' => 'submit', 'variant' => 'secondary']) ?>
    </form>
  </details>
</section>
</div>
<?php endif; ?>

<?php if ($status === 'approved'): ?>
<section class="card stack-sm" aria-labelledby="pr-a">
  <h2 id="pr-a" class="card__title"><?= $e($t('payroll.after_approval')) ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('payroll.field.approved')) ?></dt><dd><?= $e($date((string) $run['approved_at'])) ?></dd></div>
    <div><dt><?= $e($t('payroll.field.payment')) ?></dt><dd><?php if ($run['payment_batch_pid'] !== null): ?><a href="<?= $e($url('/payments/' . $run['payment_batch_pid'])) ?>"><?= $e($t('payroll.open_batch')) ?></a><?php else: ?><?= $e($t('payroll.not_yet')) ?><?php endif; ?></dd></div>
    <div><dt><?= $e($t('payroll.field.posting')) ?></dt><dd><?php if ($run['posting_request_pid'] !== null): ?><a href="<?= $e($url('/finance/requests/' . $run['posting_request_pid'])) ?>"><?= $e($t('payroll.open_posting')) ?></a><?php else: ?><?= $e($t('payroll.not_yet')) ?><?php endif; ?></dd></div>
  </dl>
  <?php if ($run['can_prepare']): ?>
  <div class="row">
    <?php if ($run['payment_batch_pid'] === null && $canPay): ?><form method="post" action="<?= $e($url($base . '/payment')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('payroll.make_payment'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'cash']) ?></form><?php endif; ?>
    <?php if ($run['posting_request_pid'] === null): ?><form method="post" action="<?= $e($url($base . '/posting')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('payroll.make_posting'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'receipt']) ?></form><?php endif; ?>
  </div>
  <?php if ($run['payment_batch_pid'] === null && !$canPay): ?><p class="text-sm text-muted"><?= $e($t('payroll.payment_needs_right')) ?></p><?php endif; ?>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($status === 'approved' && $run['run_type'] === 'regular'): ?>
<section class="card stack-sm" id="corrections" aria-labelledby="pr-co">
  <h2 id="pr-co" class="card__title"><?= $e($t('payroll.corrections')) ?></h2>
  <?php if ($corrections === []): ?><p class="text-sm text-muted"><?= $e($t('payroll.corrections_none')) ?></p><?php else: ?>
  <ul class="plain-list">
    <?php foreach ($corrections as $c): ?><li><a href="<?= $e($url('/payroll/runs/' . $c['public_id'])) ?>"><?= $e('#' . $c['run_no'] . ' · ' . $c['label']) ?></a> <?php $status = (string) $c['status']; include __DIR__ . '/_status.php'; $status = 'approved'; ?></li><?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php if ($run['can_prepare']): ?>
  <details<?= $form === 'correction' || $reasons !== [] ? ' open' : '' ?>><summary><?= $e($t('payroll.make_correction')) ?></summary>
    <form method="post" action="<?= $e($url($base . '/correction')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <p class="text-sm text-muted"><?= $e($t('payroll.make_correction_help')) ?></p>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'pay_date', 'id' => 'f-corr-pay', 'type' => 'date', 'label' => $t('payroll.field.pay_date'), 'required' => true, 'value' => $o('pay_date', $today), 'error' => $form === 'correction' ? ($errors['pay_date'] ?? null) : null]) ?>
        <?= $ui('field', ['name' => 'purpose', 'id' => 'f-corr-purpose', 'label' => $t('payroll.field.purpose'), 'required' => true, 'value' => $o('purpose'), 'help' => $t('payroll.purpose_help'), 'error' => $form === 'correction' ? ($errors['purpose'] ?? null) : null]) ?>
      </div>
      <div class="row"><?= $ui('button', ['label' => $t('payroll.make_correction'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'refresh']) ?></div>
    </form>
  </details>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($status === 'approved' && $recon !== null): ?>
<section class="card stack-sm" id="recon" aria-labelledby="pr-rc">
  <h2 id="pr-rc" class="card__title"><?= $e($t('payroll.recon_title')) ?> <?= $ui('badge', ['tone' => $recon['ok'] ? 'success' : 'warning', 'label' => $t($recon['ok'] ? 'payroll.recon_ok' : 'payroll.recon_check')]) ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('payroll.recon.result')) ?></dt><dd><?= $e($t($recon['intact'] ? 'payroll.recon.intact' : 'payroll.recon.damaged')) ?></dd></div>
    <div><dt><?= $e($t('payroll.recon.net')) ?></dt><dd><?= $e(Money::format($recon['totals']['net'])) ?></dd></div>
    <div><dt><?= $e($t('payroll.recon.left_out')) ?></dt><dd><?= $e(Money::format($recon['totals']['left_out'])) ?></dd></div>
    <div><dt><?= $e($t('payroll.recon.batch')) ?></dt><dd><?= $recon['batch'] !== null ? $e(Money::format($recon['totals']['batch']) . ' · ' . $recon['batch']['batch_no'] . ' · ' . $t('payroll.recon.batch_status.' . $recon['batch']['status'])) : $e($t('payroll.not_yet')) ?></dd></div>
    <div><dt><?= $e($t('payroll.recon.paid')) ?></dt><dd><?= $e(Money::format($recon['totals']['paid'])) ?></dd></div>
    <div><dt><?= $e($t('payroll.recon.differs')) ?></dt><dd><?= (int) $recon['totals']['diff'] ?></dd></div>
  </dl>
  <?php $odd = array_values(array_filter($recon['rows'], static fn (array $x): bool => !in_array($x['state'], ['match', 'nothing_due'], true))); ?>
  <?php if ($odd !== []): ?>
  <div class="table-wrap" role="region" aria-labelledby="pr-rc" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('payroll.field.employee')) ?></th><th scope="col" class="num"><?= $e($t('payroll.col.net')) ?></th><th scope="col" class="num"><?= $e($t('payroll.recon.item')) ?></th><th scope="col"><?= $e($t('payroll.col.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($odd as $x): ?>
        <tr><th scope="row" data-label="<?= $e($t('payroll.field.employee')) ?>"><?= $e(trim($x['code'] . ' · ' . $x['name'], ' ·')) ?></th>
          <td data-label="<?= $e($t('payroll.col.net')) ?>" class="num"><?= $e(Money::format($x['net'])) ?></td>
          <td data-label="<?= $e($t('payroll.recon.item')) ?>" class="num"><?= $x['item'] !== null ? $e(Money::format($x['item'])) : '—' ?></td>
          <td data-label="<?= $e($t('payroll.col.status')) ?>"><?= $ui('badge', ['tone' => $x['state'] === 'left_out' ? 'info' : 'danger', 'label' => $t('payroll.recon.state.' . $x['state'])]) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <p class="text-sm text-muted"><?= $e($t('payroll.recon_help')) ?></p>
</section>
<?php endif; ?>

<?php if ($status === 'approved'): $last = $reviews[0] ?? null; ?>
<section class="card stack-sm" id="review" aria-labelledby="pr-rv">
  <h2 id="pr-rv" class="card__title"><?= $e($t('payroll.review.card')) ?>
    <?= $ui('badge', ['tone' => $last === null ? 'warning' : ($last['result'] === 'ok' ? 'success' : 'danger'), 'label' => $t('payroll.review.state.' . ($last['result'] ?? 'waiting'))]) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('payroll.review.card_help')) ?></p>
  <?php if ($reviews !== []): ?>
  <ul class="plain-list">
    <?php foreach ($reviews as $rv): ?>
      <li><?= $ui('badge', ['tone' => $rv['result'] === 'ok' ? 'success' : 'danger', 'label' => $t('payroll.review.state.' . $rv['result'])]) ?> <?= $e((string) $rv['reviewer']) ?>
        <span class="text-sm text-muted"><?= $e($date((string) $rv['reviewed_at'])) ?></span>
        <?php if ($rv['calc_hash'] !== $run['calc_hash']): ?><?= $ui('badge', ['tone' => 'warning', 'label' => $t('payroll.review.other_result')]) ?><?php endif; ?>
        <?php if ($rv['note'] !== null): ?><br><span class="text-sm"><?= $e((string) $rv['note']) ?></span><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php if ($reviewBlock === ''): ?>
  <form method="post" action="<?= $e($url($base . '/review')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <fieldset class="stack-sm">
      <legend class="field__label"><?= $e($t('payroll.review.result')) ?></legend>
      <?php if (isset($errors['result'])): ?><p class="field__error" role="alert"><?= $e((string) $errors['result']) ?></p><?php endif; ?>
      <label class="check"><input type="radio" name="result" value="ok"<?= $o('result') === 'ok' ? ' checked' : '' ?>> <?= $e($t('payroll.review.result_ok')) ?></label>
      <label class="check"><input type="radio" name="result" value="issues"<?= $o('result') === 'issues' ? ' checked' : '' ?>> <?= $e($t('payroll.review.result_issues')) ?></label>
    </fieldset>
    <?= $ui('field', ['name' => 'note', 'id' => 'f-review-note', 'type' => 'textarea', 'label' => $t('payroll.review.note'), 'value' => $o('note'), 'help' => $t('payroll.review.note_help'), 'error' => $errors['note'] ?? null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('payroll.review.submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'clipboard-check']) ?></div>
  </form>
  <?php elseif ($reviewBlock === 'took_part'): ?>
    <p class="text-sm text-muted"><?= $e($t('payroll.review.error.took_part')) ?></p>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($status === 'approved'): ?>
<section class="card stack-sm" id="slips" aria-labelledby="pr-sl">
  <h2 id="pr-sl" class="card__title"><?= $e($t('payroll.slips_title')) ?></h2>
  <p><?= $e($t('payroll.slips_status', ['n' => (string) $slips['published'], 'all' => (string) $run['employee_count'], 'v' => (string) $slips['viewed']])) ?></p>
  <?php if ($run['can_prepare'] && $slips['published'] < (int) $run['employee_count']): ?>
    <form method="post" action="<?= $e($url($base . '/publish')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('payroll.publish'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></form>
    <p class="text-sm text-muted"><?= $e($t('payroll.publish_help')) ?></p>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($editable || $manual !== []): ?>
<section class="card stack-sm" id="manual" aria-labelledby="pr-m">
  <h2 id="pr-m" class="card__title"><?= $e($t('payroll.manual_title')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('payroll.manual_help')) ?></p>
  <?php if ($manual !== []): ?>
  <div class="table-wrap" role="region" aria-labelledby="pr-m" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('payroll.field.employee')) ?></th><th scope="col"><?= $e($t('payroll.field.component')) ?></th><th scope="col"><?= $e($t('payroll.field.amount')) ?></th><th scope="col"><?= $e($t('payroll.field.note')) ?></th><?php if ($editable): ?><th scope="col"><span class="sr-only"><?= $e($t('payroll.remove')) ?></span></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($manual as $x): ?>
        <tr><th scope="row" data-label="<?= $e($t('payroll.field.employee')) ?>"><?= $e($x['employee_code'] . ' · ' . $x['name_th']) ?></th>
          <td data-label="<?= $e($t('payroll.field.component')) ?>"><?= $e($x['component_code'] . ' · ' . $x['component_name']) ?></td>
          <td data-label="<?= $e($t('payroll.field.amount')) ?>"><?= $e(($x['kind'] === 'deduction' ? '−' : '') . $m($x['amount'])) ?></td>
          <td data-label="<?= $e($t('payroll.field.note')) ?>"><?= $e((string) $x['note']) ?></td>
          <?php if ($editable): ?><td><form method="post" action="<?= $e($url($base . '/manual/' . $x['public_id'] . '/delete')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('payroll.remove'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'trash']) ?></form></td><?php endif; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <?php if ($editable): ?>
    <?php if ($manualComps === []): ?><p class="text-sm text-muted"><?= $e($t('payroll.no_manual_components')) ?></p><?php else: ?>
    <form method="post" action="<?= $e($url($base . '/manual')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'employee', 'type' => 'select', 'label' => $t('payroll.field.employee'), 'required' => true, 'value' => $o('employee'), 'options' => ['' => '—'] + $people, 'error' => $form === 'manual' ? ($errors['employee'] ?? null) : null]) ?>
        <?= $ui('field', ['name' => 'component', 'type' => 'select', 'label' => $t('payroll.field.component'), 'required' => true, 'value' => $o('component'), 'options' => ['' => '—'] + $manualComps, 'error' => $form === 'manual' ? ($errors['component'] ?? null) : null]) ?>
        <?= $ui('field', ['name' => 'amount', 'type' => 'money', 'label' => $t('payroll.field.amount'), 'required' => true, 'value' => $o('amount'), 'error' => $form === 'manual' ? ($errors['amount'] ?? null) : null]) ?>
        <?= $ui('field', ['name' => 'note', 'label' => $t('payroll.field.note'), 'required' => true, 'value' => $o('note'), 'help' => $t('payroll.note_help'), 'error' => $form === 'manual' ? ($errors['note'] ?? null) : null]) ?>
      </div>
      <div class="row"><?= $ui('button', ['label' => $t('payroll.add_manual'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'plus']) ?></div>
    </form>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($results !== []): ?>
<section class="card stack-sm" aria-labelledby="pr-p">
  <h2 id="pr-p" class="card__title"><?= $e($t('payroll.people_results')) ?></h2>
  <?php if ($canExport && $status !== 'draft'): ?><p><?= $ui('button', ['label' => $t('payroll.register'), 'href' => $url($base . '/register'), 'variant' => 'secondary', 'icon' => 'download']) ?> <span class="text-sm text-muted"><?= $e($t('payroll.register_help')) ?></span></p><?php endif; ?>
  <div class="table-wrap" role="region" aria-labelledby="pr-p" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('payroll.field.employee')) ?></th><th scope="col"><?= $e($t('payroll.col.gross')) ?></th><th scope="col"><?= $e($t('payroll.col.deductions')) ?></th><th scope="col"><?= $e($t('payroll.col.net')) ?></th><th scope="col"><?= $e($t('payroll.col.warnings')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($results as $x): ?>
        <tr><th scope="row" data-label="<?= $e($t('payroll.field.employee')) ?>"><a href="<?= $e($url($base . '/e/' . $x['employee_pid'])) ?>"><?= $e($x['snap']['code'] . ' · ' . $x['snap']['name']) ?></a></th>
          <td data-label="<?= $e($t('payroll.col.gross')) ?>"><?= $e($m($x['gross'])) ?></td>
          <td data-label="<?= $e($t('payroll.col.deductions')) ?>"><?= $e($m($x['deductions'])) ?></td>
          <td data-label="<?= $e($t('payroll.col.net')) ?>"><?= $e($m($x['net'])) ?><?php if (isset($slips['map'][(int) $x['employee_id']])): ?> · <a href="<?= $e($url('/payroll/payslips/' . $slips['map'][(int) $x['employee_id']] . '/pdf')) ?>"><?= $e($t('payroll.slip_pdf')) ?></a><?php endif; ?></td>
          <td data-label="<?= $e($t('payroll.col.warnings')) ?>"><?php if ($x['warnings'] === []): ?>—<?php else: ?><ul class="plain-list text-sm"><?php foreach ($x['warnings'] as $w): ?><li><?= $w['blocking'] ? $ui('badge', ['tone' => 'danger', 'label' => $t('payroll.blocking')]) . ' ' : '' ?><?= $e($warn($w)) ?></li><?php endforeach; ?></ul><?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<section class="card stack-sm" aria-labelledby="pr-h">
  <h2 id="pr-h" class="card__title"><?= $e($t('payroll.history')) ?></h2>
  <ul class="plain-list">
    <?php foreach ($events as $ev): $act = explode(':', (string) $ev['action'])[0]; ?>
      <li><?= $e($t('payroll.event.' . $act)) ?> · <?= $e((string) ($ev['actor'] ?? '—')) ?> <span class="text-sm text-muted"><?= $e($date((string) $ev['occurred_at'])) ?></span><?= $ev['note'] !== null ? ' — ' . $e((string) $ev['note']) : '' ?></li>
    <?php endforeach; ?>
  </ul>
</section>
