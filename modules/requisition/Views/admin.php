<?php
/**
 * Supply administration: categories, items (picture, reorder level), department quotas and the company rules
 * (over-quota rule, request limit, open days/hours, pick-up expiry). No defaults — every rule is the company's.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $categories @var list<array<string, mixed>> $items
 * @var ?array<string, mixed> $edit @var array<string, mixed> $policy @var list<array<string, mixed>> $quotas @var array<string, string> $depts @var string $today
 * @var array<string, string> $errors @var array<string, mixed> $old @var string $form @var string $here @var bool $canIssue @var bool $canManage
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$le = '<input type="hidden" name="le" value="' . $e($lePid) . '">';
$fo = static fn (string $f, string $k, string $d = ''): string => $form === $f && is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$fe = static fn (string $f, string $k): ?string => $form === $f ? ($errors[$k] ?? null) : null;
$catOpts = ['' => $t('requisition.pick')] + array_column(array_filter($categories, static fn (array $c): bool => (int) $c['active'] === 1), 'name', 'public_id');
$itemOpts = ['' => $t('requisition.pick')];
foreach ($items as $i) {
    $itemOpts[(string) $i['public_id']] = $i['code'] . ' · ' . $i['name'];
}
$iv = $edit !== null && $form !== 'item' ? ['code' => $edit['code'], 'name' => $edit['name'], 'unit' => $edit['unit'], 'category' => $edit['category_id'], 'description' => (string) ($edit['description'] ?? ''), 'reorder_level' => (string) ($edit['reorder_level'] ?? ''), 'active' => (int) $edit['active'] === 1 ? '1' : ''] : $old;
if ($edit !== null && $form !== 'item') {
    foreach ($categories as $c) {
        if ((int) $c['id'] === (int) $edit['category_id']) {
            $iv['category'] = (string) $c['public_id'];
        }
    }
}
$ivs = static fn (string $k): string => is_scalar($iv[$k] ?? null) ? (string) $iv[$k] : '';
$ie = static fn (string $k): ?string => $form === 'item' ? ($errors[$k] ?? null) : null;
$days = $form === 'policy' ? array_map('strval', (array) ($old['open_days'] ?? [])) : explode(',', (string) ($policy['open_days'] ?? ''));
?>
<?= $ui('page-header', ['title' => $t('requisition.admin_title'), 'subtitle' => $t('requisition.admin_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php include __DIR__ . '/_companies.php'; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<section class="card stack-sm" id="ra-policy" aria-labelledby="ra-p-h">
  <h2 id="ra-p-h" class="card__title"><?= $e($t('requisition.policy')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('requisition.no_defaults')) ?></p>
  <form method="post" action="<?= $e($url('/requisition/admin/policy')) ?>" class="stack-sm" novalidate><?= $csrf . $le ?>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'over_quota', 'type' => 'select', 'label' => $t('requisition.field.over_quota'), 'value' => $fo('policy', 'over_quota', (string) ($policy['over_quota'] ?? '')), 'options' => ['' => $t('requisition.rule.none'), 'warn' => $t('requisition.rule.warn'), 'block' => $t('requisition.rule.block'), 'special' => $t('requisition.rule.special')], 'error' => $fe('policy', 'over_quota')]) ?>
      <?= $ui('field', ['name' => 'max_requests', 'type' => 'number', 'label' => $t('requisition.field.max_requests'), 'value' => $fo('policy', 'max_requests', (string) ($policy['max_requests'] ?? '')), 'help' => $t('requisition.blank_none'), 'error' => $fe('policy', 'max_requests')]) ?>
      <?= $ui('field', ['name' => 'max_per', 'type' => 'select', 'label' => $t('requisition.field.max_per'), 'value' => $fo('policy', 'max_per', (string) ($policy['max_per'] ?? '')), 'options' => ['' => '—', 'week' => $t('requisition.per.week'), 'month' => $t('requisition.per.month')], 'error' => $fe('policy', 'max_per')]) ?>
    </div>
    <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('requisition.field.open_days')) ?></legend>
      <div class="row"><?php for ($d = 1; $d <= 7; $d++): ?><label class="check"><input type="checkbox" name="open_days[]" value="<?= $d ?>"<?= in_array((string) $d, $days, true) ? ' checked' : '' ?>> <?= $e($t('requisition.dow.' . $d)) ?></label><?php endfor; ?></div>
      <p class="field__help"><?= $e($t('requisition.open_days_help')) ?></p>
    </fieldset>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'open_from', 'type' => 'time', 'label' => $t('requisition.field.open_from'), 'value' => $fo('policy', 'open_from', substr((string) ($policy['open_from'] ?? ''), 0, 5)), 'error' => $fe('policy', 'open_from')]) ?>
      <?= $ui('field', ['name' => 'open_to', 'type' => 'time', 'label' => $t('requisition.field.open_to'), 'value' => $fo('policy', 'open_to', substr((string) ($policy['open_to'] ?? ''), 0, 5))]) ?>
      <?= $ui('field', ['name' => 'expire_days', 'type' => 'number', 'label' => $t('requisition.field.expire_days'), 'value' => $fo('policy', 'expire_days', (string) ($policy['expire_days'] ?? '')), 'help' => $t('requisition.expire_help'), 'error' => $fe('policy', 'expire_days')]) ?>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('requisition.save'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
  </form>
</section>
<section class="card stack-sm" id="ra-category" aria-labelledby="ra-c-h">
  <h2 id="ra-c-h" class="card__title"><?= $e($t('requisition.categories')) ?></h2>
  <?php if ($categories !== []): ?>
  <ul class="plain-list stack-sm">
    <?php foreach ($categories as $c): $cpid = (string) $c['public_id']; $mine = $form === 'cat-' . $cpid; ?>
    <li><form method="post" action="<?= $e($url('/requisition/c/' . $cpid)) ?>" class="row" novalidate><?= $csrf ?>
      <?= $ui('field', ['name' => 'name', 'id' => 'cn-' . $cpid, 'label' => $t('requisition.field.category_name'), 'value' => $mine ? (string) ($old['name'] ?? '') : (string) $c['name'], 'error' => $mine ? ($errors['cat_name'] ?? null) : null]) ?>
      <label class="check"><input type="checkbox" name="active" value="1"<?= (int) $c['active'] === 1 ? ' checked' : '' ?>> <?= $e($t('requisition.active')) ?></label>
      <span class="text-sm text-muted"><?= $e($t('requisition.n_items', ['n' => (string) $c['items']])) ?></span>
      <?= $ui('button', ['label' => $t('requisition.save'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm']) ?></form></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <form method="post" action="<?= $e($url('/requisition/admin/category')) ?>" class="row" novalidate><?= $csrf . $le ?>
    <?= $ui('field', ['name' => 'name', 'id' => 'cn-new', 'label' => $t('requisition.add_category'), 'value' => $fo('category', 'name'), 'error' => $fe('category', 'cat_name')]) ?>
    <?= $ui('button', ['label' => $t('requisition.add'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'plus']) ?>
  </form>
</section>
<section class="card card--flush" id="ra-items" aria-labelledby="ra-i-h">
  <div class="card__head"><h2 id="ra-i-h" class="card__title"><?= $e($t('requisition.items')) ?></h2></div>
  <?php if ($items === []): ?><div class="card__pad"><p class="text-muted"><?= $e($t('requisition.no_items')) ?></p></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="ra-i-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('requisition.field.code')) ?></th><th scope="col"><?= $e($t('requisition.field.name')) ?></th><th scope="col"><?= $e($t('requisition.field.category')) ?></th><th scope="col"><?= $e($t('requisition.field.unit')) ?></th><th scope="col"><?= $e($t('requisition.field.reorder_level')) ?></th><th scope="col"><?= $e($t('requisition.col.edit')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($items as $i): ?>
        <tr><th scope="row" data-label="<?= $e($t('requisition.field.code')) ?>"><?= $e((string) $i['code']) ?></th>
          <td data-label="<?= $e($t('requisition.field.name')) ?>"><?= $e((string) $i['name']) ?><?= (int) $i['active'] === 1 ? '' : ' ' . $ui('badge', ['tone' => 'neutral', 'label' => $t('requisition.inactive')]) ?><?= $i['photo_pid'] !== null ? ' ' . $ui('badge', ['tone' => 'info', 'label' => $t('requisition.has_photo')]) : '' ?></td>
          <td data-label="<?= $e($t('requisition.field.category')) ?>"><?= $e((string) $i['category_name']) ?></td>
          <td data-label="<?= $e($t('requisition.field.unit')) ?>"><?= $e((string) $i['unit']) ?></td>
          <td data-label="<?= $e($t('requisition.field.reorder_level')) ?>"><?= $i['reorder_level'] === null ? '—' : (int) $i['reorder_level'] ?></td>
          <td data-label="<?= $e($t('requisition.col.edit')) ?>"><a href="<?= $e($url('/requisition/admin?le=' . rawurlencode($lePid) . '&item=' . $i['public_id'] . '#ra-item-form')) ?>"><?= $e($t('requisition.edit_item_short', ['code' => (string) $i['code']])) ?></a></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<section class="card stack-sm" id="ra-item-form" aria-labelledby="ra-if-h">
  <h2 id="ra-if-h" class="card__title"><?= $e($edit !== null ? $t('requisition.edit_item', ['code' => (string) $edit['code']]) : $t('requisition.add_item')) ?></h2>
  <?php if ($categories === []): ?><p class="text-muted"><?= $e($t('requisition.add_category_first')) ?></p><?php else: ?>
  <form method="post" action="<?= $e($url($edit !== null ? '/requisition/i/' . $edit['public_id'] : '/requisition/admin/item')) ?>" enctype="multipart/form-data" class="stack-sm" novalidate><?= $csrf . $le ?>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'code', 'label' => $t('requisition.field.code'), 'required' => true, 'value' => $ivs('code'), 'help' => $t('requisition.code_help'), 'error' => $ie('code')]) ?>
      <?= $ui('field', ['name' => 'name', 'id' => 'f-iname', 'label' => $t('requisition.field.name'), 'required' => true, 'value' => $ivs('name'), 'error' => $ie('name')]) ?>
      <?= $ui('field', ['name' => 'unit', 'label' => $t('requisition.field.unit'), 'required' => true, 'value' => $ivs('unit'), 'help' => $t('requisition.unit_help'), 'error' => $ie('unit')]) ?>
      <?= $ui('field', ['name' => 'category', 'type' => 'select', 'label' => $t('requisition.field.category'), 'required' => true, 'value' => $ivs('category'), 'options' => $catOpts, 'error' => $ie('category')]) ?>
      <?= $ui('field', ['name' => 'reorder_level', 'type' => 'number', 'label' => $t('requisition.field.reorder_level'), 'value' => $ivs('reorder_level'), 'help' => $t('requisition.reorder_help'), 'error' => $ie('reorder_level')]) ?>
      <?= $ui('field', ['name' => 'photo', 'type' => 'file', 'label' => $t('requisition.field.photo'), 'accept' => '.png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp', 'help' => $t('requisition.photo_help'), 'error' => $ie('photo')]) ?>
    </div>
    <?= $ui('field', ['name' => 'description', 'type' => 'textarea', 'label' => $t('requisition.field.description'), 'value' => $ivs('description'), 'error' => $ie('description')]) ?>
    <?php if ($edit !== null): ?><label class="check"><input type="checkbox" name="active" value="1"<?= $ivs('active') !== '' ? ' checked' : '' ?>> <?= $e($t('requisition.active')) ?></label><?php endif; ?>
    <div class="row"><?= $ui('button', ['label' => $t('requisition.save'), 'type' => 'submit', 'variant' => 'primary']) ?>
      <?php if ($edit !== null): ?><?= $ui('button', ['label' => $t('requisition.add_item'), 'href' => $url('/requisition/admin?le=' . rawurlencode($lePid) . '#ra-item-form'), 'variant' => 'ghost', 'icon' => 'plus']) ?><?php endif; ?></div>
  </form>
  <?php endif; ?>
</section>
<section class="card stack-sm" id="ra-quota" aria-labelledby="ra-q-h">
  <h2 id="ra-q-h" class="card__title"><?= $e($t('requisition.quotas')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('requisition.quota_help')) ?></p>
  <?php if ($quotas !== []): ?>
  <div class="table-wrap" role="region" aria-labelledby="ra-q-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('requisition.col.department')) ?></th><th scope="col"><?= $e($t('requisition.field.name')) ?></th><th scope="col"><?= $e($t('requisition.col.quota')) ?></th><th scope="col"><?= $e($t('requisition.col.valid')) ?></th><th scope="col"><?= $e($t('requisition.col.edit')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($quotas as $q): $ended = $q['valid_to'] !== null && (string) $q['valid_to'] < $today; ?>
        <tr><th scope="row" data-label="<?= $e($t('requisition.col.department')) ?>"><?= $e((string) $q['dept_code']) ?></th>
          <td data-label="<?= $e($t('requisition.field.name')) ?>"><?= $e($q['item_code'] . ' · ' . $q['item_name']) ?></td>
          <td data-label="<?= $e($t('requisition.col.quota')) ?>"><?= $e($q['qty'] . ' ' . $q['unit'] . ' ' . $t('requisition.per.' . $q['period'])) ?></td>
          <td data-label="<?= $e($t('requisition.col.valid')) ?>"><?= $e($date((string) $q['valid_from'], false) . ' – ' . ($q['valid_to'] !== null ? $date((string) $q['valid_to'], false) : $t('requisition.open_ended'))) ?></td>
          <td data-label="<?= $e($t('requisition.col.edit')) ?>"><?php if (!$ended): ?><form method="post" action="<?= $e($url('/requisition/q/' . $q['public_id'] . '/end')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('requisition.end_quota'), 'type' => 'submit', 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'x', 'attrs' => ['aria-label' => $t('requisition.end_quota_for', ['dept' => (string) $q['dept_code'], 'code' => (string) $q['item_code']])]]) ?></form><?php else: ?><?= $ui('badge', ['tone' => 'neutral', 'label' => $t('requisition.ended')]) ?><?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <?php if ($items !== [] && $depts !== []): ?>
  <form method="post" action="<?= $e($url('/requisition/admin/quota')) ?>" class="stack-sm" novalidate><?= $csrf . $le ?>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'department', 'type' => 'select', 'label' => $t('requisition.col.department'), 'required' => true, 'value' => $fo('quota', 'department'), 'options' => ['' => $t('requisition.pick')] + $depts, 'error' => $fe('quota', 'department')]) ?>
      <?= $ui('field', ['name' => 'item', 'id' => 'f-qitem', 'type' => 'select', 'label' => $t('requisition.field.name'), 'required' => true, 'value' => $fo('quota', 'item'), 'options' => $itemOpts, 'error' => $fe('quota', 'quota_item')]) ?>
      <?= $ui('field', ['name' => 'qty', 'id' => 'f-qqty', 'type' => 'number', 'label' => $t('requisition.col.quota'), 'required' => true, 'value' => $fo('quota', 'qty'), 'error' => $fe('quota', 'quota_qty')]) ?>
      <?= $ui('field', ['name' => 'period', 'type' => 'select', 'label' => $t('requisition.col.period'), 'required' => true, 'value' => $fo('quota', 'period'), 'options' => ['' => $t('requisition.pick'), 'week' => $t('requisition.per.week'), 'month' => $t('requisition.per.month')], 'error' => $fe('quota', 'period')]) ?>
      <?= $ui('field', ['name' => 'valid_from', 'type' => 'date', 'label' => $t('requisition.field.valid_from'), 'required' => true, 'value' => $fo('quota', 'valid_from', $today), 'error' => $fe('quota', 'valid_from')]) ?>
      <?= $ui('field', ['name' => 'valid_to', 'type' => 'date', 'label' => $t('requisition.field.valid_to'), 'value' => $fo('quota', 'valid_to'), 'help' => $t('requisition.blank_open')]) ?>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('requisition.add_quota'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'plus']) ?></div>
  </form>
  <?php endif; ?>
</section>
