<?php
/**
 * A person's pay profile: the one in force · all versions (pending ones wait for another person) · propose a change.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $emp @var list<array<string, mixed>> $profiles @var array<string, mixed>|null $current @var array<string, string> $fixed
 * @var list<array<string, mixed>> $ytdEntries @var bool $canApprove @var int $me @var string $form
 * @var bool $canPrepare @var string $today @var string $lePid @var array<string, string> $errors @var array<string, mixed> $old
 */
use Blue\Core\Support\Money;

$o = static fn (string $k, string $d = ''): string => $form !== 'opening' && is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$m = static fn (mixed $x): string => Money::format(Money::fromDb($x));
$items = static fn (array $p): string => implode(', ', array_map(static fn (array $i): string => $i['code'] . ' ' . Money::format(Money::fromDb($i['amount'])), (array) json_decode((string) ($p['items_json'] ?? '[]'), true))) ?: '—';
$tones = ['pending' => 'info', 'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'neutral'];
$hasPending = count(array_filter($profiles, static fn (array $p): bool => $p['status'] === 'pending')) > 0;
$oldItems = array_values((array) ($old['items'] ?? []));
?>
<?= $ui('page-header', ['title' => $emp['employee_code'] . ' · ' . $emp['name_th'], 'subtitle' => $t('payroll.profile_subtitle'), 'crumbs' => [[$t('payroll.title'), $url('/payroll?le=' . rawurlencode($lePid))], [$t('payroll.people_title'), $url('/payroll/people?le=' . rawurlencode($lePid))], [(string) $emp['employee_code'], null]]]) ?>
<section class="card stack-sm" aria-labelledby="pf-c">
  <h2 id="pf-c" class="card__title"><?= $e($t('payroll.profile_now')) ?></h2>
  <?php if ($current === null): ?><p class="text-sm text-muted"><?= $e($t('payroll.no_profile_text')) ?></p><?php else: ?>
  <dl class="facts">
    <div><dt><?= $e($t('payroll.field.pay')) ?></dt><dd><?= $e($t('payroll.basis.' . $current['pay_basis']) . ' ' . $m($current['base_amount'])) ?></dd></div>
    <div><dt><?= $e($t('payroll.field.items')) ?></dt><dd><?= $e($items($current)) ?></dd></div>
    <div><dt><?= $e($t('payroll.field.bank')) ?></dt><dd><?= $current['account_last4'] !== null ? $e($current['bank_code'] . ' · ••••' . $current['account_last4']) : $e($t('payroll.no_bank')) ?></dd></div>
    <div><dt><?= $e($t('payroll.field.effective_from')) ?></dt><dd><?= $e($date((string) $current['effective_from'], false)) ?></dd></div>
  </dl>
  <?php endif; ?>
</section>
<section class="card stack-sm" aria-labelledby="pf-h">
  <h2 id="pf-h" class="card__title"><?= $e($t('payroll.profile_versions')) ?></h2>
  <div class="table-wrap" role="region" aria-labelledby="pf-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('payroll.field.effective_from')) ?></th><th scope="col"><?= $e($t('payroll.field.pay')) ?></th><th scope="col"><?= $e($t('payroll.field.items')) ?></th><th scope="col"><?= $e($t('payroll.col.status')) ?></th><th scope="col"><?= $e($t('payroll.field.reason')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($profiles as $p): ?>
        <tr><th scope="row" data-label="<?= $e($t('payroll.field.effective_from')) ?>"><?= $e($date((string) $p['effective_from'], false)) ?></th>
          <td data-label="<?= $e($t('payroll.field.pay')) ?>"><?= $e($t('payroll.basis.' . $p['pay_basis']) . ' ' . $m($p['base_amount'])) ?></td>
          <td data-label="<?= $e($t('payroll.field.items')) ?>"><?= $e($items($p)) ?></td>
          <td data-label="<?= $e($t('payroll.col.status')) ?>"><?= $ui('badge', ['tone' => $tones[$p['status']] ?? 'neutral', 'label' => $t('payroll.pstatus.' . $p['status'])]) ?><br><span class="text-sm text-muted"><?= $e($t('payroll.proposed_by', ['name' => (string) $p['proposed_name']])) ?><?= $p['decided_name'] !== null ? ' · ' . $e($t('payroll.decided_by', ['name' => (string) $p['decided_name']])) : '' ?></span></td>
          <td data-label="<?= $e($t('payroll.field.reason')) ?>"><?= $e((string) $p['reason']) ?><?php if ((int) ($p['retro'] ?? 0) === 1): ?> <?= $ui('badge', ['tone' => 'warning', 'label' => $t('payroll.retro_badge')]) ?><?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if ($profiles === []): ?><tr><td colspan="5"><?= $ui('states', ['icon' => 'wallet', 'title' => $t('payroll.no_versions')]) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
<section class="card stack-sm" id="ytd" aria-labelledby="pf-y">
  <h2 id="pf-y" class="card__title"><?= $e($t('payroll.ytd_section')) ?></h2>
  <?php
  $years = [];
  foreach ($ytdEntries as $y) {
      if ($y['status'] !== 'approved') {
          continue;
      }
      $k = (int) $y['tax_year'];
      $years[$k] ??= ['gross' => 0, 'taxable' => 0, 'wht' => 0, 'sso' => 0];
      foreach (['gross' => 'gross', 'taxable' => 'taxable_income', 'wht' => 'wht', 'sso' => 'sso_employee'] as $a => $col) {
          $years[$k][$a] += Money::fromDb($y[$col]);
      }
  }
  $ytones = ['pending' => 'info', 'approved' => 'success', 'rejected' => 'danger'];
  ?>
  <?php if ($years === []): ?><p class="text-sm text-muted"><?= $e($t('payroll.ytd_none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="pf-y" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('payroll.field.tax_year')) ?></th><th scope="col"><?= $e($t('payroll.ytd.gross')) ?></th><th scope="col"><?= $e($t('payroll.ytd.taxable')) ?></th><th scope="col"><?= $e($t('payroll.ytd.wht')) ?></th><th scope="col"><?= $e($t('payroll.ytd.sso')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($years as $yr => $tot): ?>
        <tr><th scope="row" data-label="<?= $e($t('payroll.field.tax_year')) ?>"><?= (int) $yr ?></th>
          <?php foreach (['gross', 'taxable', 'wht', 'sso'] as $a): ?><td data-label="<?= $e($t('payroll.ytd.' . $a)) ?>"><?= $e(Money::format($tot[$a])) ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <?php if ($ytdEntries !== []): ?>
  <details><summary><?= $e($t('payroll.ytd_entries')) ?></summary>
    <ul class="plain-list text-sm">
      <?php foreach ($ytdEntries as $y): ?>
        <li><?= $ui('badge', ['tone' => $ytones[$y['status']] ?? 'neutral', 'label' => $t('payroll.ystatus.' . $y['status'])]) ?>
          <?= $e((int) $y['tax_year'] . ' · ' . ($y['source_type'] === 'run' ? (string) $y['run_label'] : $t('payroll.opening')) . ' · ' . $date((string) $y['as_of'], false)) ?>
          — <?= $e($t('payroll.ytd.taxable') . ' ' . $m($y['taxable_income']) . ' · ' . $t('payroll.ytd.wht') . ' ' . $m($y['wht']) . ' · ' . $t('payroll.ytd.sso') . ' ' . $m($y['sso_employee'])) ?>
          <?php if ($y['note'] !== null): ?><span class="text-muted">· <?= $e((string) $y['note']) ?></span><?php endif; ?>
          <?php if ($y['status'] === 'pending' && $canApprove && (int) $y['created_by'] !== $me): ?>
            <form method="post" action="<?= $e($url('/payroll/people/' . $emp['public_id'] . '/opening/' . $y['public_id'] . '/approve')) ?>" class="row"><?= $csrf ?><?= $ui('button', ['label' => $t('payroll.approve_opening'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></form>
            <form method="post" action="<?= $e($url('/payroll/people/' . $emp['public_id'] . '/opening/' . $y['public_id'] . '/reject')) ?>" class="row" novalidate><?= $csrf ?>
              <?= $ui('field', ['name' => 'decision_note', 'id' => 'f-reject-' . $y['public_id'], 'label' => $t('payroll.field.reject_note')]) ?>
              <?= $ui('button', ['label' => $t('payroll.reject_opening'), 'type' => 'submit', 'variant' => 'secondary']) ?></form>
          <?php elseif ($y['status'] === 'pending'): ?><span class="text-muted">· <?= $e($t('payroll.waiting_other')) ?></span><?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
  </details>
  <?php endif; ?>
  <?php if ($canPrepare): ?>
  <details<?= $form === 'opening' ? ' open' : '' ?>><summary><?= $e($t('payroll.opening_title')) ?></summary>
    <form method="post" action="<?= $e($url('/payroll/people/' . $emp['public_id'] . '/opening')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <p class="text-sm text-muted"><?= $e($t('payroll.opening_help')) ?></p>
      <?php $oo = static fn (string $k, string $d = ''): string => $form === 'opening' && is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d; $oe = static fn (string $k): ?string => $form === 'opening' ? ($errors[$k] ?? null) : null; ?>
      <div class="grid grid--3">
        <?= $ui('field', ['name' => 'tax_year', 'type' => 'number', 'label' => $t('payroll.field.tax_year'), 'required' => true, 'value' => $oo('tax_year', substr($today, 0, 4)), 'error' => $oe('tax_year')]) ?>
        <?= $ui('field', ['name' => 'as_of', 'type' => 'date', 'label' => $t('payroll.field.as_of'), 'required' => true, 'value' => $oo('as_of'), 'help' => $t('payroll.as_of_help'), 'error' => $oe('as_of')]) ?>
        <?= $ui('field', ['name' => 'gross', 'type' => 'money', 'label' => $t('payroll.ytd.gross'), 'value' => $oo('gross'), 'error' => $oe('gross')]) ?>
        <?= $ui('field', ['name' => 'taxable_income', 'type' => 'money', 'label' => $t('payroll.ytd.taxable'), 'value' => $oo('taxable_income'), 'error' => $oe('taxable_income')]) ?>
        <?= $ui('field', ['name' => 'wht', 'type' => 'money', 'label' => $t('payroll.ytd.wht'), 'value' => $oo('wht'), 'error' => $oe('wht')]) ?>
        <?= $ui('field', ['name' => 'sso_employee', 'type' => 'money', 'label' => $t('payroll.ytd.sso'), 'value' => $oo('sso_employee'), 'error' => $oe('sso_employee')]) ?>
      </div>
      <?= $ui('field', ['name' => 'note', 'id' => 'f-opening-note', 'label' => $t('payroll.field.opening_note'), 'required' => true, 'value' => $oo('note'), 'help' => $t('payroll.opening_note_help'), 'error' => $oe('note')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('payroll.propose'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'send']) ?></div>
    </form>
  </details>
  <?php endif; ?>
</section>
<?php if ($canPrepare): ?>
<section class="card stack" aria-labelledby="pf-n">
  <h2 id="pf-n" class="card__title"><?= $e($t('payroll.propose_title')) ?></h2>
  <?php if ($hasPending): ?><p class="text-sm text-muted"><?= $e($t('payroll.error.profile_pending')) ?></p><?php else: ?>
  <p class="text-sm text-muted"><?= $e($t('payroll.propose_help')) ?></p>
  <form method="post" action="<?= $e($url('/payroll/people/' . $emp['public_id'])) ?>" class="stack" novalidate autocomplete="off"><?= $csrf ?>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'effective_from', 'type' => 'date', 'label' => $t('payroll.field.effective_from'), 'required' => true, 'value' => $o('effective_from', $today), 'error' => $errors['effective_from'] ?? null]) ?>
      <?= $ui('field', ['name' => 'pay_basis', 'type' => 'select', 'label' => $t('payroll.field.basis'), 'required' => true, 'value' => $o('pay_basis', (string) ($current['pay_basis'] ?? 'monthly')), 'options' => ['monthly' => $t('payroll.basis.monthly'), 'daily' => $t('payroll.basis.daily')], 'error' => $errors['pay_basis'] ?? null]) ?>
      <?= $ui('field', ['name' => 'base_amount', 'type' => 'money', 'label' => $t('payroll.field.base_amount'), 'required' => true, 'value' => $o('base_amount'), 'help' => $t('payroll.base_help'), 'error' => $errors['base_amount'] ?? null]) ?>
    </div>
    <?php if ($fixed !== []): ?>
    <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('payroll.field.items')) ?></legend>
      <?php for ($i = 0; $i < 3; $i++): ?>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => "items[{$i}][component]", 'id' => "f-item-{$i}-c", 'type' => 'select', 'label' => $t('payroll.field.component'), 'value' => (string) ($oldItems[$i]['component'] ?? ''), 'options' => ['' => '—'] + $fixed, 'error' => isset($errors["items.{$i}.component"]) ? $errors["items.{$i}.component"] : null]) ?>
        <?= $ui('field', ['name' => "items[{$i}][amount]", 'id' => "f-item-{$i}-a", 'type' => 'money', 'label' => $t('payroll.field.amount'), 'value' => (string) ($oldItems[$i]['amount'] ?? ''), 'error' => isset($errors["items.{$i}.amount"]) ? $errors["items.{$i}.amount"] : null]) ?>
      </div>
      <?php endfor; ?>
      <?php if (isset($errors['items'])): ?><p class="field__error"><?= $e($t($errors['items'])) ?></p><?php endif; ?>
    </fieldset>
    <?php endif; ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'bank_code', 'label' => $t('payroll.field.bank_code'), 'value' => $o('bank_code', (string) ($current['bank_code'] ?? '')), 'error' => $errors['bank_code'] ?? null]) ?>
      <?= $ui('field', ['name' => 'account_no', 'label' => $t('payroll.field.account_no'), 'value' => '', 'inputmode' => 'numeric', 'autocomplete' => 'off', 'help' => $t('payroll.account_help'), 'error' => $errors['account_no'] ?? null]) ?>
    </div>
    <?= $ui('field', ['name' => 'reason', 'label' => $t('payroll.field.reason'), 'required' => true, 'value' => $o('reason'), 'error' => $errors['reason'] ?? null]) ?>
    <label class="check"><input type="checkbox" name="retro" value="1"<?= !empty($old['retro']) ? ' checked' : '' ?>> <?= $e($t('payroll.retro_ack')) ?></label>
    <p class="text-sm text-muted"><?= $e($t('payroll.retro_help')) ?></p>
    <div class="row"><?= $ui('button', ['label' => $t('payroll.propose'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>
