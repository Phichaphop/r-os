<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var list<array<string,mixed>> $defs @var list<string> $roles @var list<string> $permissions @var array<string, array<string,string>> $errors
 */
$approverOptions = [];
foreach ($permissions as $p) {
    $approverOptions['permission:' . $p] = $t('workflow.admin.by_permission', ['p' => $p]);
}
foreach ($roles as $role) {
    $approverOptions['role:' . $role] = $t('workflow.admin.by_role', ['r' => $role . ' — ' . $t('role.' . $role)]);
}
?>
<?= $ui('page-header', ['title' => $t('workflow.admin.title'), 'subtitle' => $t('workflow.admin.subtitle')]) ?>
<?php foreach ($defs as $d): $steps = json_decode((string) $d['steps_json'], true) ?: []; $anchor = str_replace('.', '-', (string) $d['code']); $err = $errors[$d['code']] ?? []; ?>
<section class="card stack" id="<?= $e($anchor) ?>" aria-labelledby="<?= $e($anchor) ?>-h">
  <div class="card__head"><h2 id="<?= $e($anchor) ?>-h" class="card__title"><?= $e($t('workflow.subject.' . $d['subject_type'])) ?> <span class="mono text-sm text-muted"><?= $e($d['code']) ?></span></h2>
    <?= $ui('badge', ['tone' => 'info', 'label' => $t('workflow.version_n', ['n' => (int) $d['version_no']])]) ?></div>
  <p class="text-sm text-muted"><?= $e($t('workflow.admin.open', ['n' => (int) $d['open_count']])) ?> · <?= $e($date($d['created_at'])) ?><?= $d['change_reason'] ? ' · ' . $e((string) $d['change_reason']) : '' ?></p>
  <form method="post" action="<?= $e($url('/admin/workflows/' . $d['code'])) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?php for ($i = 0; $i < max(count($steps), 1) + 1 && $i < 5; $i++): $s = $steps[$i] ?? null; $pre = 'steps[' . $i . ']'; $id = $anchor . '-s' . $i; ?>
    <fieldset class="form-grid step-row">
      <legend class="field__label"><?= $e($t('workflow.step_n', ['n' => $i + 1])) ?><?= $s === null ? ' (' . $e($t('workflow.admin.optional_step')) . ')' : '' ?></legend>
      <div class="field"><label class="field__label" for="<?= $e($id) ?>-a"><?= $e($t('workflow.admin.approver')) ?></label>
        <select class="select" id="<?= $e($id) ?>-a" name="<?= $e($pre) ?>[approver]"><option value="">—</option>
          <?php foreach ($approverOptions as $val => $label): ?><option value="<?= $e($val) ?>"<?= $s !== null && $val === $s['approver']['type'] . ':' . $s['approver']['value'] ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label class="field__label" for="<?= $e($id) ?>-sc"><?= $e($t('workflow.admin.scope')) ?></label>
        <select class="select" id="<?= $e($id) ?>-sc" name="<?= $e($pre) ?>[scope]">
          <option value="subject"<?= ($s['scope'] ?? 'subject') === 'subject' ? ' selected' : '' ?>><?= $e($t('workflow.admin.scope_subject')) ?></option>
          <option value="any"<?= ($s['scope'] ?? '') === 'any' ? ' selected' : '' ?>><?= $e($t('workflow.admin.scope_any')) ?></option></select></div>
      <div class="field"><label class="field__label" for="<?= $e($id) ?>-sla"><?= $e($t('workflow.admin.sla')) ?></label>
        <input class="input" id="<?= $e($id) ?>-sla" name="<?= $e($pre) ?>[sla_hours]" inputmode="numeric" value="<?= $e((string) ($s['sla_hours'] ?? '')) ?>"></div>
      <div class="field"><label class="field__label" for="<?= $e($id) ?>-esc"><?= $e($t('workflow.admin.escalate')) ?></label>
        <select class="select" id="<?= $e($id) ?>-esc" name="<?= $e($pre) ?>[escalate_to]"><option value="">—</option>
          <?php foreach ($roles as $role): ?><option value="role:<?= $e($role) ?>"<?= $s !== null && is_array($s['escalate_to'] ?? null) && $s['escalate_to']['value'] === $role ? ' selected' : '' ?>><?= $e($role . ' — ' . $t('role.' . $role)) ?></option><?php endforeach; ?></select></div>
    </fieldset>
    <?php endfor; ?>
    <?= $ui('field', ['name' => 'reason', 'label' => $t('iam.field.reason'), 'required' => true, 'error' => isset($err['reason']) ? $err['reason'] : (isset($err['steps']) ? $err['steps'] : null)]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('workflow.admin.publish'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'send']) ?></div>
  </form>
</section>
<?php endforeach; ?>
