<?php
/**
 * A complaint for its handlers. Notes are restricted to handlers; the sender never sees names, notes or the finding.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var callable $partial @var array<string, mixed> $v
 * @var array<string, mixed> $c @var array<string, string> $errors
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$base = '/complaints/c/' . $c['public_id'];
$r = $c['roles'];
$st = (string) $c['status'];
$open = in_array($st, \Blue\Modules\Complaints\ComplaintService::OPEN, true);
$working = $r['manage'] || ($r['investigator'] && $c['accepted_at'] !== null);
$name = static fn ($id): string => $id === null ? $t('complaints.side.sender') : (string) ($c['names'][(int) $id] ?? '—');
$cats = [];
foreach (\Blue\Modules\Complaints\ComplaintService::CATEGORIES as $k) {
    $cats[$k] = $t('complaints.category.' . $k);
}
$byMsg = [];
$loose = [];
foreach ($c['files'] as $f) {
    if ($f['message_id'] === null) {
        $loose[] = $f;
    } else {
        $byMsg[(string) $f['message_id']][] = $f;
    }
}
$links = static function (array $files) use ($e, $url, $base, $ui): string {
    if ($files === []) {
        return '';
    }
    $h = '<ul class="row cp-files">';
    foreach ($files as $f) {
        $h .= '<li><a href="' . $e($url($base . '/f/' . (int) $f['id'])) . '" target="_blank" rel="noopener">' . $ui('icon', ['name' => 'paperclip']) . ' ' . $e((string) $f['filename']) . '</a></li>';
    }
    return $h . '</ul>';
};
?>
<?= $ui('page-header', ['title' => $t('complaints.case.title', ['ref' => (string) $c['ref']]), 'subtitle' => $t('complaints.category.' . $c['category'])]) ?>
<p><a href="<?= $e($url('/complaints/inbox?le=' . (int) $c['legal_entity_id'])) ?>"><?= $e($t('complaints.back_inbox')) ?></a></p>
<section class="card stack-sm" aria-labelledby="cs-h">
  <h2 id="cs-h" class="card__title"><?= $c['summary_only'] ? $e($t('complaints.case.summary')) : $e((string) ($c['title'] ?? $t('complaints.purged_short'))) ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('complaints.field.status')) ?></dt><dd><?= $partial('@complaints/_status', ['s' => $st]) ?></dd></div>
    <div><dt><?= $e($t('complaints.field.route')) ?></dt><dd><?= $e($t('complaints.route.' . $c['route'])) ?></dd></div>
    <div><dt><?= $e($t('complaints.field.urgency')) ?></dt><dd><?= $c['urgency'] === null ? '—' : $e($t('complaints.urgency.' . $c['urgency'])) ?></dd></div>
    <div><dt><?= $e($t('complaints.field.sent_on')) ?></dt><dd><?= $e($date((string) $c['created_at'], false)) ?></dd></div>
    <div><dt><?= $e($t('complaints.field.investigator')) ?></dt><dd><?= $c['investigator_id'] === null ? '—' : $e($name($c['investigator_id'])) . ($c['accepted_at'] === null ? ' (' . $e($t('complaints.not_accepted')) . ')' : '') ?></dd></div>
    <?php if ($c['due']['response'] !== null || $c['due']['resolve'] !== null): ?>
    <div><dt><?= $e($t('complaints.field.due')) ?></dt><dd><?= $c['due']['response'] !== null ? $e($t('complaints.due.response', ['d' => $date((string) $c['due']['response'], false)])) . ' ' : '' ?><?= $c['due']['resolve'] !== null ? $e($t('complaints.due.resolve', ['d' => $date((string) $c['due']['resolve'], false)])) : '' ?><?= $c['due']['late'] ? ' ' . $ui('badge', ['tone' => 'danger', 'label' => $t('complaints.late')]) : '' ?></dd></div>
    <?php endif; ?>
    <?php if ($c['duplicate_ref'] !== null): ?><div><dt><?= $e($t('complaints.field.duplicate_ref')) ?></dt><dd><?= $e((string) $c['duplicate_ref']) ?></dd></div><?php endif; ?>
    <?php if ($c['coi_note'] !== null): ?><div><dt><?= $e($t('complaints.field.coi_note')) ?></dt><dd><?= $e((string) $c['coi_note']) ?></dd></div><?php endif; ?>
  </dl>
  <?php if ($c['purged_at'] !== null): ?><p class="text-sm text-muted"><?= $e($t('complaints.purged')) ?></p><?php endif; ?>
  <?php if (!$c['summary_only'] && $c['body'] !== null): ?><p class="cp-text"><?= nl2br($e((string) $c['body'])) ?></p><?= $links($loose) ?><?php endif; ?>
  <?php if ($c['outcome'] !== null): ?>
    <div class="cp-outcome stack-sm"><h3 class="text-base"><?= $e($t('complaints.field.outcome')) ?></h3><p class="cp-text"><?= nl2br($e((string) $c['outcome'])) ?></p>
      <p class="text-sm"><?= $e($t('complaints.finding.' . $c['finding'])) ?> · <?= $e($t('complaints.field.staff_score')) ?> <?= (int) $c['staff_score'] ?>/5</p></div>
  <?php endif; ?>
  <?php if ($c['reporter_rating'] !== null): ?><p class="text-sm"><?= $e($t('complaints.case.reporter_rating', ['n' => (string) $c['reporter_rating']])) ?><?= $c['reporter_comment'] !== null ? ' — ' . $e((string) $c['reporter_comment']) : '' ?></p><?php endif; ?>
</section>

<?php if ($c['summary_only']): ?>
<section class="card stack-sm" aria-labelledby="cs-acc">
  <h2 id="cs-acc" class="card__title"><?= $e($t('complaints.accept.title')) ?></h2>
  <p class="text-sm"><?= $e($t('complaints.accept.help')) ?></p>
  <form method="post" action="<?= $e($url($base . '/accept')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <label class="check"><input type="checkbox" name="no_conflict" value="1"<?= isset($errors['no_conflict']) ? ' aria-describedby="cs-coi-err"' : '' ?>> <?= $e($t('complaints.field.no_conflict')) ?></label>
    <?php if (isset($errors['no_conflict'])): ?><p class="field__error" id="cs-coi-err"><?= $e($errors['no_conflict']) ?></p><?php endif; ?>
    <?= $ui('field', ['name' => 'coi_note', 'id' => 'cs-coi', 'label' => $t('complaints.field.coi_note'), 'help' => $t('complaints.coi_help')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('complaints.accept.submit'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
  </form>
  <form method="post" action="<?= $e($url($base . '/decline')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'reason', 'id' => 'cs-decline', 'type' => 'textarea', 'label' => $t('complaints.field.decline_reason'), 'required' => true, 'error' => $errors['reason'] ?? null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('complaints.accept.decline'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
  </form>
</section>
<?php else: ?>

<section class="card stack-sm" aria-labelledby="cs-th">
  <h2 id="cs-th" class="card__title"><?= $e($t('complaints.thread')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('complaints.case.thread_help')) ?></p>
  <?php if ($c['messages'] === []): ?><p class="text-sm text-muted"><?= $e($t('complaints.no_messages')) ?></p><?php endif; ?>
  <ol class="plain-list stack-sm">
    <?php foreach ($c['messages'] as $m): ?>
    <li class="hd-msg stack-sm<?= $m['side'] === 'reporter' ? ' hd-msg--requester' : '' ?>">
      <p class="text-sm"><strong><?= $e($m['side'] === 'reporter' ? $t('complaints.side.sender') : $name($m['author_id'])) ?></strong> · <span class="text-muted"><?= $e($date((string) $m['created_at'], $m['side'] === 'staff')) ?></span>
        <?= (int) $m['asks_reply'] === 1 ? $ui('badge', ['tone' => 'warning', 'label' => $t('complaints.asks_reply')]) : '' ?></p>
      <p class="cp-text"><?= $m['body'] === null ? $e($t('complaints.purged_short')) : nl2br($e((string) $m['body'])) ?></p>
      <?= $links($byMsg[(string) $m['id']] ?? []) ?>
    </li>
    <?php endforeach; ?>
  </ol>
  <?php if ($working && in_array($st, array_merge(\Blue\Modules\Complaints\ComplaintService::OPEN, ['resolved']), true)): ?>
  <form method="post" action="<?= $e($url($base . '/message')) ?>" enctype="multipart/form-data" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'body', 'id' => 'cs-msg', 'type' => 'textarea', 'label' => $t('complaints.field.staff_message'), 'required' => true, 'help' => $t('complaints.staff_message_help'), 'error' => $errors['body'] ?? null]) ?>
    <?= $ui('dropzone', ['name' => 'files[]', 'id' => 'cs-files', 'label' => $t('complaints.field.files'), 'multiple' => true, 'accept' => '.png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp', 'help' => $t('complaints.files_help'), 'error' => $errors['files'] ?? null]) ?>
    <?php if (in_array($st, ['investigating', 'action_required'], true)): ?><label class="check"><input type="checkbox" name="asks_reply" value="1"> <?= $e($t('complaints.field.asks_reply')) ?></label><?php endif; ?>
    <div class="row"><?= $ui('button', ['label' => $t('complaints.case.send'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
  </form>
  <?php endif; ?>
</section>

<section class="card stack-sm" aria-labelledby="cs-notes">
  <h2 id="cs-notes" class="card__title"><?= $e($t('complaints.notes.title')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('complaints.notes.help')) ?></p>
  <ol class="plain-list stack-sm">
    <?php foreach ($c['notes'] as $n): ?>
    <li class="hd-msg hd-msg--note stack-sm"><p class="text-sm"><strong><?= $e($name($n['author_id'])) ?></strong> · <span class="text-muted"><?= $e($date((string) $n['created_at'])) ?></span></p><p class="cp-text"><?= nl2br($e((string) $n['body'])) ?></p></li>
    <?php endforeach; ?>
  </ol>
  <?php if ($working && $c['purged_at'] === null): ?>
  <form method="post" action="<?= $e($url($base . '/note')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'body', 'id' => 'cs-note', 'type' => 'textarea', 'label' => $t('complaints.field.note'), 'required' => true, 'error' => $errors['note'] ?? null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('complaints.notes.add'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
  </form>
  <?php endif; ?>
</section>

<?php if ($r['investigator'] && $c['accepted_at'] !== null && in_array($st, ['investigating', 'action_required'], true)): ?>
<section class="card stack-sm" aria-labelledby="cs-res">
  <h2 id="cs-res" class="card__title"><?= $e($t('complaints.resolve.title')) ?></h2>
  <form method="post" action="<?= $e($url($base . '/resolve')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'outcome', 'id' => 'cs-outcome', 'type' => 'textarea', 'label' => $t('complaints.field.outcome'), 'required' => true, 'help' => $t('complaints.outcome_help'), 'error' => $errors['outcome'] ?? null]) ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'finding', 'id' => 'cs-finding', 'type' => 'select', 'label' => $t('complaints.field.finding'), 'required' => true, 'help' => $t('complaints.finding_help'),
          'options' => ['' => $t('complaints.choose')] + array_combine(\Blue\Modules\Complaints\ComplaintService::FINDINGS, array_map(static fn (string $f): string => $t('complaints.finding.' . $f), \Blue\Modules\Complaints\ComplaintService::FINDINGS)), 'error' => $errors['finding'] ?? null]) ?>
      <?= $ui('field', ['name' => 'score', 'id' => 'cs-score', 'type' => 'select', 'label' => $t('complaints.field.staff_score'), 'required' => true, 'help' => $t('complaints.score_help'),
          'options' => ['' => $t('complaints.choose'), '5' => '5', '4' => '4', '3' => '3', '2' => '2', '1' => '1'], 'error' => $errors['score'] ?? null]) ?>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('complaints.resolve.submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
  </form>
</section>
<?php endif; ?>
<?php endif; ?>

<?php if ($r['manage']): ?>
<section class="card stack" aria-labelledby="cs-man">
  <h2 id="cs-man" class="card__title"><?= $e($t('complaints.manage.title')) ?></h2>
  <?php if ($st === 'submitted'): ?>
  <form method="post" action="<?= $e($url($base . '/triage')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <h3 class="text-base"><?= $e($t('complaints.manage.triage')) ?></h3>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'urgency', 'id' => 'cs-urg', 'type' => 'select', 'label' => $t('complaints.field.urgency'), 'required' => true, 'options' => ['' => $t('complaints.choose'), 'normal' => $t('complaints.urgency.normal'), 'high' => $t('complaints.urgency.high')], 'error' => $errors['urgency'] ?? null]) ?>
      <?= $ui('field', ['name' => 'category', 'id' => 'cs-cat', 'type' => 'select', 'label' => $t('complaints.field.category'), 'value' => (string) $c['category'], 'options' => $cats, 'error' => $errors['category'] ?? null]) ?>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('complaints.manage.triage_submit'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
  </form>
  <?php endif; ?>
  <?php if (in_array($st, ['triage', 'investigating', 'action_required'], true)): ?>
  <form method="post" action="<?= $e($url($base . '/assign')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <h3 class="text-base"><?= $e($t('complaints.manage.assign')) ?></h3>
    <?= $ui('field', ['name' => 'investigator', 'id' => 'cs-inv', 'type' => 'select', 'label' => $t('complaints.field.investigator'), 'required' => true, 'help' => $t('complaints.assign_help'),
        'options' => ['' => $t('complaints.choose')] + $c['candidates'], 'error' => $errors['investigator'] ?? null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('complaints.manage.assign_submit'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
  </form>
  <?php endif; ?>
  <?php if (in_array($st, ['submitted', 'triage'], true)): ?>
  <details class="stack-sm"><summary><?= $e($t('complaints.manage.reject')) ?></summary>
    <form method="post" action="<?= $e($url($base . '/duplicate')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <?= $ui('field', ['name' => 'duplicate_ref', 'id' => 'cs-dupref', 'label' => $t('complaints.field.duplicate_ref'), 'required' => true, 'error' => $errors['duplicate_ref'] ?? null]) ?>
      <?= $ui('field', ['name' => 'reason', 'id' => 'cs-dupreason', 'label' => $t('complaints.field.reason_sender'), 'required' => true, 'error' => $errors['reason'] ?? null]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('complaints.manage.duplicate'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
    </form>
    <form method="post" action="<?= $e($url($base . '/out_of_scope')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <?= $ui('field', ['name' => 'reason', 'id' => 'cs-oos', 'type' => 'textarea', 'label' => $t('complaints.field.reason_sender'), 'required' => true, 'help' => $t('complaints.oos_help')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('complaints.manage.out_of_scope'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
    </form>
  </details>
  <?php endif; ?>
  <?php if ($st === 'resolved'): ?>
  <form method="post" action="<?= $e($url($base . '/close')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <h3 class="text-base"><?= $e($t('complaints.manage.close')) ?></h3>
    <p class="text-sm text-muted"><?= $e($t('complaints.close_help')) ?></p>
    <?= $ui('field', ['name' => 'reason', 'id' => 'cs-close', 'label' => $t('complaints.field.close_note')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('complaints.manage.close_submit'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
  </form>
  <?php endif; ?>
  <?php if (in_array($st, ['closed', 'duplicate', 'out_of_scope'], true) && $c['purged_at'] === null): ?>
  <form method="post" action="<?= $e($url($base . '/reopen')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'reason', 'id' => 'cs-reopen', 'label' => $t('complaints.field.reopen_reason'), 'required' => true, 'error' => $errors['reason'] ?? null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('complaints.manage.reopen'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'arrow-back-up']) ?></div>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="card stack-sm" aria-labelledby="cs-ev">
  <h2 id="cs-ev" class="card__title"><?= $e($t('complaints.history')) ?></h2>
  <ol class="plain-list stack-sm text-sm">
    <?php foreach ($c['events'] as $ev): ?>
    <li><span class="text-muted"><?= $e($date((string) $ev['created_at'], $ev['actor_id'] !== null)) ?></span> · <?= $e($t('complaints.event.' . $ev['event'])) ?> · <?= $e($ev['event'] === 'purged' ? $t('complaints.side.system') : $name($ev['actor_id'])) ?><?= $ev['note'] !== null && $ev['note'] !== '' ? ' — ' . $e((string) $ev['note']) : '' ?></li>
    <?php endforeach; ?>
  </ol>
</section>
