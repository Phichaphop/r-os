<?php
/**
 * HR queue of certificate requests of a company + service rating.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $rows @var string $status
 * @var array{count: int, avg: float|null} $ratings @var bool $hasTypes
 */
$actions = $ui('button', ['label' => $t('certs.types_title'), 'href' => $url('/certs/admin/types?le=' . rawurlencode($lePid)), 'variant' => 'secondary', 'icon' => 'settings']);
?>
<?= $ui('page-header', ['title' => $t('certs.admin_title'), 'subtitle' => $t('certs.admin_subtitle'), 'actions' => $actions]) ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('certs.pick_company')) ?>">
  <?php foreach ($companies as $pid => $code): ?><a href="<?= $e($url('/certs/admin?le=' . rawurlencode($pid))) ?>"<?= $pid === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<?php if (!$hasTypes): ?><?= $ui('alert', ['tone' => 'warning', 'text' => $t('certs.no_types_admin')]) ?><?php endif; ?>
<p class="text-sm"><?= $ui('icon', ['name' => 'circle-check', 'size' => 'sm']) ?> <?= $e($ratings['avg'] !== null ? $t('certs.rating_avg', ['avg' => (string) $ratings['avg'], 'n' => (string) $ratings['count']]) : $t('certs.rating_none')) ?></p>
<nav class="segmented-links" aria-label="<?= $e($t('certs.filter')) ?>">
  <?php foreach (['open', 'received', 'cancelled', 'all'] as $s): ?><a href="<?= $e($url('/certs/admin?le=' . rawurlencode($lePid) . '&status=' . $s)) ?>"<?= $s === $status ? ' aria-current="page"' : '' ?>><?= $e($t('certs.filter.' . $s)) ?></a><?php endforeach; ?>
</nav>
<section class="card stack-sm" aria-labelledby="ca-l">
  <h2 id="ca-l" class="card__title"><?= $e($t('certs.queue', ['n' => (string) count($rows)])) ?></h2>
  <?php if ($rows === []): ?><p class="text-sm text-muted"><?= $e($t('certs.none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="ca-l" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('certs.col.no')) ?></th><th scope="col"><?= $e($t('certs.col.employee')) ?></th><th scope="col"><?= $e($t('certs.field.type')) ?></th><th scope="col"><?= $e($t('certs.col.status')) ?></th><th scope="col"><?= $e($t('certs.col.when')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $x): $st = (string) $x['status']; ?>
        <tr><th scope="row" data-label="<?= $e($t('certs.col.no')) ?>"><a href="<?= $e($url('/certs/admin/r/' . $x['public_id'])) ?>"><?= $e((string) $x['request_no']) ?></a></th>
          <td data-label="<?= $e($t('certs.col.employee')) ?>"><?= $e($x['employee_code'] . ' ' . $x['emp_name']) ?></td>
          <td data-label="<?= $e($t('certs.field.type')) ?>"><?= $e((string) $x['name_th']) ?> · <?= $e(strtoupper((string) $x['language'])) ?> × <?= (int) $x['copies'] ?></td>
          <td data-label="<?= $e($t('certs.col.status')) ?>"><?php include __DIR__ . '/_status.php'; ?><?= $x['rating'] !== null ? ' ' . $e($t('certs.rated_short', ['n' => (string) $x['rating']])) : '' ?></td>
          <td data-label="<?= $e($t('certs.col.when')) ?>"><?= $e($date((string) $x['created_at'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
