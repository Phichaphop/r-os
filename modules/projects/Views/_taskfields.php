<?php
/**
 * Task fields. Needs $fill, $ferr, $others (tasks it may depend on), $tops (possible parents), $members (team), $tf (id prefix).
 * @var callable $e @var callable $t @var callable $ui @var array<string, mixed> $fill @var array<string, string> $ferr
 * @var list<array<string, mixed>> $others @var list<array<string, mixed>> $tops @var list<array<string, mixed>> $members @var string $tf
 */
$fv = static fn (string $k): string => is_scalar($fill[$k] ?? null) ? (string) $fill[$k] : '';
$deps = array_map('strval', (array) ($fill['deps'] ?? []));
$people = ['' => $t('projects.unassigned')];
foreach ($members as $m) {
    $people[(string) $m['username']] = (string) ($m['name'] ?? $m['username']);
}
$parents = ['' => $t('projects.no_parent')];
foreach ($tops as $k) {
    $parents[(string) $k['public_id']] = (string) $k['title'];
}
?>
<?= $ui('field', ['name' => 'title', 'id' => 'f-' . $tf . '-title', 'label' => $t('projects.field.task_title'), 'required' => true, 'value' => $fv('title'), 'full' => true, 'error' => $ferr['title'] ?? null]) ?>
<div class="grid grid--2">
  <?= $ui('field', ['name' => 'assignee', 'id' => 'f-' . $tf . '-assignee', 'type' => 'select', 'label' => $t('projects.field.assignee'), 'value' => $fv('assignee'), 'options' => $people, 'error' => $ferr['assignee'] ?? null]) ?>
  <?= $ui('field', ['name' => 'parent', 'id' => 'f-' . $tf . '-parent', 'type' => 'select', 'label' => $t('projects.field.parent'), 'value' => $fv('parent'), 'options' => $parents, 'error' => $ferr['parent'] ?? null]) ?>
  <?= $ui('field', ['name' => 'starts_on', 'id' => 'f-' . $tf . '-start', 'type' => 'date', 'label' => $t('projects.field.starts_on'), 'value' => $fv('starts_on'), 'error' => $ferr['starts_on'] ?? null]) ?>
  <?= $ui('field', ['name' => 'due_on', 'id' => 'f-' . $tf . '-due', 'type' => 'date', 'label' => $t('projects.field.due_on'), 'value' => $fv('due_on'), 'error' => $ferr['due_on'] ?? null]) ?>
</div>
<?= $ui('field', ['name' => 'description', 'id' => 'f-' . $tf . '-desc', 'type' => 'textarea', 'label' => $t('projects.field.description'), 'value' => $fv('description'), 'error' => $ferr['description'] ?? null]) ?>
<?= $ui('field', ['name' => 'criteria', 'id' => 'f-' . $tf . '-criteria', 'type' => 'textarea', 'label' => $t('projects.field.criteria'), 'value' => $fv('criteria'), 'help' => $t('projects.criteria_help')]) ?>
<?php if ($others !== []): ?>
<fieldset class="stack-sm">
  <legend class="field__label"><?= $e($t('projects.field.deps')) ?> <span class="field__req"><?= $e($t('form.optional_tag')) ?></span></legend>
  <p class="text-sm text-muted"><?= $e($t('projects.deps_help')) ?></p>
  <?php if (isset($ferr['deps'])): ?><p class="field__error"><?= $e($ferr['deps']) ?></p><?php endif; ?>
  <div class="check-list"><?php foreach ($others as $k): ?><label class="check"><input type="checkbox" name="deps[]" value="<?= $e((string) $k['public_id']) ?>"<?= in_array((string) $k['public_id'], $deps, true) ? ' checked' : '' ?>> <?= $e((string) $k['title']) ?></label><?php endforeach; ?></div>
</fieldset>
<?php endif; ?>
