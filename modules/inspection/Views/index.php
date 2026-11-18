<?php
/**
 * My inspection rounds (overdue first, then by day) and the corrective actions given to me.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var list<array<string, mixed>> $rounds @var list<array<string, mixed>> $actions @var string $today @var bool $canManage
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$here = '/inspection';
?>
<?= $ui('page-header', ['title' => $t('inspection.title'), 'subtitle' => $t('inspection.subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<section class="card card--flush" aria-labelledby="ii-r">
  <div class="card__head"><h2 id="ii-r" class="card__title"><?= $e($t('inspection.my_rounds')) ?></h2></div>
  <?php if ($rounds === []): ?><div class="card__pad"><?= $ui('states', ['icon' => 'clipboard-check', 'title' => $t('inspection.no_rounds'), 'text' => $t('inspection.no_rounds_help')]) ?></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="ii-r" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('inspection.col.point')) ?></th><th scope="col"><?= $e($t('inspection.field.location')) ?></th><th scope="col"><?= $e($t('inspection.col.due_date')) ?></th><th scope="col"><?= $e($t('inspection.col.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rounds as $row): ?>
        <tr><th scope="row" data-label="<?= $e($t('inspection.col.point')) ?>"><a href="<?= $e($url('/inspection/r/' . $row['public_id'])) ?>"><?= $e($row['code'] . ' · ' . $row['name']) ?></a></th>
          <td data-label="<?= $e($t('inspection.field.location')) ?>"><?= $e(trim(($row['site_code'] ?? '') . ' ' . ($row['location'] ?? ''))) ?></td>
          <td data-label="<?= $e($t('inspection.col.due_date')) ?>"><?= $e($date((string) $row['due_date'], false)) ?><?= (string) $row['due_date'] > $today ? ' ' . $ui('badge', ['tone' => 'neutral', 'label' => $t('inspection.upcoming')]) : '' ?></td>
          <td data-label="<?= $e($t('inspection.col.status')) ?>"><?php include __DIR__ . '/_status.php'; ?><?= (int) $row['revision'] > 1 ? ' ' . $ui('badge', ['tone' => 'warning', 'label' => $t('inspection.returned_badge')]) : '' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php if ($actions !== []): ?>
<section class="card stack-sm" aria-labelledby="ii-a">
  <h2 id="ii-a" class="card__title"><?= $e($t('inspection.my_actions')) ?></h2>
  <ul class="plain-list stack">
    <?php foreach ($actions as $a): ?>
    <li class="in-item stack-sm">
      <p><strong><?= $e($a['code'] . ' · ' . $a['name']) ?></strong> · <?= $e($t('inspection.action_due', ['d' => $date((string) $a['due_date'], false)])) ?></p>
      <p><?= $e((string) $a['description']) ?> · <a href="<?= $e($url('/inspection/r/' . $a['round_pid'])) ?>"><?= $e($t('inspection.open_round')) ?></a></p>
      <form method="post" action="<?= $e($url('/inspection/a/' . $a['public_id'] . '/done')) ?>" class="stack-sm" novalidate><?= $csrf ?><input type="hidden" name="back" value="mine">
        <?= $ui('field', ['name' => 'done_note', 'id' => 'f-done-' . $a['public_id'], 'label' => $t('inspection.field.done_note'), 'required' => true]) ?>
        <div class="row"><?= $ui('button', ['label' => $t('inspection.mark_done'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'circle-check']) ?></div>
      </form>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
