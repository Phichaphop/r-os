<?php
/**
 * One attendance period (DEC-66): status and steps, the review HR must do before closing, the payroll input
 * (live preview while open — the frozen snapshot once closed), the close/reopen actions and the file hand-outs.
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $p @var array<string, mixed>|null $review @var array<string, mixed>|null $snap @var array{0: list<string>, 1: list<list<scalar|null>>} $table
 * @var string $company @var bool $canManage @var bool $canExport @var list<array<string, mixed>> $exports @var string $rangeLabel @var array<string, string> $errors
 * @var string|null $instance @var string|null $reopenInstance
 */
use Blue\Core\I18n\DateFormat;

include __DIR__ . '/_helpers.php';
$loc = (string) ($v['locale'] ?? 'th');
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$st = (string) $p['status'];
$base = '/attendance/periods/' . $p['public_id'];
$steps = ['open' => 1, 'pending' => 2, 'finalized' => 3, 'reopen_pending' => 3];
$stepNow = $steps[$st] ?? 1;
$exported = $p['first_exported_at'] !== null;
[$headers, $rows] = $table;
$hdr = static function (string $h) use ($t): string {
    return str_starts_with($h, 'leave:') ? $t('attendance.col.leave_of', ['type' => substr($h, 6)]) : $t('attendance.col.' . $h);
};
$warnLabels = ['absent' => 'attendance.review.absent', 'missing' => 'attendance.review.missing', 'no_data' => 'attendance.review.no_data',
    'no_calendar' => 'attendance.review.no_calendar', 'unmapped' => 'attendance.review.unmapped'];
?>
<?= $ui('page-header', ['title' => $t('attendance.period_title', ['range' => $rangeLabel]), 'subtitle' => $company . ' · ' . $t('attendance.period_field.revision') . ' ' . (int) $p['revision'],
    'crumbs' => [[$t('attendance.periods_title'), $url('/attendance/periods?le=' . (int) $p['legal_entity_id'])], [$rangeLabel, null]],
    'actions' => $periodStatus($st, $exported ? (string) $p['first_exported_at'] : null)]) ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'text' => implode(' · ', array_values($errors)), 'live' => true]) ?><?php endif; ?>

<ol class="period-steps" aria-label="<?= $e($t('attendance.period_steps')) ?>">
  <?php foreach ([1 => 'attendance.step.review', 2 => 'attendance.step.approve', 3 => 'attendance.step.export'] as $n => $key): ?>
    <li class="period-steps__item<?= $n < $stepNow || ($n === 3 && $exported) ? ' is-done' : '' ?><?= $n === $stepNow && !($n === 3 && $exported) ? ' is-current' : '' ?>"<?= $n === $stepNow ? ' aria-current="step"' : '' ?>>
      <span class="period-steps__num" aria-hidden="true"><?= $n ?></span><span><?= $e($t($key)) ?></span></li>
  <?php endforeach; ?>
</ol>

<?php if ($st === 'pending'): ?>
  <?= $ui('alert', ['tone' => 'warning', 'title' => $t('attendance.period_pending_title'), 'text' => $t('attendance.period_pending_text')]) ?>
  <?php if ($instance !== null): ?><p><a href="<?= $e($url('/requests/' . $instance)) ?>"><?= $e($t('attendance.period_see_request')) ?></a></p><?php endif; ?>
<?php elseif ($st === 'reopen_pending'): ?>
  <?= $ui('alert', ['tone' => 'warning', 'title' => $t('attendance.period_reopen_pending_title'), 'text' => (string) $p['reopen_reason']]) ?>
<?php elseif ($st === 'finalized'): ?>
  <?= $ui('alert', ['tone' => 'success', 'title' => $t('attendance.period_final_title'), 'text' => $t('attendance.period_final_text', ['when' => $date((string) $p['finalized_at'])])]) ?>
<?php endif; ?>

