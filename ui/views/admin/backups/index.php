<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $health @var array{rows: list<array<string, mixed>>, total: int} $list @var int $page @var int $per @var bool $canRun @var bool $offsite
 * @var string $drillDb @var bool $includeDefault @var list<array<string, mixed>> $requests @var array<string, int> $settings @var string $scheduler
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$fmtSize = static fn ($b): string => $b === null ? '—' : ($b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : number_format(max(1, (int) round($b / 1024))) . ' KB');
$last = $health['last'];
$drill = $health['last_drill'];
?>
<?= $ui('page-header', ['title' => $t('backup.title'), 'subtitle' => $t('backup.subtitle')]) ?>
<?php if ($health['overdue']): ?>
  <?= $ui('alert', ['tone' => 'danger', 'title' => $t('backup.health.overdue'), 'text' => $t('backup.health.overdue_text', ['hours' => $health['max_age_hours']])]) ?>
<?php endif; ?>
<section class="card">
  <div class="grid grid--4">
    <div><div class="stat__label"><?= $e($t('backup.health.last')) ?></div><div class="stat__value stat__value--sm"><?= $last !== null ? $e($date($last['finished_at'])) : $e($t('backup.health.never')) ?></div>
      <?php if ($health['age_hours'] !== null): ?><div class="stat__meta"><?= $e($t('backup.health.age', ['hours' => number_format((float) $health['age_hours'], 1)])) ?></div><?php endif; ?></div>
    <div><div class="stat__label"><?= $e($t('backup.health.drill')) ?></div><div class="stat__value stat__value--sm"><?= $drill !== null ? $e($t('backup.drill_status.' . $drill['status'])) : $e($t('backup.health.no_drill')) ?></div>
      <?php if ($drill !== null): ?><div class="stat__meta"><a href="<?= $e($url('/admin/backups/drills/' . $drill['public_id'])) ?>"><?= $e($date($drill['finished_at'] ?? $drill['started_at'])) ?></a></div><?php endif; ?></div>
    <div><div class="stat__label"><?= $e($t('backup.health.failed')) ?></div><div class="stat__value<?= $health['failed_recent'] > 0 ? ' text-danger' : '' ?>"><?= (int) $health['failed_recent'] ?></div></div>
    <div><div class="stat__label"><?= $e($t('backup.health.offsite')) ?></div><div class="stat__value stat__value--sm"><?= $e($t($offsite ? 'backup.offsite.on' : 'backup.offsite.off')) ?></div>
      <div class="stat__meta mono">BACKUP_OFFSITE_PATH</div></div>
  </div>
  <p class="text-sm text-muted"><?= $e($t('backup.schedule_note', ['every' => $settings['interval_hours'], 'alert' => $settings['max_age_hours'], 'days' => $settings['keep_days'], 'months' => $settings['keep_monthly']])) ?>
    <?= $e($t($scheduler === 'cron' ? 'backup.scheduler.cron' : 'backup.scheduler.piggyback')) ?> <a href="<?= $e($url('/admin/config?category=backup')) ?>"><?= $e($t('backup.settings_link')) ?></a></p>
