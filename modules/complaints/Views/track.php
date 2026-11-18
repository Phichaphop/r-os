<?php
/**
 * Follow a complaint with its tracking code (signed in, or the public page). The code travels only in POST bodies.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var callable $partial @var array<string, mixed> $v
 * @var bool $public @var array<string, mixed>|null $case @var string $code @var array<string, string> $errors @var bool $asked
 * @var string|null $done @var string $base @var string|null $notice
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$hid = $csrf . '<input type="hidden" name="code" value="' . $e($code) . '">';
$c = $case;
$byMsg = [];
$loose = [];
foreach ($c['files'] ?? [] as $f) {
    if ($f['message_id'] === null) {
        $loose[] = $f;
    } else {
        $byMsg[(string) $f['message_id']][] = $f;
    }
}
$fileForms = static function (array $files) use ($e, $url, $hid, $base, $ui): string {
    if ($files === []) {
        return '';
    }
    $h = '<ul class="row cp-files">';
    foreach ($files as $f) {
        $h .= '<li><form method="post" action="' . $e($url($base . '/file/' . (int) $f['id'])) . '">' . $hid . $ui('button', ['label' => (string) $f['filename'], 'type' => 'submit', 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'paperclip']) . '</form></li>';
    }
    return $h . '</ul>';
};
?>
<div class="stack<?= $public ? ' cp-public' : '' ?>">
<?php if ($public): ?><h1><?= $e($t('complaints.track.title')) ?></h1><?php else: ?><?= $ui('page-header', ['title' => $t('complaints.track.title'), 'subtitle' => $t('complaints.track.subtitle')]) ?><?php endif; ?>
<?php if ($done !== null): ?><?= $ui('alert', ['tone' => 'success', 'text' => $done]) ?><?php endif; ?>
<?php if (isset($errors['state'])): ?><?= $ui('alert', ['tone' => 'danger', 'text' => $errors['state']]) ?><?php endif; ?>
<?php if ($c === null): ?>
  <form method="post" action="<?= $e($url($base)) ?>" class="stack-sm<?= $public ? '' : ' card' ?>" novalidate><?= $csrf ?>
    <p class="text-sm text-muted"><?= $e($t('complaints.track.code_only')) ?></p>
    <?= $ui('field', ['name' => 'code', 'id' => 'tr-code', 'label' => $t('complaints.field.code'), 'required' => true, 'autocomplete' => 'off', 'help' => $t('complaints.code_help'), 'error' => $errors['code'] ?? null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('complaints.track.open'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
  </form>
<?php else: ?>
  <section class="stack-sm<?= $public ? '' : ' card' ?>" aria-labelledby="tr-h">
    <h2 id="tr-h" class="card__title"><?= $e((string) $c['ref']) ?> · <?= $e($t('complaints.category.' . $c['category'])) ?></h2>
    <dl class="facts">
      <div><dt><?= $e($t('complaints.field.status')) ?></dt><dd><?= $partial('@complaints/_status', ['s' => (string) $c['status']]) ?></dd></div>
      <div><dt><?= $e($t('complaints.field.sent_on')) ?></dt><dd><?= $e($date((string) $c['created_at'], false)) ?></dd></div>
      <div><dt><?= $e($t('complaints.field.route')) ?></dt><dd><?= $e($t('complaints.route.' . $c['route'])) ?></dd></div>
    </dl>
    <?php if ($c['purged']): ?>
      <p class="text-sm text-muted"><?= $e($t('complaints.purged')) ?></p>
    <?php else: ?>
      <h3 class="text-base"><?= $e((string) $c['title']) ?></h3>
      <p class="cp-text"><?= nl2br($e((string) $c['body'])) ?></p>
      <?= $fileForms($loose) ?>
    <?php endif; ?>
    <?php if ($c['status'] === 'action_required'): ?><?= $ui('alert', ['tone' => 'warning', 'text' => $t('complaints.track.asked')]) ?><?php endif; ?>
    <?php if ($c['outcome'] !== null): ?>
      <div class="cp-outcome stack-sm"><h3 class="text-base"><?= $e($t('complaints.field.outcome')) ?></h3><p class="cp-text"><?= nl2br($e((string) $c['outcome'])) ?></p></div>
    <?php endif; ?>
    <?php if ($c['closed_reason'] !== null && $c['closed_reason'] !== ''): ?><p class="text-sm"><strong><?= $e($t('complaints.field.reason')) ?>:</strong> <?= $e((string) $c['closed_reason']) ?></p><?php endif; ?>
    <?php if ($c['rating'] !== null): ?><p class="text-sm"><?= $e($t('complaints.track.rated', ['n' => (string) $c['rating']])) ?></p><?php endif; ?>
  </section>

  <section class="stack-sm<?= $public ? '' : ' card' ?>" aria-labelledby="tr-th">
    <h2 id="tr-th" class="card__title"><?= $e($t('complaints.thread')) ?></h2>
    <?php if ($c['messages'] === []): ?><p class="text-sm text-muted"><?= $e($t('complaints.no_messages')) ?></p><?php endif; ?>
    <ol class="plain-list stack-sm">
      <?php foreach ($c['messages'] as $m): ?>
      <li class="hd-msg stack-sm<?= $m['side'] === 'reporter' ? ' hd-msg--requester' : '' ?>">
        <p class="text-sm"><strong><?= $e($t($m['side'] === 'reporter' ? 'complaints.side.you' : 'complaints.side.staff')) ?></strong> · <span class="text-muted"><?= $e($date((string) $m['created_at'], false)) ?></span>
          <?= (int) $m['asks_reply'] === 1 ? $ui('badge', ['tone' => 'warning', 'label' => $t('complaints.asks_reply')]) : '' ?></p>
        <p class="cp-text"><?= $m['body'] === null ? $e($t('complaints.purged_short')) : nl2br($e((string) $m['body'])) ?></p>
        <?= $fileForms($byMsg[(string) $m['id']] ?? []) ?>
      </li>
      <?php endforeach; ?>
    </ol>
    <?php if ($c['can_reply']): ?>
    <form method="post" action="<?= $e($url($base . '/reply')) ?>" enctype="multipart/form-data" class="stack-sm" novalidate><?= $hid ?>
      <?= $ui('field', ['name' => 'body', 'id' => 'tr-body', 'type' => 'textarea', 'label' => $t('complaints.field.message'), 'required' => true, 'error' => $errors['body'] ?? null]) ?>
      <?= $ui('dropzone', ['name' => 'files[]', 'id' => 'tr-files', 'label' => $t('complaints.field.files'), 'multiple' => true, 'accept' => '.png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp', 'help' => $t('complaints.files_help'), 'error' => $errors['files'] ?? null]) ?>
      <p class="text-sm text-muted"><?= $e($t('complaints.track.reply_note')) ?></p>
      <div class="row"><?= $ui('button', ['label' => $t('complaints.track.send'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
    </form>
    <?php endif; ?>
  </section>

  <?php if ($c['can_rate']): ?>
  <section class="stack-sm<?= $public ? '' : ' card' ?>" aria-labelledby="tr-rate">
    <h2 id="tr-rate" class="card__title"><?= $e($t('complaints.track.rate_title')) ?></h2>
    <form method="post" action="<?= $e($url($base . '/accept')) ?>" class="stack-sm" novalidate><?= $hid ?>
      <?= $ui('field', ['name' => 'rating', 'id' => 'tr-rating', 'type' => 'select', 'label' => $t('complaints.field.rating'), 'required' => true,
          'options' => ['' => $t('complaints.choose'), '5' => $t('complaints.rating.5'), '4' => $t('complaints.rating.4'), '3' => $t('complaints.rating.3'), '2' => $t('complaints.rating.2'), '1' => $t('complaints.rating.1')], 'error' => $errors['rating'] ?? null]) ?>
      <?= $ui('field', ['name' => 'comment', 'id' => 'tr-comment', 'type' => 'textarea', 'label' => $t('complaints.field.comment'), 'help' => $t('complaints.comment_help'), 'error' => $errors['comment'] ?? null]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('complaints.track.accept'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
    </form>
    <?php if ($c['can_reopen']): ?>
    <form method="post" action="<?= $e($url($base . '/reopen')) ?>" class="stack-sm" novalidate><?= $hid ?>
      <?= $ui('field', ['name' => 'reason', 'id' => 'tr-reason', 'type' => 'textarea', 'label' => $t('complaints.field.reopen_reason'), 'required' => true, 'error' => $errors['reason'] ?? null]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('complaints.track.reopen'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'arrow-back-up']) ?></div>
    </form>
    <?php endif; ?>
  </section>
  <?php endif; ?>
  <?php if ($notice !== null): ?><p class="text-sm"><a href="<?= $e($url($notice)) ?>"><?= $e($t('complaints.notice_link')) ?></a></p><?php endif; ?>
  <p class="text-sm text-muted"><?= $e($t('complaints.track.leave')) ?></p>
<?php endif; ?>
</div>
