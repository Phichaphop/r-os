<?php
/**
 * One revision of a government filing: what it is (company, kind, month, revision, status), the checks, totals and
 * rows, what can be done next (send for review → export the review workbook → record the submission → record the
 * authority's result), the other revisions and the history.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed>|null $official @var array<string, mixed> $rev @var string $today @var array<string, string> $errors @var array<string, mixed> $old @var string $form
 */
use Blue\Core\Support\Money;

$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$o = static fn (string $k, string $d = ''): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$err = static fn (string $f, string $k): ?string => $form === $f ? ($errors[$k] ?? null) : null;
$m = static fn (int $u): string => Money::format($u);
$wht = $rev['kind'] === 'wht_monthly';
$base = '/filing/r/' . $rev['public_id'];
$status = (string) $rev['status'];
$checkText = static fn (array $c): string => $t((string) $c['key'], (array) $c['params']);
?>
<?= $ui('page-header', ['title' => $t('filing.kind.' . $rev['kind']) . ' · ' . $rev['period'], 'subtitle' => $rev['le_code'] . ' · #' . $rev['revision_no'] . ' · ' . $t('filing.purpose.' . $rev['purpose']),
    'crumbs' => [[$t('filing.title'), $url('/filing?year=' . substr((string) $rev['period'], 0, 4))], ['#' . $rev['revision_no'], null]]]) ?>
<?= $ui('alert', ['tone' => 'warning', 'title' => $t('filing.no_official_format_title'), 'text' => $t('filing.review_copy_only')]) ?>
<?php if (!$rev['is_latest']): ?><?= $ui('alert', ['tone' => 'info', 'text' => $t('filing.not_latest')]) ?><?php endif; ?>
<?php if ($rev['source_changed']): ?><?= $ui('alert', ['tone' => 'warning', 'title' => $t('filing.stale_title'), 'text' => $t('filing.stale_text')]) ?><?php endif; ?>
<?php if (!$rev['intact']): ?><?= $ui('alert', ['tone' => 'danger', 'text' => $t('filing.damaged')]) ?><?php endif; ?>
<div class="grid grid--2">
<section class="card stack-sm" aria-labelledby="fr-d">
  <h2 id="fr-d" class="card__title"><?= $e($t('filing.details')) ?> <?php include __DIR__ . '/_status.php'; ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('filing.field.company')) ?></dt><dd><?= $e((string) $rev['le_code']) ?></dd></div>
    <div><dt><?= $e($t('filing.field.period')) ?></dt><dd><?= $e((string) $rev['period']) ?></dd></div>
    <div><dt><?= $e($t('filing.field.due')) ?></dt><dd><?= $rev['due'] !== null ? $e($date((string) $rev['due'], false)) : $e($t('filing.due_not_set')) ?></dd></div>
    <div><dt><?= $e($t('filing.field.revision')) ?></dt><dd><?= $e('#' . $rev['revision_no'] . ' · ' . $t('filing.purpose.' . $rev['purpose'])) ?></dd></div>
    <?php if ($rev['reason'] !== null): ?><div><dt><?= $e($t('filing.field.reason')) ?></dt><dd><?= $e((string) $rev['reason']) ?></dd></div><?php endif; ?>
    <div><dt><?= $e($t('filing.field.schema')) ?></dt><dd><code><?= $e((string) $rev['schema_version']) ?></code></dd></div>
    <div><dt><?= $e($t('filing.field.ledger')) ?></dt><dd><code><?= $e((string) $rev['calc_run_pid']) ?></code></dd></div>
    <div><dt><?= $e($t('filing.field.runs')) ?></dt><dd><?= $rev['runs'] === [] ? '—' : $e(implode(', ', array_map(static fn (array $x): string => '#' . $x['run_no'] . ' ' . $t('payroll.run_type.' . $x['run_type']) . ' (' . $x['pay_date'] . ')', $rev['runs']))) ?></dd></div>
    <?php if ($rev['export_sha256'] !== null): ?><div><dt><?= $e($t('filing.field.export')) ?></dt><dd><code><?= $e(substr((string) $rev['export_sha256'], 0, 16)) ?>…</code> · <?= $e($date((string) $rev['exported_at'])) ?></dd></div><?php endif; ?>
    <?php if ($rev['submitted_on'] !== null): ?><div><dt><?= $e($t('filing.field.submitted')) ?></dt><dd><?= $e($date((string) $rev['submitted_on'], false) . ' · ' . $rev['submission_ref']) ?>
      <?php if ($rev['receipt_document_pid'] !== null): ?> · <a href="<?= $e($url($base . '/receipt')) ?>"><?= $e($t('filing.open_receipt')) ?></a><?php endif; ?></dd></div><?php endif; ?>
    <?php if ($rev['result_note'] !== null): ?><div><dt><?= $e($t('filing.field.result_note')) ?></dt><dd><?= $e((string) $rev['result_note']) ?></dd></div><?php endif; ?>
  </dl>
