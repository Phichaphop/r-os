<?php
/**
 * Pay components of a company: list (status, method, flags) · activate / retire (another person) · new component.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $rows @var bool $canPrepare @var bool $canApprove @var int $me
 * @var array<string, string> $errors @var array<string, mixed> $old
 */
$o = static fn (string $k, string $d = ''): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$here = '/payroll/components';
$tones = ['draft' => 'info', 'active' => 'success', 'retired' => 'neutral'];
$methods = [];
foreach (['base', 'fixed', 'ot', 'absence', 'manual', 'sso_employee', 'sso_employer', 'wht'] as $mt) {
    $methods[$mt] = $t('payroll.method.' . $mt);
}
?>
<?= $ui('page-header', ['title' => $t('payroll.components_title'), 'subtitle' => $t('payroll.components_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<section class="card stack-sm" aria-labelledby="pc-l">
  <h2 id="pc-l" class="card__title"><?= $e($t('payroll.components_title')) ?></h2>
  <div class="table-wrap" role="region" aria-labelledby="pc-l" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('payroll.field.code')) ?></th><th scope="col"><?= $e($t('payroll.field.method')) ?></th><th scope="col"><?= $e($t('payroll.col.kind')) ?></th><th scope="col"><?= $e($t('payroll.col.flags')) ?></th><th scope="col"><?= $e($t('payroll.col.status')) ?></th><th scope="col"><span class="sr-only"><?= $e($t('payroll.col.actions')) ?></span></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $c): ?>
        <tr><th scope="row" data-label="<?= $e($t('payroll.field.code')) ?>"><?= $e($c['code']) ?> · <?= $e((string) $c['name_th']) ?></th>
          <td data-label="<?= $e($t('payroll.field.method')) ?>"><?= $e($t('payroll.method.' . $c['method'])) ?></td>
          <td data-label="<?= $e($t('payroll.col.kind')) ?>"><?= $e($t('payroll.kind.' . $c['kind'])) ?></td>
          <td data-label="<?= $e($t('payroll.col.flags')) ?>"><?= $e(implode(' · ', array_filter([(int) $c['taxable'] === 1 ? $t('payroll.flag.taxable') : '', (int) $c['sso_base'] === 1 ? $t('payroll.flag.sso_base') : '', $c['statutory'] === 'wht' ? $t('payroll.flag.wht') : ''])) ?: '—') ?></td>
          <td data-label="<?= $e($t('payroll.col.status')) ?>"><?= $ui('badge', ['tone' => $tones[$c['status']] ?? 'neutral', 'label' => $t('payroll.cstatus.' . $c['status'])]) ?></td>
          <td><?php if ($canApprove && $c['status'] === 'draft' && (int) $c['created_by'] !== $me): ?><form method="post" action="<?= $e($url('/payroll/components/' . $c['public_id'] . '/activate')) ?>"><?= $csrf ?><input type="hidden" name="le" value="<?= $e($lePid) ?>"><?= $ui('button', ['label' => $t('payroll.activate'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></form>
            <?php elseif ($canApprove && $c['status'] === 'active'): ?><form method="post" action="<?= $e($url('/payroll/components/' . $c['public_id'] . '/retire')) ?>"><?= $csrf ?><input type="hidden" name="le" value="<?= $e($lePid) ?>"><?= $ui('button', ['label' => $t('payroll.retire'), 'type' => 'submit', 'variant' => 'secondary']) ?></form>
            <?php elseif ($c['status'] === 'draft'): ?><span class="text-sm text-muted"><?= $e($t('payroll.waiting_other')) ?></span><?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if ($rows === []): ?><tr><td colspan="6"><?= $ui('states', ['icon' => 'components', 'title' => $t('payroll.components_none')]) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
<?php if ($canPrepare): ?>
<section class="card stack" aria-labelledby="pc-n">
  <h2 id="pc-n" class="card__title"><?= $e($t('payroll.new_component')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('payroll.component_help')) ?></p>
  <form method="post" action="<?= $e($url('/payroll/components')) ?>" class="stack" novalidate><?= $csrf ?>
    <input type="hidden" name="le" value="<?= $e($lePid) ?>">
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'code', 'label' => $t('payroll.field.code'), 'required' => true, 'value' => $o('code'), 'help' => $t('payroll.code_help'), 'error' => $errors['code'] ?? null]) ?>
      <?= $ui('field', ['name' => 'sort_order', 'type' => 'number', 'label' => $t('payroll.field.sort_order'), 'value' => $o('sort_order', '100'), 'error' => $errors['sort_order'] ?? null]) ?>
      <?= $ui('field', ['name' => 'name_th', 'label' => $t('payroll.field.name_th'), 'required' => true, 'value' => $o('name_th'), 'error' => $errors['name_th'] ?? null]) ?>
      <?= $ui('field', ['name' => 'name_en', 'label' => $t('payroll.field.name_en'), 'value' => $o('name_en')]) ?>
      <?= $ui('field', ['name' => 'method', 'type' => 'select', 'label' => $t('payroll.field.method'), 'required' => true, 'value' => $o('method'), 'options' => ['' => '—'] + $methods, 'help' => $t('payroll.method_help'), 'error' => $errors['method'] ?? null]) ?>
      <?= $ui('field', ['name' => 'kind', 'type' => 'select', 'label' => $t('payroll.col.kind'), 'required' => true, 'value' => $o('kind'), 'options' => ['' => '—', 'earning' => $t('payroll.kind.earning'), 'deduction' => $t('payroll.kind.deduction'), 'employer' => $t('payroll.kind.employer')], 'error' => $errors['kind'] ?? null]) ?>
    </div>
    <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('payroll.col.flags')) ?></legend>
      <label class="check"><input type="checkbox" name="taxable" value="1"<?= $o('taxable') === '1' ? ' checked' : '' ?>> <?= $e($t('payroll.flag.taxable_long')) ?></label>
      <label class="check"><input type="checkbox" name="sso_base" value="1"<?= $o('sso_base') === '1' ? ' checked' : '' ?>> <?= $e($t('payroll.flag.sso_base_long')) ?></label>
      <label class="check"><input type="checkbox" name="statutory" value="wht"<?= $o('statutory') === 'wht' ? ' checked' : '' ?>> <?= $e($t('payroll.flag.wht_long')) ?></label>
      <p class="text-sm text-muted"><?= $e($t('payroll.flags_help')) ?></p>
    </fieldset>
    <div class="row"><?= $ui('button', ['label' => $t('payroll.save_component'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></div>
  </form>
</section>
<?php endif; ?>
