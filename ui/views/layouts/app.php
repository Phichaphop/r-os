<?php
/**
 * App Shell (P0-05 §2): sidebar shows only what the user may open; topbar holds the company context
 * switcher (DEC-21), language, theme and the user menu. Flash messages render as a polite alert.
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $asset
 * @var array<string, mixed> $v @var string $content
 */
$path = $v['path'] ?? '/';
$shell = $v['shell'] ?? null;
$flash = $v['flash'] ?? null;
// a visitor (0.8.1): no account menu, search or bell — the side bar lists only the tools open to the public
if ($shell === null && is_array($v['guest_shell'] ?? null) && $v['guest_shell'] !== []) {
    $guestGroups = [['label' => $t('nav.guest_tools'), 'items' => $v['guest_shell']]];
}
// the menu item whose address matches the page best is "current" (e.g. /approvals/delegate is not also /approvals)
$best = '/';
$groups = $shell['groups'] ?? ($guestGroups ?? []);
foreach (array_merge(...array_map(static fn (array $g): array => $g['items'], $groups)) as $i) {
    if (($path === $i['href'] || str_starts_with($path, $i['href'] . '/')) && strlen($i['href']) > strlen($best)) {
        $best = $i['href'];
    }
}
$link = function (array $i) use ($e, $ui, $url, $path, $best): string {
    $current = $i['href'] === $best && ($path === $best || $best !== '/') ? ' aria-current="page"' : '';
    $count = !empty($i['count']) ? '<span class="sidebar__count">' . (int) $i['count'] . '<span class="sr-only"> รายการรอ</span></span>' : '';
    return '<a class="sidebar__link' . (!empty($i['sub']) ? ' sidebar__link--sub' : '') . '" href="' . $e($url($i['href'])) . '"' . $current . ' title="' . $e($i['label']) . '">'
        . $ui('icon', ['name' => $i['icon']]) . '<span class="sidebar__label">' . $e($i['label']) . '</span>' . $count . '</a>';
};
?><!doctype html>
<html lang="<?= $e($v['locale'] ?? 'th') ?>" data-pattern="<?= $e((string) ($v['theme']['pattern'] ?? 'dots')) ?>">
<head><?php include __DIR__ . '/../partials/head.php'; ?></head>
<body>
<a class="skip-link" href="#main"><?= $e($t('a11y.skip')) ?></a>
<?php if (($v['app_env'] ?? '') !== 'production'): ?>
  <div class="env-ribbon" role="note"><?= $e($t('env.ribbon', ['env' => strtoupper((string) $v['app_env'])])) ?></div>
