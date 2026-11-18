<?php
/**
 * Page header: breadcrumb + title + record status + ONE primary action + secondary actions (P0-05 §3.1).
 * 0.8.2 (DEC-69): rendered as the page's hero banner (navy, organization pattern) — title and purpose on the left,
 * the page's actions grouped on the right. Props: title, subtitle, crumbs (list of [label, href|null]),
 * status (badge status key), actions (trusted HTML), plain (true = old flat header, e.g. inside a dialog).
 *
 * @var array<string, mixed> $p
 * @var callable $e
 * @var callable $t
 * @var callable $ui
 */
$plain = !empty($p['plain']);
?>
<header class="page-header<?= $plain ? '' : ' page-hero card card--navy navy-dots on-navy sparkle-hero' ?>">
  <div class="page-header__text">
    <?php if (!empty($p['crumbs'])): ?>
      <nav class="breadcrumb" aria-label="<?= $e($t('a11y.breadcrumb')) ?>"><ol>
        <?php foreach ($p['crumbs'] as $i => [$label, $href]): ?>
          <li><?php if ($href !== null && $i < count($p['crumbs']) - 1): ?><a href="<?= $e($href) ?>"><?= $e($label) ?></a><?php else: ?><span aria-current="page"><?= $e($label) ?></span><?php endif; ?></li>
        <?php endforeach; ?>
      </ol></nav>
    <?php endif; ?>
    <div class="page-header__title"><h1><?= $e($p['title'] ?? '') ?></h1><?= !empty($p['status']) ? $ui('badge', ['status' => $p['status']]) : '' ?></div>
    <?php if (!empty($p['subtitle'])): ?><p class="page-header__subtitle"><?= $e($p['subtitle']) ?></p><?php endif; ?>
  </div>
  <?php if (!empty($p['actions'])): ?><div class="page-header__actions page-hero__aside"><?= $p['actions'] ?></div><?php endif; ?>
</header>
