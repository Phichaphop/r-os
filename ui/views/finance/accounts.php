<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var list<array<string, mixed>> $accounts @var bool $canManage @var array<string, string> $errors @var array<string, mixed> $old @var int $le
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$types = ['asset', 'liability', 'equity', 'revenue', 'expense'];
$headers = array_values(array_filter($accounts, static fn ($a) => (int) $a['is_postable'] === 0));
?>
<?= $ui('page-header', ['title' => $t('finance.accounts.title'), 'subtitle' => $t('finance.accounts.subtitle')]) ?>
<?php include __DIR__ . '/_tabs.php'; ?>
<div class="table-wrap" role="region" aria-label="<?= $e($t('finance.accounts.title')) ?>" tabindex="0">
  <table class="table table--stack">
    <caption class="sr-only"><?= $e($t('finance.accounts.title')) ?></caption>
    <thead><tr><th scope="col"><?= $e($t('finance.field.account')) ?></th><th scope="col"><?= $e($t('finance.field.name')) ?></th><th scope="col"><?= $e($t('finance.field.type')) ?></th>
      <th scope="col"><?= $e($t('finance.field.postable')) ?></th><th scope="col"><?= $e($t('finance.field.status')) ?></th><?php if ($canManage): ?><th scope="col"><span class="sr-only"><?= $e($t('common.actions')) ?></span></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($accounts as $a): $postable = (int) $a['is_postable'] === 1; ?>
      <tr>
        <td data-label="<?= $e($t('finance.field.account')) ?>" class="mono"><?= $a['parent_code'] !== null ? '&nbsp;&nbsp;' : '' ?><?= $postable ? $e($a['code']) : '<strong>' . $e($a['code']) . '</strong>' ?></td>
        <td data-label="<?= $e($t('finance.field.name')) ?>"><?= $postable ? $e($a['name']) : '<strong>' . $e($a['name']) . '</strong>' ?></td>
        <td data-label="<?= $e($t('finance.field.type')) ?>"><?= $e($t('finance.type.' . $a['account_type'])) ?></td>
        <td data-label="<?= $e($t('finance.field.postable')) ?>"><?= $e($t($postable ? 'finance.postable.yes' : 'finance.postable.header')) ?></td>
        <td data-label="<?= $e($t('finance.field.status')) ?>"><?= $ui('badge', ['tone' => $a['status'] === 'active' ? 'success' : 'neutral', 'icon' => $a['status'] === 'active' ? 'circle-check' : 'x', 'label' => $t('finance.astatus.' . $a['status'])]) ?></td>
        <?php if ($canManage): ?>
        <td data-label="<?= $e($t('common.actions')) ?>">
          <details><summary><?= $e($t($a['status'] === 'active' ? 'finance.account.deactivate' : 'finance.account.activate')) ?></summary>
            <form method="post" action="<?= $e($url('/finance/accounts/' . $a['public_id'] . '/status?le=' . $le)) ?>" class="inline-form"><?= $csrf ?>
              <input type="hidden" name="status" value="<?= $a['status'] === 'active' ? 'inactive' : 'active' ?>">
              <label class="sr-only" for="ar-<?= $e($a['public_id']) ?>"><?= $e($t('iam.field.reason')) ?></label>
              <input class="input" id="ar-<?= $e($a['public_id']) ?>" name="reason" required placeholder="<?= $e($t('iam.field.reason')) ?>">
              <?= $ui('button', ['label' => $t('common.save'), 'type' => 'submit', 'size' => 'sm']) ?>
            </form></details>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if ($accounts === []): ?><tr><td colspan="6"><?= $ui('states', ['icon' => 'report-money', 'title' => $t('finance.accounts.empty'), 'text' => $t('finance.accounts.empty_text')]) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php if ($canManage): ?>
<section class="card" aria-labelledby="acc-new">
  <h2 id="acc-new" class="card__title"><?= $e($t('finance.account.new')) ?></h2>
  <?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors'), 'live' => true]) ?><?php endif; ?>
  <form method="post" action="<?= $e($url('/finance/accounts?le=' . $le)) ?>" class="form-grid" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'code', 'label' => $t('finance.field.account'), 'required' => true, 'value' => (string) ($old['code'] ?? ''), 'error' => $errors['code'] ?? null, 'help' => $t('finance.account.code_help')]) ?>
    <?= $ui('field', ['name' => 'name', 'label' => $t('finance.field.name'), 'required' => true, 'value' => (string) ($old['name'] ?? ''), 'error' => $errors['name'] ?? null]) ?>
    <?= $ui('field', ['name' => 'type', 'label' => $t('finance.field.type'), 'type' => 'select', 'required' => true, 'value' => (string) ($old['type'] ?? ''), 'error' => $errors['type'] ?? null,
        'options' => ['' => '—'] + array_combine($types, array_map(static fn ($x) => $t('finance.type.' . $x), $types))]) ?>
    <?= $ui('field', ['name' => 'parent', 'label' => $t('finance.field.parent'), 'type' => 'select', 'value' => (string) ($old['parent'] ?? ''), 'error' => $errors['parent'] ?? null,
        'options' => ['' => '—'] + array_combine(array_column($headers, 'code'), array_map(static fn ($h) => $h['code'] . ' · ' . $h['name'], $headers))]) ?>
    <label class="choice field--full"><input type="checkbox" name="postable" value="1"<?= ($old['postable'] ?? true) ? ' checked' : '' ?>> <?= $e($t('finance.account.postable_label')) ?></label>
    <div class="row field--full"><?= $ui('button', ['label' => $t('finance.account.create'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></div>
  </form>
</section>
<?php endif; ?>
