<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var list<array<string, mixed>> $rules @var list<array<string, mixed>> $accounts @var bool $canPropose @var array<string, string> $errors @var array<string, mixed> $old @var int $le
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$rs = ['draft' => 'draft', 'in_review' => 'pending', 'returned' => 'returned', 'approved' => 'approved', 'superseded' => 'cancelled', 'rejected' => 'rejected', 'cancelled' => 'cancelled'];
$accOpts = ['' => '—'] + array_combine(array_column($accounts, 'code'), array_map(static fn ($a) => $a['code'] . ' · ' . $a['name'], $accounts));
$uid = isset($v['ctx']) && $v['ctx'] instanceof \Blue\Core\Http\RequestContext ? $v['ctx']->userId() : 0;
?>
<?= $ui('page-header', ['title' => $t('finance.rules.title'), 'subtitle' => $t('finance.rules.subtitle')]) ?>
<?php include __DIR__ . '/_tabs.php'; ?>
<div class="table-wrap" role="region" aria-label="<?= $e($t('finance.rules.title')) ?>" tabindex="0">
  <table class="table table--stack">
    <caption class="sr-only"><?= $e($t('finance.rules.title')) ?></caption>
    <thead><tr><th scope="col"><?= $e($t('finance.field.line_type')) ?></th><th scope="col"><?= $e($t('finance.field.debit')) ?></th><th scope="col"><?= $e($t('finance.field.credit')) ?></th>
      <th scope="col"><?= $e($t('records.field.effective_from')) ?></th><th scope="col"><?= $e($t('finance.field.status')) ?></th><th scope="col"><?= $e($t('records.field.source')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($rules as $r): ?>
      <tr id="r-<?= $e($r['public_id']) ?>">
        <td data-label="<?= $e($t('finance.field.line_type')) ?>"><span class="mono"><?= $e($r['source_module']) ?></span><br><span class="mono"><?= $e($r['line_type']) ?></span> · v<?= (int) $r['version_no'] ?>
          <?php if ((int) $r['is_demo'] === 1): ?> <?= $ui('badge', ['tone' => 'neutral', 'label' => 'DEMO']) ?><?php endif; ?></td>
        <td data-label="<?= $e($t('finance.field.debit')) ?>"><span class="mono"><?= $e($r['debit_code']) ?></span> <?= $e($r['debit_name']) ?></td>
        <td data-label="<?= $e($t('finance.field.credit')) ?>"><span class="mono"><?= $e($r['credit_code']) ?></span> <?= $e($r['credit_name']) ?></td>
        <td data-label="<?= $e($t('records.field.effective_from')) ?>"><?= $e((string) $r['effective_from']) ?></td>
        <td data-label="<?= $e($t('finance.field.status')) ?>"><?= $ui('badge', ['status' => $rs[$r['status']] ?? 'draft', 'label' => $t('finance.rule_status.' . $r['status'])]) ?>
          <div class="text-sm text-muted"><?= $e($r['proposed_name'] ?? '') ?><?= $r['decided_name'] !== null ? ' → ' . $e($r['decided_name']) : '' ?></div>
          <?php if ($canPropose && $r['status'] === 'draft' && (int) $r['proposed_by'] === $uid): ?>
            <div class="row"><form method="post" action="<?= $e($url('/finance/rules/' . $r['public_id'] . '/submit')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('finance.rule.submit'), 'type' => 'submit', 'size' => 'sm', 'variant' => 'primary', 'icon' => 'send']) ?></form>
              <form method="post" action="<?= $e($url('/finance/rules/' . $r['public_id'] . '/cancel')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('common.cancel'), 'type' => 'submit', 'size' => 'sm', 'variant' => 'ghost']) ?></form></div>
          <?php endif; ?></td>
        <td data-label="<?= $e($t('records.field.source')) ?>"><?= $e($r['source_reference'] ?? '—') ?><div class="text-sm text-muted"><?= $e($r['reason']) ?></div></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($rules === []): ?><tr><td colspan="6"><?= $ui('states', ['icon' => 'list-check', 'title' => $t('finance.rules.empty'), 'text' => $t('finance.rules.empty_text')]) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php if ($canPropose): ?>
<section class="card" aria-labelledby="rule-new">
  <h2 id="rule-new" class="card__title"><?= $e($t('finance.rule.new')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('finance.rule.help')) ?></p>
  <?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors'), 'text' => $errors['form'] ?? '', 'live' => true]) ?><?php endif; ?>
  <form method="post" action="<?= $e($url('/finance/rules?le=' . $le)) ?>" class="form-grid" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'module', 'label' => $t('finance.field.source_module'), 'required' => true, 'value' => (string) ($old['module'] ?? 'core.payment'), 'error' => $errors['module'] ?? null, 'help' => $t('finance.rule.module_help')]) ?>
    <?= $ui('field', ['name' => 'line_type', 'label' => $t('finance.field.line_type'), 'required' => true, 'value' => (string) ($old['line_type'] ?? ''), 'error' => $errors['line_type'] ?? null, 'help' => $t('finance.rule.line_help')]) ?>
    <?= $ui('field', ['name' => 'debit', 'label' => $t('finance.field.debit'), 'type' => 'select', 'required' => true, 'value' => (string) ($old['debit'] ?? ''), 'options' => $accOpts, 'error' => $errors['debit'] ?? null]) ?>
    <?= $ui('field', ['name' => 'credit', 'label' => $t('finance.field.credit'), 'type' => 'select', 'required' => true, 'value' => (string) ($old['credit'] ?? ''), 'options' => $accOpts, 'error' => $errors['credit'] ?? null]) ?>
    <?= $ui('field', ['name' => 'effective_from', 'label' => $t('records.field.effective_from'), 'type' => 'date', 'required' => true, 'value' => (string) ($old['effective_from'] ?? substr((string) ($v['now_utc'] ?? ''), 0, 10)), 'error' => $errors['effective_from'] ?? null]) ?>
    <?= $ui('field', ['name' => 'source', 'label' => $t('records.field.source'), 'value' => (string) ($old['source'] ?? ''), 'error' => $errors['source'] ?? null, 'help' => $t('finance.rule.source_help')]) ?>
    <?= $ui('field', ['name' => 'reason', 'label' => $t('iam.field.reason'), 'required' => true, 'full' => true, 'value' => (string) ($old['reason'] ?? ''), 'error' => $errors['reason'] ?? null]) ?>
    <div class="row field--full">
      <?= $ui('button', ['label' => $t('finance.rule.save'), 'type' => 'submit', 'icon' => 'checks']) ?>
      <?= $ui('button', ['label' => $t('finance.rule.save_submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send', 'attrs' => ['name' => 'submit_now', 'value' => '1']]) ?>
    </div>
  </form>
</section>
<?php endif; ?>
