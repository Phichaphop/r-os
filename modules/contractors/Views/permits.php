<?php
/**
 * Work permits (filter by status) and documents ending soon.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, string> $companies @var string $lePid
 * @var string $status @var array<string, mixed> $ov @var string $here @var bool $canView @var bool $canManage @var bool $canGate
 */
$opts = ['' => $t('contractors.all')];
foreach (['draft', 'review', 'approved', 'on_site', 'completed', 'rejected', 'cancelled', 'expired', 'suspended'] as $s) {
    $opts[$s] = $t('contractors.status.' . $s);
}
$dt = static fn (string $x): string => $date(substr($x, 0, 10), false) . ' ' . substr($x, 11, 5);
?>
<?= $ui('page-header', ['title' => $t('contractors.permits_title'), 'subtitle' => $t('contractors.permits_subtitle'),
    'actions' => $canManage ? $ui('button', ['label' => $t('contractors.new_permit'), 'href' => $url('/contractors/permits/new?le=' . rawurlencode($lePid)), 'variant' => 'primary', 'icon' => 'plus']) : '']) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php include __DIR__ . '/_companies.php'; ?>
<form method="get" action="<?= $e($url('/contractors/permits')) ?>" class="card row ct-add">
  <input type="hidden" name="le" value="<?= $e($lePid) ?>">
  <?= $ui('field', ['name' => 'status', 'type' => 'select', 'label' => $t('contractors.col.status'), 'value' => $status, 'options' => $opts]) ?>
  <?= $ui('button', ['label' => $t('contractors.show'), 'type' => 'submit', 'variant' => 'secondary']) ?>
</form>
<section class="card card--flush" aria-labelledby="cp-h">
  <div class="card__head"><h2 id="cp-h" class="card__title"><?= $e($t('contractors.permits_n', ['n' => (string) count($ov['permits'])])) ?></h2></div>
  <?php if ($ov['permits'] === []): ?><div class="card__pad"><?= $ui('states', ['icon' => 'clipboard-check', 'title' => $t('contractors.no_permits'), 'text' => $t('contractors.no_permits_help')]) ?></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="cp-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('contractors.col.ref')) ?></th><th scope="col"><?= $e($t('contractors.field.company')) ?></th><th scope="col"><?= $e($t('contractors.field.work')) ?></th><th scope="col"><?= $e($t('contractors.col.when')) ?></th><th scope="col"><?= $e($t('contractors.col.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($ov['permits'] as $p): $pst = (string) $p['status']; ?>
        <tr><th scope="row" data-label="<?= $e($t('contractors.col.ref')) ?>"><a href="<?= $e($url('/contractors/p/' . $p['public_id'])) ?>"><?= $e((string) ($p['ref'] ?? $t('contractors.draft'))) ?></a></th>
          <td data-label="<?= $e($t('contractors.field.company')) ?>"><?= $e((string) $p['company_name']) ?></td>
          <td data-label="<?= $e($t('contractors.field.work')) ?>"><?= $e($p['work'] . ' · ' . $p['site_code'] . ' ' . $p['area']) ?></td>
          <td data-label="<?= $e($t('contractors.col.when')) ?>"><?= $e($dt((string) $p['starts_at']) . ' – ' . $dt((string) $p['ends_at'])) ?></td>
          <td data-label="<?= $e($t('contractors.col.status')) ?>"><?php include __DIR__ . '/_pstatus.php'; ?><?= (int) $p['inside'] > 0 ? ' ' . $ui('badge', ['tone' => 'warning', 'label' => $t('contractors.inside_n', ['n' => (string) $p['inside']])]) : '' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<section class="card stack-sm" aria-labelledby="cp-x">
  <h2 id="cp-x" class="card__title"><?= $e($t('contractors.expiring', ['n' => (string) ($ov['alert_days'] ?? 30)])) ?></h2>
  <?php if ($ov['alert_days'] === null): ?><p class="text-sm text-muted"><?= $e($t('contractors.no_alert_days')) ?></p><?php endif; ?>
  <?php if ($ov['expiring'] === []): ?><p class="text-muted"><?= $e($t('contractors.none_expiring')) ?></p><?php else: ?>
  <ul class="plain-list stack-sm"><?php foreach ($ov['expiring'] as $x): ?><li><a href="<?= $e($url('/contractors/c/' . $x['company_pid'])) ?>"><?= $e((string) $x['company_name']) ?></a> · <?= $e($x['type_name'] . ($x['worker_name'] !== null ? ' (' . $x['worker_name'] . ')' : '')) ?> — <?= $e($date((string) $x['valid_until'], false)) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
</section>
