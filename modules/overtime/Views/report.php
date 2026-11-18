<?php
/** OT month report. @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var list<array<string, mixed>> $rows @var string $month @var string $group */
$loc = (string) ($v['locale'] ?? 'th');
include __DIR__ . '/_helpers.php';
$q = '?month=' . rawurlencode($month) . '&group=' . rawurlencode($group);
$cols = ['pending_minutes' => $t('overtime.col.pending'), 'approved_minutes' => $t('overtime.col.approved'), 'workday_minutes' => $t('overtime.day_type.workday'),
    'dayoff_minutes' => $t('overtime.day_type.dayoff'), 'holiday_minutes' => $t('overtime.day_type.holiday'), 'sent_minutes' => $t('overtime.col.sent')];
?>
<?= $ui('page-header', ['title' => $t('overtime.report_title'), 'subtitle' => $t('overtime.report_subtitle'),
    'actions' => $ui('button', ['label' => $t('overtime.export_xlsx'), 'href' => $url('/overtime/report' . $q . '&format=xlsx'), 'variant' => 'secondary', 'icon' => 'download'])]) ?>
<form method="get" action="<?= $e($url('/overtime/report')) ?>" class="row row--tight">
  <label class="sr-only" for="rp-m"><?= $e($t('overtime.field.month')) ?></label><input class="input" type="month" id="rp-m" name="month" value="<?= $e($month) ?>">
  <label class="sr-only" for="rp-g"><?= $e($t('overtime.field.group')) ?></label>
  <select class="select" id="rp-g" name="group"><?php foreach (['employee', 'department', 'site'] as $g): ?><option value="<?= $g ?>"<?= $g === $group ? ' selected' : '' ?>><?= $e($t('overtime.group.' . $g)) ?></option><?php endforeach; ?></select>
  <?= $ui('button', ['label' => $t('common.filter'), 'type' => 'submit', 'size' => 'sm', 'icon' => 'filter']) ?>
</form>
<section class="card stack-sm" aria-labelledby="rp-h">
  <h2 id="rp-h" class="card__title"><?= $e($t('overtime.report_for', ['month' => $month])) ?></h2>
  <?php if ($rows === []): ?><p class="text-sm text-muted"><?= $e($t('overtime.none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="rp-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('overtime.group.' . $group)) ?></th><th scope="col"><?= $e($t('overtime.col.requests')) ?></th><th scope="col"><?= $e($t('overtime.col.pending')) ?></th>
        <th scope="col"><?= $e($t('overtime.col.approved')) ?></th><th scope="col"><?= $e($t('overtime.day_type.workday')) ?></th><th scope="col"><?= $e($t('overtime.day_type.dayoff')) ?></th>
        <th scope="col"><?= $e($t('overtime.day_type.holiday')) ?></th><th scope="col"><?= $e($t('overtime.col.sent')) ?></th><th scope="col"><?= $e($t('overtime.col.cap')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $x): ?>
        <tr><th scope="row" data-label="<?= $e($t('overtime.group.' . $group)) ?>"><?= $e((string) $x['label']) ?></th>
          <td class="num" data-label="<?= $e($t('overtime.col.requests')) ?>"><?= (int) $x['requests'] ?></td>
          <?php foreach ($cols as $k => $lab): ?>
            <td class="num" data-label="<?= $e($lab) ?>"><?= $e($hm((int) $x[$k])) ?></td>
          <?php endforeach; ?>
          <td class="num" data-label="<?= $e($t('overtime.col.cap')) ?>"><?= (int) $x['cap_warnings'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