<?php endif; ?>
<div class="shell" data-drawer="closed">
  <nav class="sidebar navy-dots on-navy" aria-label="<?= $e($t('a11y.main_nav')) ?>">
    <?php $brandShowName = ($v['theme']['show_name'] ?? true) !== false; ?>
    <a class="sidebar__brand<?= $brandShowName ? '' : ' sidebar__brand--logo' ?>" href="<?= $e($url('/')) ?>" aria-label="<?= $e($v['app_name'] ?? 'Blue OS') ?>">
      <?php $brandSize = 'menu'; include __DIR__ . '/../partials/brand.php'; ?>
      <?php if ($brandShowName): ?>
      <span class="sidebar__brand-text">
        <span class="sidebar__brand-name"><?= $e($v['app_name'] ?? 'Blue OS') ?></span>
        <?php if (($v['theme']['tagline'] ?? '') !== ''): ?><span class="sidebar__tagline"><?= $e($v['theme']['tagline']) ?></span><?php endif; ?>
      </span>
      <?php endif; ?>
    </a>
    <?= $link(['label' => $t('nav.home'), 'icon' => 'home', 'href' => '/']) ?>
    <?php
    // 0.27.1 (DEC-92): every group folds. A short menu starts fully open; a long one (administrators) starts with
    // only "my work" and the group holding the current page open — app.js then restores what this device remembers.
    $topLevel = 0;
    foreach ($groups as $g) {
        foreach ($g['items'] as $i) {
            $topLevel += empty($i['sub']) ? 1 : 0;
        }
    }
    $long = $topLevel > 14;
    if ($long): ?>
      <div class="sidebar__filter" data-navfilter hidden>
        <label class="sr-only" for="nav-filter"><?= $e($t('nav.filter')) ?></label>
        <span class="sidebar__filter-box"><?= $ui('icon', ['name' => 'search', 'size' => 'sm']) ?><input type="search" id="nav-filter" class="sidebar__filter-input" placeholder="<?= $e($t('nav.filter_placeholder')) ?>" autocomplete="off"></span>
        <p class="sidebar__filter-empty" role="status" data-navfilter-empty hidden><?= $e($t('nav.filter_empty')) ?></p>
      </div>
    <?php endif; ?>
    <?php foreach ($groups as $gi => $g): ?>
      <?php
      $gkey = (string) ($g['key'] ?? ('g' . $gi));
      $hrefs = array_column($g['items'], 'href');
      $gcurrent = in_array($best, $hrefs, true) && $best !== '/';
      $gopen = !$long || $gcurrent || $gkey === 'work';
      $gcount = array_sum(array_map(static fn (array $i): int => (int) ($i['count'] ?? 0), $g['items']));
      $gid = 'ng-' . preg_replace('/[^a-z0-9_-]/', '', $gkey);
      ?>
      <div class="sidebar__group<?= $gopen ? ' is-open' : '' ?>" data-navgroup="<?= $e($gkey) ?>"<?= $gcurrent ? ' data-navgroup-current' : '' ?><?= $long ? '' : ' data-navgroup-short' ?>>
        <button type="button" class="sidebar__group-toggle" aria-expanded="<?= $gopen ? 'true' : 'false' ?>" aria-controls="<?= $e($gid) ?>" title="<?= $e($t('nav.group_toggle', ['name' => (string) $g['label']])) ?>">
          <span class="sidebar__group-label"><?= $e($g['label']) ?></span>
          <?php if ($gcount > 0): ?><span class="sidebar__group-count"><?= (int) $gcount ?><span class="sr-only"> รายการรอ</span></span><?php endif; ?>
          <?= $ui('icon', ['name' => 'chevron-down', 'size' => 'sm']) ?>
        </button>
        <div class="sidebar__group-items" id="<?= $e($gid) ?>"<?= $gopen ? '' : ' hidden' ?>>
        <?php
        // an item followed by "sub" items gets a toggle; sub-menus start folded unless the page is one of them (0.6.2)
        $items = array_values($g['items']);
        for ($k = 0, $n = count($items); $k < $n; $k++) {
            $i = $items[$k];
            $subs = [];
            while (isset($items[$k + 1]) && !empty($items[$k + 1]['sub'])) {
                $subs[] = $items[++$k];
            }
            if ($subs === []) {
                echo $link($i);
                continue;
            }
            $open = in_array($best, array_column($subs, 'href'), true);
            $sid = 'sub-' . substr(md5((string) $i['href']), 0, 8);
            echo '<div class="sidebar__item' . ($open ? ' is-open' : '') . '" data-subnav="' . $e($i['href']) . '"' . ($open ? ' data-subnav-current' : '') . '>'
                . '<div class="sidebar__row">' . $link($i)
                . '<button type="button" class="sidebar__toggle" aria-expanded="' . ($open ? 'true' : 'false') . '" aria-controls="' . $sid . '" aria-label="' . $e($t('nav.submenu', ['name' => (string) $i['label']])) . '">'
                . $ui('icon', ['name' => 'chevron-down', 'size' => 'sm']) . '</button></div>'
                . '<div class="sidebar__sub" id="' . $sid . '"' . ($open ? '' : ' hidden') . '>';
            foreach ($subs as $sub) {
                echo $link($sub);
            }
            echo '</div></div>';
        }
        ?>
        </div>
      </div>
    <?php endforeach; ?>
    <div class="sidebar__footer">
      <button type="button" class="sidebar__link sidebar__collapse" data-action="collapse-sidebar" aria-expanded="true" aria-label="<?= $e($t('nav.collapse')) ?>">
        <?= $ui('icon', ['name' => 'layout-sidebar-left-collapse']) ?><span class="sidebar__label"><?= $e($t('nav.collapse')) ?></span>
      </button>
    </div>
  </nav>
  <div class="main">
    <header class="topbar">
      <?= $ui('button', ['label' => $t('nav.open_menu'), 'icon' => 'menu-2', 'iconOnly' => true, 'variant' => 'ghost', 'class' => 'topbar__menu', 'attrs' => ['data-action' => 'open-drawer', 'aria-expanded' => 'false']]) ?>
      <div class="topbar__context">
        <?php $companies = $shell['companies'] ?? []; ?>
        <?php if (count($companies) > 1): ?>
          <form method="post" action="<?= $e($url('/context')) ?>" class="context-form">
            <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
            <input type="hidden" name="_back" value="<?= $e($path) ?>">
            <label class="sr-only" for="ctx-le"><?= $e($t('context.label')) ?></label>
            <span class="context-switch">
              <?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?>
              <select id="ctx-le" name="legal_entity" class="context-switch__select" data-autosubmit>
                <?php foreach ($companies as $id => $label): ?>
                  <option value="<?= (int) $id ?>"<?= (int) $id === (int) ($shell['company'] ?? 0) ? ' selected' : '' ?>><?= $e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </span>
            <noscript><button class="btn btn--sm btn--secondary"><?= $e($t('context.switch')) ?></button></noscript>
          </form>
        <?php elseif (count($companies) === 1): ?>
          <span class="context-switch" title="<?= $e($t('context.label')) ?>"><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?><span class="context-switch__entity"><?= $e(reset($companies)) ?></span></span>
        <?php endif; ?>
      </div>
      <?php if ($shell !== null): ?>
      <form class="topbar__search" method="get" action="<?= $e($url('/search')) ?>" role="search">
        <label class="sr-only" for="global-search"><?= $e($t('nav.search')) ?></label>
        <input class="input" id="global-search" name="q" type="search" minlength="2" maxlength="100" placeholder="<?= $e($t('nav.search_placeholder')) ?>" value="<?= $e($path === '/search' ? ($v['search_q'] ?? '') : '') ?>" data-hotkey="k">
      </form>
      <?php endif; ?>
      <div class="topbar__actions">
        <a class="btn btn--ghost btn--sm" href="?<?= $e(http_build_query(['lang' => ($v['locale'] ?? 'th') === 'th' ? 'en' : 'th'])) ?>" hreflang="<?= ($v['locale'] ?? 'th') === 'th' ? 'en' : 'th' ?>"><?= $ui('icon', ['name' => 'language', 'size' => 'sm']) ?><span class="topbar__lang-label"><?= ($v['locale'] ?? 'th') === 'th' ? 'EN' : 'ไทย' ?></span></a>
        <?php if ($shell !== null): $unread = (int) ($shell['unread'] ?? 0); ?>
        <a class="btn btn--ghost btn--icon bell" href="<?= $e($url('/notifications')) ?>" aria-label="<?= $e($unread > 0 ? $t('notify.bell_n', ['n' => $unread]) : $t('notify.title')) ?>"><?= $ui('icon', ['name' => 'bell']) ?><?php if ($unread > 0): ?><span class="bell__count" aria-hidden="true"><?= $unread > 99 ? '99+' : $unread ?></span><?php endif; ?></a>
        <?php endif; ?>
        <?= $ui('button', ['label' => $t('pref.theme_toggle'), 'icon' => 'moon', 'iconOnly' => true, 'variant' => 'ghost', 'attrs' => ['data-action' => 'toggle-theme']]) ?>
        <?php if ($shell !== null): ?>
          <div class="menu" data-menu>
            <button type="button" class="btn btn--ghost user-button" aria-expanded="false">
              <span class="avatar" aria-hidden="true"><?= $e($shell['user']['initials']) ?></span>
              <span class="user-button__name"><?= $e($shell['user']['name']) ?></span><?= $ui('icon', ['name' => 'chevron-down', 'size' => 'sm']) ?>
            </button>
            <ul class="menu__list" hidden>
              <li><a class="menu__item" href="<?= $e($url('/me')) ?>"><?= $ui('icon', ['name' => 'user', 'size' => 'sm']) ?><?= $e($t('nav.my_account')) ?></a></li>
              <li><a class="menu__item" href="<?= $e($url('/me/appearance')) ?>"><?= $ui('icon', ['name' => 'palette', 'size' => 'sm']) ?><?= $e($t('look.title')) ?></a></li>
              <li><form method="post" action="<?= $e($url('/logout')) ?>"><input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
                <button class="menu__item" type="submit"><?= $ui('icon', ['name' => 'logout', 'size' => 'sm']) ?><?= $e($t('nav.logout')) ?></button></form></li>
            </ul>
          </div>
        <?php elseif ($path !== '/' && (!isset($v['ctx']) || !is_object($v['ctx']) || $v['ctx']->isGuest())): ?>
          <a class="btn btn--primary btn--sm topbar__signin" href="<?= $e($url('/login' . ($path !== '/' && $path !== '/login' && ($v['method'] ?? 'GET') === 'GET' ? '?next=' . rawurlencode($path) : ''))) ?>"><?= $ui('icon', ['name' => 'user', 'size' => 'sm']) ?><span><?= $e($t('home.guest.signin')) ?></span></a>
        <?php endif; ?>
      </div>
    </header>
    <main id="main" class="content" tabindex="-1">
      <?php if (is_array($flash)): ?>
        <?= $ui('alert', ['tone' => $flash['tone'] ?? 'info', 'text' => $flash['text'] ?? '', 'live' => true]) ?>
      <?php endif; ?>
      <?= $content ?>
    </main>
  </div>
</div>
</body>
</html>
