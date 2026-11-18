<?php
/**
 * My cart (a draft request): quantities (0 removes), site, note — save or send. Shows what blocks sending: open
 * days/hours, the request limit, stock and my department's quota.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var ?array<string, mixed> $cart @var list<array<string, mixed>> $sites
 * @var ?array{key: string, params: array<string, string>} $closed @var ?array{used: int, max: int, per: string} $limit @var array<string, mixed> $policy
 * @var array<int, array<string, mixed>> $quota @var array<string, string> $errors @var array<string, mixed> $old @var string $here @var bool $canIssue @var bool $canManage
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$rule = (string) ($policy['over_quota'] ?? '');
$cartSite = '';
foreach ($sites as $s) {
    if ($cart !== null && (int) $s['id'] === (int) $cart['site_id']) {
        $cartSite = (string) $s['public_id'];
    }
}
?>
<?= $ui('page-header', ['title' => $t('requisition.cart_title'), 'subtitle' => $t('requisition.cart_subtitle'), 'crumbs' => [[$t('requisition.title'), $url('/requisition')], [$t('requisition.tab.cart'), null]]]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php include __DIR__ . '/_companies.php'; ?>
<?php if ($cart === null || $cart['lines'] === []): ?>
  <?= $ui('states', ['icon' => 'shopping-cart', 'title' => $t('requisition.cart_empty'), 'text' => $t('requisition.cart_empty_help'), 'action' => $ui('button', ['label' => $t('requisition.tab.catalog'), 'href' => $url('/requisition?le=' . rawurlencode($lePid)), 'variant' => 'primary'])]) ?>
<?php else: ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<?php if ($closed !== null): ?><?= $ui('alert', ['tone' => 'warning', 'title' => $t('requisition.closed_title'), 'text' => $t($closed['key'], $closed['params'])]) ?><?php endif; ?>
<?php if ($limit !== null): ?><?= $ui('alert', ['tone' => 'warning', 'title' => $t('requisition.limit_title'), 'text' => $t('requisition.error.limit', ['max' => (string) $limit['max'], 'per' => $t('requisition.per.' . $limit['per'])])]) ?><?php endif; ?>
<form method="post" action="<?= $e($url('/requisition/cart/save')) ?>" class="stack" novalidate><?= $csrf ?>
  <input type="hidden" name="le" value="<?= $e($lePid) ?>">
  <section class="card card--flush" aria-labelledby="rc-h">
    <div class="card__head"><h2 id="rc-h" class="card__title"><?= $e($t('requisition.cart_items', ['n' => (string) count($cart['lines'])])) ?></h2></div>
    <div class="table-wrap" role="region" aria-labelledby="rc-h" tabindex="0">
      <table class="table table--stack">
        <thead><tr><th scope="col"><?= $e($t('requisition.field.name')) ?></th><th scope="col"><?= $e($t('requisition.col.available')) ?></th><th scope="col"><?= $e($t('requisition.col.quota_left')) ?></th><th scope="col"><?= $e($t('requisition.col.qty')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($cart['lines'] as $l): $ipid = (string) $l['item_pid']; $q = $quota[(int) $l['item_id']] ?? null; $err = $errors['qty_' . $ipid] ?? null;
            $val = is_array($old['qty'] ?? null) && isset($old['qty'][$ipid]) ? (string) $old['qty'][$ipid] : (string) $l['qty']; $over = $q !== null && (int) $l['qty'] > $q['left']; ?>
          <tr><th scope="row" data-label="<?= $e($t('requisition.field.name')) ?>"><?= $e((string) $l['name']) ?> <span class="text-sm text-muted"><?= $e((string) $l['code']) ?></span>
              <?= (int) $l['active'] !== 1 ? $ui('badge', ['tone' => 'danger', 'label' => $t('requisition.inactive')]) : '' ?></th>
            <td data-label="<?= $e($t('requisition.col.available')) ?>"><?= $e($l['available'] . ' ' . $l['unit']) ?><?= (int) $l['qty'] > $l['available'] ? ' ' . $ui('badge', ['tone' => 'danger', 'label' => $t('requisition.not_enough')]) : '' ?></td>
            <td data-label="<?= $e($t('requisition.col.quota_left')) ?>"><?= $q === null ? $e($t('requisition.no_quota')) : $e($q['left'] . ' / ' . $q['quota'] . ' ' . $l['unit'] . ' ' . $t('requisition.per.' . $q['period'])) ?><?= $over ? ' ' . $ui('badge', ['tone' => 'warning', 'label' => $t('requisition.over_quota_badge')]) : '' ?></td>
            <td data-label="<?= $e($t('requisition.col.qty')) ?>"><label class="sr-only" for="cq-<?= $e($ipid) ?>"><?= $e($t('requisition.qty_for', ['name' => (string) $l['name']])) ?></label>
              <input class="input rq-qty" type="number" min="0" max="9999" inputmode="numeric" id="cq-<?= $e($ipid) ?>" name="qty[<?= $e($ipid) ?>]" value="<?= $e($val) ?>"<?= $err !== null ? ' aria-invalid="true" aria-describedby="cq-' . $e($ipid) . '-err"' : '' ?>> <?= $e((string) $l['unit']) ?>
              <?php if ($err !== null): ?><div class="field__error" id="cq-<?= $e($ipid) ?>-err"><?= $e($err) ?></div><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="card__pad text-sm text-muted"><?= $e($t('requisition.zero_removes')) ?></p>
  </section>
  <div class="card stack-sm">
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'site', 'type' => 'select', 'label' => $t('requisition.field.pickup_site'), 'value' => (string) ($old['site'] ?? $cartSite), 'options' => array_column($sites, 'code', 'public_id'), 'help' => $t('requisition.site_help'), 'error' => $errors['site'] ?? null]) ?>
      <?= $ui('field', ['name' => 'note', 'label' => $t('requisition.field.note'), 'value' => (string) ($old['note'] ?? $cart['note'] ?? ''), 'error' => $errors['note'] ?? null]) ?>
    </div>
    <p class="text-sm text-muted"><?= $e($t('requisition.rule_note.' . ($rule === '' ? 'none' : $rule))) ?></p>
    <div class="row">
      <?= $ui('button', ['label' => $t('requisition.save_cart'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'pencil']) ?>
      <?= $ui('button', ['label' => $t('requisition.send'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send', 'attrs' => ['formaction' => $url('/requisition/cart/submit')], 'disabled' => $closed !== null || $limit !== null]) ?>
    </div>
  </div>
</form>
<?php endif; ?>
