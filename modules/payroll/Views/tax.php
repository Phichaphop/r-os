<?php
/**
 * Payroll tax of a company and year: allowance declarations to check · year totals and certificates (issue, reissue).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var int $year @var list<array<string, mixed>> $declarations @var list<array<string, mixed>> $types
 * @var list<array<string, mixed>> $candidates @var list<array<string, mixed>> $certs @var array<string, mixed>|null $payer @var int $me
 * @var bool $canPrepare @var bool $canApprove
 */
use Blue\Core\Support\Money;

$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$hidden = '<input type="hidden" name="le" value="' . $e($lePid) . '"><input type="hidden" name="tax_year" value="' . (int) $year . '">';
$m = static fn (mixed $x): string => Money::format(Money::fromDb($x));
$here = '/payroll/tax';
$names = array_column($types, 'name', 'code');
$tones = ['pending' => 'info', 'approved' => 'success', 'rejected' => 'danger'];
$missing = count(array_filter($candidates, static fn (array $c): bool => $c['cert'] === null && $c['has_id']));
?>
<?= $ui('page-header', ['title' => $t('payroll.tax_title'), 'subtitle' => $t('payroll.tax_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<nav class="segmented-links" aria-label="<?= $e($t('payroll.field.tax_year')) ?>">
  <?php foreach ([$year - 1, $year, $year + 1] as $y): ?><a href="<?= $e($url('/payroll/tax?le=' . rawurlencode($lePid) . '&year=' . $y)) ?>"<?= $y === $year ? ' aria-current="page"' : '' ?>><?= (int) $y ?></a><?php endforeach; ?>
</nav>
<section class="card stack-sm" aria-labelledby="tx-d">
  <h2 id="tx-d" class="card__title"><?= $e($t('payroll.declarations')) ?></h2>
  <?php if ($types === []): ?><p class="text-sm text-muted"><?= $e($t('payroll.error.no_allowance_types')) ?></p><?php endif; ?>
  <?php if ($declarations === []): ?><p class="text-sm text-muted"><?= $e($t('payroll.declarations_none')) ?></p><?php else: ?>
  <ul class="plain-list">
    <?php foreach ($declarations as $d): $items = (array) json_decode((string) $d['items_json'], true); ?>
    <li class="stack-sm"><div><?= $ui('badge', ['tone' => $tones[$d['status']] ?? 'neutral', 'label' => $t('payroll.dstatus.' . $d['status'])]) ?> <strong><?= $e($d['employee_code'] . ' · ' . $d['name_th']) ?></strong> <span class="text-sm text-muted"><?= $e($date((string) $d['created_at'])) ?></span></div>
      <div class="text-sm"><?= $e(implode(' · ', array_map(static fn (array $i): string => ($names[$i['code']] ?? $i['code']) . ' ' . $i['value'], $items)) ?: $t('payroll.declare_nothing')) ?></div>
      <?php if ($d['status'] === 'pending' && $canPrepare && (int) $d['submitted_by'] !== $me): ?>
      <div class="row">
        <form method="post" action="<?= $e($url('/payroll/tax/declarations/' . $d['public_id'] . '/approve')) ?>"><?= $csrf . $hidden ?><?= $ui('button', ['label' => $t('payroll.declare_ok'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></form>
        <form method="post" action="<?= $e($url('/payroll/tax/declarations/' . $d['public_id'] . '/reject')) ?>" class="row" novalidate><?= $csrf . $hidden ?>
          <?= $ui('field', ['name' => 'decision_note', 'id' => 'f-dn-' . $d['public_id'], 'label' => $t('payroll.field.reject_note')]) ?>
          <?= $ui('button', ['label' => $t('payroll.declare_no'), 'type' => 'submit', 'variant' => 'secondary']) ?></form>
      </div>
      <?php elseif ($d['decided_name'] !== null): ?><div class="text-sm text-muted"><?= $e($t('payroll.decided_by', ['name' => (string) $d['decided_name']])) ?><?= $d['decision_note'] !== null ? ' — ' . $e((string) $d['decision_note']) : '' ?></div><?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<section class="card stack-sm" id="certs" aria-labelledby="tx-c">
  <h2 id="tx-c" class="card__title"><?= $e($t('payroll.certs_title', ['year' => (string) $year])) ?></h2>
  <?= $ui('alert', ['tone' => 'warning', 'text' => $t('payroll.cert_form_notice')]) ?>
  <?php if ($payer === null): ?><p class="text-sm"><?= $ui('badge', ['tone' => 'warning', 'label' => $t('payroll.error.no_payer')]) ?></p><?php endif; ?>
  <?php if ($candidates === []): ?><p class="text-sm text-muted"><?= $e($t('payroll.certs_none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="tx-c" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('payroll.field.employee')) ?></th><th scope="col"><?= $e($t('payroll.ytd.taxable')) ?></th><th scope="col"><?= $e($t('payroll.ytd.wht')) ?></th><th scope="col"><?= $e($t('payroll.ytd.sso')) ?></th><th scope="col"><?= $e($t('payroll.cert')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($candidates as $c): ?>
        <tr><th scope="row" data-label="<?= $e($t('payroll.field.employee')) ?>"><?= $e($c['employee_code'] . ' · ' . $c['name_th']) ?></th>
          <td data-label="<?= $e($t('payroll.ytd.taxable')) ?>"><?= $e($m($c['income'])) ?></td>
          <td data-label="<?= $e($t('payroll.ytd.wht')) ?>"><?= $e($m($c['wht'])) ?></td>
          <td data-label="<?= $e($t('payroll.ytd.sso')) ?>"><?= $e($m($c['sso'])) ?></td>
          <td data-label="<?= $e($t('payroll.cert')) ?>">
            <?php if ($c['cert'] !== null): ?>
              <a href="<?= $e($url('/payroll/tax/certificates/' . $c['cert']['public_id'] . '/pdf')) ?>"><?= $e((string) $c['cert']['cert_no']) ?></a>
              <?php if ($c['outdated']): ?><?= $ui('badge', ['tone' => 'warning', 'label' => $t('payroll.cert_outdated')]) ?>
                <?php if ($canApprove): ?><form method="post" action="<?= $e($url('/payroll/tax/certificates/' . $c['cert']['public_id'] . '/reissue')) ?>" class="row" novalidate><?= $csrf . $hidden ?>
                  <?= $ui('field', ['name' => 'reason', 'id' => 'f-ri-' . $c['cert']['public_id'], 'label' => $t('payroll.field.reissue_reason')]) ?>
                  <?= $ui('button', ['label' => $t('payroll.reissue'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'refresh']) ?></form><?php endif; ?>
              <?php endif; ?>
            <?php elseif (!$c['has_id']): ?><?= $ui('badge', ['tone' => 'warning', 'label' => $t('payroll.cert_no_id')]) ?>
            <?php else: ?><span class="text-muted"><?= $e($t('payroll.cert_not_yet')) ?></span><?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($canApprove && $missing > 0): ?>
    <form method="post" action="<?= $e($url('/payroll/tax/issue')) ?>"><?= $csrf . $hidden ?><?= $ui('button', ['label' => $t('payroll.issue_certs', ['n' => (string) $missing]), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'file-type-pdf', 'disabled' => $payer === null]) ?></form>
  <?php endif; ?>
  <?php endif; ?>
  <?php $voided = array_filter($certs, static fn (array $c): bool => $c['status'] === 'voided'); ?>
  <?php if ($voided !== []): ?><details><summary><?= $e($t('payroll.certs_voided')) ?></summary><ul class="plain-list text-sm"><?php foreach ($voided as $c): ?><li><?= $e($c['cert_no'] . ' · ' . $c['employee_code']) ?> · <?= $e($date((string) $c['voided_at'])) ?></li><?php endforeach; ?></ul></details><?php endif; ?>
</section>
