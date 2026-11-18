<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $b @var bool $canRun @var bool $canDownload @var bool $canRestore @var ?string $openRequest @var bool $newest
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$base = '/admin/backups/' . $b['public_id'];
$back = '<input type="hidden" name="_back" value="' . $e($base) . '">';
$bst = (string) $b['status'];
$ready = in_array($bst, ['verified', 'completed'], true);
$m = $b['manifest'];
$fmtSize = static fn ($x): string => $x === null ? '—' : ($x >= 1048576 ? number_format($x / 1048576, 1) . ' MB' : number_format(max(1, (int) round($x / 1024))) . ' KB');
?>
<?= $ui('page-header', ['title' => $t('backup.detail'), 'subtitle' => $date($b['started_at']) . ' · ' . $t('backup.kind.' . $b['kind']),
    'crumbs' => [[$t('backup.title'), $url('/admin/backups')], [$date($b['started_at']), null]]]) ?>
<?php if ($bst === 'running'): ?>
  <section class="card">
    <?= $ui('alert', ['tone' => 'info', 'title' => $t('backup.running.title'), 'text' => $t('backup.running.text', ['phase' => $t('backup.phase.' . $b['phase'])])]) ?>
    <?php if ($canRun): ?>
    <form method="post" action="<?= $e($url($base . '/step')) ?>" data-continue-after="2"><?= $csrf ?>
      <p class="text-sm text-muted" aria-live="polite"><?= $e($t('backup.running.auto')) ?> <span data-countdown>2</span></p>
      <?= $ui('button', ['label' => $t('backup.running.continue'), 'type' => 'submit', 'icon' => 'refresh']) ?>
    </form>
    <?php endif; ?>
  </section>
<?php elseif ($bst === 'failed'): ?>
  <?= $ui('alert', ['tone' => 'danger', 'title' => $t('backup.failed.title'), 'text' => (string) $b['error']]) ?>
<?php endif; ?>
<div class="grid grid--2">
  <section class="card" aria-labelledby="bd">
    <h2 id="bd" class="card__title"><?= $e($t('backup.summary')) ?></h2>
    <dl class="dl">
      <dt><?= $e($t('backup.field.status')) ?></dt><dd><?php include __DIR__ . '/_status.php'; ?></dd>
      <dt><?= $e($t('backup.field.kind')) ?></dt><dd><?= $e($t('backup.kind.' . $b['kind'])) ?> · <?= $e($b['requested_name'] ?? $t('backup.by_schedule')) ?></dd>
      <dt><?= $e($t('backup.field.started')) ?></dt><dd><?= $e($date($b['started_at'])) ?><?= $b['finished_at'] !== null ? ' → ' . $e($date($b['finished_at'])) : '' ?></dd>
      <?php if ($b['consistency'] !== null): ?><dt><?= $e($t('backup.field.consistency')) ?></dt><dd><?= $e($t('backup.consistency.' . $b['consistency'])) ?></dd><?php endif; ?>
      <dt><?= $e($t('backup.field.size')) ?></dt><dd><?= $e($fmtSize($b['size_bytes'] !== null ? (int) $b['size_bytes'] : null)) ?></dd>
      <?php if ($b['db_tables'] !== null): ?><dt><?= $e($t('backup.field.content')) ?></dt><dd><?= $e($t('backup.content', ['tables' => (int) $b['db_tables'], 'rows' => number_format((int) $b['db_rows']), 'files' => (int) $b['file_count']])) ?></dd><?php endif; ?>
      <?php if ($b['sha256'] !== null): ?><dt>SHA-256</dt><dd class="mono text-sm break-all"><?= $e($b['sha256']) ?></dd><?php endif; ?>
      <?php if ($b['key_fingerprint'] !== null): ?><dt><?= $e($t('backup.field.key')) ?></dt><dd class="mono"><?= $e($b['key_fingerprint']) ?></dd><?php endif; ?>
      <dt><?= $e($t('backup.field.schema')) ?></dt><dd class="mono"><?= $e((string) ($b['schema_version'] ?? '—')) ?> · <?= $e((string) ($b['app_version'] ?? '')) ?></dd>
      <dt><?= $e($t('backup.health.offsite')) ?></dt><dd><?= $e($t('backup.offsite_status.' . $b['offsite_status'])) ?><?= $b['offsite_detail'] !== null ? ' · ' . $e($b['offsite_detail']) : '' ?></dd>
      <dt><?= $e($t('backup.field.downloads')) ?></dt><dd><?= (int) $b['download_count'] ?></dd>
      <?php if ($bst === 'deleted'): ?><dt><?= $e($t('backup.field.deleted')) ?></dt><dd><?= $e($date($b['deleted_at'])) ?> · <?= $e((string) $b['delete_reason']) ?></dd><?php endif; ?>
    </dl>
  </section>
  <section class="card" aria-labelledby="ba">
    <h2 id="ba" class="card__title"><?= $e($t('finance.journal.next')) ?></h2>
    <?php if ($ready): ?>
      <div class="stack-sm">
        <?php if ($canDownload): ?><div><?= $ui('button', ['label' => $t('backup.download'), 'href' => $url($base . '/download'), 'icon' => 'download']) ?>
          <p class="text-sm text-muted"><?= $e($t('backup.download_help')) ?></p></div><?php endif; ?>
        <?php if ($canRun): ?>
          <form method="post" action="<?= $e($url($base . '/recheck')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('backup.recheck'), 'type' => 'submit', 'icon' => 'shield-check']) ?></form>
          <form method="post" action="<?= $e($url($base . '/drill')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('backup.drill.run'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'database']) ?>
            <p class="text-sm text-muted"><?= $e($t('backup.drill.help')) ?></p></form>
        <?php endif; ?>
        <?php if ($canRestore): ?>
          <details><summary><?= $e($t('backup.restore.request')) ?></summary>
            <?php if ($openRequest !== null): ?><p><a href="<?= $e($url('/admin/backups/restore/' . $openRequest)) ?>"><?= $e($t('backup.restore.open_exists')) ?></a></p>
            <?php else: ?>
            <p class="text-sm text-muted"><?= $e($t('backup.restore.request_help')) ?></p>
            <form method="post" action="<?= $e($url($base . '/restore')) ?>" class="form-grid"><?= $csrf ?>
              <?= $ui('field', ['name' => 'incident', 'label' => $t('backup.field.incident'), 'required' => true, 'help' => $t('backup.restore.incident_help')]) ?>
              <?= $ui('field', ['name' => 'reason', 'label' => $t('iam.field.reason'), 'required' => true]) ?>
              <div class="row field--full"><?= $ui('button', ['label' => $t('backup.restore.request'), 'type' => 'submit', 'variant' => 'danger', 'icon' => 'history']) ?></div>
            </form>
            <?php endif; ?>
          </details>
        <?php endif; ?>
        <?php if ($canRun && !$newest): ?>
          <details><summary><?= $e($t('backup.delete')) ?></summary>
            <form method="post" action="<?= $e($url($base . '/delete')) ?>" class="form-grid"><?= $csrf ?><?= $back ?>
              <?= $ui('field', ['name' => 'reason', 'label' => $t('iam.field.reason'), 'required' => true, 'full' => true]) ?>
              <div class="row field--full"><?= $ui('button', ['label' => $t('backup.delete'), 'type' => 'submit', 'variant' => 'danger', 'icon' => 'trash']) ?></div>
            </form></details>
        <?php endif; ?>
      </div>
    <?php else: ?><p class="text-muted"><?= $e($t('finance.journal.no_action')) ?></p><?php endif; ?>
  </section>
