<?php
/** @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $d */
$tone = ['passed' => 'success', 'failed' => 'danger', 'blocked' => 'warning', 'running' => 'info'][$d['status']] ?? 'info';
?>
<?= $ui('page-header', ['title' => $t('backup.drill.title'), 'subtitle' => $date($d['started_at']),
    'crumbs' => [[$t('backup.title'), $url('/admin/backups')], [$t('backup.detail'), $url('/admin/backups/' . $d['backup_pid'])], [$t('backup.drill.title'), null]]]) ?>
<?= $ui('alert', ['tone' => $tone, 'title' => $t('backup.drill_status.' . $d['status']), 'text' => $d['error'] !== null ? $t((string) $d['error']) : $t('backup.drill.result_' . ($d['status'] === 'passed' ? 'ok' : 'bad'))]) ?>
<section class="card">
  <dl class="dl">
    <dt><?= $e($t('backup.drill.target')) ?></dt><dd class="mono"><?= $e($d['target_database']) ?></dd>
    <dt><?= $e($t('backup.drill.duration')) ?></dt><dd><?= $d['duration_ms'] !== null ? $e($t('backup.drill.took', ['s' => number_format((int) $d['duration_ms'] / 1000, 1)])) : '—' ?></dd>
    <dt><?= $e($t('backup.drill.age')) ?></dt><dd><?= $d['backup_age_minutes'] !== null ? $e($t('backup.drill.age_value', ['m' => (int) $d['backup_age_minutes']])) : '—' ?></dd>
    <dt><?= $e($t('backup.field.content')) ?></dt><dd><?= $e($t('backup.content', ['tables' => (int) $d['tables_checked'], 'rows' => number_format((int) $d['rows_checked']), 'files' => (int) $d['files_checked']])) ?></dd>
    <dt><?= $e($t('backup.drill.key')) ?></dt><dd><?= $e($t('backup.key_check.' . $d['key_check'])) ?></dd>
    <dt><?= $e($t('backup.drill.by')) ?></dt><dd><?= $e($d['performed_name'] ?? '') ?></dd>
  </dl>
</section>
<section class="card">
  <h2 class="card__title"><?= $e($t('backup.drill.checks')) ?></h2>
  <ul class="stack-sm">
  <?php foreach ($d['report'] as $c): ?>
    <li><?= $ui('badge', ['tone' => !empty($c['ok']) ? 'success' : 'danger', 'icon' => !empty($c['ok']) ? 'circle-check' : 'alert-triangle', 'label' => (string) $c['check']]) ?> <?= $e((string) ($c['detail'] ?? '')) ?></li>
  <?php endforeach; ?>
  </ul>
  <p class="text-sm text-muted"><?= $e($t('backup.drill.note')) ?></p>
</section>
