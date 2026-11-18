<?php
/**
 * Overview tab: the record now, names/hire date (HR), linked account (HR).
 * Uses the variables of show.php.
 */
$job = $emp['job'];
$label = static fn (string $type, mixed $id): string => $id === null ? '—' : ($allOrg[$type][(int) $id] ?? '#' . (int) $id);
$personOld = $form === 'person' ? $old : $emp;
$f = static fn (string $k): string => is_scalar($personOld[$k] ?? null) ? (string) $personOld[$k] : '';
?>
<section class="card stack-sm" aria-labelledby="es-now">
  <h2 id="es-now" class="card__title"><?= $e($t('employees.section.now')) ?></h2>
  <dl class="dl">
    <dt><?= $e($t('employees.field.company')) ?></dt><dd><?= $e($label('legal_entity', $job['legal_entity_id'] ?? null)) ?></dd>
    <dt><?= $e($t('employees.field.site')) ?></dt><dd><?= $e($label('site', $job['site_id'] ?? null)) ?></dd>
    <dt><?= $e($t('employees.field.department')) ?></dt><dd><?= $e($label('department', $job['department_id'] ?? null)) ?></dd>
    <dt><?= $e($t('employees.field.position')) ?></dt><dd><?= $e((string) ($job['position_title'] ?? '—')) ?></dd>
    <dt><?= $e($t('employees.field.manager')) ?></dt><dd><?= $e((string) ($emp['history'][0]['manager_name'] ?? '—')) ?></dd>
    <dt><?= $e($t('employees.field.group')) ?></dt><dd><?= $e((string) ($job['employee_group'] ?? '—')) ?></dd>
    <dt><?= $e($t('employees.field.type')) ?></dt><dd><?= $job !== null ? $e($t('employees.type.' . $job['employment_type'])) : '—' ?></dd>
    <dt><?= $e($t('employees.field.hire_date')) ?></dt><dd><?= $e($day((string) $emp['hire_date'])) ?></dd>
    <?php if ($emp['termination_date'] !== null): ?>
      <dt><?= $e($t('employees.field.last_working_day')) ?></dt><dd><?= $e($day((string) $emp['termination_date'])) ?><?= $emp['separation_type'] !== null ? ' · ' . $e($t('employees.separation.' . $emp['separation_type'])) : '' ?></dd>
    <?php endif; ?>
    <?php if ($emp['work_email'] !== null || $emp['work_phone'] !== null): ?>
      <dt><?= $e($t('employees.field.work_contact')) ?></dt><dd><?= $e(implode(' · ', array_filter([(string) $emp['work_email'], (string) $emp['work_phone']]))) ?></dd>
    <?php endif; ?>
    <dt><?= $e($t('employees.field.account')) ?></dt><dd><?= $emp['username'] !== null ? $e((string) $emp['username']) : $e($t('employees.no_account')) ?></dd>
  </dl>
</section>

<?php if ($acc['manage']): ?>
<section class="card stack" aria-labelledby="es-person">
  <details<?= $form === 'person' ? ' open' : '' ?>>
    <summary><h2 id="es-person" class="card__title d-inline"><?= $e($t('employees.edit_person')) ?></h2></summary>
    <p class="text-sm text-muted"><?= $e($t('employees.edit_person_help')) ?></p>
    <form method="post" action="<?= $e($url($base)) ?>" class="stack" novalidate>
      <?= $csrf ?>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'employee_code', 'label' => $t('employees.field.code'), 'value' => $f('employee_code'), 'readonly' => true, 'help' => $t('employees.code_locked')]) ?>
        <?= $ui('field', ['name' => 'hire_date', 'type' => 'date', 'label' => $t('employees.field.hire_date'), 'required' => true, 'value' => $f('hire_date'), 'error' => $errors['hire_date'] ?? null,
            'readonly' => $emp['status'] !== 'pre_hire', 'help' => $emp['status'] !== 'pre_hire' ? $t('employees.hire_locked_help') : null]) ?>
        <?= $ui('field', ['name' => 'name_th', 'label' => $t('employees.field.name_th'), 'required' => true, 'value' => $f('name_th'), 'error' => $errors['name_th'] ?? null]) ?>
        <?= $ui('field', ['name' => 'name_en', 'label' => $t('employees.field.name_en'), 'value' => $f('name_en')]) ?>
        <?= $ui('field', ['name' => 'preferred_name', 'label' => $t('employees.field.preferred_name'), 'value' => $f('preferred_name')]) ?>
      </div>
      <div class="row"><?= $ui('button', ['label' => $t('employees.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
    </form>
  </details>
</section>

<section class="card stack" aria-labelledby="es-user">
  <details<?= $form === 'user' ? ' open' : '' ?>>
    <summary><h2 id="es-user" class="card__title d-inline"><?= $e($t('employees.link_user')) ?></h2></summary>
    <p class="text-sm text-muted"><?= $e($t('employees.link_user_help')) ?></p>
    <form method="post" action="<?= $e($url($base . '/user')) ?>" class="stack" novalidate>
      <?= $csrf ?>
      <?= $ui('field', ['name' => 'username', 'label' => $t('employees.field.username'), 'value' => $form === 'user' ? (string) ($old['username'] ?? '') : (string) ($emp['username'] ?? ''),
          'error' => $errors['username'] ?? null, 'help' => $t('employees.username_help')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('employees.save_user'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'user']) ?></div>
    </form>
  </details>
</section>
<?php endif; ?>
