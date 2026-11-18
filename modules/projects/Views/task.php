<?php
/**
 * One task: plan, warnings, dependencies, subtasks, the assignee's update and submission, revisions with the lead's decision,
 * comments; leads edit or cancel it.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v @var array<string, mixed> $tk
 * @var array<string, string> $errors @var array<string, mixed> $old @var string $form
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$tpid = (string) $tk['public_id'];
$p = $tk['project'];
$r = $tk['roles'];
$st = (string) $tk['status'];
$live = in_array($p['status'], ['active', 'at_risk', 'on_hold'], true);
$open = in_array($p['status'], ['planning', 'pending_approval', 'active', 'at_risk', 'on_hold'], true);
$act = static fn (string $a): string => $url('/projects/t/' . $tpid . '/' . $a);
$fe = static fn (string $f, string $k): ?string => $form === $f ? ($errors[$k] ?? null) : null;
$ov = static fn (string $f, string $k, string $def = ''): string => $form === $f && is_scalar($old[$k] ?? null) ? (string) $old[$k] : $def;
$ttone = ['todo' => 'neutral', 'in_progress' => 'info', 'submitted' => 'warning', 'returned' => 'danger', 'accepted' => 'success', 'cancelled' => 'neutral'];
?>
<?= $ui('page-header', ['title' => (string) $tk['title'], 'subtitle' => (string) $p['name'], 'crumbs' => [[$t('projects.title'), $url('/projects')], [(string) $p['name'], $url('/projects/p/' . $p['public_id'])], [(string) $tk['title'], null]]]) ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<?php foreach ($tk['warnings'] as [$wk, $wp]): ?><?= $ui('alert', ['tone' => 'warning', 'title' => $t($wk, $wp)]) ?><?php endforeach; ?>

<section class="card stack-sm" aria-labelledby="tk-sum">
  <div class="pj-card__head"><h2 id="tk-sum" class="card__title"><?= $e($t('projects.task')) ?></h2> <?= $ui('badge', ['tone' => $ttone[$st] ?? 'neutral', 'label' => $t('projects.task_status.' . $st)]) ?></div>
  <dl class="pj-dl">
    <dt><?= $e($t('projects.field.assignee')) ?></dt><dd><?= $e((string) ($tk['assignee_name'] ?? $t('projects.unassigned'))) ?></dd>
    <dt><?= $e($t('projects.col.dates')) ?></dt><dd><?= $e(($tk['starts_on'] ?? '—') . ' – ' . ($tk['due_on'] ?? '—')) ?></dd>
    <dt><?= $e($t('projects.progress')) ?></dt><dd><?= $st === 'accepted' ? 100 : (int) $tk['progress'] ?>%</dd>
    <?php if ($tk['parent'] !== null): ?><dt><?= $e($t('projects.field.parent')) ?></dt><dd><a href="<?= $e($url('/projects/t/' . $tk['parent']['public_id'])) ?>"><?= $e((string) $tk['parent']['title']) ?></a></dd><?php endif; ?>
    <?php if (($tk['risk'] ?? '') !== ''): ?><dt><?= $e($t('projects.field.risk')) ?></dt><dd class="pj-text"><?= $e((string) $tk['risk']) ?></dd><?php endif; ?>
  </dl>
  <?php if (($tk['description'] ?? '') !== ''): ?><p class="pj-text"><?= $e((string) $tk['description']) ?></p><?php endif; ?>
  <?php if (($tk['criteria'] ?? '') !== ''): ?><p class="pj-text"><strong><?= $e($t('projects.field.criteria')) ?>:</strong> <?= $e((string) $tk['criteria']) ?></p><?php endif; ?>
  <?php if ($tk['deps'] !== []): ?><p class="text-sm"><?= $e($t('projects.field.deps')) ?>: <?= implode(', ', array_map(static fn (array $d): string => '<a href="' . $e($url('/projects/t/' . $d['public_id'])) . '">' . $e((string) $d['title']) . '</a> (' . $e($t('projects.task_status.' . $d['status'])) . ')', $tk['deps'])) ?></p><?php endif; ?>
  <?php if ($tk['waiting'] !== []): ?><p class="text-sm"><?= $e($t('projects.waiting_on_this')) ?> <?= implode(', ', array_map(static fn (array $d): string => '<a href="' . $e($url('/projects/t/' . $d['public_id'])) . '">' . $e((string) $d['title']) . '</a>', $tk['waiting'])) ?></p><?php endif; ?>
  <?php if ($tk['subtasks'] !== []): ?><p class="text-sm"><?= $e($t('projects.subtasks')) ?>: <?= implode(', ', array_map(static fn (array $d): string => '<a href="' . $e($url('/projects/t/' . $d['public_id'])) . '">' . $e((string) $d['title']) . '</a> (' . $e($t('projects.task_status.' . $d['status'])) . ')', $tk['subtasks'])) ?></p><?php endif; ?>
</section>

<?php if ($tk['mine'] && $live && in_array($st, ['todo', 'in_progress', 'returned'], true)): ?>
<section class="card stack-sm" aria-labelledby="tk-mine">
  <h2 id="tk-mine" class="card__title"><?= $e($t('projects.my_update')) ?></h2>
  <form method="post" action="<?= $e($act('mine')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'status', 'id' => 'f-m-status', 'type' => 'select', 'label' => $t('projects.field.status'), 'value' => $ov('mine', 'status', $st), 'options' => $st === 'returned' ? ['returned' => $t('projects.task_status.returned'), 'in_progress' => $t('projects.task_status.in_progress')] : ['todo' => $t('projects.task_status.todo'), 'in_progress' => $t('projects.task_status.in_progress')], 'error' => $fe('mine', 'status')]) ?>
      <?= $ui('field', ['name' => 'progress', 'id' => 'f-m-progress', 'type' => 'number', 'label' => $t('projects.field.progress'), 'value' => $ov('mine', 'progress', (string) $tk['progress']), 'help' => $t('projects.progress_help'), 'error' => $fe('mine', 'progress')]) ?>
      <?= $ui('field', ['name' => 'starts_on', 'id' => 'f-m-start', 'type' => 'date', 'label' => $t('projects.field.starts_on'), 'value' => $ov('mine', 'starts_on', (string) ($tk['starts_on'] ?? '')), 'error' => $fe('mine', 'starts_on')]) ?>
      <?= $ui('field', ['name' => 'due_on', 'id' => 'f-m-due', 'type' => 'date', 'label' => $t('projects.field.due_on'), 'value' => $ov('mine', 'due_on', (string) ($tk['due_on'] ?? '')), 'error' => $fe('mine', 'due_on')]) ?>
    </div>
    <?= $ui('field', ['name' => 'risk', 'id' => 'f-m-risk', 'label' => $t('projects.field.risk'), 'value' => $ov('mine', 'risk', (string) ($tk['risk'] ?? '')), 'error' => $fe('mine', 'risk')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('projects.save'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm']) ?></div>
  </form>
  <h3 class="text-sm"><?= $e($t('projects.submit_work')) ?></h3>
  <form method="post" action="<?= $e($act('submit')) ?>" enctype="multipart/form-data" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'note', 'id' => 'f-s-note', 'type' => 'textarea', 'label' => $t('projects.field.sub_note'), 'value' => $ov('submit', 'note'), 'help' => $t('projects.submit_help'), 'error' => $fe('submit', 'note')]) ?>
    <?= $ui('field', ['name' => 'link', 'id' => 'f-s-link', 'label' => $t('projects.field.link'), 'value' => $ov('submit', 'link'), 'placeholder' => 'https://', 'error' => $fe('submit', 'link')]) ?>
    <div class="field">
      <label class="field__label" for="f-s-files"><?= $e($t('projects.field.files')) ?> <span class="field__req"><?= $e($t('form.optional_tag')) ?></span></label>
      <input class="input" type="file" id="f-s-files" name="files[]" multiple aria-describedby="f-s-files-help<?= $fe('submit', 'files') !== null ? ' f-s-files-err' : '' ?>">
      <p class="field__help" id="f-s-files-help"><?= $e($t('projects.files_help')) ?></p>
      <?php if ($fe('submit', 'files') !== null): ?><p class="field__error" id="f-s-files-err"><?= $e((string) $fe('submit', 'files')) ?></p><?php endif; ?>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('projects.submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
  </form>
</section>
<?php endif; ?>

<?php if ($r['lead'] && $st === 'submitted'): ?>
<section class="card stack-sm" aria-labelledby="tk-dec">
  <h2 id="tk-dec" class="card__title"><?= $e($t('projects.review')) ?></h2>
  <form method="post" action="<?= $e($act('accept')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'feedback', 'id' => 'f-accept-fb', 'type' => 'textarea', 'label' => $t('projects.field.feedback_optional'), 'value' => $ov('accept', 'feedback'), 'error' => $fe('accept', 'feedback')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('projects.accept'), 'type' => 'submit', 'variant' => 'primary', 'size' => 'sm']) ?></div>
  </form>
  <details<?= $form === 'return' ? ' open' : '' ?>><summary><?= $e($t('projects.return')) ?></summary>
    <form method="post" action="<?= $e($act('return')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <?= $ui('field', ['name' => 'feedback', 'id' => 'f-return-fb', 'type' => 'textarea', 'label' => $t('projects.field.feedback'), 'required' => true, 'value' => $ov('return', 'feedback'), 'error' => $fe('return', 'feedback')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('projects.return'), 'type' => 'submit', 'variant' => 'danger', 'size' => 'sm']) ?></div>
    </form>
  </details>
</section>
<?php endif; ?>

<section class="card stack-sm" aria-labelledby="tk-subs">
  <h2 id="tk-subs" class="card__title"><?= $e($t('projects.revisions_n', ['n' => (string) count($tk['subs'])])) ?></h2>
  <?php if ($tk['subs'] === []): ?><p class="text-sm text-muted"><?= $e($t('projects.no_revisions')) ?></p><?php endif; ?>
  <?php foreach ($tk['subs'] as $s): ?>
  <div class="pj-entry stack-sm">
    <p class="text-sm"><strong><?= $e($t('projects.rev_n', ['n' => (string) $s['rev']])) ?></strong> · <?= $e($date((string) $s['submitted_at']) . ' · ' . ($s['by_name'] ?? '')) ?>
      <?= $s['decision'] !== null ? $ui('badge', ['tone' => $s['decision'] === 'accepted' ? 'success' : 'danger', 'label' => $t('projects.decision.' . $s['decision'])]) : $ui('badge', ['tone' => 'warning', 'label' => $t('projects.decision.waiting')]) ?></p>
    <?php if ($s['note'] !== null): ?><p class="pj-text"><?= $e((string) $s['note']) ?></p><?php endif; ?>
    <?php if ($s['link'] !== null): ?><p class="text-sm"><a href="<?= $e((string) $s['link']) ?>" rel="noopener noreferrer" target="_blank"><?= $e((string) $s['link']) ?></a></p><?php endif; ?>
    <?php if ($s['files'] !== []): ?><p class="text-sm"><?= implode(' · ', array_map(static fn (array $f): string => '<a href="' . $e($url('/projects/t/' . $tpid . '/f/' . $f['id'])) . '">' . $e((string) $f['filename']) . '</a>', $s['files'])) ?></p><?php endif; ?>
    <?php if ($s['feedback'] !== null): ?><p class="pj-text text-sm"><strong><?= $e($t('projects.field.feedback')) ?> (<?= $e((string) ($s['decided_name'] ?? '')) ?>):</strong> <?= $e((string) $s['feedback']) ?></p><?php endif; ?>
  </div>
  <?php endforeach; ?>
</section>

<section class="card stack-sm" id="comments" aria-labelledby="tk-com">
  <h2 id="tk-com" class="card__title"><?= $e($t('projects.comments')) ?></h2>
  <?php foreach ($tk['comments'] as $c): ?><div class="pj-entry"><p class="text-sm"><strong><?= $e((string) $c['username']) ?></strong> · <?= $e($date((string) $c['created_at'])) ?></p><p class="pj-text"><?= $e((string) $c['body']) ?></p></div><?php endforeach; ?>
  <?php if ($tk['comments'] === []): ?><p class="text-sm text-muted"><?= $e($t('projects.no_comments')) ?></p><?php endif; ?>
  <?php if ($r['member']): ?>
  <form method="post" action="<?= $e($act('comment')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'body', 'id' => 'f-comment', 'type' => 'textarea', 'label' => $t('projects.field.comment'), 'required' => true, 'value' => $ov('comment', 'body'), 'error' => $fe('comment', 'body')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('projects.send_comment'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm']) ?></div>
  </form>
  <?php endif; ?>
</section>

<?php if ($r['lead'] && $open && !in_array($st, ['accepted', 'cancelled'], true)): ?>
<details class="card"<?= $form === 'edit' ? ' open' : '' ?>><summary><?= $e($t('projects.edit_task')) ?></summary>
  <form method="post" action="<?= $e($act('edit')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?php
    $fill = $form === 'edit' ? $old : $tk + ['assignee' => (string) ($tk['assignee'] ?? ''), 'parent' => (string) ($tk['parent']['public_id'] ?? ''), 'deps' => array_column($tk['deps'], 'public_id')];
    $ferr = $form === 'edit' ? $errors : [];
    $others = $tk['others'];
    $tops = $tk['tops'];
    $members = $tk['members'];
    $tf = 'e';
    include __DIR__ . '/_taskfields.php';
    ?>
    <div class="row"><?= $ui('button', ['label' => $t('projects.save'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm']) ?></div>
  </form>
</details>
<details class="card"<?= $form === 'cancel' ? ' open' : '' ?>><summary><?= $e($t('projects.cancel_task')) ?></summary>
  <form method="post" action="<?= $e($act('cancel')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'reason', 'id' => 'f-cancel', 'label' => $t('projects.field.reason'), 'required' => true, 'value' => $ov('cancel', 'reason'), 'error' => $fe('cancel', 'reason')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('projects.cancel_task'), 'type' => 'submit', 'variant' => 'danger', 'size' => 'sm']) ?></div>
  </form>
</details>
<?php endif; ?>
