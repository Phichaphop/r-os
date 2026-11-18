<?php
/** Layout without navigation: installer, error pages, login (phase 2). @var callable $e @var callable $t @var array<string, mixed> $v @var callable $ui @var string $content */
?><!doctype html>
<html lang="<?= $e($v['locale'] ?? 'th') ?>" data-pattern="<?= $e((string) ($v['theme']['pattern'] ?? 'dots')) ?>">
<head><?php include __DIR__ . '/../partials/head.php'; ?></head>
<body class="minimal">
<a class="skip-link" href="#main"><?= $e($t('a11y.skip')) ?></a>
<main id="main" class="minimal__main">
  <?php $brandShowName = ($v['theme']['show_name'] ?? true) !== false; ?>
  <div class="minimal__brand navy-dots on-navy<?= $brandShowName ? '' : ' minimal__brand--logo' ?>">
    <?php $brandSize = 'minimal'; include __DIR__ . '/../partials/brand.php'; ?>
    <?php if ($brandShowName): ?><span class="sidebar__brand-name"><?= $e($v['app_name'] ?? 'Blue OS') ?></span><?php endif; ?>
  </div>
  <div class="minimal__card">
    <?php if (is_array($v['flash'] ?? null)): ?><?= $ui('alert', ['tone' => $v['flash']['tone'] ?? 'info', 'text' => $v['flash']['text'] ?? '', 'live' => true]) ?><?php endif; ?>
    <?= $content ?>
  </div>
</main>
</body>
</html>
