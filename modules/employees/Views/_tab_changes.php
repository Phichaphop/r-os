<?php
/**
 * Changes tab: requests (who asked, who decided, applied or not), ask for an employment / status change,
 * exit checklist. Uses the variables of show.php + $requests, $offboarding, $managers, $labelled.
 */
use Blue\Modules\Employees\ChangeService;
use Blue\Modules\Employees\FileRules;

$job = $emp['job'];
$jobOld = $form === 'job' ? $old : ($job ?? []) + ['effective_from' => '', 'change_reason' => ''];
if ($form !== 'job') {
    $jobOld['effective_from'] = '';
    $jobOld['change_reason'] = '';
}
$st = $form === 'status' ? $old : [];
$targets = [];
foreach (ChangeService::STATUS_TARGETS as $s) {
    if ($s !== $emp['status']) {
        $targets[$s] = $t('employees.status.' . $s);
    }
}
$sep = [];
foreach (FileRules::SEPARATION_TYPES as $s) {
    $sep[$s] = $t('employees.separation.' . $s);
}
$ownerLabel = static fn (string $o): string => $t('employees.owner.' . $o);
?>
<section class="card card--flush" aria-labelledby="ec-list">
  <div class="card__head"><h2 id="ec-list" class="card__title"><?= $e($t('employees.section.requests')) ?></h2></div>
  <div class="table-wrap" role="region" aria-labelledby="ec-list" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('employees.change_kind_label')) ?></th><th scope="col"><?= $e($t('employees.field.effective_from')) ?></th><th scope="col"><?= $e($t('employees.field.change')) ?></th>
        <th scope="col"><?= $e($t('employees.field.status')) ?></th><th scope="col"><span class="sr-only"><?= $e($t('employees.actions')) ?></span></th></tr></thead>
      <tbody>
      <?php foreach ($requests as $q): ?>
        <?php $after = $labelled((array) json_decode((string) $q['payload_json'], true)); $before = $labelled((array) json_decode((string) ($q['before_json'] ?? '[]'), true)); ?>
        <tr>
          <td data-label="<?= $e($t('employees.change_kind_label')) ?>"><?= $e($t('employees.change_kind.' . $q['kind'])) ?>
            <div class="text-sm text-muted"><?= $e((string) $q['requested_name']) ?><?= $q['approved_name'] !== null ? ' → ' . $e((string) $q['approved_name']) : '' ?></div></td>
          <td data-label="<?= $e($t('employees.field.effective_from')) ?>"><?= $e($day((string) $q['effective_from'])) ?>
            <?php if ((int) $q['retroactive'] === 1): ?><?= $ui('badge', ['tone' => 'warning', 'label' => $t('employees.retroactive')]) ?><?php endif; ?></td>
          <td data-label="<?= $e($t('employees.field.change')) ?>"><ul class="list-plain text-sm">
            <?php foreach ($after as $k => $val): ?><?php if (($before[$k] ?? null) !== $val || $q['kind'] === 'status'): ?>
              <li><?= $e($t('employees.field.' . $k)) ?>: <?= isset($before[$k]) ? $e($before[$k]) . ' → ' : '' ?><?= $e($val) ?></li>
            <?php endif; ?><?php endforeach; ?>
            <li class="text-muted">“<?= $e((string) $q['reason']) ?>”</li></ul></td>
          <td data-label="<?= $e($t('employees.field.status')) ?>"><?= $reqBadge((string) $q['status']) ?>
            <?php if ($q['error'] !== null): ?><div class="text-sm text-danger"><?= $e($t((string) $q['error'])) ?></div><?php endif; ?>
            <?php if ($q['instance_pid'] !== null): ?><div class="text-sm"><a href="<?= $e($url('/requests/' . $q['instance_pid'])) ?>"><?= $e($t('employees.see_request')) ?></a></div><?php endif; ?></td>
          <td>
            <?php if (in_array($q['status'], ['pending', 'returned'], true) && (int) $q['requested_by'] === (int) $userId): ?>
              <form method="post" action="<?= $e($url($base . '/changes/' . $q['public_id'] . '/withdraw')) ?>"><?= $csrf ?>
                <?= $ui('button', ['label' => $t('employees.withdraw'), 'type' => 'submit', 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'x']) ?></form>
            <?php endif; ?>
            <?php if ($q['status'] === 'failed' && $acc['manage']): ?>
              <form method="post" action="<?= $e($url($base . '/changes/' . $q['public_id'] . '/retry')) ?>"><?= $csrf ?>
                <?= $ui('button', ['label' => $t('employees.retry'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'refresh']) ?></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($requests === []): ?><tr><td colspan="5"><?= $e($t('employees.none_yet')) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php if ($offboarding !== []): ?>
<section class="card card--flush" aria-labelledby="ec-off">
  <div class="card__head"><h2 id="ec-off" class="card__title"><?= $e($t('employees.section.offboarding')) ?></h2></div>
  <div class="table-wrap" role="region" aria-labelledby="ec-off" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('employees.field.item')) ?></th><th scope="col"><?= $e($t('employees.field.owner')) ?></th><th scope="col"><?= $e($t('employees.field.due')) ?></th>
        <th scope="col"><?= $e($t('employees.field.status')) ?></th><th scope="col"><span class="sr-only"><?= $e($t('employees.actions')) ?></span></th></tr></thead>
      <tbody>
      <?php foreach ($offboarding as $o): ?>
        <tr>
          <td data-label="<?= $e($t('employees.field.item')) ?>"><?= $e((string) $o['label']) ?><?php if ($o['note'] !== null): ?><div class="text-sm text-muted">“<?= $e((string) $o['note']) ?>”</div><?php endif; ?></td>
          <td data-label="<?= $e($t('employees.field.owner')) ?>"><?= $e($ownerLabel((string) $o['owner'])) ?></td>
          <td data-label="<?= $e($t('employees.field.due')) ?>"><?= $e($day($o['due_date'] !== null ? (string) $o['due_date'] : null)) ?></td>
          <td data-label="<?= $e($t('employees.field.status')) ?>"><?= $ui('badge', ['tone' => $o['status'] === 'open' ? 'warning' : 'success', 'label' => $t('employees.offb_status.' . $o['status'])]) ?>
            <?php if ($o['done_name'] !== null): ?><div class="text-sm text-muted"><?= $e((string) $o['done_name']) ?></div><?php endif; ?></td>
          <td>
            <?php if ($acc['manage']): ?>
            <form method="post" action="<?= $e($url($base . '/offboarding/' . $o['public_id'])) ?>" class="row row--wrap" novalidate>
              <?= $csrf ?>
              <?= $ui('field', ['name' => 'status', 'id' => 'os-' . $o['public_id'], 'type' => 'select', 'label' => $t('employees.field.status'), 'value' => (string) $o['status'],
                  'options' => ['open' => $t('employees.offb_status.open'), 'done' => $t('employees.offb_status.done'), 'not_applicable' => $t('employees.offb_status.not_applicable')]]) ?>
              <?= $ui('field', ['name' => 'note', 'id' => 'on-' . $o['public_id'], 'label' => $t('employees.field.note'), 'value' => (string) ($o['note'] ?? ''),
                  'error' => ($old['item'] ?? '') === $o['public_id'] ? ($errors['note'] ?? null) : null]) ?>
              <?= $ui('button', ['label' => $t('employees.save'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm']) ?>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<?php if ($acc['manage'] && $emp['status'] !== 'former'): ?>
<section class="card stack" aria-labelledby="ec-job">
  <details<?= $form === 'job' ? ' open' : '' ?>>
    <summary><h2 id="ec-job" class="card__title d-inline"><?= $e($t('employees.change_job')) ?></h2></summary>
    <p class="text-sm text-muted"><?= $e($t('employees.change_job_help')) ?></p>
    <form method="post" action="<?= $e($url($base . '/employment')) ?>" class="stack" novalidate>
      <?= $csrf ?>
      <?php $old = $jobOld; include __DIR__ . '/_job.php'; ?>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'effective_from', 'type' => 'date', 'label' => $t('employees.field.effective_from'), 'required' => true, 'value' => (string) ($jobOld['effective_from'] ?? ''),
            'error' => $errors['effective_from'] ?? null, 'help' => $t('employees.effective_help')]) ?>
        <?= $ui('field', ['name' => 'change_reason', 'label' => $t('employees.field.reason'), 'required' => true, 'value' => (string) ($jobOld['change_reason'] ?? ''), 'error' => $errors['change_reason'] ?? null]) ?>
      </div>
      <div class="row"><?= $ui('button', ['label' => $t('employees.send_change'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
    </form>
  </details>
</section>

<section class="card stack" aria-labelledby="ec-st">
  <details<?= $form === 'status' ? ' open' : '' ?>>
    <summary><h2 id="ec-st" class="card__title d-inline"><?= $e($t('employees.change_status')) ?></h2></summary>
    <p class="text-sm text-muted"><?= $e($t('employees.change_status_help')) ?></p>
    <form method="post" action="<?= $e($url($base . '/status')) ?>" class="stack" novalidate>
      <?= $csrf ?>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'to_status', 'type' => 'select', 'label' => $t('employees.field.to_status'), 'required' => true, 'value' => (string) ($st['to_status'] ?? ''), 'options' => ['' => '—'] + $targets,
            'error' => $errors['to_status'] ?? null]) ?>
        <?= $ui('field', ['name' => 'effective_from', 'id' => 'st-from', 'type' => 'date', 'label' => $t('employees.field.effective_from'), 'value' => (string) ($st['effective_from'] ?? ''),
            'error' => $form === 'status' ? ($errors['effective_from'] ?? null) : null, 'help' => $t('employees.status_from_help')]) ?>
        <?= $ui('field', ['name' => 'last_working_day', 'type' => 'date', 'label' => $t('employees.field.last_working_day'), 'value' => (string) ($st['last_working_day'] ?? ''),
            'error' => $errors['last_working_day'] ?? null, 'help' => $t('employees.last_day_help')]) ?>
        <?= $ui('field', ['name' => 'separation_type', 'type' => 'select', 'label' => $t('employees.field.separation_type'), 'value' => (string) ($st['separation_type'] ?? ''), 'options' => ['' => '—'] + $sep,
            'error' => $errors['separation_type'] ?? null]) ?>
        <?= $ui('field', ['name' => 'reason', 'type' => 'textarea', 'label' => $t('employees.field.reason'), 'required' => true, 'value' => (string) ($st['reason'] ?? ''), 'error' => $errors['reason'] ?? null, 'full' => true]) ?>
      </div>
      <div class="row"><?= $ui('button', ['label' => $t('employees.send_change'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
    </form>
  </details>
</section>
<?php endif; ?>
