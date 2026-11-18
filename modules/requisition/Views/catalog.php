<?php
/**
 * The catalogue of a site: search, category, favourites; each item shows what is available, my department's quota left
 * and a quantity to put in the cart. Sending is closed outside the company's open days/hours.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $sites @var int $siteId
 * @var list<array<string, mixed>> $categories @var list<array<string, mixed>> $items @var array<string, string> $f @var ?array<string, mixed> $cart
 * @var ?array{key: string, params: array<string, string>} $closed @var array<int, array<string, mixed>> $quota @var string $here @var bool $canIssue @var bool $canManage
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$sitePid = '';
foreach ($sites as $s) {
    if ((int) $s['id'] === $siteId) {
        $sitePid = (string) $s['public_id'];
    }
}
$inCart = $cart === null ? 0 : count($cart['lines']);
$cartSite = $cart === null ? null : (int) $cart['site_id'];
$back = '/requisition?' . http_build_query(array_filter(['le' => $lePid, 'site' => $sitePid, 'q' => $f['q'], 'category' => $f['category'], 'fav' => $f['fav']]));
?>
<?= $ui('page-header', ['title' => $t('requisition.title'), 'subtitle' => $t('requisition.subtitle'),
    'actions' => $ui('button', ['label' => $t('requisition.go_cart', ['n' => (string) $inCart]), 'href' => $url('/requisition/cart?le=' . rawurlencode($lePid)), 'variant' => 'primary', 'icon' => 'clipboard-check'])]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php include __DIR__ . '/_companies.php'; ?>
<?php if ($closed !== null): ?><?= $ui('alert', ['tone' => 'warning', 'title' => $t('requisition.closed_title'), 'text' => $t($closed['key'], $closed['params'])]) ?><?php endif; ?>
<?php if ($cartSite !== null && $cartSite !== $siteId): ?><?= $ui('alert', ['tone' => 'info', 'text' => $t('requisition.other_site_note')]) ?><?php endif; ?>
<form method="get" action="<?= $e($url('/requisition')) ?>" class="card row rq-filter" role="search" aria-label="<?= $e($t('requisition.search')) ?>">
  <input type="hidden" name="le" value="<?= $e($lePid) ?>">
  <?= $ui('field', ['name' => 'site', 'type' => 'select', 'label' => $t('requisition.field.site'), 'value' => $sitePid, 'options' => array_column($sites, 'code', 'public_id')]) ?>
  <?= $ui('field', ['name' => 'category', 'type' => 'select', 'label' => $t('requisition.field.category'), 'value' => $f['category'], 'options' => ['' => $t('requisition.all_categories')] + array_column($categories, 'name', 'public_id')]) ?>
  <?= $ui('field', ['name' => 'q', 'type' => 'search', 'label' => $t('requisition.search'), 'value' => $f['q'], 'placeholder' => $t('requisition.search_placeholder')]) ?>
  <label class="check"><input type="checkbox" name="fav" value="1"<?= $f['fav'] !== '' ? ' checked' : '' ?>> <?= $e($t('requisition.only_fav')) ?></label>
  <?= $ui('button', ['label' => $t('requisition.show'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'search']) ?>
</form>
<?php if ($items === []): ?>
  <?= $ui('states', ['icon' => 'shopping-cart', 'title' => $t('requisition.no_items'), 'text' => $t('requisition.no_items_help')]) ?>
<?php else: ?>
<ul class="rq-grid" aria-label="<?= $e($t('requisition.items')) ?>">
  <?php foreach ($items as $it): $ipid = (string) $it['public_id']; $q = $quota[(int) $it['id']] ?? null; $hid = 'rq-i-' . $ipid; ?>
  <li class="rq-card card" aria-labelledby="<?= $e($hid) ?>">
    <div class="rq-card__img">
      <?php if ($it['photo_pid'] !== null): ?><img src="<?= $e($url('/requisition/i/' . $ipid . '/photo')) ?>" alt="" loading="lazy" width="160" height="120"><?php else: ?><?= $ui('icon', ['name' => 'shopping-cart']) ?><?php endif; ?>
    </div>
    <h2 class="rq-card__name" id="<?= $e($hid) ?>"><?= $e((string) $it['name']) ?></h2>
    <p class="text-sm text-muted"><?= $e($it['code'] . ' · ' . $it['category_name']) ?></p>
    <p class="text-sm"><?= $e($t('requisition.available_n', ['n' => (string) $it['available'], 'unit' => (string) $it['unit']])) ?>
      <?= $it['available'] === 0 ? $ui('badge', ['tone' => 'danger', 'label' => $t('requisition.out_of_stock')]) : ($it['low'] ? $ui('badge', ['tone' => 'warning', 'label' => $t('requisition.low')]) : '') ?></p>
    <?php if ($q !== null): ?><p class="text-sm<?= $q['left'] === 0 ? ' text-danger' : '' ?>"><?= $e($t('requisition.quota_left', ['left' => (string) $q['left'], 'quota' => (string) $q['quota'], 'unit' => (string) $it['unit'], 'per' => $t('requisition.per.' . $q['period'])])) ?></p><?php endif; ?>
    <form method="post" action="<?= $e($url('/requisition/add')) ?>" class="row rq-card__add"><?= $csrf ?>
      <input type="hidden" name="le" value="<?= $e($lePid) ?>"><input type="hidden" name="site" value="<?= $e($sitePid) ?>"><input type="hidden" name="item" value="<?= $e($ipid) ?>">
      <label class="sr-only" for="q-<?= $e($ipid) ?>"><?= $e($t('requisition.qty_for', ['name' => (string) $it['name']])) ?></label>
      <input class="input rq-qty" type="number" id="q-<?= $e($ipid) ?>" name="qty" value="1" min="1" max="9999" inputmode="numeric"<?= $it['available'] === 0 ? ' disabled' : '' ?>>
      <?= $ui('button', ['label' => $t('requisition.add'), 'type' => 'submit', 'variant' => 'primary', 'size' => 'sm', 'icon' => 'plus', 'disabled' => $it['available'] === 0, 'attrs' => ['aria-describedby' => $hid]]) ?>
    </form>
    <form method="post" action="<?= $e($url('/requisition/fav/' . $ipid)) ?>"><?= $csrf ?><input type="hidden" name="back" value="<?= $e($back) ?>">
      <?= $ui('button', ['label' => $t((bool) $it['fav'] ? 'requisition.unfav' : 'requisition.fav'), 'type' => 'submit', 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'star', 'attrs' => ['aria-pressed' => (bool) $it['fav'] ? 'true' : 'false', 'aria-describedby' => $hid]]) ?>
    </form>
  </li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
