<?php
/**
 * Manager: signing requests of a company, by status.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date
 * @var array<string, string> $companies @var string $lePid @var string $status @var list<array<string, mixed>> $rows
 */
include __DIR__ . '/_status.php';
?>
<?= $ui('page-header', ['title' => $t('esign.admin_title'), 'subtitle' => $t('esign.admin_subtitle'), 'crumbs' => [[$t('esign.title'), $url('/esign')], [$t('esign.admin_title'), null]]]) ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('esign.field.company')) ?>">
  <?php foreach ($companies as $pid => $code): ?><a href="<?= $e($url('/esign/admin?le=' . rawurlencode($pid))) ?>"<?= $pid === $lePid ? ' aria-current="page"' : '' ?>><?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<nav class="segmented-links" aria-label="<?= $e($t('esign.col.status')) ?>">
  <?php foreach (['' => 'esign.all', 'sent' => 'esign.status.sent', 'partial' => 'esign.status.partial', 'completed' => 'esign.status.completed', 'declined' => 'esign.status.declined', 'expired' => 'esign.status.expired', 'cancelled' => 'esign.status.cancelled', 'voided' => 'esign.status.voided'] as $k => $label): ?>
    <a href="<?= $e($url('/esign/admin?le=' . rawurlencode($lePid) . ($k !== '' ? '&status=' . $k : ''))) ?>"<?= $k === $status ? ' aria-current="page"' : '' ?>><?= $e($t($label)) ?></a>
  <?php endforeach; ?>
</nav>
<section class="card stack-sm" aria-labelledby="ea-l">
  <h2 id="ea-l" class="card__title"><?= $e($t('esign.requests')) ?></h2>
  <?php if ($rows === []): ?><p class="text-sm text-muted"><?= $e($t('esign.none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="ea-l" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('esign.field.title')) ?></th><th scope="col"><?= $e($t('esign.sender')) ?></th><th scope="col"><?= $e($t('esign.col.progress')) ?></th><th scope="col"><?= $e($t('esign.field.expires_on')) ?></th><th scope="col"><?= $e($t('esign.col.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $x): ?>
        <tr><th scope="row" data-label="<?= $e($t('esign.field.title')) ?>"><a href="<?= $e($url('/esign/r/' . $x['public_id'])) ?>"><?= $e(($x['request_no'] ?? '') . ' · ' . $x['title']) ?></a></th>
          <td data-label="<?= $e($t('esign.sender')) ?>"><?= $e((string) ($x['sender_name'] ?? '—')) ?></td>
          <td data-label="<?= $e($t('esign.col.progress')) ?>"><?= $e($t('esign.signed_of', ['n' => (string) $x['n_signed'], 'total' => (string) $x['n_signers']])) ?></td>
          <td data-label="<?= $e($t('esign.field.expires_on')) ?>"><?= $e($date((string) $x['expires_on'], false)) ?></td>
          <td data-label="<?= $e($t('esign.col.status')) ?>"><?= $stBadge((string) $x['status']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
