<?php
/**
 * One incident, by role: summary (everyone with access) · triage / assign / close (safety team) · investigation, witnesses,
 * actions (investigator + team) · own actions (owners) · medical details (safety.medical) · verification (team, not the
 * investigator) · outside communication · timeline.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v @var array<string, mixed> $in
 * @var array<string, string> $errors @var array<string, mixed> $old @var string $form @var int $uid @var bool $canManage @var string $here
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$pid = (string) $in['public_id'];
$r = $in['roles'];
$st = (string) $in['status'];
$fe = static fn (string $f, string $k): ?string => $form === $f ? ($errors[$k] ?? null) : null;
$ov = static fn (string $f, string $k, string $def = ''): string => $form === $f && is_scalar($old[$k] ?? null) ? (string) $old[$k] : $def;
$act = static fn (string $a): string => $url('/safety/i/' . $pid . '/' . $a);
$canInvestigate = ($r['manage'] || $r['investigator']) && in_array($st, ['investigating', 'action_assigned'], true);
$levelTone = [1 => 'neutral', 2 => 'info', 3 => 'warning', 4 => 'danger'][(int) $in['severity']] ?? 'neutral';
$statusLabel = $st === 'closed' && $in['closed_reason'] !== null && $in['closed_reason'] !== 'done' ? $t('safety.closed_reason.' . $in['closed_reason']) : $t('safety.status.' . $st);
$kinds = ['injury' => $t('safety.kind.injury'), 'damage' => $t('safety.kind.damage'), 'near_miss' => $t('safety.kind.near_miss'), 'hazard' => $t('safety.kind.hazard')];
$levels = [];
foreach ([1, 2, 3, 4] as $l) {
    $levels[(string) $l] = $t('safety.level.' . $l);
}
$scale = ['' => '—', '1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5'];
$fileLink = static fn (array $f): string => '<a href="' . $e($url('/safety/i/' . $pid . '/f/' . $f['id'])) . '">' . $e((string) $f['filename']) . '</a>';
?>
<?= $ui('page-header', ['title' => $in['ref'] . ' · ' . $kinds[$in['kind']], 'subtitle' => (string) $in['location'], 'crumbs' => [[$t('safety.title'), $url($here)], [(string) $in['ref'], null]]]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>

<section class="card stack-sm" aria-labelledby="sf-sum">
  <h2 id="sf-sum" class="card__title"><?= $e($t('safety.summary')) ?></h2>
  <div class="row sf-badges"><?= $ui('badge', ['tone' => $levelTone, 'label' => $t('safety.level.' . $in['severity'])]) ?> <?= $ui('badge', ['tone' => $st === 'closed' ? 'neutral' : 'info', 'label' => $statusLabel]) ?><?= (int) $in['anonymous'] === 1 ? ' ' . $ui('badge', ['tone' => 'neutral', 'label' => $t('safety.anonymous')]) : '' ?></div>
  <dl class="sf-dl">
    <dt><?= $e($t('safety.field.occurred_at')) ?></dt><dd><?= $e(substr((string) $in['occurred_at'], 0, 16)) ?></dd>
    <?php if ($in['site'] !== null): ?><dt><?= $e($t('safety.field.site')) ?></dt><dd><?= $e((string) $in['site']) ?></dd><?php endif; ?>
    <dt><?= $e($t('safety.field.location')) ?></dt><dd><?= $e((string) $in['location']) ?></dd>
    <?php if ($in['level_note'] !== ''): ?><dt><?= $e($t('safety.field.severity')) ?></dt><dd><?= $e((string) $in['level_note']) ?></dd><?php endif; ?>
    <?php if ($r['full']): ?><dt><?= $e($t('safety.reporter')) ?></dt><dd><?= $e($in['reporter'] ?? $t('safety.anonymous')) ?></dd><?php endif; ?>
    <?php if ($in['investigator'] !== null): ?><dt><?= $e($t('safety.investigator')) ?></dt><dd><?= $e((string) $in['investigator']) ?></dd><?php endif; ?>
  </dl>
  <p class="sf-text"><?= $e((string) $in['description']) ?></p>
  <?php if (($in['immediate_action'] ?? '') !== ''): ?><p class="sf-text"><strong><?= $e($t('safety.field.immediate_action')) ?>:</strong> <?= $e((string) $in['immediate_action']) ?></p><?php endif; ?>
  <?php if ($r['full'] && ($in['people'] ?? '') !== ''): ?><p class="sf-text"><strong><?= $e($t('safety.field.people')) ?>:</strong> <?= $e((string) $in['people']) ?></p><?php endif; ?>
  <?php $rep = array_values(array_filter($in['files'], static fn (array $f): bool => $f['stage'] === 'report')); if ($rep !== []): ?><p class="text-sm"><?= $e($t('safety.files')) ?>: <?= implode(' · ', array_map($fileLink, $rep)) ?></p><?php endif; ?>
  <?php if ($in['related'] !== null): ?><p class="text-sm"><?= $e($t('safety.repeat_of')) ?> <?= $r['full'] ? '<a href="' . $e($url('/safety/i/' . $in['related']['public_id'])) . '">' . $e((string) $in['related']['ref']) . '</a>' : $e((string) $in['related']['ref']) ?></p><?php endif; ?>
  <?php if ($in['repeats'] !== []): ?><p class="text-sm"><?= $e($t('safety.repeated_by')) ?> <?= implode(', ', array_map(static fn (array $x): string => '<a href="' . $e($url('/safety/i/' . $x['public_id'])) . '">' . $e((string) $x['ref']) . '</a>', $in['repeats'])) ?></p><?php endif; ?>
  <?php if ($in['has_medical'] && !$r['medical']): ?><p class="text-sm text-muted"><?= $e($t('safety.medical_restricted')) ?></p><?php endif; ?>
  <?php if ($st === 'closed' && ($in['outcome'] ?? '') !== ''): ?><?= $ui('alert', ['tone' => 'success', 'title' => $t('safety.outcome'), 'text' => (string) $in['outcome']]) ?><?php endif; ?>
</section>

<?php if ($r['manage'] && in_array($st, ['reported', 'triage', 'reopened', 'investigating'], true)): ?>
<section class="card stack-sm" aria-labelledby="sf-tri">
  <h2 id="sf-tri" class="card__title"><?= $e($t('safety.triage')) ?></h2>
  <?php if (in_array($st, ['reported', 'triage'], true)): ?>
  <form method="post" action="<?= $e($act('triage')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'kind', 'id' => 'f-tri-kind', 'type' => 'select', 'label' => $t('safety.field.kind'), 'value' => $ov('triage', 'kind', (string) $in['kind']), 'options' => $kinds, 'error' => $fe('triage', 'kind')]) ?>
      <?= $ui('field', ['name' => 'severity', 'id' => 'f-tri-sev', 'type' => 'select', 'label' => $t('safety.field.severity'), 'value' => $ov('triage', 'severity', (string) $in['severity']), 'options' => $levels, 'error' => $fe('triage', 'severity')]) ?>
      <?= $ui('field', ['name' => 'related', 'id' => 'f-tri-rel', 'label' => $t('safety.field.related'), 'value' => $ov('triage', 'related', (string) ($in['related']['ref'] ?? '')), 'help' => $t('safety.related_help'), 'error' => $fe('triage', 'related')]) ?>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('safety.do_triage'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm']) ?></div>
  </form>
  <?php endif; ?>
  <?php if (in_array($st, ['triage', 'reopened', 'investigating'], true)): ?>
  <form method="post" action="<?= $e($act('assign')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'who', 'id' => 'f-assign', 'label' => $t('safety.field.investigator'), 'required' => true, 'value' => $ov('assign', 'who', (string) ($in['investigator'] ?? '')), 'help' => $t('safety.person_help'), 'error' => $fe('assign', 'who')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('safety.assign'), 'type' => 'submit', 'variant' => 'primary', 'size' => 'sm']) ?></div>
  </form>
  <?php endif; ?>
  <?php if (in_array($st, ['reported', 'triage'], true)): ?>
  <details<?= $form === 'reject' ? ' open' : '' ?>><summary><?= $e($t('safety.reject')) ?></summary>
    <form method="post" action="<?= $e($act('reject')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <?= $ui('field', ['name' => 'reason', 'id' => 'f-rej-reason', 'type' => 'select', 'label' => $t('safety.field.close_reason'), 'value' => $ov('reject', 'reason'), 'options' => ['' => $t('safety.pick'), 'not_incident' => $t('safety.closed_reason.not_incident'), 'duplicate' => $t('safety.closed_reason.duplicate')], 'error' => $fe('reject', 'reason')]) ?>
      <?= $ui('field', ['name' => 'note', 'id' => 'f-rej-note', 'type' => 'textarea', 'label' => $t('safety.field.note_reporter'), 'required' => true, 'value' => $ov('reject', 'note'), 'error' => $fe('reject', 'note')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('safety.reject'), 'type' => 'submit', 'variant' => 'danger', 'size' => 'sm']) ?></div>
    </form>
  </details>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($r['full'] && !in_array($st, ['reported', 'triage'], true) || $r['owner'] && ($in['root_cause'] ?? '') !== ''): ?>
<section class="card stack-sm" aria-labelledby="sf-inv">
  <h2 id="sf-inv" class="card__title"><?= $e($t('safety.investigation')) ?></h2>
  <?php if ($canInvestigate): ?>
  <form method="post" action="<?= $e($act('investigate')) ?>" enctype="multipart/form-data" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'facts', 'id' => 'f-facts', 'type' => 'textarea', 'label' => $t('safety.field.facts'), 'value' => $ov('investigate', 'facts', (string) ($in['facts'] ?? '')), 'error' => $fe('investigate', 'facts')]) ?>
    <?= $ui('field', ['name' => 'root_cause', 'id' => 'f-root', 'type' => 'textarea', 'label' => $t('safety.field.root_cause'), 'value' => $ov('investigate', 'root_cause', (string) ($in['root_cause'] ?? '')), 'help' => $t('safety.root_cause_help'), 'error' => $fe('investigate', 'root_cause')]) ?>
    <?= $ui('field', ['name' => 'temp_control', 'id' => 'f-temp', 'type' => 'textarea', 'label' => $t('safety.field.temp_control'), 'value' => $ov('investigate', 'temp_control', (string) ($in['temp_control'] ?? '')), 'error' => $fe('investigate', 'temp_control')]) ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'likelihood', 'id' => 'f-lik', 'type' => 'select', 'label' => $t('safety.field.likelihood'), 'value' => $ov('investigate', 'likelihood', (string) ($in['likelihood'] ?? '')), 'options' => $scale, 'error' => $fe('investigate', 'likelihood')]) ?>
      <?= $ui('field', ['name' => 'impact', 'id' => 'f-imp', 'type' => 'select', 'label' => $t('safety.field.impact'), 'value' => $ov('investigate', 'impact', (string) ($in['impact'] ?? '')), 'options' => $scale, 'error' => $fe('investigate', 'impact')]) ?>
    </div>
    <p class="text-sm text-muted"><?= $e($t('safety.risk_help')) ?></p>
    <div class="field">
      <label class="field__label" for="f-inv-files"><?= $e($t('safety.field.files')) ?> <span class="field__req"><?= $e($t('form.optional_tag')) ?></span></label>
      <input class="input" type="file" id="f-inv-files" name="files[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf,image/*"<?= $fe('investigate', 'files') !== null ? ' aria-describedby="f-inv-files-err"' : '' ?>>
      <?php if ($fe('investigate', 'files') !== null): ?><p class="field__error" id="f-inv-files-err"><?= $e((string) $fe('investigate', 'files')) ?></p><?php endif; ?>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('safety.save'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm']) ?></div>
  </form>
  <?php else: ?>
  <dl class="sf-dl">
    <?php if ($r['full']): ?><dt><?= $e($t('safety.field.facts')) ?></dt><dd class="sf-text"><?= $e((string) ($in['facts'] ?? '—')) ?></dd><?php endif; ?>
    <dt><?= $e($t('safety.field.root_cause')) ?></dt><dd class="sf-text"><?= $e((string) ($in['root_cause'] ?? '—')) ?></dd>
    <?php if ($r['full']): ?><dt><?= $e($t('safety.field.temp_control')) ?></dt><dd class="sf-text"><?= $e((string) ($in['temp_control'] ?? '—')) ?></dd><?php endif; ?>
  </dl>
  <?php endif; ?>
  <?php if ($r['full'] && $in['likelihood'] !== null && $in['impact'] !== null): ?><p class="text-sm"><?= $e($t('safety.risk_score', ['l' => (string) $in['likelihood'], 'i' => (string) $in['impact'], 'score' => (string) ((int) $in['likelihood'] * (int) $in['impact'])])) ?></p><?php endif; ?>
  <?php $inv = array_values(array_filter($in['files'], static fn (array $f): bool => $f['stage'] === 'investigation')); if ($inv !== []): ?><p class="text-sm"><?= $e($t('safety.files')) ?>: <?= implode(' · ', array_map($fileLink, $inv)) ?></p><?php endif; ?>
</section>
<?php endif; ?>

<?php if ($r['full'] && !in_array($st, ['reported', 'triage'], true)): ?>
<section class="card stack-sm" aria-labelledby="sf-wit">
  <h2 id="sf-wit" class="card__title"><?= $e($t('safety.witnesses')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('safety.need_to_know')) ?></p>
  <?php foreach ($in['witnesses'] as $w): ?><div class="sf-entry"><p class="text-sm"><strong><?= $e((string) $w['name']) ?></strong> · <?= $e($date((string) $w['taken_at'])) ?></p><p class="sf-text"><?= $e((string) $w['statement']) ?></p></div><?php endforeach; ?>
  <?php if ($in['witnesses'] === []): ?><p class="text-sm text-muted"><?= $e($t('safety.no_witnesses')) ?></p><?php endif; ?>
  <?php if (($r['manage'] || $r['investigator']) && in_array($st, ['investigating', 'action_assigned', 'verification'], true)): ?>
  <details<?= $form === 'witness' ? ' open' : '' ?>><summary><?= $e($t('safety.add_witness')) ?></summary>
    <form method="post" action="<?= $e($act('witness')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <?= $ui('field', ['name' => 'who', 'id' => 'f-wit-who', 'label' => $t('safety.field.witness'), 'required' => true, 'value' => $ov('witness', 'who'), 'help' => $t('safety.witness_help'), 'error' => $fe('witness', 'who')]) ?>
      <?= $ui('field', ['name' => 'statement', 'id' => 'f-wit-st', 'type' => 'textarea', 'label' => $t('safety.field.statement'), 'required' => true, 'value' => $ov('witness', 'statement'), 'error' => $fe('witness', 'statement')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('safety.save'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm']) ?></div>
    </form>
  </details>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($r['medical']): ?>
<section class="card stack-sm" aria-labelledby="sf-med">
  <h2 id="sf-med" class="card__title"><?= $e($t('safety.medical')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('safety.medical_note')) ?></p>
  <?php foreach ($in['medical'] as $m): ?>
  <div class="sf-entry"><p class="text-sm"><strong><?= $e((string) $m['name']) ?></strong><?= $m['body_part'] !== null ? ' · ' . $e((string) $m['body_part']) : '' ?><?= $m['days_off'] !== null ? ' · ' . $e($t('safety.days_off_n', ['n' => (string) $m['days_off']])) : '' ?><?= (int) $m['hospital'] === 1 ? ' · ' . $e($t('safety.hospital')) : '' ?></p>
    <p class="sf-text"><?= $e((string) $m['injury']) ?></p><?php if ($m['treatment'] !== null): ?><p class="sf-text text-sm"><?= $e((string) $m['treatment']) ?></p><?php endif; ?></div>
  <?php endforeach; ?>
  <details<?= $form === 'medical' ? ' open' : '' ?>><summary><?= $e($t('safety.add_medical')) ?></summary>
    <form method="post" action="<?= $e($act('medical')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <?= $ui('field', ['name' => 'who', 'id' => 'f-med-who', 'label' => $t('safety.field.injured'), 'required' => true, 'value' => $ov('medical', 'who'), 'help' => $t('safety.witness_help'), 'error' => $fe('medical', 'who')]) ?>
      <?= $ui('field', ['name' => 'injury', 'id' => 'f-med-inj', 'type' => 'textarea', 'label' => $t('safety.field.injury'), 'required' => true, 'value' => $ov('medical', 'injury'), 'error' => $fe('medical', 'injury')]) ?>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'body_part', 'id' => 'f-med-part', 'label' => $t('safety.field.body_part'), 'value' => $ov('medical', 'body_part'), 'error' => $fe('medical', 'body_part')]) ?>
        <?= $ui('field', ['name' => 'days_off', 'id' => 'f-med-days', 'type' => 'number', 'label' => $t('safety.field.days_off'), 'value' => $ov('medical', 'days_off'), 'error' => $fe('medical', 'days_off')]) ?>
      </div>
      <?= $ui('field', ['name' => 'treatment', 'id' => 'f-med-tr', 'type' => 'textarea', 'label' => $t('safety.field.treatment'), 'value' => $ov('medical', 'treatment'), 'error' => $fe('medical', 'treatment')]) ?>
      <label class="check"><input type="checkbox" name="hospital" value="1"> <?= $e($t('safety.field.hospital')) ?></label>
      <div class="row"><?= $ui('button', ['label' => $t('safety.save'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm']) ?></div>
    </form>
  </details>
</section>
<?php endif; ?>

<?php if ($in['actions'] !== [] || $canInvestigate): ?>
<section class="card stack-sm" aria-labelledby="sf-act">
  <h2 id="sf-act" class="card__title"><?= $e($t('safety.actions')) ?></h2>
  <?php foreach ($in['actions'] as $a): $ap = (string) $a['public_id']; $late = $a['status'] === 'open' && (string) $a['due_on'] < (string) $in['today']; ?>
  <div class="sf-entry stack-sm">
    <div class="row sf-badges"><?= $ui('badge', ['tone' => $late ? 'danger' : (['open' => 'warning', 'done' => 'success'][$a['status']] ?? 'neutral'), 'label' => $late ? $t('safety.overdue') : $t('safety.action_status.' . $a['status'])]) ?> <span class="text-sm"><?= $e($t('safety.action_kind.' . $a['kind'])) ?> · <?= $e((string) $a['owner']) ?> · <?= $e($t('safety.due_on', ['date' => (string) $a['due_on']])) ?></span><?= $a['external_ref'] !== null ? ' <span class="text-sm text-muted">· ' . $e((string) $a['external_ref']) . '</span>' : '' ?></div>
    <p class="sf-text"><?= $e((string) $a['description']) ?></p>
    <?php if ($a['status'] === 'done'): ?><p class="sf-text text-sm"><strong><?= $e($t('safety.done_note')) ?>:</strong> <?= $e((string) $a['done_note']) ?></p>
      <?php $ev = array_values(array_filter($in['files'], static fn (array $f): bool => (int) ($f['action_id'] ?? 0) === (int) $a['id'])); if ($ev !== []): ?><p class="text-sm"><?= implode(' · ', array_map($fileLink, $ev)) ?></p><?php endif; ?>
    <?php elseif ($a['status'] === 'cancelled'): ?><p class="text-sm text-muted"><?= $e((string) $a['cancel_reason']) ?></p><?php endif; ?>
    <?php if ($a['status'] === 'open' && $st === 'action_assigned' && ((int) $a['owner_id'] === $uid || $r['manage'])): ?>
    <details<?= $form === 'a_done_' . $ap ? ' open' : '' ?>><summary><?= $e($t('safety.complete_action')) ?></summary>
      <form method="post" action="<?= $e($url('/safety/a/' . $ap . '/done')) ?>" enctype="multipart/form-data" class="stack-sm" novalidate><?= $csrf ?>
        <?= $ui('field', ['name' => 'note', 'id' => 'f-done-' . $ap, 'type' => 'textarea', 'label' => $t('safety.done_note'), 'required' => true, 'error' => $fe('a_done_' . $ap, 'note')]) ?>
        <div class="field">
          <label class="field__label" for="f-ev-<?= $e($ap) ?>"><?= $e($t('safety.field.evidence')) ?> <span class="field__req"><?= $e($t('form.optional_tag')) ?></span></label>
          <input class="input" type="file" id="f-ev-<?= $e($ap) ?>" name="file" accept=".jpg,.jpeg,.png,.webp,.pdf,image/*">
          <?php if ($fe('a_done_' . $ap, 'file') !== null): ?><p class="field__error"><?= $e((string) $fe('a_done_' . $ap, 'file')) ?></p><?php endif; ?>
        </div>
        <div class="row"><?= $ui('button', ['label' => $t('safety.complete_action'), 'type' => 'submit', 'variant' => 'primary', 'size' => 'sm']) ?></div>
      </form>
    </details>
    <?php endif; ?>
    <?php if ($a['status'] === 'open' && ($r['manage'] || $r['investigator'])): ?>
    <details<?= $form === 'a_cancel_' . $ap ? ' open' : '' ?>><summary><?= $e($t('safety.cancel_action')) ?></summary>
      <form method="post" action="<?= $e($url('/safety/a/' . $ap . '/cancel')) ?>" class="stack-sm" novalidate><?= $csrf ?>
        <?= $ui('field', ['name' => 'reason', 'id' => 'f-cancel-' . $ap, 'label' => $t('safety.field.reason'), 'required' => true, 'error' => $fe('a_cancel_' . $ap, 'reason')]) ?>
        <div class="row"><?= $ui('button', ['label' => $t('safety.cancel_action'), 'type' => 'submit', 'variant' => 'danger', 'size' => 'sm']) ?></div>
      </form>
    </details>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php if ($canInvestigate): ?>
  <details<?= $form === 'action' ? ' open' : '' ?>><summary><?= $e($t('safety.add_action')) ?></summary>
    <form method="post" action="<?= $e($act('action')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <div class="grid grid--3">
        <?= $ui('field', ['name' => 'kind', 'id' => 'f-act-kind', 'type' => 'select', 'label' => $t('safety.field.action_kind'), 'required' => true, 'value' => $ov('action', 'kind'), 'options' => ['' => $t('safety.pick'), 'corrective' => $t('safety.action_kind.corrective'), 'preventive' => $t('safety.action_kind.preventive')], 'error' => $fe('action', 'kind')]) ?>
        <?= $ui('field', ['name' => 'owner', 'id' => 'f-act-owner', 'label' => $t('safety.field.owner'), 'required' => true, 'value' => $ov('action', 'owner'), 'help' => $t('safety.person_help'), 'error' => $fe('action', 'owner')]) ?>
        <?= $ui('field', ['name' => 'due_on', 'id' => 'f-act-due', 'type' => 'date', 'label' => $t('safety.field.due_on'), 'required' => true, 'value' => $ov('action', 'due_on'), 'error' => $fe('action', 'due_on')]) ?>
      </div>
      <?= $ui('field', ['name' => 'description', 'id' => 'f-act-desc', 'type' => 'textarea', 'label' => $t('safety.field.action'), 'required' => true, 'value' => $ov('action', 'description'), 'error' => $fe('action', 'description')]) ?>
      <?= $ui('field', ['name' => 'external_ref', 'id' => 'f-act-ref', 'label' => $t('safety.field.external_ref'), 'value' => $ov('action', 'external_ref'), 'help' => $t('safety.external_ref_help'), 'error' => $fe('action', 'external_ref')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('safety.add_action'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'plus']) ?></div>
    </form>
  </details>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($r['manage'] && $st === 'verification'): ?>
<section class="card stack-sm" aria-labelledby="sf-ver">
  <h2 id="sf-ver" class="card__title"><?= $e($t('safety.verification')) ?></h2>
  <?php if ($r['investigator']): ?><p class="text-sm text-muted"><?= $e($t('safety.verify_other')) ?></p><?php else: ?>
  <form method="post" action="<?= $e($act('verify')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('safety.field.decision')) ?></legend>
      <label class="check"><input type="radio" name="decision" value="effective"<?= $ov('verify', 'decision', 'effective') === 'effective' ? ' checked' : '' ?>> <?= $e($t('safety.effective')) ?></label>
      <label class="check"><input type="radio" name="decision" value="not"<?= $ov('verify', 'decision') === 'not' ? ' checked' : '' ?>> <?= $e($t('safety.not_effective')) ?></label>
    </fieldset>
    <?= $ui('field', ['name' => 'note', 'id' => 'f-ver-note', 'type' => 'textarea', 'label' => $t('safety.field.outcome'), 'required' => true, 'value' => $ov('verify', 'note'), 'help' => $t('safety.outcome_help'), 'error' => $fe('verify', 'note')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('safety.save_verification'), 'type' => 'submit', 'variant' => 'primary', 'size' => 'sm']) ?></div>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($r['manage'] && $st === 'closed'): ?>
<section class="card stack-sm" aria-labelledby="sf-reo">
  <h2 id="sf-reo" class="card__title"><?= $e($t('safety.reopen')) ?></h2>
  <form method="post" action="<?= $e($act('reopen')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'reason', 'id' => 'f-reopen', 'label' => $t('safety.field.reason'), 'required' => true, 'value' => $ov('reopen', 'reason'), 'error' => $fe('reopen', 'reason')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('safety.reopen'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm']) ?></div>
  </form>
</section>
<?php endif; ?>

<?php if ($r['manage'] || $r['view']): ?>
<section class="card stack-sm" aria-labelledby="sf-com">
  <h2 id="sf-com" class="card__title"><?= $e($t('safety.comms')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('safety.comms_note')) ?></p>
  <?php foreach ($in['comms'] as $c): ?><p class="text-sm sf-entry"><strong><?= $e((string) $c['sent_on']) ?></strong> · <?= $e((string) $c['recipient']) ?><?= $c['channel'] !== null ? ' · ' . $e((string) $c['channel']) : '' ?><?= $c['reference'] !== null ? ' · ' . $e((string) $c['reference']) : '' ?><?= $c['note'] !== null ? '<br>' . $e((string) $c['note']) : '' ?></p><?php endforeach; ?>
  <?php if ($r['manage']): ?>
  <details<?= $form === 'comm' ? ' open' : '' ?>><summary><?= $e($t('safety.add_comm')) ?></summary>
    <form method="post" action="<?= $e($act('comm')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'sent_on', 'id' => 'f-com-date', 'type' => 'date', 'label' => $t('safety.field.sent_on'), 'required' => true, 'value' => $ov('comm', 'sent_on', (string) $in['today']), 'error' => $fe('comm', 'sent_on')]) ?>
        <?= $ui('field', ['name' => 'recipient', 'id' => 'f-com-to', 'label' => $t('safety.field.recipient'), 'required' => true, 'value' => $ov('comm', 'recipient'), 'error' => $fe('comm', 'recipient')]) ?>
        <?= $ui('field', ['name' => 'channel', 'id' => 'f-com-ch', 'label' => $t('safety.field.channel'), 'value' => $ov('comm', 'channel')]) ?>
        <?= $ui('field', ['name' => 'reference', 'id' => 'f-com-ref', 'label' => $t('safety.field.reference'), 'value' => $ov('comm', 'reference')]) ?>
      </div>
      <?= $ui('field', ['name' => 'note', 'id' => 'f-com-note', 'type' => 'textarea', 'label' => $t('safety.field.note'), 'value' => $ov('comm', 'note'), 'error' => $fe('comm', 'note')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('safety.save'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm']) ?></div>
    </form>
  </details>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($in['events'] !== []): ?>
<details class="card"><summary><?= $e($t('safety.timeline')) ?></summary>
  <ol class="plain-list stack-sm sf-timeline">
    <?php foreach ($in['events'] as $ev): ?><li class="text-sm"><?= $e($date((string) $ev['created_at'])) ?> · <?= $e($t('safety.event.' . $ev['event'])) ?><?= $ev['username'] !== null ? ' · ' . $e((string) $ev['username']) : '' ?><?= $ev['note'] !== null ? ' — ' . $e((string) $ev['note']) : '' ?></li><?php endforeach; ?>
  </ol>
</details>
<?php endif; ?>
