<?php
/** Contact cards (also returned alone for type-ahead). @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var list<array<string, mixed>> $cards @var string $q @var string $view */
$back = '/directory?view=' . $view . ($q !== '' ? '&q=' . rawurlencode($q) : '');
?>
<div data-dir-results aria-live="polite">
<?php if ($cards === []): ?>
  <p class="text-sm text-muted"><?= $e($t($q === '' && $view === 'all' ? 'directory.start_typing' : 'directory.none')) ?></p>
<?php else: ?>
  <p class="text-sm text-muted"><?= $e($t('directory.found', ['n' => (string) count($cards)])) ?></p>
  <ul class="dir-grid" role="list">
    <?php foreach ($cards as $c): $initial = mb_strtoupper(mb_substr(trim((string) $c['name']), 0, 1)); ?>
    <li class="card dir-card">
      <div class="dir-card__head">
        <span class="dir-card__avatar" aria-hidden="true"><?= $e($initial) ?></span>
        <div class="dir-card__who"><strong class="dir-card__name"><?= $e((string) $c['name']) ?></strong>
          <span class="text-sm text-muted"><?= $e(implode(' · ', array_filter([$c['position'], $c['department'], $c['site']]))) ?></span></div>
      </div>
      <ul class="dir-card__ways" role="list">
        <?php if ($c['phone'] !== null && $c['phone'] !== ''): ?><li><a href="tel:<?= $e(preg_replace('/[^0-9+]/', '', (string) $c['phone'])) ?>"><?= $ui('icon', ['name' => 'external-link', 'size' => 'sm']) ?> <?= $e($t('directory.call')) ?> <?= $e((string) $c['phone']) ?></a></li><?php endif; ?>
        <?php if ($c['extension'] !== null && $c['extension'] !== ''): ?><li><?= $e($t('directory.ext', ['n' => (string) $c['extension']])) ?></li><?php endif; ?>
        <?php if ($c['email'] !== null && $c['email'] !== ''): ?><li><a href="mailto:<?= $e((string) $c['email']) ?>"><?= $ui('icon', ['name' => 'send', 'size' => 'sm']) ?> <?= $e((string) $c['email']) ?></a></li><?php endif; ?>
        <li><span class="text-muted" title="<?= $e($t('directory.chat_off')) ?>"><?= $e($t('directory.chat')) ?> — <?= $e($t('directory.chat_soon')) ?></span></li>
      </ul>
      <form method="post" action="<?= $e($url('/directory/p/' . $c['public_id'] . '/pin')) ?>">
        <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>"><input type="hidden" name="on" value="<?= $c['pinned'] ? '0' : '1' ?>"><input type="hidden" name="back" value="<?= $e($back) ?>">
        <button type="submit" class="btn btn--ghost btn--sm" aria-pressed="<?= $c['pinned'] ? 'true' : 'false' ?>"><?= $ui('icon', ['name' => $c['pinned'] ? 'x' : 'plus', 'size' => 'sm']) ?><span><?= $e($t($c['pinned'] ? 'directory.unpin' : 'directory.pin')) ?></span><span class="sr-only"> <?= $e((string) $c['name']) ?></span></button>
      </form>
    </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
</div>
