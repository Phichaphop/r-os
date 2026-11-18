<?php
/**
 * Wallet admin: rules, top-ups, reconciliation. @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $asset @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var array<string, mixed>|null $rules @var list<array<string, mixed>> $topups @var bool $canTopup @var bool $canPolicy
 * @var array<string, array{label: string, balance: int}> $people @var array<string, mixed>|null $recon @var array<string, string> $errors @var array<string, mixed> $old @var string $form @var string $today
 */
$loc = (string) ($v['locale'] ?? 'th');
include __DIR__ . '/_helpers.php';
$err = static fn (string $f, string $k) => $form === $f ? ($errors[$k] ?? null) : null;
$ru = $form === 'rules' ? $old : ($rules ?? []);
$cur = (string) ($rules['currency'] ?? '');
$picked = (array) ($old['employees'] ?? []);
?>
<?= $ui('page-header', ['title' => $t('eid.wallet_admin_title'), 'subtitle' => $t('eid.wallet_admin_subtitle')]) ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('eid.pick_company')) ?>">
  <?php foreach ($companies as $pid => $code): ?><a href="<?= $e($url('/eid/wallet/admin?le=' . rawurlencode($pid))) ?>"<?= $pid === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<?= $ui('alert', ['tone' => 'info', 'title' => $t('eid.wallet_closed_title'), 'text' => $t('eid.wallet_closed_text')]) ?>

