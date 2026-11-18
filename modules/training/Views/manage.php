<?php
/**
 * HR work page of a session: status, details, enrol people, check-in (e-ID QR / code), attendance list, results, certificates.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $s @var list<array<string, mixed>> $roster @var array<string, string> $courseOptions @var list<array<string, mixed>> $certs
 * @var array<string, mixed>|null $scan @var string $today @var array<string, string> $errors @var array<string, mixed> $old @var string $form
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$base = '/training/admin/s/' . $s['public_id'];
$st = (string) $s['status'];
$x = $s;
$live = in_array($st, ['open', 'closed'], true);
$started = $today >= (string) $s['start_date'];
$o = static fn (string $k, string $d = ''): string => $form === 'details' && is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$statusBtn = static function (string $to, string $label, string $variant) use ($e, $url, $base, $csrf, $ui): string {
    return '<form method="post" action="' . $e($url($base . '/status')) . '">' . $csrf . '<input type="hidden" name="to" value="' . $e($to) . '">' . $ui('button', ['label' => $label, 'type' => 'submit', 'variant' => $variant]) . '</form>';
};
$actions = match ($st) {
    'planned' => $statusBtn('open', $t('training.open_enrol'), 'primary'),
    'open' => $statusBtn('closed', $t('training.close_enrol'), 'secondary') . $statusBtn('completed', $t('training.complete'), 'ghost'),
    'closed' => $statusBtn('open', $t('training.open_enrol'), 'secondary') . $statusBtn('completed', $t('training.complete'), 'ghost'),
    default => '',
};
$count = static fn (string $status): int => count(array_filter($roster, static fn (array $r): bool => $r['status'] === $status));
?>
<?= $ui('page-header', ['title' => (string) $s['title'], 'subtitle' => $s['code'] . ' · ' . $t('training.session_status.' . $st), 'crumbs' => [[$t('training.admin_title'), $url('/training/admin')], [(string) $s['code'], null]], 'actions' => $actions]) ?>
<?php if ($st === 'cancelled'): ?><?= $ui('alert', ['tone' => 'info', 'text' => $t('training.cancelled_note', ['reason' => (string) $s['cancel_reason']])]) ?><?php endif; ?>
<ul class="funnel">
  <li><span class="text-sm text-muted"><?= $e($t('training.col.when')) ?></span><strong class="text-sm"><?php include __DIR__ . '/_when.php'; ?></strong></li>
  <li><span class="text-sm text-muted"><?= $e($t('training.enr.enrolled')) ?></span><strong><?= $count('enrolled') + $count('attended') + $count('passed') + $count('failed') + $count('no_show') ?> / <?= (int) $s['seats'] ?></strong></li>
  <li><span class="text-sm text-muted"><?= $e($t('training.enr.waitlisted')) ?></span><strong><?= $count('waitlisted') ?></strong></li>
  <li><span class="text-sm text-muted"><?= $e($t('training.enr.pending')) ?></span><strong><?= $count('pending') ?></strong></li>
  <li><span class="text-sm text-muted"><?= $e($t('training.enr.attended')) ?></span><strong><?= $count('attended') + $count('passed') + $count('failed') ?></strong></li>
</ul>
<?php if ($live): ?>
<section class="card stack-sm" aria-labelledby="tw-c" id="checkin">
  <h2 id="tw-c" class="card__title"><?= $e($t('training.checkin')) ?></h2>
  <?php if ($scan !== null): ?><?= $ui('alert', ['tone' => $scan['result'] === 'ok' ? 'success' : ($scan['result'] === 'already' ? 'info' : 'danger'), 'live' => true,
      'text' => $t('training.scan.' . $scan['result'], ['name' => (string) ($scan['name'] ?? '')])]) ?><?php endif; ?>
  <?php if (!$started): ?><p class="text-sm text-muted"><?= $e($t('training.checkin_later')) ?></p><?php else: ?>
  <form method="post" action="<?= $e($url($base . '/checkin')) ?>" class="row row--tight" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'scan', 'label' => $t('training.scan_label'), 'autocomplete' => 'off', 'autofocus' => true, 'help' => $t('training.scan_help')]) ?>
    <?= $ui('button', ['label' => $t('training.checkin_do'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'qrcode']) ?>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>
<section class="card stack-sm" aria-labelledby="tw-r">
  <h2 id="tw-r" class="card__title"><?= $e($t('training.roster', ['n' => (string) count($roster)])) ?></h2>
  <?php if ($roster === []): ?><p class="text-sm text-muted"><?= $e($t('training.none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="tw-r" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('training.col.employee')) ?></th><th scope="col"><?= $e($t('training.col.status')) ?></th><th scope="col"><?= $e($t('training.col.checked')) ?></th><th scope="col"><?= $e($t('training.col.score')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($roster as $r): $stE = (string) $r['status']; ?>
        <tr><th scope="row" data-label="<?= $e($t('training.col.employee')) ?>"><?= $e($r['employee_code'] . ' ' . $r['name_th']) ?><span class="d-block text-sm text-muted"><?= $e(trim(($r['dept_code'] ?? '') . ' · ' . ($r['position_title'] ?? ''), ' ·')) ?> · <?= $e($t('training.source.' . $r['source'])) ?></span></th>
          <td data-label="<?= $e($t('training.col.status')) ?>"><?php $st0 = $st; $st = $stE; include __DIR__ . '/_enr.php'; $st = $st0; ?></td>
          <td data-label="<?= $e($t('training.col.checked')) ?>"><?= $r['attended_at'] !== null ? $e($date((string) $r['attended_at']) . ' · ' . $t('training.method.' . $r['check_method'])) : '—' ?></td>
          <td data-label="<?= $e($t('training.col.score')) ?>"><?= $r['score'] !== null ? $e((string) (float) $r['score']) : '—' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php if ($live && $started && $count('enrolled') > 0): ?>
<details class="card stack-sm"<?= $form === 'attend' ? ' open' : '' ?>>
  <summary class="card__title"><?= $e($t('training.attend_list')) ?></summary>
  <form method="post" action="<?= $e($url($base . '/attend')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <fieldset class="stack-sm"><legend class="sr-only"><?= $e($t('training.attend_list')) ?></legend>
    <?php foreach ($roster as $r): if ($r['status'] !== 'enrolled') { continue; } ?>
      <label class="check"><input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>"> <?= $e($r['employee_code'] . ' ' . $r['name_th']) ?></label>
    <?php endforeach; ?></fieldset>
    <div class="row"><?= $ui('button', ['label' => $t('training.mark_attended'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'checks']) ?></div>
  </form>
</details>
<?php endif; ?>
<?php if ($live && $started): ?>
<section class="card stack-sm" aria-labelledby="tw-e">
  <h2 id="tw-e" class="card__title"><?= $e($t('training.results')) ?></h2>
  <p class="text-sm text-muted"><?= $e($s['pass_score'] !== null ? $t('training.results_help_score', ['p' => (string) $s['pass_score']]) : $t('training.results_help_attend')) ?></p>
  <?php if (isset($errors) && $form === 'evaluate' && $errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'text' => implode(' ', array_unique($errors))]) ?><?php endif; ?>
  <form method="post" action="<?= $e($url($base . '/evaluate')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?php if ($s['pass_score'] !== null): ?>
      <div class="grid grid--3">
      <?php foreach ($roster as $r): if (!in_array($r['status'], ['attended', 'passed', 'failed'], true)) { continue; } ?>
        <?= $ui('field', ['name' => 'score[' . (int) $r['id'] . ']', 'id' => 'ts-' . (int) $r['id'], 'label' => $r['employee_code'] . ' ' . $r['name_th'], 'inputmode' => 'decimal',
            'value' => $form === 'evaluate' && is_scalar(($old['score'] ?? [])[(int) $r['id']] ?? null) ? (string) $old['score'][(int) $r['id']] : ($r['score'] !== null ? (string) (float) $r['score'] : ''), 'error' => $errors['score_' . $r['id']] ?? null]) ?>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <label class="check"><input type="checkbox" name="no_shows" value="1"> <?= $e($t('training.no_shows', ['n' => (string) $count('enrolled')])) ?></label>
    <div class="row"><?= $ui('button', ['label' => $t('training.save_results'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
  </form>
</section>
<?php endif; ?>
<section class="card stack-sm" aria-labelledby="tw-x">
  <h2 id="tw-x" class="card__title"><?= $e($t('training.certificates')) ?></h2>
  <?php if ($count('passed') > 0): ?>
    <form method="post" action="<?= $e($url($base . '/certs')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('training.issue_certs'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'file-text']) ?></form>
  <?php endif; ?>
  <?php if ($certs === []): ?><p class="text-sm text-muted"><?= $e($t('training.no_certs')) ?></p><?php else: ?>
  <ul class="plain-list stack-sm">
    <?php foreach ($certs as $c): ?>
      <li><a href="<?= $e($url('/training/c/' . $c['public_id'])) ?>"><?= $e($c['cert_no'] . ' v' . $c['version_no']) ?></a> · <?= $e($c['employee_code'] . ' ' . $c['name_th']) ?>
        <?= $ui('badge', ['tone' => $c['status'] === 'valid' ? 'success' : 'neutral', 'label' => $t('training.cert_status.' . $c['status'])]) ?>
        <?= $c['expires_on'] !== null ? '<span class="text-sm text-muted">' . $e($t('training.expires_on', ['d' => $date((string) $c['expires_on'], false)])) . '</span>' : '' ?>
        <?= $c['revoke_reason'] !== null ? '<span class="d-block text-sm text-muted">' . $e((string) $c['revoke_reason']) . '</span>' : '' ?>
        <?php if ($c['status'] === 'valid'): ?>
          <details class="d-block"><summary class="text-sm"><?= $e($t('training.revoke')) ?></summary>
            <form method="post" action="<?= $e($url('/training/admin/cert/' . $c['public_id'] . '/revoke')) ?>" class="stack-sm" novalidate><?= $csrf ?>
              <?= $ui('field', ['name' => 'reason', 'id' => 'tr-' . $c['public_id'], 'label' => $t('training.reason'), 'required' => true]) ?>
              <label class="check"><input type="checkbox" name="reissue" value="1" checked> <?= $e($t('training.reissue')) ?></label>
              <?= $ui('button', ['label' => $t('training.revoke'), 'type' => 'submit', 'variant' => 'danger', 'size' => 'sm']) ?></form></details>
        <?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php if (in_array($st, ['planned', 'open', 'closed'], true)): ?>
<div class="grid grid--2">
<details class="card stack-sm"<?= $form === 'enroll' ? ' open' : '' ?>>
  <summary class="card__title"><?= $e($t('training.enrol_people')) ?></summary>
  <form method="post" action="<?= $e($url($base . '/enroll')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'codes', 'type' => 'textarea', 'label' => $t('training.codes'), 'required' => true, 'help' => $t('training.codes_help')]) ?>
    <?= $ui('button', ['label' => $t('training.enrol_do'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'plus']) ?>
  </form>
</details>
<details class="card stack-sm"<?= $form === 'status' ? ' open' : '' ?>>
  <summary class="card__title"><?= $e($t('training.cancel_session')) ?></summary>
  <form method="post" action="<?= $e($url($base . '/status')) ?>" class="stack-sm" novalidate><?= $csrf ?><input type="hidden" name="to" value="cancelled">
    <?= $ui('field', ['name' => 'reason', 'label' => $t('training.reason'), 'required' => true, 'error' => $form === 'status' ? ($errors['reason'] ?? null) : null, 'help' => $t('training.cancel_help')]) ?>
    <?= $ui('button', ['label' => $t('training.cancel_session'), 'type' => 'submit', 'variant' => 'danger', 'icon' => 'x']) ?>
  </form>
</details>
</div>
<details class="card stack"<?= $form === 'details' ? ' open' : '' ?>>
  <summary class="card__title"><?= $e($t('training.edit_session')) ?></summary>
  <form method="post" action="<?= $e($url($base)) ?>" class="stack" novalidate><?= $csrf ?>
    <?php $o = static fn (string $k, string $d = ''): string => $form === 'details' && is_scalar($old[$k] ?? null) ? (string) $old[$k] : (string) ($k === 'course' ? $s['course_pid'] : ($s[$k] ?? $d)); ?>
    <?php include __DIR__ . '/_session_fields.php'; ?>
    <div class="row"><?= $ui('button', ['label' => $t('training.save'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
  </form>
</details>
<?php endif; ?>
