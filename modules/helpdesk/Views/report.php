<?php
/**
 * A month of tickets (by opening): per category and per technician.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var string $month @var bool $staff @var bool $canManage
 * @var array{categories: list<array<string, mixed>>, agents: list<array<string, mixed>>} $report
 */
$here = '/helpdesk/report';
$q = '?le=' . rawurlencode($lePid) . '&month=' . rawurlencode($month);
$tables = ['categories' => 'helpdesk.field.category', 'agents' => 'helpdesk.col.assignee'];
?>
<?= $ui('page-header', ['title' => $t('helpdesk.report_title'), 'subtitle' => $t('helpdesk.report_subtitle'),
    'actions' => $ui('button', ['label' => $t('helpdesk.export'), 'href' => $url('/helpdesk/report/export' . $q), 'variant' => 'secondary', 'icon' => 'download'])]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<form method="get" action="<?= $e($url('/helpdesk/report')) ?>" class="card row">
  <?php if (count($companies) > 1): ?><?= $ui('field', ['name' => 'le', 'type' => 'select', 'label' => $t('helpdesk.pick_company'), 'value' => $lePid, 'options' => $companies]) ?><?php else: ?><input type="hidden" name="le" value="<?= $e($lePid) ?>"><?php endif; ?>
  <?= $ui('field', ['name' => 'month', 'type' => 'month', 'label' => $t('helpdesk.field.month'), 'value' => $month]) ?>
  <?= $ui('button', ['label' => $t('helpdesk.show'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'filter']) ?>
</form>
<?php foreach ($tables as $key => $label): ?>
<section class="card card--flush" aria-labelledby="hr-<?= $e($key) ?>">
  <div class="card__head"><h2 id="hr-<?= $e($key) ?>" class="card__title"><?= $e($t('helpdesk.by.' . $key)) ?></h2></div>
  <?php if ($report[$key] === []): ?><p class="card__pad text-sm text-muted"><?= $e($t('helpdesk.queue_empty')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="hr-<?= $e($key) ?>" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t($label)) ?></th><th scope="col" class="num"><?= $e($t('helpdesk.col.tickets')) ?></th><th scope="col" class="num"><?= $e($t('helpdesk.col.resolved')) ?></th><th scope="col" class="num"><?= $e($t('helpdesk.col.avg_hours')) ?></th>
        <th scope="col" class="num"><?= $e($t('helpdesk.col.within_sla')) ?></th><th scope="col" class="num"><?= $e($t('helpdesk.col.avg_rating')) ?></th><th scope="col" class="num"><?= $e($t('helpdesk.col.reopened')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($report[$key] as $r): ?>
        <tr><th scope="row" data-label="<?= $e($t($label)) ?>"><?= $e((string) $r['name']) ?></th>
          <td class="num" data-label="<?= $e($t('helpdesk.col.tickets')) ?>"><?= (int) $r['tickets'] ?></td>
          <td class="num" data-label="<?= $e($t('helpdesk.col.resolved')) ?>"><?= (int) $r['resolved'] ?></td>
          <td class="num" data-label="<?= $e($t('helpdesk.col.avg_hours')) ?>"><?= $e((string) ($r['avg_hours'] ?? '—')) ?></td>
          <td class="num" data-label="<?= $e($t('helpdesk.col.within_sla')) ?>"><?= (int) $r['with_sla'] > 0 ? $e($r['within_sla'] . '/' . $r['with_sla']) : '—' ?></td>
          <td class="num" data-label="<?= $e($t('helpdesk.col.avg_rating')) ?>"><?= $e((string) ($r['avg_rating'] ?? '—')) ?></td>
          <td class="num" data-label="<?= $e($t('helpdesk.col.reopened')) ?>"><?= (int) $r['reopened'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php endforeach; ?>
<p class="text-sm text-muted"><?= $e($t('helpdesk.report_note')) ?></p>
