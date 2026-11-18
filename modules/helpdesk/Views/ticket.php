<?php
/**
 * One ticket: details and deadlines, the conversation (with internal work notes for the team), the requester's
 * confirm / rate / reopen, the team's assign / progress / resolve / priority / transfer, and the history.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $tk @var list<array<string, mixed>> $categories @var string $nowAt
 * @var array<string, string> $errors @var array<string, mixed> $old @var string $form
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$o = static fn (string $f, string $k, string $d = ''): string => $form === $f && is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$err = static fn (string $f, string $k): ?string => $form === $f ? ($errors[$k] ?? null) : null;
$base = '/helpdesk/t/' . $tk['public_id'];
$row = $tk;
$fileLinks = static function (array $files) use ($e, $url, $ui, $t, $base): string {
    $out = '';
    foreach ($files as $f) {
        $out .= '<li><a href="' . $e($url($base . '/f/' . $f['id'])) . '">' . $ui('icon', ['name' => 'file-text', 'size' => 'sm']) . ' ' . $e((string) $f['filename']) . '</a>'
            . ((int) $f['internal'] === 1 ? ' ' . $ui('badge', ['tone' => 'neutral', 'label' => $t('helpdesk.internal')]) : '') . '</li>';
    }
    return $out === '' ? '' : '<ul class="plain-list stack-sm">' . $out . '</ul>';
};
$byMessage = [];
foreach ($tk['files'] as $f) {
    $byMessage[(string) ($f['message_id'] ?? 'ticket')][] = $f;
}
$deadline = static function (?string $at) use ($e, $date, $t): string {
    return $at === null ? $e($t('helpdesk.no_sla')) : $e($date(substr($at, 0, 10), false) . ' ' . substr($at, 11, 5));
};
$ratingField = static function (string $f) use ($e, $t, $o, $err): string {
    $h = '<fieldset class="stack-sm"><legend class="field__label">' . $e($t('helpdesk.field.rating')) . ' <span class="field__req">' . $e($t('form.required_tag')) . '</span></legend><div class="row">';
    for ($i = 5; $i >= 1; $i--) {
        $h .= '<label class="check"><input type="radio" name="rating" value="' . $i . '"' . ($o($f, 'rating') === (string) $i ? ' checked' : '') . '> ' . $e($t('helpdesk.rating_' . $i)) . '</label>';
    }
    $h .= '</div>' . ($err($f, 'rating') !== null ? '<p class="field__error">' . $e((string) $err($f, 'rating')) . '</p>' : '') . '</fieldset>';
    return $h;
};
?>
<?= $ui('page-header', ['title' => $tk['ref'] . ' · ' . $tk['title'], 'subtitle' => $tk['category_name'] . ' · ' . $tk['requester_name'],
    'crumbs' => [[$t('helpdesk.title'), $url($tk['staff'] && !$tk['mine'] ? '/helpdesk/queue' : '/helpdesk')], [(string) $tk['ref'], null]]]) ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<div class="grid grid--2">
<section class="card stack-sm" aria-labelledby="ht-d">
  <h2 id="ht-d" class="card__title"><?= $e($t('helpdesk.details')) ?> <?php include __DIR__ . '/_badges.php'; ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('helpdesk.field.category')) ?></dt><dd><?= $e((string) $tk['category_name']) ?></dd></div>
    <div><dt><?= $e($t('helpdesk.col.requester')) ?></dt><dd><?= $e((string) $tk['requester_name']) ?><?= $tk['source'] === 'inspection' ? ' · ' . $e($t('helpdesk.from_inspection')) : '' ?></dd></div>
    <div><dt><?= $e($t('helpdesk.col.opened')) ?></dt><dd><?= $e($date(substr((string) $tk['opened_at'], 0, 10), false) . ' ' . substr((string) $tk['opened_at'], 11, 5)) ?></dd></div>
    <?php if ($tk['site_code'] !== null || $tk['location'] !== null): ?><div><dt><?= $e($t('helpdesk.field.location')) ?></dt><dd><?= $e(trim(($tk['site_code'] ?? '') . ' ' . ($tk['location'] ?? ''))) ?></dd></div><?php endif; ?>
    <?php if ($tk['asset'] !== null): ?><div><dt><?= $e($t('helpdesk.field.asset')) ?></dt><dd><?= $e((string) $tk['asset']) ?></dd></div><?php endif; ?>
    <div><dt><?= $e($t('helpdesk.col.assignee')) ?></dt><dd><?= $e((string) ($tk['assignee_name'] ?? $t('helpdesk.waiting_assign'))) ?></dd></div>
    <?php if ($tk['due_at'] !== null): ?><div><dt><?= $e($t('helpdesk.field.due')) ?></dt><dd><?= $deadline((string) $tk['due_at']) ?></dd></div><?php endif; ?>
    <div><dt><?= $e($t('helpdesk.col.response_due')) ?></dt><dd><?= $deadline($tk['response_due'] !== null ? (string) $tk['response_due'] : null) ?><?= $tk['first_response_at'] !== null ? ' · ' . $e($t('helpdesk.responded', ['at' => $date(substr((string) $tk['first_response_at'], 0, 10), false) . ' ' . substr((string) $tk['first_response_at'], 11, 5)])) : '' ?></dd></div>
    <div><dt><?= $e($t('helpdesk.col.resolve_due')) ?></dt><dd><?= $deadline($tk['resolve_due'] !== null ? (string) $tk['resolve_due'] : null) ?><?= (int) $tk['sla_breached'] === 1 ? ' ' . $ui('badge', ['tone' => 'danger', 'label' => $t('helpdesk.breached')]) : '' ?></dd></div>
    <?php if ($tk['resolution'] !== null): ?><div><dt><?= $e($t('helpdesk.field.resolution')) ?></dt><dd><?= nl2br($e((string) $tk['resolution'])) ?></dd></div><?php endif; ?>
    <?php if ($tk['rating'] !== null): ?><div><dt><?= $e($t('helpdesk.field.rating')) ?></dt><dd><?= $e($t('helpdesk.rating_' . (int) $tk['rating'])) ?><?= $tk['rating_note'] !== null ? ' — ' . $e((string) $tk['rating_note']) : '' ?></dd></div><?php endif; ?>
  </dl>
  <h3 class="text-md"><?= $e($t('helpdesk.field.description')) ?></h3>
  <p><?= nl2br($e((string) $tk['description'])) ?></p>
  <?= $fileLinks($byMessage['ticket'] ?? []) ?>
</section>

<div class="stack">
<?php if ($tk['can_confirm']): ?>
<section class="card stack-sm" aria-labelledby="hd-rate">
  <h2 id="hd-rate" class="card__title"><?= $e($t('helpdesk.confirm_title')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('helpdesk.confirm_help')) ?></p>
  <form method="post" action="<?= $e($url($base . '/confirm')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ratingField('confirm') ?>
    <?= $ui('field', ['name' => 'rating_note', 'id' => 'f-confirm-note', 'label' => $t('helpdesk.field.rating_note'), 'value' => $o('confirm', 'rating_note'), 'error' => $err('confirm', 'rating_note')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('helpdesk.confirm'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
  </form>
  <?php if ($tk['can_reopen']): ?>
  <form method="post" action="<?= $e($url($base . '/reopen')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'reopen_reason', 'label' => $t('helpdesk.field.reopen_reason'), 'required' => true, 'value' => $o('reopen', 'reopen_reason'),
        'help' => $tk['policy']['reopen_days'] !== null ? $t('helpdesk.reopen_window', ['n' => (string) $tk['policy']['reopen_days']]) : null, 'error' => $err('reopen', 'reopen_reason')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('helpdesk.reopen'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'arrow-back-up']) ?></div>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php if ($tk['can_rate']): ?>
<section class="card stack-sm" aria-labelledby="hd-rate">
  <h2 id="hd-rate" class="card__title"><?= $e($t('helpdesk.rate_title')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('helpdesk.rate_help')) ?></p>
  <form method="post" action="<?= $e($url($base . '/rate')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ratingField('rate') ?>
    <?= $ui('field', ['name' => 'rating_note', 'id' => 'f-rate-note', 'label' => $t('helpdesk.field.rating_note'), 'value' => $o('rate', 'rating_note'), 'error' => $err('rate', 'rating_note')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('helpdesk.rate'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'star']) ?></div>
  </form>
</section>
<?php endif; ?>
<?php if ($tk['can_assign']): ?>
<section class="card stack-sm" aria-labelledby="ht-as">
  <h2 id="ht-as" class="card__title"><?= $e($t($tk['assignee_id'] === null ? 'helpdesk.assign' : 'helpdesk.reassign')) ?></h2>
  <form method="post" action="<?= $e($url($base . '/assign')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?php $team = ['' => $t('helpdesk.pick_person')]; foreach ($tk['team'] as $m) { $team[(string) $m['user_id']] = $m['name'] . ' (' . $t('helpdesk.role.' . $m['role']) . ')'; } ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'assignee', 'type' => 'select', 'label' => $t('helpdesk.col.assignee'), 'required' => true, 'value' => $o('assign', 'assignee', (string) ($tk['assignee_id'] ?? '')), 'options' => $team, 'error' => $err('assign', 'assignee')]) ?>
      <?= $ui('field', ['name' => 'due', 'id' => 'f-assign-due', 'type' => 'datetime-local', 'label' => $t('helpdesk.field.due'), 'value' => $o('assign', 'due', $tk['due_at'] !== null ? str_replace(' ', 'T', substr((string) $tk['due_at'], 0, 16)) : ''), 'min' => $nowAt, 'error' => $err('assign', 'due')]) ?>
    </div>
    <?php if ($tk['assignee_id'] !== null): ?><?= $ui('field', ['name' => 'reason', 'id' => 'f-assign-reason', 'label' => $t('helpdesk.field.reassign_reason'), 'value' => $o('assign', 'reason'), 'help' => $t('helpdesk.reassign_help'), 'error' => $err('assign', 'reason')]) ?><?php endif; ?>
    <div class="row"><?= $ui('button', ['label' => $t($tk['assignee_id'] === null ? 'helpdesk.assign' : 'helpdesk.reassign'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'user']) ?></div>
  </form>
</section>
<?php endif; ?>
<?php if ($tk['can_work'] && $tk['assignee_id'] !== null): ?>
<section class="card stack-sm" aria-labelledby="ht-w">
  <h2 id="ht-w" class="card__title"><?= $e($t('helpdesk.work')) ?></h2>
  <form method="post" action="<?= $e($url($base . '/status')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'status', 'type' => 'select', 'label' => $t('helpdesk.field.progress'), 'value' => $o('status', 'status', in_array($tk['status'], ['in_progress', 'waiting_user', 'waiting_parts'], true) ? (string) $tk['status'] : 'in_progress'),
          'options' => ['in_progress' => $t('helpdesk.status.in_progress'), 'waiting_user' => $t('helpdesk.status.waiting_user'), 'waiting_parts' => $t('helpdesk.status.waiting_parts')], 'error' => $err('status', 'status')]) ?>
      <?= $ui('field', ['name' => 'status_note', 'label' => $t('helpdesk.field.status_note'), 'value' => $o('status', 'status_note'), 'error' => $err('status', 'status_note')]) ?>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('helpdesk.update_status'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'refresh']) ?></div>
  </form>
  <form method="post" action="<?= $e($url($base . '/resolve')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'resolution', 'type' => 'textarea', 'label' => $t('helpdesk.field.resolution'), 'required' => true, 'value' => $o('resolve', 'resolution'), 'help' => $t('helpdesk.resolution_help'), 'error' => $err('resolve', 'resolution')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('helpdesk.resolve'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
  </form>
</section>
<?php endif; ?>
</div>
</div>

<section class="card stack-sm" aria-labelledby="hd-thread">
  <h2 id="hd-thread" class="card__title"><?= $e($t('helpdesk.thread')) ?></h2>
  <?php if ($tk['messages'] === []): ?><p class="text-sm text-muted"><?= $e($t('helpdesk.no_messages')) ?></p><?php endif; ?>
  <ol class="plain-list stack">
    <?php foreach ($tk['messages'] as $m): ?>
    <li class="hd-msg stack-sm<?= $m['kind'] === 'note' ? ' hd-msg--note' : '' ?><?= (int) $m['author_id'] === (int) $tk['requester_id'] ? ' hd-msg--requester' : '' ?>">
      <p class="text-sm"><strong><?= $e((string) $m['author']) ?></strong> · <span class="text-muted"><?= $e($date((string) $m['created_at'])) ?></span>
        <?= $m['kind'] === 'note' ? $ui('badge', ['tone' => 'neutral', 'label' => $t('helpdesk.internal_note')]) : '' ?></p>
      <p><?= nl2br($e((string) $m['body'])) ?></p>
      <?= $fileLinks($byMessage[(string) $m['id']] ?? []) ?>
    </li>
    <?php endforeach; ?>
  </ol>
  <?php if ($tk['can_message']): ?>
  <form method="post" action="<?= $e($url($base . '/message')) ?>" enctype="multipart/form-data" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'body', 'type' => 'textarea', 'label' => $t('helpdesk.field.message'), 'required' => true, 'value' => $o('message', 'body'), 'error' => $err('message', 'body')]) ?>
    <?= $ui('dropzone', ['name' => 'files[]', 'id' => 'f-msg-files', 'label' => $t('helpdesk.field.files'), 'multiple' => true, 'accept' => '.pdf,.png,.jpg,.jpeg,.webp,image/*', 'help' => $t('helpdesk.files_help'), 'error' => $err('message', 'files')]) ?>
    <?php if ($tk['staff'] && !$tk['mine']): ?><label class="check"><input type="checkbox" name="internal" value="1"<?= $o('message', 'internal') !== '' ? ' checked' : '' ?>> <?= $e($t('helpdesk.field.internal')) ?></label><?php endif; ?>
    <div class="row"><?= $ui('button', ['label' => $t('helpdesk.send_message'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
  </form>
  <?php endif; ?>
</section>

<?php if ($tk['can_assign'] || $tk['can_cancel']): ?>
<div class="grid grid--3">
<?php if ($tk['can_assign']): ?>
<section class="card stack-sm" aria-labelledby="ht-p">
  <h2 id="ht-p" class="card__title"><?= $e($t('helpdesk.change_priority')) ?></h2>
  <form method="post" action="<?= $e($url($base . '/priority')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'priority', 'id' => 'f-prio', 'type' => 'select', 'label' => $t('helpdesk.field.priority'), 'value' => $o('priority', 'priority', (string) $tk['priority']),
        'options' => ['low' => $t('helpdesk.priority.low'), 'normal' => $t('helpdesk.priority.normal'), 'high' => $t('helpdesk.priority.high'), 'urgent' => $t('helpdesk.priority.urgent')], 'error' => $err('priority', 'priority')]) ?>
    <?= $ui('field', ['name' => 'priority_reason', 'label' => $t('helpdesk.field.reason'), 'required' => true, 'value' => $o('priority', 'priority_reason'), 'error' => $err('priority', 'priority_reason')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('helpdesk.save'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
  </form>
</section>
<?php $others = array_filter($categories, static fn (array $c): bool => (int) $c['id'] !== (int) $tk['category_id']); ?>
<?php if ($others !== []): ?>
<section class="card stack-sm" aria-labelledby="ht-tr">
  <h2 id="ht-tr" class="card__title"><?= $e($t('helpdesk.transfer')) ?></h2>
  <form method="post" action="<?= $e($url($base . '/transfer')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'category', 'id' => 'f-tr-cat', 'type' => 'select', 'label' => $t('helpdesk.field.category'), 'required' => true, 'value' => $o('transfer', 'category'), 'options' => ['' => $t('helpdesk.pick_category')] + array_column($others, 'name', 'public_id'), 'error' => $err('transfer', 'category')]) ?>
    <?= $ui('field', ['name' => 'transfer_reason', 'label' => $t('helpdesk.field.reason'), 'required' => true, 'value' => $o('transfer', 'transfer_reason'), 'error' => $err('transfer', 'transfer_reason')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('helpdesk.transfer'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
  </form>
</section>
<?php endif; ?>
<?php endif; ?>
<?php if ($tk['can_cancel']): ?>
<section class="card stack-sm" aria-labelledby="ht-c">
  <h2 id="ht-c" class="card__title"><?= $e($t('helpdesk.cancel')) ?></h2>
  <form method="post" action="<?= $e($url($base . '/cancel')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'cancel_reason', 'label' => $t('helpdesk.field.reason'), 'required' => !$tk['mine'], 'value' => $o('cancel', 'cancel_reason'), 'error' => $err('cancel', 'cancel_reason')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('helpdesk.cancel'), 'type' => 'submit', 'variant' => 'danger', 'icon' => 'x']) ?></div>
  </form>
</section>
<?php endif; ?>
</div>
<?php endif; ?>

<section class="card stack-sm" aria-labelledby="ht-h">
  <h2 id="ht-h" class="card__title"><?= $e($t('helpdesk.history')) ?></h2>
  <ul class="plain-list stack-sm"><?php foreach ($tk['events'] as $ev): ?><li><?= $e($t('helpdesk.event.' . $ev['event'])) ?> · <?= $e((string) ($ev['actor'] ?? $t('helpdesk.system'))) ?> <span class="text-sm text-muted"><?= $e($date((string) $ev['created_at'])) ?></span><?= $ev['note'] !== null ? ' — ' . $e((string) $ev['note']) : '' ?></li><?php endforeach; ?></ul>
</section>