</div>
<section class="card" aria-labelledby="dl">
  <h2 id="dl" class="card__title"><?= $e($t('backup.drill.list')) ?></h2>
  <ul class="stack-sm">
  <?php foreach ($b['drills'] as $d): ?>
    <li><a href="<?= $e($url('/admin/backups/drills/' . $d['public_id'])) ?>"><?= $e($date($d['started_at'])) ?></a> · <?= $e($t('backup.drill_status.' . $d['status'])) ?>
      <?php if ($d['duration_ms'] !== null): ?> · <?= $e($t('backup.drill.took', ['s' => number_format((int) $d['duration_ms'] / 1000, 1)])) ?><?php endif; ?> · <?= $e($d['performed_name'] ?? '') ?></li>
  <?php endforeach; ?>
  <?php if ($b['drills'] === []): ?><li class="text-muted"><?= $e($t('backup.drill.none')) ?></li><?php endif; ?>
  </ul>
</section>
<?php if (($m['db']['tables'] ?? []) !== []): ?>
<section class="card" aria-labelledby="mt">
  <details><summary id="mt"><?= $e($t('backup.manifest')) ?> (<?= count((array) $m['db']['tables']) ?>)</summary>
  <div class="table-wrap" role="region" aria-label="<?= $e($t('backup.manifest')) ?>" tabindex="0">
    <table class="table table--stack"><caption class="sr-only"><?= $e($t('backup.manifest')) ?></caption>
      <thead><tr><th scope="col"><?= $e($t('backup.field.table')) ?></th><th scope="col" class="num"><?= $e($t('backup.field.rows')) ?></th><th scope="col">SHA-256</th></tr></thead>
      <tbody><?php foreach ((array) $m['db']['tables'] as $tb): ?>
        <tr><td data-label="<?= $e($t('backup.field.table')) ?>" class="mono"><?= $e((string) $tb['name']) ?><?= empty($tb['data']) ? ' · ' . $e($t('backup.structure_only')) : '' ?></td>
          <td data-label="<?= $e($t('backup.field.rows')) ?>" class="num"><?= (int) $tb['rows'] ?></td><td data-label="SHA-256" class="mono text-sm"><?= $e(substr((string) $tb['data_sha256'], 0, 16)) ?>…</td></tr>
      <?php endforeach; ?></tbody>
    </table>
  </div></details>
</section>
<?php endif; ?>