</section>
<?php if ($canRun): ?>
<div class="grid grid--2">
  <section class="card" aria-labelledby="run-h">
    <h2 id="run-h" class="card__title"><?= $e($t('backup.run.title')) ?></h2>
    <p class="text-sm text-muted"><?= $e($t('backup.run.help')) ?></p>
    <form method="post" action="<?= $e($url('/admin/backups/run')) ?>" class="stack-sm"><?= $csrf ?>
      <label class="choice"><input type="checkbox" name="include_files" value="1"<?= $includeDefault ? ' checked' : '' ?>> <?= $e($t('backup.run.include_files')) ?></label>
      <div class="row"><?= $ui('button', ['label' => $t('backup.run.button'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'database', 'disabled' => $health['running'] !== null]) ?></div>
    </form>
  </section>
  <section class="card" aria-labelledby="up-h">
    <h2 id="up-h" class="card__title"><?= $e($t('backup.upload.title')) ?></h2>
    <p class="text-sm text-muted"><?= $e($t('backup.upload.help')) ?></p>
    <form method="post" action="<?= $e($url('/admin/backups/upload')) ?>" enctype="multipart/form-data" class="stack-sm"><?= $csrf ?>
      <input type="hidden" name="_back" value="/admin/backups">
      <?= $ui('field', ['name' => 'file', 'label' => $t('backup.upload.file'), 'type' => 'file', 'required' => true, 'accept' => '.bluebak']) ?>
      <div class="row"><?= $ui('button', ['label' => $t('backup.upload.button'), 'type' => 'submit', 'icon' => 'upload']) ?></div>
    </form>
  </section>
</div>
<?php endif; ?>
<section class="card" aria-labelledby="list-h">
  <h2 id="list-h" class="card__title"><?= $e($t('backup.list')) ?></h2>
  <div class="table-wrap" role="region" aria-label="<?= $e($t('backup.list')) ?>" tabindex="0">
    <table class="table table--stack">
      <caption class="sr-only"><?= $e($t('backup.list')) ?></caption>
      <thead><tr><th scope="col"><?= $e($t('backup.field.started')) ?></th><th scope="col"><?= $e($t('backup.field.kind')) ?></th><th scope="col"><?= $e($t('backup.field.status')) ?></th>
        <th scope="col" class="num"><?= $e($t('backup.field.size')) ?></th><th scope="col"><?= $e($t('backup.field.content')) ?></th><th scope="col"><?= $e($t('backup.field.by')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($list['rows'] as $b): $bst = (string) $b['status']; ?>
        <tr>
          <td data-label="<?= $e($t('backup.field.started')) ?>"><a href="<?= $e($url('/admin/backups/' . $b['public_id'])) ?>"><?= $e($date($b['started_at'])) ?></a></td>
          <td data-label="<?= $e($t('backup.field.kind')) ?>"><?= $e($t('backup.kind.' . $b['kind'])) ?></td>
          <td data-label="<?= $e($t('backup.field.status')) ?>"><?php include __DIR__ . '/_status.php'; ?><?php if ($b['consistency'] === 'stepwise'): ?> <span class="text-sm text-muted"><?= $e($t('backup.stepwise')) ?></span><?php endif; ?></td>
          <td data-label="<?= $e($t('backup.field.size')) ?>" class="num"><?= $e($fmtSize($b['size_bytes'] !== null ? (int) $b['size_bytes'] : null)) ?></td>
          <td data-label="<?= $e($t('backup.field.content')) ?>"><?= $b['db_tables'] !== null ? $e($t('backup.content', ['tables' => (int) $b['db_tables'], 'rows' => number_format((int) $b['db_rows']), 'files' => (int) $b['file_count']])) : '—' ?></td>
          <td data-label="<?= $e($t('backup.field.by')) ?>"><?= $e($b['requested_name'] ?? $t('backup.by_schedule')) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($list['rows'] === []): ?><tr><td colspan="6"><?= $ui('states', ['icon' => 'database', 'title' => $t('backup.empty'), 'text' => $t('backup.empty_text')]) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= $ui('pagination', ['path' => '/admin/backups', 'query' => [], 'page' => $page, 'per' => $per, 'total' => $list['total']]) ?>
</section>
<?php if ($requests !== []): ?>
<section class="card" aria-labelledby="rq-h">
  <h2 id="rq-h" class="card__title"><?= $e($t('backup.restore.list')) ?></h2>
  <ul class="stack-sm">
  <?php foreach ($requests as $q): ?>
    <li><a href="<?= $e($url('/admin/backups/restore/' . $q['public_id'])) ?>"><?= $e($date($q['created_at'])) ?> · <?= $e($q['incident_ref']) ?></a> · <?= $e($t('backup.rstatus.' . $q['status'])) ?> · <?= $e($q['requested_name'] ?? '') ?></li>
  <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<section class="card" aria-labelledby="dr-h">
  <h2 id="dr-h" class="card__title"><?= $e($t('backup.howto.title')) ?></h2>
  <ol class="stack-sm">
    <li><?= $e($t('backup.howto.1')) ?></li><li><?= $e($t('backup.howto.2', ['db' => $drillDb])) ?></li><li><?= $e($t('backup.howto.3')) ?></li><li><?= $e($t('backup.howto.4')) ?></li>
  </ol>
</section>
