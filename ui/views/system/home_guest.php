<?php
/**
 * Public home for a visitor who has not signed in (0.8.1, DEC-68): the apps the organization chose to show here.
 * "Open" apps can be used straight away; the others ask to sign in first and then continue to that app.
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $asset @var array<string, mixed> $v
 * @var list<array{key: string, label: string, summary: string, icon: string, href: string, open: bool}> $apps
 */
$open = array_values(array_filter($apps, static fn (array $a): bool => $a['open']));
$signin = array_values(array_filter($apps, static fn (array $a): bool => !$a['open']));
$tile = static function (array $a, bool $isOpen) use ($e, $t, $ui, $url): string {
    $tid = 'gh-' . preg_replace('/[^a-z0-9]/', '-', (string) $a['key']);
    $href = $isOpen ? $a['href'] : '/login?next=' . rawurlencode($a['href']);
    return '<li data-hub-item data-hub-text="' . $e(mb_strtolower($a['label'] . ' ' . $a['summary'])) . '">'
        . '<a class="app-tile' . ($isOpen ? '' : ' app-tile--signin') . '" href="' . $e($url($href)) . '" aria-describedby="' . $e($tid) . '">'
        . '<span class="app-tile__icon">' . $ui('icon', ['name' => $a['icon']]) . (!$isOpen ? '<span class="app-tile__lock" aria-hidden="true">' . $ui('icon', ['name' => 'lock', 'size' => 'sm']) . '</span>' : '') . '</span>'
        . '<span class="app-tile__name" title="' . $e($a['label']) . '">' . $e($a['label']) . '</span>'
        . '<span class="app-tile__desc"' . ($a['summary'] !== '' ? ' title="' . $e($a['summary']) . '"' : '') . '>' . $e($a['summary']) . '</span>'
        . '<span class="app-tile__state" id="' . $e($tid) . '">' . $ui('badge', $isOpen ? ['tone' => 'success', 'icon' => 'lock-open', 'label' => $t('home.guest.open')] : ['tone' => 'neutral', 'icon' => 'lock', 'label' => $t('home.guest.signin_needed')]) . '</span>'
        . '</a></li>';
};
?>
<section class="page-header page-hero page-hero--home card card--navy navy-dots on-navy sparkle-hero hub-hero" aria-labelledby="hub-h">
  <div class="page-header__text hub-hero__text">
    <h1 id="hub-h"><?= $e($t('home.guest.hero')) ?></h1>
    <p class="page-hero__lead"><?= $e($t('home.guest.subtitle')) ?></p>
  </div>
  <div class="page-hero__aside">
    <div class="hub-search" data-hub-search hidden>
      <label for="hub-q" class="sr-only"><?= $e($t('hub.search')) ?></label>
      <span class="hub-search__icon"><?= $ui('icon', ['name' => 'search', 'size' => 'sm']) ?></span>
      <input id="hub-q" class="input hub-search__input" type="search" autocomplete="off" placeholder="<?= $e($t('hub.search_placeholder')) ?>" aria-describedby="hub-found">
    </div>
    <a class="btn btn--primary" href="<?= $e($url('/login')) ?>"><?= $ui('icon', ['name' => 'user', 'size' => 'sm']) ?><?= $e($t('home.guest.signin')) ?></a>
  </div>
</section>
<p id="hub-found" class="sr-only" aria-live="polite" data-hub-found data-template="<?= $e($t('hub.found', ['n' => '{n}'])) ?>"></p>

<div class="hub" data-hub>
  <?php if ($open !== []): ?>
  <section class="hub-group" aria-labelledby="gh-open" data-hub-group>
    <h2 id="gh-open" class="hub-group__title"><?= $e($t('home.guest.open_title')) ?></h2>
    <p class="text-sm text-muted"><?= $e($t('home.guest.open_text')) ?></p>
    <ul class="app-grid" role="list"><?php foreach ($open as $a) { echo $tile($a, true); } ?></ul>
  </section>
  <?php endif; ?>
  <?php if ($signin !== []): ?>
  <section class="hub-group" aria-labelledby="gh-signin" data-hub-group>
    <h2 id="gh-signin" class="hub-group__title"><?= $e($t('home.guest.signin_title')) ?></h2>
    <p class="text-sm text-muted"><?= $e($t('home.guest.signin_text')) ?></p>
    <ul class="app-grid" role="list"><?php foreach ($signin as $a) { echo $tile($a, false); } ?></ul>
  </section>
  <?php endif; ?>
  <?php if ($apps === []): ?>
    <?= $ui('alert', ['tone' => 'info', 'title' => $t('home.guest.empty_title'), 'text' => $t('home.guest.empty_text')]) ?>
  <?php endif; ?>
  <p class="hub-none text-muted" data-hub-none hidden><?= $e($t('hub.none')) ?></p>
</div>

<script src="<?= $e($asset('ui/js/home.js')) ?>" defer></script>
