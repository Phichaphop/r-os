<?php
/** Lines waiting to be certified. @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var list<array<string, mixed>> $rows */
$loc = (string) ($v['locale'] ?? 'th');
include __DIR__ . '/_helpers.php';
?>
<?= $ui('page-header', ['title' => $t('overtime.verify_title'), 'subtitle' => $t('overtime.verify_subtitle'), 'crumbs' => [[$t('overtime.title'), $url('/overtime')], [$t('overtime.verify_title'), null]]]) ?>
<section class="card stack-sm" aria-labelledby="vl-h">
  <h2 id="vl-h" class="card__title"><?= $e($t('overtime.verify_list', ['n' => (string) count($rows)])) ?></h2>
  <?php if ($rows === []): ?><p class="text-sm text-muted"><?= $e($t('overtime.verify_none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="vl-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('overtime.field.employee')) ?></th><th scope="col"><?= $e($t('overtime.field.when')) ?></th><th scope="col"><?= $e($t('overtime.field.day_type')) ?></th>
        <th scope="col"><?= $e($t('overtime.field.requested')) ?></th><th scope="col"><?= $e($t('overtime.field.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $q): ?>
        <tr><td data-label="<?= $e($t('overtime.field.employee')) ?>"><?= $e($q['employee_code'] . ' · ' . $q['name_th']) ?></td>
          <td data-label="<?= $e($t('overtime.field.when')) ?>"><a href="<?= $e($url('/overtime/r/' . $q['public_id'])) ?>"><?= $e($otWhen($q)) ?></a></td>
          <td data-label="<?= $e($t('overtime.field.day_type')) ?>"><?= $e($t('overtime.day_type.' . $q['day_type'])) ?></td>
          <td data-label="<?= $e($t('overtime.field.requested')) ?>" class="num"><?= $e($hm((int) $q['minutes_requested'])) ?></td>
          <td data-label="<?= $e($t('overtime.field.status')) ?>"><?= $otStatus((string) $q['status']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
