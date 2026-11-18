<?php
/**
 * One chat message (the page and the polling script use the same partial). Text is escaped (ChatService::render).
 * Removed text is never sent to members: deleted, hidden by a moderator or past retention show a placeholder only.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $m @var array<string, mixed> $c @var array<int, int> $reads
 * @var string|null $edit @var string|null $reportId
 */
use Blue\Modules\Chat\ChatService;

$edit = $edit ?? '';
$reportId = $reportId ?? '';
$pid = (string) $m['public_id'];
$base = '/chat/c/' . $c['public_id'];
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
if ($m['kind'] === 'system'): ?>
<li class="msg msg--system" id="m-<?= $e($pid) ?>" data-id="<?= (int) $m['id'] ?>"><span><?= $e(ChatService::systemText((string) $m['body'], $t)) ?> · <?= $e($date((string) $m['created_at'])) ?></span></li>
<?php return; endif;
$state = $m['purged_at'] !== null ? 'purged' : ($m['hidden_at'] !== null ? 'hidden' : ($m['deleted_at'] !== null ? 'deleted' : ''));
$read = 0;
foreach ($reads as $u => $rid) {
    if ($u !== (int) $m['sender_id'] && $rid >= (int) $m['id']) {
        $read++;
    }
}
?>
<li class="msg<?= $m['mine'] ? ' msg--mine' : '' ?><?= $state !== '' ? ' msg--gone' : '' ?>" id="m-<?= $e($pid) ?>" data-id="<?= (int) $m['id'] ?>">
  <div class="msg__head">
    <?php if (!$m['mine']): ?><span class="msg__who"><?= $e((string) $m['sender']) ?></span><?php endif; ?>
    <time class="msg__time" datetime="<?= $e((string) $m['created_at']) ?>"><?= $e($date((string) $m['created_at'])) ?></time>
  </div>
  <div class="msg__bubble">
    <?php if ($state !== ''): ?>
      <p class="msg__placeholder"><?= $ui('icon', ['name' => $state === 'hidden' ? 'shield-check' : 'trash', 'size' => 'sm']) ?> <?= $e($t('chat.gone.' . $state)) ?></p>
    <?php elseif ($edit === $pid && $m['can_change']): ?>
      <form method="post" action="<?= $e($url($base . '/m/' . $pid . '/edit')) ?>" class="stack-sm">
        <?= $csrf ?>
        <label class="sr-only" for="ed-<?= $e($pid) ?>"><?= $e($t('chat.edit_label')) ?></label>
        <textarea class="input" id="ed-<?= $e($pid) ?>" name="body" rows="3" maxlength="<?= ChatService::MAX_LENGTH ?>"><?= $e((string) $m['body']) ?></textarea>
        <div class="row"><?= $ui('button', ['label' => $t('common.save'), 'type' => 'submit', 'variant' => 'primary', 'size' => 'sm']) ?>
          <a class="btn btn--ghost btn--sm" href="<?= $e($url($base . '#m-' . $pid)) ?>"><?= $e($t('common.cancel')) ?></a></div>
      </form>
    <?php else: ?>
      <?php if ((string) $m['body'] !== ''): ?><div class="msg__text"><?= ChatService::render((string) $m['body']) ?></div><?php endif; ?>
      <?php if ($m['files'] !== []): ?>
        <ul class="msg__files" role="list">
          <?php foreach ($m['files'] as $f): $href = $url($base . '/f/' . (int) $f['id']); ?>
            <li><?php if (str_starts_with((string) $f['mime'], 'image/')): ?>
              <a href="<?= $e($href) ?>" target="_blank" rel="noopener"><img class="msg__img" src="<?= $e($href) ?>" alt="<?= $e((string) $f['filename']) ?>" loading="lazy"></a>
            <?php else: ?>
              <a class="msg__file" href="<?= $e($href) ?>"><?= $ui('icon', ['name' => 'file-type-pdf', 'size' => 'sm']) ?> <span class="break-all"><?= $e((string) $f['filename']) ?></span></a>
            <?php endif; ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <div class="msg__meta">
    <?php if ($m['edited_at'] !== null && $state === ''): ?><span><?= $e($t('chat.edited')) ?></span><?php endif; ?>
    <?php if ($m['mine'] && $state === ''): ?><span data-read="<?= (int) $m['id'] ?>"><?= $read > 0 ? $e($c['kind'] === 'direct' ? $t('chat.read') : $t('chat.read_n', ['n' => (string) $read])) : $e($t('chat.sent')) ?></span><?php endif; ?>
    <?php if ($state === '' && ($m['can_change'] || !$m['mine'])): ?>
      <details class="msg__menu">
        <summary aria-label="<?= $e($t('chat.actions')) ?>"><?= $ui('icon', ['name' => 'dots', 'size' => 'sm']) ?></summary>
        <div class="msg__menu-body stack-sm">
          <?php if ($m['can_change']): ?>
            <a class="btn btn--ghost btn--sm" href="<?= $e($url($base . '?edit=' . $pid . '#m-' . $pid)) ?>"><?= $ui('icon', ['name' => 'pencil', 'size' => 'sm']) ?> <?= $e($t('chat.edit')) ?></a>
            <form method="post" action="<?= $e($url($base . '/m/' . $pid . '/delete')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('chat.delete'), 'type' => 'submit', 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'trash']) ?></form>
          <?php elseif (!$m['mine']): ?>
            <form method="post" action="<?= $e($url($base . '/m/' . $pid . '/report')) ?>" class="stack-sm">
              <?= $csrf ?>
              <label class="field__label" for="rp-<?= $e($pid) ?>"><?= $e($t('chat.report_reason')) ?></label>
              <input class="input" id="rp-<?= $e($pid) ?>" name="reason" minlength="5" maxlength="500" required>
              <p class="text-xs text-muted"><?= $e($t('chat.report_help')) ?></p>
              <?= $ui('button', ['label' => $t('chat.report'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'flag']) ?>
            </form>
          <?php endif; ?>
        </div>
      </details>
    <?php endif; ?>
  </div>
</li>