</section>
<section class="card stack-sm" aria-labelledby="fr-t">
  <h2 id="fr-t" class="card__title"><?= $e($t('filing.totals')) ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('filing.field.people')) ?></dt><dd><?= (int) $rev['people'] ?></dd></div>
    <div><dt><?= $e($t($wht ? 'filing.col.income' : 'filing.col.wage_used')) ?></dt><dd><?= $e($m(Money::fromDb($rev['total_a']))) ?></dd></div>
    <div><dt><?= $e($t($wht ? 'filing.col.wht' : 'filing.col.ee')) ?></dt><dd><strong><?= $e($m(Money::fromDb($rev['total_b']))) ?></strong></dd></div>
    <?php if (!$wht): ?><div><dt><?= $e($t('filing.col.er')) ?></dt><dd><strong><?= $e($m(Money::fromDb($rev['total_c']))) ?></strong></dd></div><?php endif; ?>
  </dl>
  <p class="text-sm text-muted"><?= $e($t($wht ? 'filing.totals_help_wht' : 'filing.totals_help_sso')) ?></p>
</section>
</div>

<section class="card stack-sm" aria-labelledby="fr-c">
  <h2 id="fr-c" class="card__title"><?= $e($t('filing.checks')) ?></h2>
  <?php if ($rev['errors'] === [] && $rev['warnings'] === []): ?><p><?= $ui('badge', ['tone' => 'success', 'label' => $t('filing.checks_ok')]) ?></p><?php endif; ?>
  <?php if ($rev['errors'] !== []): ?>
  <ul class="plain-list stack-sm"><?php foreach ($rev['errors'] as $c): ?><li><?= $ui('badge', ['tone' => 'danger', 'label' => $t('filing.check_error')]) ?> <?= $e($checkText($c)) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
  <?php if ($rev['warnings'] !== []): ?>
  <ul class="plain-list stack-sm"><?php foreach ($rev['warnings'] as $c): ?><li><?= $ui('badge', ['tone' => 'warning', 'label' => $t('filing.check_warning')]) ?> <?= $e($checkText($c)) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
</section>

