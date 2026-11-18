<?php
/**
 * One appraisal — what each person may see and do: self review, manager review, final review (calibration), result +
 * acknowledge / appeal, HR actions (evaluator, skip self review, appeal outcome), earlier cycles and the history.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $a @var list<array<string, mixed>> $events @var list<array<string, mixed>> $previous @var string $today
 * @var array<string, string> $errors @var array<string, mixed> $old @var string $form
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$st = (string) $a['status'];
$f = (array) $a['form'];
$max = (int) ($f['scale_max'] ?? 5);
$items = (array) ($f['items'] ?? []);
$num = static fn (mixed $n): string => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
$open = $a['cycle_status'] === 'open';
$editSelf = $a['is_self'] && $st === 'self_review' && $open;
$editMgr = $a['is_manager'] && $st === 'manager_review' && $open;
$canFinal = $st === 'final_review' && $open && !$a['is_self'] && !$a['is_manager'] && ($a['is_hr'] || $a['is_reviewer']);
$oldText = static fn (string $k, mixed $d): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : (string) ($d ?? '');
$steps = (int) $a['final_review'] === 1 ? ['self_review', 'manager_review', 'final_review', 'finalized', 'acknowledged'] : ['self_review', 'manager_review', 'finalized', 'acknowledged'];
$at = array_search($st, $steps, true);
$back = $a['is_hr'] && !$a['is_manager'] ? [$t('appraisal.admin_title'), $url('/appraisal/admin/c/' . $a['cycle_pid'])] : [$t('appraisal.title'), $url('/appraisal')];
$sectionLabel = static fn (string $s): string => $t('appraisal.section.' . $s);
?>
<?= $ui('page-header', ['title' => $a['employee_code'] . ' · ' . $a['name_th'], 'subtitle' => (string) $a['cycle_name'], 'crumbs' => [$back, [(string) $a['name_th'], null]]]) ?>
<ol class="steps<?= count($steps) === 5 ? ' steps--5' : '' ?>" aria-label="<?= $e($t('appraisal.progress')) ?>">
  <?php foreach ($steps as $i => $s): ?><li class="steps__item<?= $at !== false && $i < $at ? ' is-done' : '' ?><?= $i === $at ? ' is-current' : '' ?>"<?= $i === $at ? ' aria-current="step"' : '' ?>><?= $e($t('appraisal.status.' . $s)) ?></li><?php endforeach; ?>
</ol>
<?php if ($a['is_self'] && in_array($st, ['finalized', 'acknowledged'], true) && !$a['released']): ?>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('appraisal.wait_results', ['d' => $date((string) $a['results_on'], false)])]) ?>
<?php endif; ?>
<?php if ($a['is_self'] && in_array($st, ['manager_review', 'final_review'], true)): ?>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('appraisal.self_sent')]) ?>
<?php endif; ?>
<?php if ($st === 'manager_review' && $a['is_manager'] && $a['reviewer_comment'] !== null): ?>
  <?= $ui('alert', ['tone' => 'warning', 'text' => $t('appraisal.returned_note', ['note' => (string) $a['reviewer_comment']])]) ?>
<?php endif; ?>
<div class="grid grid--2">
  <section class="card stack-sm" aria-labelledby="as-d">
    <h2 id="as-d" class="card__title"><?= $e($t('appraisal.details')) ?></h2>
    <dl class="facts">
      <div><dt><?= $e($t('appraisal.field.period')) ?></dt><dd><?= $e($t('appraisal.period.' . $a['period'])) ?></dd></div>
      <div><dt><?= $e($t('appraisal.field.form')) ?></dt><dd><?= $e(($f['code'] ?? '') . ' · ' . ($f['name'] ?? '')) ?></dd></div>
      <div><dt><?= $e($t('appraisal.col.manager')) ?></dt><dd><?= $e((string) ($a['manager_name'] ?? $t('appraisal.nobody'))) ?></dd></div>
      <?php if ((int) $a['final_review'] === 1): ?><div><dt><?= $e($t('appraisal.col.reviewer')) ?></dt><dd><?= $e((string) ($a['reviewer_name'] ?? $t('appraisal.reviewer_auto'))) ?></dd></div><?php endif; ?>
      <div><dt><?= $e($t('appraisal.field.self_due')) ?></dt><dd><?= $e($date((string) $a['self_due'], false)) ?></dd></div>
      <div><dt><?= $e($t('appraisal.field.manager_due')) ?></dt><dd><?= $e($date((string) $a['manager_due'], false)) ?></dd></div>
      <?php if ($a['final_due'] !== null): ?><div><dt><?= $e($t('appraisal.field.final_due')) ?></dt><dd><?= $e($date((string) $a['final_due'], false)) ?></dd></div><?php endif; ?>
      <div><dt><?= $e($t('appraisal.field.results_on')) ?></dt><dd><?= $e($date((string) $a['results_on'], false)) ?></dd></div>
    </dl>
  </section>
  <section class="card stack-sm" aria-labelledby="as-s">
    <h2 id="as-s" class="card__title"><?= $e($t('appraisal.scores')) ?></h2>
    <dl class="facts">
      <div><dt><?= $e($t('appraisal.col.self')) ?></dt><dd><?= $e($a['show_self'] ? $num($a['self_score']) : $t('appraisal.hidden_until_sent')) ?></dd></div>
      <?php if ($a['show_manager']): ?>
      <div><dt><?= $e($t('appraisal.col.manager_score')) ?></dt><dd><?= $e($num($a['manager_score'])) ?></dd></div>
      <div><dt><?= $e($t('appraisal.col.final')) ?></dt><dd><strong><?= $e($num($a['final_score'])) ?></strong><?= $a['rating_label'] !== null ? ' ' . $ui('badge', ['tone' => 'info', 'label' => (string) $a['rating_label']]) : '' ?></dd></div>
      <?php if ($a['calibration_reason'] !== null): ?><div><dt><?= $e($t('appraisal.calibrated')) ?></dt><dd><?= $e((string) $a['calibration_reason']) ?></dd></div><?php endif; ?>
      <?php else: ?>
      <div><dt><?= $e($t('appraisal.col.final')) ?></dt><dd><?= $e($t('appraisal.hidden_until_results')) ?></dd></div>
      <?php endif; ?>
    </dl>
    <p class="text-sm text-muted"><?= $e($t('appraisal.formula', ['max' => (string) $max])) ?></p>
    <?php if ((array) ($f['bands'] ?? []) !== []): ?>
      <p class="text-sm text-muted"><?= $e($t('appraisal.bands_are')) ?> <?= $e(implode(' · ', array_map(static fn (array $b): string => '≥ ' . rtrim(rtrim((string) $b['from'], '0'), '.') . ' ' . $b['label'], (array) $f['bands']))) ?></p>
    <?php endif; ?>
  </section>
</div>

<?php if ($editSelf || $editMgr): $who = $editSelf ? 'self' : 'manager'; ?>
<form method="post" action="<?= $e($url('/appraisal/a/' . $a['public_id'] . '/' . $who)) ?>" class="stack" novalidate>
  <?= $csrf ?>
  <?php if ($errors !== [] && in_array($form, ['self', 'manager'], true)): ?><?= $ui('alert', ['tone' => 'danger', 'text' => $t('appraisal.fix_errors')]) ?><?php endif; ?>
  <?php foreach ($items as $no => $it): $sc = $a['scores'][$no] ?? [];
      $cur = is_array($old['score'] ?? null) ? (string) ($old['score'][$no] ?? '') : (string) ($sc[$who . '_score'] ?? '');
      $note = is_array($old['note'] ?? null) ? (string) ($old['note'][$no] ?? '') : (string) ($sc[$who . '_note'] ?? '');
      $err = $errors['item_' . $no] ?? null; ?>
  <fieldset class="card stack-sm<?= $err !== null ? ' is-invalid' : '' ?>" id="item-<?= (int) $no ?>">
    <legend class="sr-only"><?= $e($it['title']) ?></legend>
    <p class="text-sm text-muted"><?= $e($sectionLabel((string) $it['section'])) ?> · <?= $e($t('appraisal.weight_n', ['w' => $num($it['weight'])])) ?></p>
    <h3 class="card__title"><?= $e((string) $it['title']) ?></h3>
    <?php if ((string) ($it['description'] ?? '') !== ''): ?><p class="text-sm pre-line"><?= $e((string) $it['description']) ?></p><?php endif; ?>
    <?php if ($editMgr && $a['show_self']): ?>
      <p class="text-sm"><?= $e($t('appraisal.self_gave', ['s' => $sc['self_score'] !== null && $sc !== [] ? (string) $sc['self_score'] : '—'])) ?><?= ($sc['self_note'] ?? null) !== null ? ' · ' . $e((string) $sc['self_note']) : '' ?></p>
    <?php endif; ?>
    <div class="rating-row" role="radiogroup" aria-label="<?= $e($t('appraisal.score_for', ['item' => (string) $it['title']])) ?>">
      <?php for ($i = 1; $i <= $max; $i++): ?><label class="rating-row__item"><input type="radio" name="score[<?= (int) $no ?>]" value="<?= $i ?>"<?= $cur === (string) $i ? ' checked' : '' ?>> <span><?= $i ?></span></label><?php endfor; ?>
    </div>
    <?php if ($err !== null): ?><p class="field__error"><?= $e($err) ?></p><?php endif; ?>
    <?= $ui('field', ['name' => 'note[' . (int) $no . ']', 'id' => 'note-' . (int) $no, 'type' => 'textarea', 'label' => $t('appraisal.field.note'), 'value' => $note]) ?>
  </fieldset>
  <?php endforeach; ?>
  <section class="card stack-sm">
    <?php if ($editSelf): ?>
      <?= $ui('field', ['name' => 'self_comment', 'type' => 'textarea', 'label' => $t('appraisal.field.self_comment'), 'value' => $oldText('self_comment', $a['self_comment'])]) ?>
    <?php else: ?>
      <?php if ($a['self_comment'] !== null): ?><p class="text-sm"><strong><?= $e($t('appraisal.field.self_comment')) ?>:</strong> <?= $e((string) $a['self_comment']) ?></p><?php endif; ?>
      <?= $ui('field', ['name' => 'strengths', 'type' => 'textarea', 'label' => $t('appraisal.field.strengths'), 'value' => $oldText('strengths', $a['strengths'])]) ?>
      <?= $ui('field', ['name' => 'manager_comment', 'type' => 'textarea', 'label' => $t('appraisal.field.manager_comment'), 'required' => true, 'value' => $oldText('manager_comment', $a['manager_comment']), 'error' => $errors['manager_comment'] ?? null]) ?>
      <?= $ui('field', ['name' => 'development_plan', 'type' => 'textarea', 'label' => $t('appraisal.field.development_plan'), 'required' => true, 'value' => $oldText('development_plan', $a['development_plan']), 'help' => $t('appraisal.plan_help'), 'error' => $errors['development_plan'] ?? null]) ?>
      <?= $ui('field', ['name' => 'comp_proposal', 'label' => $t('appraisal.field.comp_proposal'), 'value' => $oldText('comp_proposal', $a['comp_proposal']), 'help' => $t('appraisal.comp_help')]) ?>
    <?php endif; ?>
    <p class="text-sm text-muted"><?= $e($t('appraisal.submit_help')) ?></p>
    <div class="row">
      <?= $ui('button', ['label' => $t('appraisal.save_draft'), 'type' => 'submit', 'variant' => 'secondary', 'attrs' => ['name' => 'submit', 'value' => '0']]) ?>
      <?= $ui('button', ['label' => $t($editSelf ? 'appraisal.submit_self' : 'appraisal.submit_manager'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send', 'attrs' => ['name' => 'submit', 'value' => '1']]) ?>
    </div>
  </section>
</form>
<?php else: ?>
<section class="card stack" aria-labelledby="as-i">
  <h2 id="as-i" class="card__title"><?= $e($t('appraisal.items')) ?></h2>
  <div class="table-wrap" role="region" aria-labelledby="as-i" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('appraisal.col.item')) ?></th><th scope="col"><?= $e($t('appraisal.col.weight')) ?></th><th scope="col"><?= $e($t('appraisal.col.self')) ?></th><th scope="col"><?= $e($t('appraisal.col.manager_score')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($items as $no => $it): $sc = $a['scores'][$no] ?? []; ?>
        <tr><th scope="row" data-label="<?= $e($t('appraisal.col.item')) ?>"><?= $e((string) $it['title']) ?><span class="d-block text-sm text-muted"><?= $e($sectionLabel((string) $it['section'])) ?></span></th>
          <td data-label="<?= $e($t('appraisal.col.weight')) ?>"><?= $e($num($it['weight'])) ?></td>
          <td data-label="<?= $e($t('appraisal.col.self')) ?>"><?= $e($a['show_self'] ? $num($sc['self_score'] ?? null) : '—') ?><?= $a['show_self'] && ($sc['self_note'] ?? null) !== null ? '<span class="d-block text-sm">' . $e((string) $sc['self_note']) . '</span>' : '' ?></td>
          <td data-label="<?= $e($t('appraisal.col.manager_score')) ?>"><?= $e($a['show_manager'] ? $num($sc['manager_score'] ?? null) : '—') ?><?= $a['show_manager'] && ($sc['manager_note'] ?? null) !== null ? '<span class="d-block text-sm">' . $e((string) $sc['manager_note']) . '</span>' : '' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <dl class="facts">
    <?php if ($a['show_self'] && $a['self_comment'] !== null): ?><div><dt><?= $e($t('appraisal.field.self_comment')) ?></dt><dd class="pre-line"><?= $e((string) $a['self_comment']) ?></dd></div><?php endif; ?>
    <?php if ($a['show_manager']): ?>
      <?php foreach (['strengths', 'manager_comment', 'development_plan', 'comp_proposal', 'reviewer_comment'] as $k): if (($a[$k] ?? null) === null) { continue; } ?>
        <div><dt><?= $e($t('appraisal.field.' . $k)) ?></dt><dd class="pre-line"><?= $e((string) $a[$k]) ?></dd></div>
      <?php endforeach; ?>
    <?php endif; ?>
    <?php if ($a['employee_comment'] !== null && ($a['is_self'] || $a['is_hr'] || $a['is_manager'])): ?><div><dt><?= $e($t((int) $a['appeal'] === 1 ? 'appraisal.appeal_reason' : 'appraisal.field.employee_comment')) ?></dt><dd class="pre-line"><?= $e((string) $a['employee_comment']) ?></dd></div><?php endif; ?>
    <?php if ($a['appeal_outcome'] !== null): ?><div><dt><?= $e($t('appraisal.appeal_outcome')) ?></dt><dd class="pre-line"><?= $e((string) $a['appeal_outcome']) ?></dd></div><?php endif; ?>
  </dl>
</section>
<?php endif; ?>

<?php if ($canFinal): ?>
<section class="card stack" aria-labelledby="as-f">
  <h2 id="as-f" class="card__title"><?= $e($t('appraisal.final_title')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('appraisal.final_help')) ?></p>
  <form method="post" action="<?= $e($url('/appraisal/a/' . $a['public_id'] . '/final')) ?>" class="stack" novalidate>
    <?= $csrf ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'calibrated', 'label' => $t('appraisal.field.calibrated'), 'inputmode' => 'decimal', 'value' => $oldText('calibrated', ''), 'help' => $t('appraisal.calibrated_help', ['s' => $num($a['manager_score']), 'max' => (string) $max]), 'error' => $errors['calibrated'] ?? null]) ?>
      <?= $ui('field', ['name' => 'calibration_reason', 'label' => $t('appraisal.field.calibration_reason'), 'value' => $oldText('calibration_reason', ''), 'error' => $errors['calibration_reason'] ?? null]) ?>
    </div>
    <?= $ui('field', ['name' => 'reviewer_comment', 'type' => 'textarea', 'label' => $t('appraisal.field.reviewer_comment'), 'value' => $oldText('reviewer_comment', ''), 'help' => $t('appraisal.return_help'), 'error' => $errors['reviewer_comment'] ?? null]) ?>
    <div class="row">
      <?= $ui('button', ['label' => $t('appraisal.approve_final'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check', 'attrs' => ['name' => 'decision', 'value' => 'approve']]) ?>
      <?= $ui('button', ['label' => $t('appraisal.return_manager'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'arrow-back-up', 'attrs' => ['name' => 'decision', 'value' => 'return']]) ?>
    </div>
  </form>
</section>
<?php endif; ?>

<?php if ($a['is_self'] && $st === 'finalized' && $a['released']): ?>
<section class="card stack" aria-labelledby="as-k">
  <h2 id="as-k" class="card__title"><?= $e($t('appraisal.ack_title')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('appraisal.ack_help')) ?></p>
  <form method="post" action="<?= $e($url('/appraisal/a/' . $a['public_id'] . '/ack')) ?>" class="stack" novalidate>
    <?= $csrf ?>
    <?= $ui('field', ['name' => 'employee_comment', 'type' => 'textarea', 'label' => $t('appraisal.field.employee_comment'), 'value' => $oldText('employee_comment', ''), 'help' => $t('appraisal.appeal_help'), 'error' => $errors['employee_comment'] ?? null]) ?>
    <div class="row">
      <?= $ui('button', ['label' => $t('appraisal.acknowledge'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check', 'attrs' => ['name' => 'appeal', 'value' => '0']]) ?>
      <?= $ui('button', ['label' => $t('appraisal.appeal'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'alert-circle', 'attrs' => ['name' => 'appeal', 'value' => '1']]) ?>
    </div>
  </form>
</section>
<?php endif; ?>

<?php if ($a['is_hr']): ?>
<section class="card stack" aria-labelledby="as-h">
  <h2 id="as-h" class="card__title"><?= $e($t('appraisal.hr_actions')) ?></h2>
  <?php if ((int) $a['appeal'] === 1 && $a['appeal_status'] === 'open'): ?>
  <form method="post" action="<?= $e($url('/appraisal/a/' . $a['public_id'] . '/appeal')) ?>" class="stack" novalidate>
    <?= $csrf ?>
    <?= $ui('alert', ['tone' => 'warning', 'text' => $t('appraisal.appeal_open')]) ?>
    <?= $ui('field', ['name' => 'outcome', 'type' => 'textarea', 'label' => $t('appraisal.appeal_outcome'), 'required' => true, 'value' => $oldText('outcome', ''), 'error' => $errors['outcome'] ?? null]) ?>
    <div class="row">
      <?= $ui('button', ['label' => $t('appraisal.appeal_close'), 'type' => 'submit', 'variant' => 'primary', 'attrs' => ['name' => 'outcome_action', 'value' => 'close']]) ?>
      <?= $ui('button', ['label' => $t('appraisal.appeal_reopen'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'arrow-back-up', 'attrs' => ['name' => 'outcome_action', 'value' => 'reopen']]) ?>
    </div>
  </form>
  <?php endif; ?>
  <?php if (!in_array($st, ['finalized', 'acknowledged'], true)): ?>
  <form method="post" action="<?= $e($url('/appraisal/a/' . $a['public_id'] . '/evaluator')) ?>" class="stack-sm" novalidate>
    <?= $csrf ?>
    <h3 class="text-sm"><?= $e($t('appraisal.change_evaluator')) ?></h3>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'role', 'type' => 'select', 'label' => $t('appraisal.field.role'), 'value' => $oldText('role', 'manager'), 'options' => ['manager' => $t('appraisal.col.manager')] + ((int) $a['final_review'] === 1 ? ['reviewer' => $t('appraisal.col.reviewer')] : [])]) ?>
      <?= $ui('field', ['name' => 'username', 'label' => $t('appraisal.field.username'), 'required' => true, 'autocomplete' => 'off', 'value' => $oldText('username', ''), 'error' => $errors['username'] ?? null]) ?>
      <?= $ui('field', ['name' => 'reason', 'id' => 'ev-reason', 'label' => $t('appraisal.field.reason'), 'required' => true, 'value' => $form === 'evaluator' ? $oldText('reason', '') : '', 'error' => $form === 'evaluator' ? ($errors['reason'] ?? null) : null]) ?>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('appraisal.change'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
  </form>
  <?php endif; ?>
  <?php if ($st === 'self_review'): ?>
  <form method="post" action="<?= $e($url('/appraisal/a/' . $a['public_id'] . '/skip')) ?>" class="stack-sm" novalidate>
    <?= $csrf ?>
    <h3 class="text-sm"><?= $e($t('appraisal.skip_self')) ?></h3>
    <?= $ui('field', ['name' => 'reason', 'id' => 'skip-reason', 'label' => $t('appraisal.field.reason'), 'required' => true, 'value' => $form === 'skip' ? $oldText('reason', '') : '', 'help' => $t('appraisal.skip_help'), 'error' => $form === 'skip' ? ($errors['reason'] ?? null) : null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('appraisal.skip'), 'type' => 'submit', 'variant' => 'ghost']) ?></div>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($previous !== []): ?>
<section class="card stack-sm" aria-labelledby="as-p">
  <h2 id="as-p" class="card__title"><?= $e($t('appraisal.previous')) ?></h2>
  <ul class="plain-list stack-sm">
    <?php foreach ($previous as $p): ?><li><a href="<?= $e($url('/appraisal/a/' . $p['public_id'])) ?>"><?= $e((string) $p['cycle_name']) ?></a> · <?= $e($num($p['final_score'])) ?><?= $p['rating_label'] !== null ? ' ' . $ui('badge', ['tone' => 'neutral', 'label' => (string) $p['rating_label']]) : '' ?></li><?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if ($events !== []): ?>
<section class="card stack-sm" aria-labelledby="as-e">
  <h2 id="as-e" class="card__title"><?= $e($t('appraisal.history')) ?></h2>
  <ul class="plain-list stack-sm">
    <?php foreach ($events as $ev): ?><li><?= $e($t('appraisal.event.' . $ev['action'])) ?> · <?= $e((string) ($ev['actor'] ?? '—')) ?> <span class="text-sm text-muted"><?= $e($date((string) $ev['occurred_at'])) ?></span><?= $ev['note'] !== null ? '<span class="d-block text-sm">' . $e((string) $ev['note']) . '</span>' : '' ?></li><?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
