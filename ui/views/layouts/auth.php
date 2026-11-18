<?php
/**
 * Sign-in pages (login, forgot / reset password, set password, MFA code) — 0.37.0: reversed split screen, branding
 * on the left half and the form centred in the right half (50:50); stacked (branding on top) on narrow screens.
 * @var callable $e @var callable $t @var array<string, mixed> $v @var callable $ui @var callable $url @var callable $asset @var string $content
 */
$brandShowName = ($v['theme']['show_name'] ?? true) !== false;
$brandTagline = (string) ($v['theme']['tagline'] ?? '');
?><!doctype html>
<html lang="<?= $e($v['locale'] ?? 'th') ?>" data-pattern="<?= $e((string) ($v['theme']['pattern'] ?? 'dots')) ?>">
<head><?php include __DIR__ . '/../partials/head.php'; ?></head>
<body class="auth">
<a class="skip-link" href="#main"><?= $e($t('a11y.skip')) ?></a>
<div class="auth-split">
  <aside class="auth-split__brand navy-dots on-navy" aria-label="<?= $e($v['app_name'] ?? 'Blue OS') ?>">
    <div class="auth-split__brand-inner">
      <?php $brandSize = 'hero'; include __DIR__ . '/../partials/brand.php'; ?>
      <?php if ($brandShowName): ?><p class="auth-split__name"><?= $e($v['app_name'] ?? 'Blue OS') ?></p><?php endif; ?>
      <p class="auth-split__lead"><?= $e($brandTagline !== '' ? $brandTagline : $t('auth.split.lead')) ?></p>
    </div>
  </aside>
  <main id="main" class="auth-split__form">
    <div class="auth-split__card">
      <?php if (is_array($v['flash'] ?? null)): ?><?= $ui('alert', ['tone' => $v['flash']['tone'] ?? 'info', 'text' => $v['flash']['text'] ?? '', 'live' => true]) ?><?php endif; ?>
      <?= $content ?>
    </div>
  </main>
</div>
</body>
</html>
