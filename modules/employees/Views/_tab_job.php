<?php
/**
 * Employment tab: effective-dated employment history and status history (M09 ★ timeline). Read-only — changes are
 * asked on the "Changes" tab and approved by another HR person. Uses the variables of show.php + $statusHistory.
 */
$label = static fn (string $type, mixed $id): string => $id === null ? '—' : ($allOrg[$type][(int) $id] ?? '#' . (int) $id);
?>
<section class="card card--flush" aria-labelledby="es-hist">
  <div class="card__head"><h2 id="es-hist" class="card__title"><?= $e($t('employees.section.history')) ?></h2></div>
  <div class="table-wrap" role="region" aria-labelledby="es-hist" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('employees.field.effective')) ?></th><th scope="col"><?= $e($t('employees.field.company')) ?></th><th scope="col"><?= $e($t('employees.field.department')) ?></th>
        <th scope="col"><?= $e($t('employees.field.position')) ?></th><th scope="col"><?= $e($t('employees.field.manager')) ?></th><th scope="col"><?= $e($t('employees.field.group')) ?></th>
        <th scope="col"><?= $e($t('employees.field.reason')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($emp['history'] as $h): ?>
        <tr<?= $h['effective_from'] > $today ? ' class="is-future"' : '' ?>>
          <td data-label="<?= $e($t('employees.field.effective')) ?>"><?= $e($day((string) $h['effective_from'])) ?> → <?= $h['effective_to'] !== null ? $e($day((string) $h['effective_to'])) : $e($t('employees.open_ended')) ?>
            <?php if ($h['effective_from'] > $today): ?><?= $ui('badge', ['tone' => 'info', 'label' => $t('employees.future')]) ?><?php endif; ?></td>
          <td data-label="<?= $e($t('employees.field.company')) ?>"><?= $e($label('site', $h['site_id']) !== '—' ? $label('site', $h['site_id']) : $label('legal_entity', $h['legal_entity_id'])) ?></td>
          <td data-label="<?= $e($t('employees.field.department')) ?>"><?= $e($label('department', $h['department_id'])) ?></td>
          <td data-label="<?= $e($t('employees.field.position')) ?>"><?= $e((string) ($h['position_title'] ?? '—')) ?></td>
          <td data-label="<?= $e($t('employees.field.manager')) ?>"><?= $e((string) ($h['manager_name'] ?? '—')) ?></td>
          <td data-label="<?= $e($t('employees.field.group')) ?>"><?= $e((string) $h['employee_group']) ?> · <?= $e($t('employees.type.' . $h['employment_type'])) ?></td>
          <td data-label="<?= $e($t('employees.field.reason')) ?>"><?= $e((string) ($h['change_reason'] ?? '—')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="card card--flush" aria-labelledby="es-st">
  <div class="card__head"><h2 id="es-st" class="card__title"><?= $e($t('employees.section.status_history')) ?></h2></div>
  <div class="table-wrap" role="region" aria-labelledby="es-st" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('employees.field.effective')) ?></th><th scope="col"><?= $e($t('employees.field.status')) ?></th><th scope="col"><?= $e($t('employees.field.reason')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($statusHistory as $s): ?>
        <tr>
          <td data-label="<?= $e($t('employees.field.effective')) ?>"><?= $e($day((string) $s['effective_from'])) ?> → <?= $s['effective_to'] !== null ? $e($day((string) $s['effective_to'])) : $e($t('employees.open_ended')) ?></td>
          <td data-label="<?= $e($t('employees.field.status')) ?>"><?= $statusBadge((string) $s['status']) ?>
            <?php if ($s['separation_type'] !== null): ?><div class="text-sm text-muted"><?= $e($t('employees.separation.' . $s['separation_type'])) ?> · <?= $e($t('employees.field.last_working_day')) ?> <?= $e($day((string) $s['last_working_day'])) ?></div><?php endif; ?></td>
          <td data-label="<?= $e($t('employees.field.reason')) ?>"><?= $e((string) ($s['reason'] ?? '—')) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($statusHistory === []): ?><tr><td colspan="3"><?= $e($t('employees.none_yet')) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
