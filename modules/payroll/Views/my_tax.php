<?php
/**
 * My tax: the allowances I declare for a tax year (Payroll checks them) and my withholding tax certificates.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $p @var int $year @var array<string, string> $errors @var array<string, mixed> $old
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$tones = ['pending' => 'info', 'approved' => 'success', 'rejected' => 'danger', 'superseded' => 'neutral', 'withdrawn' => 'neutral'];
$shown = [];
foreach ((array) json_decode((string) (($p['pending'] ?? $p['current'])['items_json'] ?? '[]'), true) as $it) {
    $shown[(string) $it['code']] = (string) $it['value'];
}
$val = static fn (string $code): string => is_scalar($old['v'][$code] ?? null) ? (string) $old['v'][$code] : ($shown[$code] ?? '');
?>
<?= $ui('page-header', ['title' => $t('payroll.my_tax_title'), 'subtitle' => $t('payroll.my_tax_subtitle')]) ?>
<nav class="segmented-links" aria-label="<?= $e($t('payroll.my_sections')) ?>">
  <a href="<?= $e($url('/me/payslips')) ?>"><?= $ui('icon', ['name' => 'receipt', 'size' => 'sm']) ?> <?= $e($t('payroll.my_title')) ?></a>
  <a href="<?= $e($url('/me/tax')) ?>" aria-current="page"><?= $ui('icon', ['name' => 'file-text', 'size' => 'sm']) ?> <?= $e($t('payroll.my_tax_title')) ?></a>
</nav>
<?php if ($p['employee'] === null): ?>
  <?= $ui('states', ['icon' => 'file-text', 'title' => $t('payroll.error.no_employee')]) ?>
<?php else: ?>
<nav class="segmented-links" aria-label="<?= $e($t('payroll.field.tax_year')) ?>">
  <?php foreach ([$year - 1, $year, $year + 1] as $y): ?><a href="<?= $e($url('/me/tax?year=' . $y)) ?>"<?= $y === $year ? ' aria-current="page"' : '' ?>><?= (int) $y ?></a><?php endforeach; ?>
</nav>
<section class="card stack" aria-labelledby="mt-d">
  <h2 id="mt-d" class="card__title"><?= $e($t('payroll.declare_title', ['year' => (string) $year])) ?>
    <?php if ($p['pending'] !== null): ?><?= $ui('badge', ['tone' => 'info', 'label' => $t('payroll.dstatus.pending')]) ?><?php elseif ($p['current'] !== null): ?><?= $ui('badge', ['tone' => 'success', 'label' => $t('payroll.dstatus.approved')]) ?><?php endif; ?></h2>
  <?php if ($p['types'] === []): ?>
    <p class="text-sm text-muted"><?= $e($t('payroll.error.no_allowance_types')) ?></p>
  <?php else: ?>
  <p class="text-sm text-muted"><?= $e($t('payroll.declare_help')) ?></p>
  <form method="post" action="<?= $e($url('/me/tax')) ?>" class="stack" novalidate><?= $csrf ?>
    <input type="hidden" name="tax_year" value="<?= (int) $year ?>">
    <div class="grid grid--2">
    <?php foreach ($p['types'] as $ty): ?>
      <?= $ui('field', ['name' => 'v[' . $ty['code'] . ']', 'id' => 'f-v-' . strtolower((string) $ty['code']), 'type' => $ty['unit'] === 'count' ? 'number' : 'money', 'label' => (string) $ty['name'],
          'value' => $val((string) $ty['code']), 'help' => $ty['unit'] === 'count' ? $t('payroll.declare_count', ['v' => (string) $ty['per_unit']]) . ($ty['cap'] !== null ? ' · ' . $t('payroll.declare_cap', ['v' => (string) $ty['cap']]) : '') : ($ty['cap'] !== null ? $t('payroll.declare_cap', ['v' => (string) $ty['cap']]) : ''),
          'error' => $errors['v_' . $ty['code']] ?? null]) ?>
    <?php endforeach; ?>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('payroll.declare_send'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
  </form>
  <?php endif; ?>
  <?php if ($p['history'] !== []): ?>
  <details><summary><?= $e($t('payroll.declare_history')) ?></summary>
    <ul class="plain-list text-sm">
      <?php foreach ($p['history'] as $d): ?><li><?= $ui('badge', ['tone' => $tones[$d['status']] ?? 'neutral', 'label' => $t('payroll.dstatus.' . $d['status'])]) ?> <?= $e($date((string) $d['created_at'])) ?><?= $d['decision_note'] !== null ? ' — ' . $e((string) $d['decision_note']) : '' ?></li><?php endforeach; ?>
    </ul>
  </details>
  <?php endif; ?>
</section>
<section class="card stack-sm" aria-labelledby="mt-c">
  <h2 id="mt-c" class="card__title"><?= $e($t('payroll.my_certs')) ?></h2>
  <?php if ($p['certificates'] === []): ?><p class="text-sm text-muted"><?= $e($t('payroll.my_certs_none')) ?></p><?php else: ?>
  <ul class="plain-list">
    <?php foreach ($p['certificates'] as $c): ?><li><a href="<?= $e($url('/me/tax/certificates/' . $c['public_id'] . '/pdf')) ?>"><?= $ui('icon', ['name' => 'file-type-pdf', 'size' => 'sm']) ?> <?= $e($t('payroll.cert_label', ['year' => (string) $c['tax_year'], 'no' => (string) $c['cert_no']])) ?></a> <span class="text-sm text-muted"><?= $e($date((string) $c['issued_at'])) ?></span></li><?php endforeach; ?>
  </ul>
  <p class="text-sm text-muted"><?= $e($t('payroll.my_certs_help')) ?></p>
  <?php endif; ?>
</section>
<?php endif; ?>
