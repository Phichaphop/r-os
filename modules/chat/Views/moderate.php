<?php
/**
 * Report queue (chat.moderate). Each item is ONE reported message — its text when reported, earlier versions and files —
 * nothing else of the conversation (Q-05). Uphold hides it from the members (with the reason, the sender is told).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var list<array<string, mixed>> $reports @var string $state @var array<string, string> $errors
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
?>
<?= $ui('page-header', ['title' => $t('chat.moderate.title'), 'subtitle' => $t('chat.moderate.subtitle')]) ?>
<?= $ui('alert', ['tone' => 'info', 'text' => $t('chat.moderate.privacy')]) ?>
<nav class="segmented-links" aria-label="<?= $e($t('chat.moderate.title')) ?>">
  <a href="<?= $e($url('/chat/moderate')) ?>"<?= $state === 'open' ? ' aria-current="page"' : '' ?>><?= $e($t('chat.moderate.open')) ?></a>
  <a href="<?= $e($url('/chat/moderate?status=done')) ?>"<?= $state === 'done' ? ' aria-current="page"' : '' ?>><?= $e($t('chat.moderate.done')) ?></a>
</nav>
<?php if ($reports === []): ?>
  <?= $ui('states', ['kind' => 'empty', 'title' => $t('chat.moderate.none')]) ?>
<?php endif; ?>
<?php foreach ($reports as $r): ?>
<section class="card stack-sm" aria-labelledby="rp-<?= $e((string) $r['public_id']) ?>">
  <h2 id="rp-<?= $e((string) $r['public_id']) ?>" class="card__title"><?= $e($t('chat.moderate.item', ['sender' => (string) $r['sender'], 'at' => $date((string) $r['sent_at'])])) ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('chat.moderate.reporter')) ?></dt><dd><?= $e((string) $r['reporter']) ?> · <?= $e($date((string) $r['created_at'])) ?></dd></div>
    <div><dt><?= $e($t('chat.moderate.reason')) ?></dt><dd><?= $e((string) $r['reason']) ?></dd></div>
    <div><dt><?= $e($t('chat.moderate.text')) ?></dt><dd class="chat-quote"><?= $r['snapshot_body'] !== null ? \Blue\Modules\Chat\ChatService::render((string) $r['snapshot_body']) : $e($t('chat.moderate.no_text')) ?></dd></div>
    <?php foreach ($r['edits'] as $ed): ?>
      <div><dt><?= $e($t('chat.moderate.earlier_' . $ed['action'])) ?> · <?= $e($date((string) $ed['created_at'])) ?></dt><dd class="chat-quote"><?= $e((string) ($ed['old_body'] ?? '—')) ?></dd></div>
    <?php endforeach; ?>
    <?php if ($r['files'] !== []): ?>
      <div><dt><?= $e($t('chat.moderate.files')) ?></dt><dd><ul class="plain-list">
        <?php foreach ($r['files'] as $f): ?><li><?php if ($f['purged_at'] === null): ?><a href="<?= $e($url('/chat/moderate/r/' . $r['public_id'] . '/f/' . (int) $f['id'])) ?>"><?= $e((string) $f['filename']) ?></a><?php else: ?><?= $e((string) $f['filename']) ?> (<?= $e($t('chat.gone.purged')) ?>)<?php endif; ?></li><?php endforeach; ?>
      </ul></dd></div>
    <?php endif; ?>
    <?php if ($r['status'] !== 'open'): ?>
      <div><dt><?= $e($t('chat.moderate.decision')) ?></dt><dd><?= $ui('badge', ['tone' => $r['status'] === 'upheld' ? 'danger' : 'neutral', 'label' => $t('chat.moderate.status.' . $r['status'])]) ?> <?= $e((string) ($r['note'] ?? '')) ?></dd></div>
    <?php endif; ?>
  </dl>
  <?php if ($r['status'] === 'open'): ?>
    <div class="row">
      <form method="post" action="<?= $e($url('/chat/moderate/r/' . $r['public_id'] . '/uphold')) ?>" class="row">
        <?= $csrf ?>
        <label class="sr-only" for="note-<?= $e((string) $r['public_id']) ?>"><?= $e($t('chat.moderate.note')) ?></label>
        <input class="input" id="note-<?= $e((string) $r['public_id']) ?>" name="note" minlength="5" maxlength="500" placeholder="<?= $e($t('chat.moderate.note')) ?>" required>
        <?= $ui('button', ['label' => $t('chat.moderate.uphold'), 'type' => 'submit', 'variant' => 'danger', 'icon' => 'eye-off']) ?>
      </form>
      <form method="post" action="<?= $e($url('/chat/moderate/r/' . $r['public_id'] . '/dismiss')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('chat.moderate.dismiss'), 'type' => 'submit', 'variant' => 'ghost']) ?></form>
    </div>
  <?php endif; ?>
</section>
<?php endforeach; ?>
