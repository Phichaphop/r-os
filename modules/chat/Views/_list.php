<?php
/**
 * Conversation list beside every chat page.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date
 * @var list<array<string, mixed>> $list @var string $current
 */
?>
<nav class="chat__list" aria-labelledby="chat-list-h">
  <div class="chat__list-head">
    <h2 id="chat-list-h" class="card__title"><?= $e($t('chat.conversations')) ?></h2>
    <?= $ui('button', ['label' => $t('chat.new'), 'href' => $url('/chat/new'), 'variant' => 'primary', 'size' => 'sm', 'icon' => 'plus']) ?>
  </div>
  <form method="get" action="<?= $e($url('/chat')) ?>" role="search" class="chat__search">
    <label class="sr-only" for="chat-q"><?= $e($t('chat.search')) ?></label>
    <input class="input" type="search" id="chat-q" name="q" placeholder="<?= $e($t('chat.search')) ?>" maxlength="60">
  </form>
  <?php if ($list === []): ?>
    <p class="text-sm text-muted"><?= $e($t('chat.none')) ?></p>
  <?php else: ?>
  <ul class="chat__items" role="list">
    <?php foreach ($list as $c): $name = $c['kind'] === 'direct' ? (string) ($c['other_name'] ?? '—') : (string) $c['title']; ?>
      <li><a class="chat__item<?= $c['public_id'] === $current ? ' is-current' : '' ?>" href="<?= $e($url('/chat/c/' . $c['public_id'])) ?>"<?= $c['public_id'] === $current ? ' aria-current="page"' : '' ?>>
        <span class="chat__avatar" aria-hidden="true"><?= $ui('icon', ['name' => $c['kind'] === 'direct' ? 'user' : 'users', 'size' => 'sm']) ?></span>
        <span class="chat__item-body"><span class="chat__item-name"><?= $e($name) ?></span>
          <span class="text-xs text-muted"><?= $c['kind'] === 'group' ? $e($t('chat.members_n', ['n' => (string) $c['members']])) . ' · ' : '' ?><?= $e($date((string) ($c['last_message_at'] ?? ''))) ?></span></span>
        <?php if ((int) $c['unread'] > 0): ?><span class="badge badge--danger chat__unread"><span class="sr-only"><?= $e($t('chat.unread')) ?> </span><?= (int) $c['unread'] ?></span><?php endif; ?>
      </a></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</nav>