<?php if ($review !== null): ?>
<section class="card stack" aria-labelledby="rv-h">
  <h2 id="rv-h" class="card__title"><?= $e($t('attendance.review_title')) ?></h2>
  <div class="grid grid--4">
    <div class="card stat-card<?= $review['blockers']['pending_corrections'] > 0 ? ' stat-card--bad' : '' ?>">
      <div class="stat__label"><?= $e($t('attendance.review.pending')) ?></div><div class="stat__value num"><?= (int) $review['blockers']['pending_corrections'] ?></div>
      <div class="stat__meta"><?= $e($t('attendance.review.blocker')) ?></div></div>
    <?php foreach (['absent', 'missing', 'no_data', 'unmapped'] as $k): ?>
      <div class="card stat-card<?= (int) $review['warnings'][$k] > 0 ? ' stat-card--warn' : '' ?>"><div class="stat__label"><?= $e($t($warnLabels[$k])) ?></div>
        <div class="stat__value num"><?= (int) $review['warnings'][$k] ?></div><div class="stat__meta"><?= $e($t('attendance.review.warning')) ?></div></div>
    <?php endforeach; ?>
  </div>
  <p class="text-sm text-muted"><?= $e($t('attendance.review_employees', ['n' => (string) $review['employees']])) ?><?= $review['refreshed'] ? ' · ' . $e($t('attendance.review_refreshed')) : '' ?></p>
  <?php if ($review['items'] !== []): ?>
  <details<?= $review['blockers']['pending_corrections'] > 0 ? ' open' : '' ?>>
    <summary><?= $e($t('attendance.review_list', ['n' => (string) count($review['items'])])) ?></summary>
    <div class="table-wrap" role="region" aria-label="<?= $e($t('attendance.review_title')) ?>" tabindex="0">
      <table class="table table--stack">
        <thead><tr><th scope="col"><?= $e($t('attendance.field.date')) ?></th><th scope="col"><?= $e($t('attendance.field.employee')) ?></th><th scope="col"><?= $e($t('attendance.review.what')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($review['items'] as $it): $em = $it['employee']; ?>
          <tr><td data-label="<?= $e($t('attendance.field.date')) ?>"><a href="<?= $e($url((string) $it['link'])) ?>"><?= $e(DateFormat::day((string) $it['date'], $loc)) ?></a></td>
            <td data-label="<?= $e($t('attendance.field.employee')) ?>"><?= $e($em['employee_code'] . ' · ' . ($em['preferred_name'] ?: $em['name_th'])) ?></td>
            <td data-label="<?= $e($t('attendance.review.what')) ?>"><?= $it['kind'] === 'pending_correction' ? $ui('badge', ['tone' => 'danger', 'icon' => 'hourglass', 'label' => $t('attendance.review.pending_one')]) : $attStatus((string) $it['kind']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </details>
  <?php endif; ?>
  <?php if ($canManage && $st === 'open'): ?>
  <form method="post" action="<?= $e($url($base . '/submit')) ?>" class="stack-sm period-submit" novalidate>
    <?= $csrf ?>
    <?= $ui('field', ['name' => 'note', 'type' => 'textarea', 'label' => $t('attendance.period_note'), 'help' => $t('attendance.period_note_help')]) ?>
    <?php if (array_sum($review['warnings']) > 0): ?>
      <label class="check"><input type="checkbox" name="confirm" value="1"> <?= $e($t('attendance.period_confirm_warnings')) ?></label>
      <?php if (isset($errors['confirm'])): ?><p class="field__error" role="alert"><?= $e($errors['confirm']) ?></p><?php endif; ?>
    <?php endif; ?>
    <div class="row">
      <?= $ui('button', ['label' => $t('attendance.period_submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'lock',
          'disabled' => $review['blockers']['pending_corrections'] > 0 || $review['blockers']['not_ended'] > 0,
          'disabledReason' => $t($review['blockers']['not_ended'] > 0 ? 'attendance.period_error.not_ended' : 'attendance.period_error.pending')]) ?>
    </div>
    <p class="text-sm text-muted"><?= $e($t('attendance.period_submit_help')) ?></p>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="card card--flush" aria-labelledby="pi-h">
  <div class="card__head row">
    <h2 id="pi-h" class="card__title"><?= $e($t($snap !== null ? 'attendance.input_frozen' : 'attendance.input_preview')) ?> <span class="text-sm text-muted">(<?= count($rows) ?>)</span></h2>
    <span class="spacer"></span>
    <?php if ($canExport && in_array($st, ['finalized'], true)): ?>
      <?= $ui('button', ['label' => $t('attendance.export_xlsx'), 'href' => $url($base . '/export'), 'variant' => 'primary', 'icon' => 'download', 'size' => 'sm']) ?>
      <?= $ui('button', ['label' => 'CSV', 'href' => $url($base . '/export?format=csv'), 'variant' => 'ghost', 'icon' => 'download', 'size' => 'sm']) ?>
    <?php endif; ?>
  </div>
  <?php if ($snap !== null): ?>
    <p class="text-sm text-muted card__pad"><?= $ui('icon', ['name' => $snap['intact'] ? 'shield-check' : 'alert-triangle', 'size' => 'sm']) ?>
      <?= $e($t($snap['intact'] ? 'attendance.snapshot_intact' : 'attendance.snapshot_damaged', ['run' => (string) $p['snapshot_run_pid'], 'hash' => substr((string) $p['output_hash'], 0, 16)])) ?></p>
  <?php else: ?>
    <p class="text-sm text-muted card__pad"><?= $e($t('attendance.input_preview_help')) ?></p>
  <?php endif; ?>
  <div class="table-wrap" role="region" aria-labelledby="pi-h" tabindex="0">
    <table class="table table--compact period-input">
      <thead><tr><?php foreach ($headers as $i => $h): ?><th scope="col"<?= $i >= 4 ? ' class="num"' : '' ?>><?= $e($hdr($h)) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr><?php foreach ($r as $i => $c): ?><?= $i === 0 ? '<th scope="row" class="mono">' . $e((string) $c) . '</th>' : '<td' . ($i >= 4 ? ' class="num"' : '') . '>' . $e((string) $c) . '</td>' ?><?php endforeach; ?></tr>
      <?php endforeach; ?>
      <?php if ($rows === []): ?><tr><td colspan="<?= count($headers) ?>"><?= $ui('states', ['icon' => 'clock', 'title' => $t('attendance.input_none')]) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php if ($canManage && $st === 'finalized'): ?>
<section class="card stack-sm" aria-labelledby="ro-h">
  <h2 id="ro-h" class="card__title"><?= $ui('icon', ['name' => 'lock-open', 'size' => 'sm']) ?> <?= $e($t('attendance.reopen_title')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t($exported ? 'attendance.reopen_help_exported' : 'attendance.reopen_help')) ?></p>
  <form method="post" action="<?= $e($url($base . '/reopen')) ?>" class="stack-sm" novalidate>
    <?= $csrf ?>
    <?= $ui('field', ['name' => 'reason', 'label' => $t('attendance.reopen_reason'), 'required' => true, 'error' => $errors['reason'] ?? null, 'placeholder' => $t('attendance.reopen_example')]) ?>
    <?= $ui('button', ['label' => $t('attendance.reopen_submit'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'send']) ?>
  </form>
</section>
<?php endif; ?>

<?php if ($exports !== []): ?>
<section class="card stack-sm" aria-labelledby="ex-h">
  <h2 id="ex-h" class="card__title"><?= $e($t('attendance.exports_title')) ?></h2>
  <ul class="plain-list">
    <?php foreach ($exports as $x): ?>
      <li><?= $e($date((string) $x['exported_at'])) ?> · <?= $e((string) ($x['by_name'] ?? '—')) ?> · <?= $e(strtoupper((string) $x['format'])) ?> · <?= $e($t('attendance.period_field.revision')) ?> <?= (int) $x['revision'] ?>
        · <?= (int) $x['row_count'] ?> <?= $e($t('attendance.rows')) ?> · <span class="mono text-sm">SHA-256 <?= $e(substr((string) $x['file_sha256'], 0, 16)) ?>…</span></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
