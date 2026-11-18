<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $req @var string $phrase @var bool $isRequester @var bool $canRestore @var ?string $wf
 */
?>
<?= $ui('page-header', ['title' => $t('backup.restore.title'), 'subtitle' => (string) $req['incident_ref'],
    'crumbs' => [[$t('backup.title'), $url('/admin/backups')], [$t('backup.restore.title'), null]]]) ?>
<section class="card">
  <dl class="dl">
    <dt><?= $e($t('backup.field.status')) ?></dt><dd><?= $e($t('backup.rstatus.' . $req['status'])) ?></dd>
    <dt><?= $e($t('backup.field.backup')) ?></dt><dd><a href="<?= $e($url('/admin/backups/' . $req['backup_pid'])) ?>"><?= $e($date($req['backup_started'])) ?></a></dd>
    <dt><?= $e($t('backup.drill.title')) ?></dt><dd><a href="<?= $e($url('/admin/backups/drills/' . $req['drill_pid'])) ?>"><?= $e($t('backup.drill_status.passed')) ?></a></dd>
    <dt><?= $e($t('backup.field.incident')) ?></dt><dd><?= $e($req['incident_ref']) ?></dd>
    <dt><?= $e($t('iam.field.reason')) ?></dt><dd><?= $e($req['reason']) ?></dd>
    <dt><?= $e($t('backup.restore.requested_by')) ?></dt><dd><?= $e($req['requested_name'] ?? '') ?></dd>
    <?php if ($req['approved_name'] !== null): ?><dt><?= $e($t('backup.restore.approved_by')) ?></dt><dd><?= $e($req['approved_name']) ?> · <?= $e($date($req['approved_at'])) ?></dd><?php endif; ?>
    <?php if ($req['finished_at'] !== null): ?><dt><?= $e($t('backup.restore.finished')) ?></dt><dd><?= $e($date($req['finished_at'])) ?><?= $req['duration_ms'] !== null ? ' · ' . $e($t('backup.drill.took', ['s' => number_format((int) $req['duration_ms'] / 1000, 1)])) : '' ?></dd><?php endif; ?>
    <?php if ($req['error'] !== null): ?><dt><?= $e($t('backup.restore.error')) ?></dt><dd class="text-danger"><?= $e($req['error']) ?></dd><?php endif; ?>
  </dl>
  <?php if ($wf !== null): ?><p class="text-sm"><a href="<?= $e($url('/requests/' . $wf)) ?>"><?= $e($t('finance.approval_history')) ?></a></p><?php endif; ?>
</section>
<?php if ($req['status'] === 'approved' && $isRequester && $canRestore): ?>
<section class="card" aria-labelledby="ex">
  <h2 id="ex" class="card__title"><?= $e($t('backup.restore.execute')) ?></h2>
  <?= $ui('alert', ['tone' => 'danger', 'title' => $t('backup.restore.warning_title'), 'text' => $t('backup.restore.warning_text')]) ?>
  <form method="post" action="<?= $e($url('/admin/backups/restore/' . $req['public_id'] . '/execute')) ?>" class="form-grid">
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>"><input type="hidden" name="_back" value="/admin/backups/restore/<?= $e($req['public_id']) ?>">
    <?= $ui('field', ['name' => 'phrase', 'label' => $t('backup.restore.phrase', ['phrase' => $phrase]), 'required' => true, 'full' => true, 'autocomplete' => 'off']) ?>
    <div class="row field--full"><?= $ui('button', ['label' => $t('backup.restore.execute'), 'type' => 'submit', 'variant' => 'danger', 'icon' => 'history']) ?></div>
  </form>
</section>
<?php elseif ($req['status'] === 'approved'): ?>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('backup.restore.only_requester')]) ?>
<?php elseif ($req['status'] === 'in_review'): ?>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('backup.restore.waiting')]) ?>
<?php endif; ?>
