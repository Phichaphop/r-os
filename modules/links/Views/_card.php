<?php
/**
 * One link card (links page and the home "external links" section). Goes through the counted redirect, never the raw URL.
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, mixed> $l @var bool $compact @var string|null $tag optional category label shown on the card
 */
$compact = (bool) ($compact ?? false);
$name = ($v['locale'] ?? 'th') === 'en' && !empty($l['name_en']) ? $l['name_en'] : $l['name_th'];
$newTab = (int) $l['open_new_tab'] === 1;
?>
<a class="link-card<?= $compact ? ' link-card--compact' : '' ?>" href="<?= $e($url('/links/go/' . $l['public_id'])) ?>"<?= $newTab ? ' target="_blank" rel="noopener noreferrer"' : ' rel="noreferrer"' ?> referrerpolicy="no-referrer">
  <span class="link-card__icon" aria-hidden="true"><?= $ui('icon', ['name' => (string) $l['icon']]) ?></span>
  <span class="link-card__body"><span class="link-card__name"><?= $e($name) ?><?php if ($newTab): ?><span class="sr-only"> (<?= $e($t('links.new_tab')) ?>)</span><?php endif; ?></span>
    <?php if (!$compact && !empty($l['description'])): ?><span class="link-card__desc"><?= $e($l['description']) ?></span><?php endif; ?>
    <span class="link-card__host"><?= $e($l['host']) ?></span>
    <?php if (($tag ?? '') !== ''): ?><span class="link-card__tag"><?= $e($tag) ?></span><?php endif; ?></span>
  <?php if ($newTab): ?><span class="link-card__out" aria-hidden="true"><?= $ui('icon', ['name' => 'external-link', 'size' => 'sm']) ?></span><?php endif; ?>
</a>
