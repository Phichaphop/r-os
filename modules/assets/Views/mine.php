<?php
/**
 * What I hold now (acknowledge receipt) and what I returned.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var list<array<string, mixed>> $rows @var string $here @var bool $canView @var bool $canManage
 */
$dt = static fn (?string $x): string => $x === null ? '—' : $date(substr($x, 0, 10), false) . ' ' . substr($x, 11, 5);
$now = array_values(array_filter($rows, static fn (array $r): bool => $r['returned_at'] === null));
$past = array_values(array_filter($rows, static fn (array $r): bool => $r['returned_at'] !== null));
?>
<?= $ui('page-header', ['title' => $t('assets.mine_title'), 'subtitle' => $t('assets.mine_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<section class="card card--flush" aria-labelledby="am-h">
  <div class="card__head"><h2 id="am-h" class="card__title"><?= $e($t('assets.holding', ['n' => (string) count($now)])) ?></h2></div>
  <?php if ($now === []): ?><div class="card__pad"><?= $ui('states', ['icon' => 'briefcase', 'title' => $t('assets.holding_none'), 'text' => $t('assets.holding_none_help')]) ?></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="am-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('assets.field.code')) ?></th><th scope="col"><?= $e($t('assets.field.name')) ?></th><th scope="col"><?= $e($t('assets.col.assigned')) ?></th><th scope="col"><?= $e($t('assets.col.acknowledged')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($now as $r): ?>
        <tr><th scope="row" data-label="<?= $e($t('assets.field.code')) ?>"><a href="<?= $e($url('/assets/a/' . $r['public_id'])) ?>"><?= $e((string) $r['code']) ?></a></th>
          <td data-label="<?= $e($t('assets.field.name')) ?>"><?= $e((string) $r['name']) ?><?= $r['serial_no'] !== null ? ' <span class="text-sm text-muted">' . $e('S/N ' . $r['serial_no']) . '</span>' : '' ?></td>
          <td data-label="<?= $e($t('assets.col.assigned')) ?>"><?= $e($dt((string) $r['assigned_at'])) ?></td>
          <td data-label="<?= $e($t('assets.col.acknowledged')) ?>"><?= $r['acknowledged_at'] === null ? $ui('badge', ['tone' => 'warning', 'label' => $t('assets.not_acknowledged')]) . ' <a href="' . $e($url('/assets/a/' . $r['public_id'])) . '">' . $e($t('assets.ack_now')) . '</a>' : $e($dt((string) $r['acknowledged_at'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php if ($past !== []): ?>
<section class="card card--flush" aria-labelledby="am-p">
  <div class="card__head"><h2 id="am-p" class="card__title"><?= $e($t('assets.returned_list')) ?></h2></div>
  <div class="table-wrap" role="region" aria-labelledby="am-p" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('assets.field.code')) ?></th><th scope="col"><?= $e($t('assets.field.name')) ?></th><th scope="col"><?= $e($t('assets.col.assigned')) ?></th><th scope="col"><?= $e($t('assets.col.returned')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($past as $r): ?>
        <tr><th scope="row" data-label="<?= $e($t('assets.field.code')) ?>"><?= $e((string) $r['code']) ?></th><td data-label="<?= $e($t('assets.field.name')) ?>"><?= $e((string) $r['name']) ?></td>
          <td data-label="<?= $e($t('assets.col.assigned')) ?>"><?= $e($dt((string) $r['assigned_at'])) ?></td><td data-label="<?= $e($t('assets.col.returned')) ?>"><?= $e($dt((string) $r['returned_at'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>
