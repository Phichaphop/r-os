<?php
/**
 * Log tab: what was done to this record, by whom, when (audit events — no values of personal data are stored there).
 * Uses the variables of show.php + $log.
 */
?>
<section class="card card--flush" aria-labelledby="el-h">
  <div class="card__head"><h2 id="el-h" class="card__title"><?= $e($t('employees.section.log')) ?></h2></div>
  <div class="table-wrap" role="region" aria-labelledby="el-h" tabindex="0">
    <table class="table table--stack table--compact">
      <thead><tr><th scope="col"><?= $e($t('employees.field.when')) ?></th><th scope="col"><?= $e($t('employees.field.what')) ?></th><th scope="col"><?= $e($t('employees.field.who')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($log as $l): ?>
        <?php $key = 'employees.log.' . str_replace('.', '_', (string) $l['action']); $txt = $t($key); ?>
        <tr>
          <td data-label="<?= $e($t('employees.field.when')) ?>"><?= $e($date((string) $l['occurred_at'])) ?></td>
          <td data-label="<?= $e($t('employees.field.what')) ?>"><?= $e($txt === $key ? (string) $l['action'] : $txt) ?><?= $l['outcome'] !== 'success' ? ' · ' . $e($t('employees.log_outcome.' . $l['outcome'])) : '' ?></td>
          <td data-label="<?= $e($t('employees.field.who')) ?>"><?= $e((string) ($l['display_name'] ?? $t('employees.system'))) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($log === []): ?><tr><td colspan="3"><?= $e($t('employees.none_yet')) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
