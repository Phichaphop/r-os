<?php
/**
 * Home = app hub (6.4, REQ-IAM-003): every app; the ones the user may open are links, the others are greyed out
 * with "no access" and a Request access button (→ /me/access?app=…, central approval inbox).
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $asset @var array<string, mixed> $v
 * @var list<array{key: string, title: string, items: list<array<string, mixed>>, empty?: string, more_href?: string}> $sections module groups (manifest "home")
 * @var array{groups: list<array{label: string, items: list<array<string, mixed>>}>, locked: list<array<string, mixed>>, disabled: int, total: int} $hub
 */
$lockedState = [
    'locked' => ['neutral', 'lock', 'hub.state.locked'],
    'pending' => ['warning', 'hourglass', 'hub.state.pending'],
    'returned' => ['warning', 'arrow-back-up', 'hub.state.returned'],
];
?>
<section class="page-header page-hero page-hero--home card card--navy navy-dots on-navy sparkle-hero hub-hero" aria-labelledby="hub-h">
  <div class="page-header__text hub-hero__text">
    <h1 id="hub-h"><?= $e($t('home.title')) ?></h1>
    <p class="page-hero__lead"><?= $e(isset($v['shell']['user']) ? $t('home.hello', ['name' => (string) $v['shell']['user']['name']]) : $t('home.subtitle')) ?></p>
    <p class="page-hero__lead"><?= $e($t('hub.count', ['n' => (string) $hub['total']])) ?><?= $hub['locked'] !== [] ? ' · ' . $e($t('hub.count_locked', ['n' => (string) count($hub['locked'])])) : '' ?></p>
  </div>
  <div class="page-hero__aside">
    <div class="hub-search" data-hub-search hidden>
      <label for="hub-q" class="sr-only"><?= $e($t('hub.search')) ?></label>
      <span class="hub-search__icon"><?= $ui('icon', ['name' => 'search', 'size' => 'sm']) ?></span>
      <input id="hub-q" class="input hub-search__input" type="search" autocomplete="off" placeholder="<?= $e($t('hub.search_placeholder')) ?>" aria-describedby="hub-found">
    </div>
  </div>
</section>
<p id="hub-found" class="sr-only" aria-live="polite" data-hub-found data-template="<?= $e($t('hub.found', ['n' => '{n}'])) ?>"></p>