<?php if ($rev['can_prepare'] && $rev['is_latest']): ?>
<section class="card stack" aria-labelledby="fr-a">
  <h2 id="fr-a" class="card__title"><?= $e($t('filing.next_step')) ?></h2>
  <?php if ($status === 'validation_failed'): ?>
    <p class="text-sm"><?= $e($t('filing.next.fix')) ?></p>
  <?php elseif ($status === 'ready'): ?>
    <form method="post" action="<?= $e($url($base . '/submit')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <?= $ui('field', ['name' => 'note', 'type' => 'textarea', 'label' => $t('filing.field.note'), 'value' => $o('note'), 'error' => $err('submit', 'note')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('filing.send_review'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
    </form>
    <p class="text-sm text-muted"><?= $e($t('filing.send_review_help')) ?></p>
  <?php elseif ($status === 'pending'): ?>
    <p class="text-sm"><?= $e($t('filing.next.pending')) ?></p>
  <?php elseif ($status === 'returned'): ?>
    <p class="text-sm"><?= $e($t('filing.next.returned')) ?></p>
  <?php elseif (in_array($status, ['approved', 'exported'], true)): ?>
    <div class="row"><?= $ui('button', ['label' => $t('filing.export'), 'href' => $url($base . '/export'), 'variant' => $status === 'approved' ? 'primary' : 'secondary', 'icon' => 'download']) ?></div>
    <p class="text-sm text-muted"><?= $e($t('filing.export_help')) ?></p>
    <?php if ($status === 'exported'): ?>
    <form method="post" action="<?= $e($url($base . '/submission')) ?>" enctype="multipart/form-data" class="stack-sm" novalidate><?= $csrf ?>
      <h3 class="section-title"><?= $e($t('filing.record_submission')) ?></h3>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'submitted_on', 'type' => 'date', 'label' => $t('filing.field.submitted_on'), 'required' => true, 'value' => $o('submitted_on', $today), 'max' => $today, 'error' => $err('submission', 'submitted_on')]) ?>
        <?= $ui('field', ['name' => 'reference', 'label' => $t('filing.field.reference'), 'required' => true, 'value' => $o('reference'), 'help' => $t('filing.reference_help'), 'error' => $err('submission', 'reference')]) ?>
      </div>
      <?= $ui('dropzone', ['name' => 'receipt', 'id' => 'f-receipt', 'label' => $t('filing.field.receipt'), 'accept' => '.pdf,.png,.jpg,.jpeg,.webp,image/*', 'help' => $t('filing.receipt_help'), 'error' => $err('submission', 'receipt')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('filing.record_submission'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
    </form>
    <?php endif; ?>
  <?php elseif ($status === 'submitted'): ?>
    <div class="row"><?= $ui('button', ['label' => $t('filing.export_again'), 'href' => $url($base . '/export'), 'variant' => 'secondary', 'icon' => 'download']) ?></div>
    <form method="post" action="<?= $e($url($base . '/result')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <h3 class="section-title"><?= $e($t('filing.record_result')) ?></h3>
      <?= $ui('field', ['name' => 'result', 'type' => 'select', 'label' => $t('filing.field.result'), 'required' => true, 'value' => $o('result'), 'options' => ['' => '—', 'accepted' => $t('filing.status.accepted'), 'rejected' => $t('filing.status.rejected')], 'error' => $err('result', 'result')]) ?>
      <?= $ui('field', ['name' => 'note', 'id' => 'f-result-note', 'type' => 'textarea', 'label' => $t('filing.field.result_note'), 'value' => $o('note'), 'help' => $t('filing.result_note_help'), 'error' => $err('result', 'note')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('filing.record_result'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
    </form>
  <?php elseif (in_array($status, ['accepted', 'rejected'], true)): ?>
    <div class="row"><?= $ui('button', ['label' => $t('filing.export_again'), 'href' => $url($base . '/export'), 'variant' => 'secondary', 'icon' => 'download']) ?></div>
    <p class="text-sm text-muted"><?= $e($t($status === 'rejected' ? 'filing.next.rejected' : 'filing.next.accepted')) ?></p>
  <?php endif; ?>
  <?php if (!in_array($status, ['pending'], true)): ?>
  <form method="post" action="<?= $e($url('/filing/generate')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <input type="hidden" name="kind" value="<?= $e((string) $rev['kind']) ?>"><input type="hidden" name="period" value="<?= $e((string) $rev['period']) ?>">
    <input type="hidden" name="le" value="<?= $e((string) $rev['le_pid']) ?>">
    <h3 class="section-title"><?= $e($t($rev['additional_next'] ? 'filing.make_additional' : 'filing.regenerate')) ?></h3>
    <?= $ui('field', ['name' => 'reason', 'id' => 'f-regen-reason', 'label' => $t('filing.field.reason'), 'required' => $rev['additional_next'], 'value' => $form === 'regen' ? $o('reason') : '',
        'help' => $t($rev['additional_next'] ? 'filing.additional_help' : 'filing.regenerate_help')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t($rev['additional_next'] ? 'filing.make_additional' : 'filing.regenerate'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'refresh']) ?></div>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($official !== null): ?>
<section class="card stack" id="official" aria-labelledby="fr-o">
  <h2 id="fr-o" class="card__title"><?= $e($t($wht ? 'filing.official.title_pnd1' : 'filing.official.title_sso')) ?></h2>
  <?= $ui('alert', ['tone' => 'warning', 'text' => $t('filing.official.not_verified')]) ?>
  <p class="text-sm text-muted"><?= $e($t($wht ? 'filing.official.help_pnd1' : 'filing.official.help_sso')) ?></p>
  <?php if ($wht && count($official['paid_dates']) > 1): ?><p class="text-sm"><?= $ui('badge', ['tone' => 'warning', 'label' => $t('filing.official.many_dates', ['d' => $date($official['paid_dates'][0], false)])]) ?></p><?php endif; ?>
  <?php if ($official['problems'] !== []): ?>
    <p class="text-sm"><strong><?= $e($t('filing.official.blocked')) ?></strong></p>
    <ul class="text-sm"><?php foreach (array_slice($official['problems'], 0, 20) as $c): ?><li><?= $e($checkText($c)) ?></li><?php endforeach; ?></ul>
  <?php else: ?>
    <div class="row"><?= $ui('button', ['label' => $t($wht ? 'filing.official.download_pnd1' : 'filing.official.download_sso'), 'href' => $url($base . '/official'), 'variant' => 'primary', 'icon' => 'download']) ?></div>
    <p class="text-sm text-muted"><?= $e($t('filing.official.download_help')) ?></p>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="card card--flush" aria-labelledby="fr-r">
  <div class="card__head"><h2 id="fr-r" class="card__title"><?= $e($t('filing.rows', ['n' => (string) count($rev['rows'])])) ?></h2></div>
  <?php if ($rev['rows'] === []): ?><div class="card__pad"><?= $ui('states', ['icon' => 'file-text', 'title' => $t('filing.rows_none')]) ?></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="fr-r" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('filing.col.code')) ?></th><th scope="col"><?= $e($t('filing.col.id_last4')) ?></th>
      <?php if ($wht): ?><th scope="col" class="num"><?= $e($t('filing.col.income')) ?></th><th scope="col" class="num"><?= $e($t('filing.col.wht')) ?></th>
      <?php else: ?><th scope="col" class="num"><?= $e($t('filing.col.wage')) ?></th><th scope="col" class="num"><?= $e($t('filing.col.wage_used')) ?></th><th scope="col" class="num"><?= $e($t('filing.col.ee')) ?></th><th scope="col" class="num"><?= $e($t('filing.col.er')) ?></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($rev['rows'] as $x): ?>
        <tr><th scope="row" data-label="<?= $e($t('filing.col.code')) ?>"><?= $e($x['code'] . ' · ' . $x['name']) ?></th>
          <td data-label="<?= $e($t('filing.col.id_last4')) ?>"><?= $x['id_last4'] !== '' ? $e('•••' . $x['id_last4']) : $ui('badge', ['tone' => 'danger', 'label' => $t('filing.no_id_short')]) ?></td>
          <?php if ($wht): ?>
          <td data-label="<?= $e($t('filing.col.income')) ?>" class="num"><?= $e($m((int) $x['income'])) ?></td>
          <td data-label="<?= $e($t('filing.col.wht')) ?>" class="num"><?= $e($m((int) $x['wht'])) ?></td>
          <?php else: ?>
          <td data-label="<?= $e($t('filing.col.wage')) ?>" class="num"><?= $e($m((int) $x['wage'])) ?></td>
          <td data-label="<?= $e($t('filing.col.wage_used')) ?>" class="num"><?= $e($m((int) $x['wage_used'])) ?><?php if ($x['wage_unknown']): ?> <?= $ui('badge', ['tone' => 'warning', 'label' => $t('filing.wage_unknown_short')]) ?><?php endif; ?></td>
          <td data-label="<?= $e($t('filing.col.ee')) ?>" class="num"><?= $e($m((int) $x['ee'])) ?></td>
          <td data-label="<?= $e($t('filing.col.er')) ?>" class="num"><?= $e($m((int) $x['er'])) ?></td>
          <?php endif; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<div class="grid grid--2">
<section class="card stack-sm" aria-labelledby="fr-h">
  <h2 id="fr-h" class="card__title"><?= $e($t('filing.revisions')) ?></h2>
  <ul class="plain-list stack-sm">
  <?php foreach ($rev['history'] as $h): $status = (string) $h['status']; ?>
    <li><?php if ($h['public_id'] === $rev['public_id']): ?><strong><?= $e('#' . $h['revision_no']) ?></strong><?php else: ?><a href="<?= $e($url('/filing/r/' . $h['public_id'])) ?>"><?= $e('#' . $h['revision_no']) ?></a><?php endif; ?>
      <?= $e($t('filing.purpose.' . $h['purpose'])) ?> <?php include __DIR__ . '/_status.php'; ?> <span class="text-sm text-muted"><?= $e($date((string) $h['created_at'])) ?></span></li>
  <?php endforeach; $status = (string) $rev['status']; ?>
  </ul>
</section>
<section class="card stack-sm" aria-labelledby="fr-e">
  <h2 id="fr-e" class="card__title"><?= $e($t('filing.history')) ?></h2>
  <ul class="plain-list stack-sm">
  <?php foreach ($rev['events'] as $ev): ?>
    <li><?= $e($t('filing.event.' . $ev['event'])) ?> · <?= $e((string) ($ev['actor'] ?? '—')) ?> <span class="text-sm text-muted"><?= $e($date((string) $ev['created_at'])) ?></span><?= $ev['note'] !== null ? ' — ' . $e((string) $ev['note']) : '' ?></li>
  <?php endforeach; ?>
  </ul>
</section>
</div>