<?php if ($canTopup): ?>
<section class="card stack" aria-labelledby="tp-h">
  <h2 id="tp-h" class="card__title"><?= $e($t('eid.topup_new')) ?></h2>
  <?php if ($rules === null): ?>
    <?= $ui('alert', ['tone' => 'warning', 'text' => $t('eid.error.no_rules')]) ?>
  <?php else: ?>
  <p class="text-sm text-muted"><?= $e($t('eid.topup_help', ['max' => (string) $rules['max_topup'], 'cap' => (string) $rules['max_balance'], 'cur' => $cur])) ?></p>
  <form method="post" action="<?= $e($url('/eid/wallet/admin/topup')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <input type="hidden" name="legal_entity" value="<?= $e($lePid) ?>">
    <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('eid.field.employees')) ?></legend>
      <?php if ($err('topup', 'employees') !== null): ?><p class="field__error"><?= $e((string) $err('topup', 'employees')) ?></p><?php endif; ?>
      <?php if ($err('topup', 'lines') !== null): ?><p class="field__error"><?= $e((string) $err('topup', 'lines')) ?></p><?php endif; ?>
      <div class="check-list">
        <?php foreach ($people as $pid => $p): ?><label class="check"><input type="checkbox" name="employees[]" value="<?= $e($pid) ?>"<?= in_array($pid, $picked, true) ? ' checked' : '' ?>> <?= $e($p['label']) ?> <span class="text-muted">(<?= $e($money($p['balance'], $cur)) ?>)</span></label><?php endforeach; ?>
      </div>
    </fieldset>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'amount', 'id' => 'tp-amount', 'type' => 'money', 'label' => $t('eid.field.amount_each'), 'required' => true, 'value' => (string) ($old['amount'] ?? ''), 'prefix' => $cur, 'error' => $err('topup', 'amount')]) ?>
      <?= $ui('field', ['name' => 'reason', 'id' => 'tp-reason', 'label' => $t('eid.field.reason'), 'required' => true, 'value' => (string) ($old['reason'] ?? ''), 'placeholder' => $t('eid.topup_reason_example'), 'error' => $err('topup', 'reason')]) ?>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('eid.topup_submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="card stack-sm" aria-labelledby="tl-h">
  <h2 id="tl-h" class="card__title"><?= $e($t('eid.topups')) ?></h2>
  <?php if ($topups === []): ?><p class="text-sm text-muted"><?= $e($t('eid.none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="tl-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('eid.field.topup')) ?></th><th scope="col"><?= $e($t('eid.field.status')) ?></th><th scope="col"><?= $e($t('eid.field.lines')) ?></th><th scope="col"><?= $e($t('eid.field.total')) ?></th><th scope="col"><?= $e($t('eid.field.by')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($topups as $x): ?>
        <tr><td data-label="<?= $e($t('eid.field.topup')) ?>"><a href="<?= $e($url('/eid/wallet/topup/' . $x['public_id'])) ?>"><?= $e($t('eid.topup_title', ['n' => (string) $x['topup_no']])) ?></a></td>
          <td data-label="<?= $e($t('eid.field.status')) ?>"><?= $e($t('eid.topup_status.' . $x['status'])) ?></td>
          <td data-label="<?= $e($t('eid.field.lines')) ?>" class="num"><?= (int) $x['line_count'] ?></td>
          <td data-label="<?= $e($t('eid.field.total')) ?>" class="num"><?= $e($money((string) $x['total_amount'], (string) $x['currency_code'])) ?></td>
          <td data-label="<?= $e($t('eid.field.by')) ?>"><?= $e((string) ($x['created_name'] ?? '—')) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<?php if ($recon !== null): ?>
<section class="card stack-sm" aria-labelledby="rc-h">
  <h2 id="rc-h" class="card__title"><?= $e($t('eid.recon')) ?></h2>
  <?= $ui('alert', ['tone' => $recon['mismatches'] === [] ? 'success' : 'danger', 'text' => $recon['mismatches'] === [] ? $t('eid.recon_ok', ['n' => (string) $recon['wallets'], 'total' => $money($recon['ledger_total'], $cur)]) : $t('eid.recon_bad', ['n' => (string) count($recon['mismatches'])])]) ?>
  <dl class="facts">
    <div><dt><?= $e($t('eid.recon_balance')) ?></dt><dd><?= $e($money($recon['balance_total'], $cur)) ?></dd></div>
    <div><dt><?= $e($t('eid.recon_ledger')) ?></dt><dd><?= $e($money($recon['ledger_total'], $cur)) ?></dd></div>
  </dl>
</section>
<?php endif; ?>

<section class="card stack" aria-labelledby="ru-h">
  <h2 id="ru-h" class="card__title"><?= $e($t('eid.rules.title')) ?></h2>
  <?php if ($rules === null): ?>
    <?= $ui('alert', ['tone' => 'info', 'text' => $t('eid.rules.none')]) ?>
  <?php else: ?>
    <dl class="facts">
      <div><dt><?= $e($t('eid.rules.currency')) ?></dt><dd><?= $e($cur) ?></dd></div>
      <div><dt><?= $e($t('eid.rules.max_topup')) ?></dt><dd><?= $e($rules['max_topup'] . ' ' . $cur) ?></dd></div>
      <div><dt><?= $e($t('eid.rules.max_balance')) ?></dt><dd><?= $e($rules['max_balance'] . ' ' . $cur) ?></dd></div>
      <div><dt><?= $e($t('eid.rules.low_balance')) ?></dt><dd><?= $e(($rules['low_balance'] ?? null) === null ? '—' : $rules['low_balance'] . ' ' . $cur) ?></dd></div>
      <div><dt><?= $e($t('eid.rules.purpose')) ?></dt><dd><?= $e((string) $rules['purpose']) ?></dd></div>
    </dl>
  <?php endif; ?>
  <?php if ($canPolicy): ?>
  <details<?= $form === 'rules' ? ' open' : '' ?>>
    <summary><?= $e($t('eid.rules.propose')) ?></summary>
    <form method="post" action="<?= $e($url('/eid/wallet/admin/rules')) ?>" class="stack" novalidate>
      <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
      <input type="hidden" name="legal_entity" value="<?= $e($lePid) ?>">
      <?= $ui('alert', ['tone' => 'warning', 'title' => $t('eid.rules.warn_title'), 'text' => $t('eid.rules.warn_text')]) ?>
      <div class="grid grid--3">
        <?= $ui('field', ['name' => 'currency', 'id' => 'ru-cur', 'label' => $t('eid.rules.currency'), 'required' => true, 'value' => (string) ($ru['currency'] ?? ''), 'placeholder' => 'THB', 'error' => $err('rules', 'currency')]) ?>
        <?= $ui('field', ['name' => 'max_topup', 'id' => 'ru-top', 'type' => 'money', 'label' => $t('eid.rules.max_topup'), 'required' => true, 'value' => (string) ($ru['max_topup'] ?? ''), 'error' => $err('rules', 'max_topup')]) ?>
        <?= $ui('field', ['name' => 'max_balance', 'id' => 'ru-bal', 'type' => 'money', 'label' => $t('eid.rules.max_balance'), 'required' => true, 'value' => (string) ($ru['max_balance'] ?? ''), 'error' => $err('rules', 'max_balance')]) ?>
        <?= $ui('field', ['name' => 'low_balance', 'id' => 'ru-low', 'type' => 'money', 'label' => $t('eid.rules.low_balance'), 'value' => (string) ($ru['low_balance'] ?? ''), 'error' => $err('rules', 'low_balance')]) ?>
        <?= $ui('field', ['name' => 'effective_from', 'id' => 'ru-from', 'type' => 'date', 'label' => $t('eid.rules.effective_from'), 'required' => true, 'value' => (string) ($old['effective_from'] ?? $today), 'error' => $err('rules', 'effective_from')]) ?>
      </div>
      <?= $ui('field', ['name' => 'purpose', 'id' => 'ru-purpose', 'label' => $t('eid.rules.purpose'), 'required' => true, 'value' => (string) ($ru['purpose'] ?? ''), 'help' => $t('eid.rules.purpose_help'), 'error' => $err('rules', 'purpose')]) ?>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'source_reference', 'id' => 'ru-src', 'label' => $t('eid.rules.source'), 'required' => true, 'value' => (string) ($old['source_reference'] ?? ''), 'help' => $t('eid.rules.source_help'), 'error' => $err('rules', 'source_reference')]) ?>
        <?= $ui('field', ['name' => 'reason', 'id' => 'ru-reason', 'label' => $t('eid.field.reason'), 'required' => true, 'value' => (string) ($old['reason'] ?? ''), 'error' => $err('rules', 'reason')]) ?>
      </div>
      <div class="row"><?= $ui('button', ['label' => $t('eid.rules.submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
    </form>
  </details>
  <?php endif; ?>
</section>