<div class="hub" data-hub>
  <?php foreach ($hub['groups'] as $gi => $g): ?>
  <section class="hub-group" aria-labelledby="hub-g<?= $gi ?>" data-hub-group>
    <h2 id="hub-g<?= $gi ?>" class="hub-group__title"><?= $e($g['label']) ?></h2>
    <ul class="app-grid" role="list">
      <?php foreach ($g['items'] as $i): ?>
      <li data-hub-item data-hub-text="<?= $e(mb_strtolower($i['label'] . ' ' . $i['summary'] . ' ' . $i['group'])) ?>">
        <a class="app-tile" href="<?= $e($url($i['href'])) ?>">
          <span class="app-tile__icon"><?= $ui('icon', ['name' => $i['icon']]) ?></span>
          <span class="app-tile__name" title="<?= $e($i['label']) ?>"><?= $e($i['label']) ?></span>
          <span class="app-tile__desc"<?= $i['summary'] !== '' ? ' title="' . $e($i['summary']) . '"' : '' ?>><?= $e($i['summary']) ?></span>
          <?php if ($i['count'] > 0): ?><span class="app-tile__count"><?= $e($t('hub.waiting', ['n' => (string) $i['count']])) ?></span><?php endif; ?>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endforeach; ?>

  <?php foreach ($sections ?? [] as $sec): $sk = preg_replace('/[^a-z0-9_-]/', '', (string) $sec['key']); ?>
  <section class="hub-group hub-group--<?= $e($sk) ?>" aria-labelledby="hub-s-<?= $e($sk) ?>" data-hub-group>
    <div class="hub-group__head">
      <h2 id="hub-s-<?= $e($sk) ?>" class="hub-group__title"><?= $e($sec['title']) ?></h2>
      <?php if (isset($sec['more_href'])): ?><a class="hub-group__more" href="<?= $e($url($sec['more_href'])) ?>"><?= $e($t('home.see_all')) ?> <?= $ui('icon', ['name' => 'arrow-right', 'size' => 'sm']) ?></a><?php endif; ?>
    </div>
    <?php if ($sec['items'] === [] && isset($sec['empty'])): ?><p class="text-sm text-muted"><?= $e($sec['empty']) ?></p><?php endif; ?>
    <ul class="app-grid" role="list">
      <?php foreach ($sec['items'] as $i): $newTab = !empty($i['new_tab']); ?>
      <li data-hub-item data-hub-text="<?= $e(mb_strtolower($i['label'] . ' ' . $i['summary'] . ' ' . $sec['title'])) ?>">
        <a class="app-tile" href="<?= $e($url((string) $i['href'])) ?>"<?= $newTab ? ' target="_blank" rel="noopener noreferrer"' : '' ?> referrerpolicy="no-referrer">
          <span class="app-tile__icon"><?= $ui('icon', ['name' => (string) $i['icon']]) ?></span>
          <span class="app-tile__name" title="<?= $e($i['label']) ?>"><?= $e($i['label']) ?><?php if ($newTab): ?><span class="sr-only"> (<?= $e($t('home.new_tab')) ?>)</span><?php endif; ?></span>
          <span class="app-tile__desc" title="<?= $e($i['summary']) ?>"><?= $e($i['summary']) ?></span>
          <?php if ($newTab): ?><span class="app-tile__corner" aria-hidden="true"><?= $ui('icon', ['name' => 'external-link', 'size' => 'sm']) ?></span><?php endif; ?>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endforeach; ?>

  <?php if ($hub['locked'] !== []): ?>
  <section class="hub-group hub-group--locked" aria-labelledby="hub-locked" data-hub-group>
    <h2 id="hub-locked" class="hub-group__title"><?= $e($t('hub.locked_title')) ?></h2>
    <p class="text-sm text-muted"><?= $e($t('hub.locked_text')) ?></p>
    <ul class="app-grid" role="list">
      <?php foreach ($hub['locked'] as $l): [$tone, $icon, $stateKey] = $lockedState[$l['state']] ?? $lockedState['locked']; $tid = 'hub-l-' . preg_replace('/[^a-z0-9]/', '-', (string) $l['key']); ?>
      <li data-hub-item data-hub-text="<?= $e(mb_strtolower($l['label'] . ' ' . $l['summary'] . ' ' . $l['group'])) ?>">
        <div class="app-tile app-tile--locked" role="group" aria-labelledby="<?= $e($tid) ?>">
          <span class="app-tile__icon"><?= $ui('icon', ['name' => $l['icon']]) ?><span class="app-tile__lock" aria-hidden="true"><?= $ui('icon', ['name' => 'lock', 'size' => 'sm']) ?></span></span>
          <span class="app-tile__name" id="<?= $e($tid) ?>" title="<?= $e($l['label']) ?>"><?= $e($l['label']) ?></span>
          <span class="app-tile__desc"<?= $l['summary'] !== '' ? ' title="' . $e($l['summary']) . '"' : '' ?>><?= $e($l['summary']) ?></span>
          <?php if ($l['state'] === 'locked'): ?>
            <span class="app-tile__state app-tile__state--locked"><?= $ui('badge', ['tone' => $tone, 'icon' => $icon, 'label' => $t($stateKey)]) ?></span>
            <a class="app-tile__request" href="<?= $e($url('/me/access?app=' . rawurlencode((string) $l['key']))) ?>" aria-describedby="<?= $e($tid) ?>">
              <?= $ui('icon', ['name' => 'send', 'size' => 'sm']) ?><span><?= $e($t('hub.request')) ?></span></a>
          <?php else: ?>
            <a class="app-tile__state" href="<?= $e($url('/requests')) ?>" aria-describedby="<?= $e($tid) ?>" title="<?= $e($t('hub.see_request')) ?>"><?= $ui('badge', ['tone' => $tone, 'icon' => $icon, 'label' => $t($stateKey)]) ?><span class="sr-only"> — <?= $e($t('hub.see_request')) ?></span></a>
          <?php endif; ?>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <?php if ($hub['total'] === 0 && $hub['locked'] === []): ?>
    <?= $ui('alert', ['tone' => 'info', 'title' => $t('hub.empty_title'), 'text' => $t('hub.empty_text')]) ?>
  <?php endif; ?>
  <p class="hub-none text-muted" data-hub-none hidden><?= $e($t('hub.none')) ?></p>
</div>


<?php if ($hub['disabled'] > 0): ?>
<p class="text-sm text-muted"><?= $ui('icon', ['name' => 'info-circle', 'size' => 'sm']) ?> <?= $e($t('hub.disabled', ['n' => (string) $hub['disabled']])) ?>
  <a href="<?= $e($url('/admin/modules')) ?>"><?= $e($t('nav.modules')) ?></a></p>
<?php endif; ?>

<?php $popup = null; foreach ($sections ?? [] as $sec) { if (is_array($sec['popup'] ?? null)) { $popup = $sec['popup']; break; } } ?>
<?php if ($popup !== null): ?>
<dialog class="home-popup" data-home-popup data-seconds="<?= (int) ($popup['seconds'] ?? 0) ?>" aria-labelledby="home-popup-h">
  <div class="home-popup__body stack-sm">
    <?php if (($popup['kicker'] ?? '') !== ''): ?><span class="badge badge--info"><?= $e((string) $popup['kicker']) ?></span><?php endif; ?>
    <h2 id="home-popup-h" class="home-popup__title"><?= $e((string) $popup['title']) ?></h2>
    <p class="home-popup__text"><?= nl2br($e((string) $popup['text'])) ?></p>
    <div class="row">
      <a class="btn btn--primary" href="<?= $e($url((string) $popup['href'])) ?>"><?= $e((string) ($popup['label'] ?? '')) ?></a>
      <button type="button" class="btn btn--ghost" data-home-popup-close><?= $e((string) ($popup['close'] ?? '')) ?> <span data-home-popup-count></span></button>
    </div>
  </div>
</dialog>
<?php endif; ?>
<script src="<?= $e($asset('ui/js/home.js')) ?>" defer></script>
