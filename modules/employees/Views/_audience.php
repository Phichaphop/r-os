<?php
/**
 * Shared target-group fieldset (DEC-72) — included by forms, announcements and controlled documents.
 * Needs: $e, $t; $audOptions = Audience::options(); $audIn = ['sites' => [pids], 'departments' => [pids], 'groups' => 'A, B'];
 * $audError ?string; $audId string (prefix for ids).
 * @var callable $e @var callable $t @var array{sites: array<string, string>, departments: array<string, string>} $audOptions
 * @var array{sites?: list<string>, departments?: list<string>, groups?: string} $audIn @var string|null $audError @var string $audId
 */
$audSites = (array) ($audIn['sites'] ?? []);
$audDepts = (array) ($audIn['departments'] ?? []);
?>
<fieldset class="stack-sm audience">
  <legend class="field__label"><?= $e($t('employees.audience')) ?> <span class="field__req"><?= $e($t('form.optional_tag')) ?></span></legend>
  <p class="text-sm text-muted"><?= $e($t('employees.audience_help')) ?></p>
  <?php if (!empty($audError)): ?><p class="field__error"><?= $e((string) $audError) ?></p><?php endif; ?>
  <?php if ($audOptions['sites'] !== []): ?>
  <details<?= $audSites !== [] ? ' open' : '' ?>><summary><?= $e($t('employees.audience_sites')) ?><?= $audSites !== [] ? ' (' . count($audSites) . ')' : '' ?></summary>
    <div class="check-list"><?php foreach ($audOptions['sites'] as $pid => $label): ?><label class="check"><input type="checkbox" name="sites[]" value="<?= $e($pid) ?>"<?= in_array($pid, $audSites, true) ? ' checked' : '' ?>> <?= $e($label) ?></label><?php endforeach; ?></div>
  </details>
  <?php endif; ?>
  <?php if ($audOptions['departments'] !== []): ?>
  <details<?= $audDepts !== [] ? ' open' : '' ?>><summary><?= $e($t('employees.audience_departments')) ?><?= $audDepts !== [] ? ' (' . count($audDepts) . ')' : '' ?></summary>
    <p class="text-sm text-muted"><?= $e($t('employees.audience_departments_help')) ?></p>
    <div class="check-list"><?php foreach ($audOptions['departments'] as $pid => $label): ?><label class="check"><input type="checkbox" name="departments[]" value="<?= $e($pid) ?>"<?= in_array($pid, $audDepts, true) ? ' checked' : '' ?>> <?= $e($label) ?></label><?php endforeach; ?></div>
  </details>
  <?php endif; ?>
  <div class="field">
    <label class="field__label" for="<?= $e($audId) ?>-groups"><?= $e($t('employees.audience_groups')) ?> <span class="field__req"><?= $e($t('form.optional_tag')) ?></span></label>
    <input class="input" id="<?= $e($audId) ?>-groups" name="groups" value="<?= $e((string) ($audIn['groups'] ?? '')) ?>" placeholder="FIELD, SALES">
  </div>
</fieldset>
