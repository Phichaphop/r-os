<?php
/**
 * A work permit (new or draft): contractor company (choose to list its workers), site and area, work, tools, hazard
 * categories and controls, time window, internal supervisor, workers. Save as draft or send for approval.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $list @var string $chosen @var list<array<string, mixed>> $workers
 * @var list<array<string, mixed>> $sites @var ?array<string, mixed> $permit @var array<string, string> $errors @var array<string, mixed> $old
 * @var string $here @var bool $canView @var bool $canManage @var bool $canGate
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$p = $permit;
$def = $p === null ? [] : [
    'site' => $p['site_pid'], 'work' => $p['work'], 'area' => $p['area'], 'tools' => $p['tools'], 'controls' => $p['controls'], 'supervisor' => $p['supervisor_username'],
    'starts_at' => str_replace(' ', 'T', substr((string) $p['starts_at'], 0, 16)), 'ends_at' => str_replace(' ', 'T', substr((string) $p['ends_at'], 0, 16)),
];
$o = static fn (string $k): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : (is_scalar($def[$k] ?? null) ? (string) $def[$k] : '');
$hz = array_key_exists('hazards', $old) || $old !== [] ? array_map('strval', (array) ($old['hazards'] ?? [])) : ($p !== null && $p['hazards'] !== null ? explode(',', (string) $p['hazards']) : []);
$picked = array_key_exists('workers', $old) || ($old !== [] && $p === null) ? array_map('strval', (array) ($old['workers'] ?? [])) : ($p !== null ? array_column($p['workers'], 'public_id') : []);
$action = $p === null ? '/contractors/permits/new' : '/contractors/p/' . $p['public_id'] . '/edit';
$coOpts = ['' => $t('contractors.pick')];
foreach ($list as $co) {
    $coOpts[(string) $co['public_id']] = $co['name'] . ((int) $co['suspended'] === 1 ? ' (' . $t('contractors.suspended') . ')' : '');
}
?>
<?= $ui('page-header', ['title' => $p === null ? $t('contractors.new_permit') : $t('contractors.edit_permit'), 'subtitle' => $t('contractors.permit_form_help'),
    'crumbs' => [[$t('contractors.permits_title'), $url('/contractors/permits')], [$p === null ? $t('contractors.new_permit') : (string) ($p['ref'] ?? $t('contractors.draft')), null]]]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<?php if ($list === []): ?>
  <?= $ui('states', ['icon' => 'id-badge-2', 'title' => $t('contractors.none'), 'text' => $t('contractors.none_help')]) ?>
<?php else: ?>
<form method="post" action="<?= $e($url($action)) ?>" class="card stack" novalidate><?= $csrf ?><input type="hidden" name="le" value="<?= $e($lePid) ?>">
  <div class="row ct-add">
    <?= $ui('field', ['name' => 'company', 'type' => 'select', 'label' => $t('contractors.field.company'), 'required' => true, 'value' => $chosen, 'options' => $coOpts, 'error' => $errors['company'] ?? null]) ?>
    <?= $ui('button', ['label' => $t('contractors.list_workers'), 'type' => 'submit', 'variant' => 'secondary', 'attrs' => ['name' => 'pick_company', 'value' => '1']]) ?>
  </div>
  <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('contractors.field.workers')) ?> <span class="field__req"><?= $e($t('form.required_tag')) ?></span></legend>
    <?php if ($workers === []): ?><p class="text-sm text-muted"><?= $e($chosen === '' ? $t('contractors.pick_company_first') : $t('contractors.no_workers')) ?></p><?php else: ?>
    <div class="row"><?php foreach ($workers as $w): ?><label class="check"><input type="checkbox" name="workers[]" value="<?= $e((string) $w['public_id']) ?>"<?= in_array((string) $w['public_id'], $picked, true) ? ' checked' : '' ?>> <?= $e($w['name'] . ($w['position'] !== null ? ' · ' . $w['position'] : '')) ?></label><?php endforeach; ?></div>
    <?php endif; ?>
    <?php if (isset($errors['workers'])): ?><div class="field__error"><?= $e($errors['workers']) ?></div><?php endif; ?>
  </fieldset>
  <div class="grid grid--3">
    <?= $ui('field', ['name' => 'site', 'type' => 'select', 'label' => $t('contractors.field.site'), 'required' => true, 'value' => $o('site'), 'options' => ['' => $t('contractors.pick')] + array_column($sites, 'code', 'public_id'), 'error' => $errors['site'] ?? null]) ?>
    <?= $ui('field', ['name' => 'area', 'label' => $t('contractors.field.area'), 'required' => true, 'value' => $o('area'), 'help' => $t('contractors.area_help'), 'error' => $errors['area'] ?? null]) ?>
    <?= $ui('field', ['name' => 'supervisor', 'label' => $t('contractors.field.supervisor'), 'required' => true, 'value' => $o('supervisor'), 'help' => $t('contractors.supervisor_help'), 'error' => $errors['supervisor'] ?? null]) ?>
    <?= $ui('field', ['name' => 'starts_at', 'type' => 'datetime-local', 'label' => $t('contractors.field.starts_at'), 'required' => true, 'value' => $o('starts_at'), 'error' => $errors['starts_at'] ?? null]) ?>
    <?= $ui('field', ['name' => 'ends_at', 'type' => 'datetime-local', 'label' => $t('contractors.field.ends_at'), 'required' => true, 'value' => $o('ends_at'), 'help' => $t('contractors.window_help')]) ?>
  </div>
  <?= $ui('field', ['name' => 'work', 'type' => 'textarea', 'label' => $t('contractors.field.work'), 'required' => true, 'value' => $o('work'), 'error' => $errors['work'] ?? null]) ?>
  <?= $ui('field', ['name' => 'tools', 'label' => $t('contractors.field.tools'), 'value' => $o('tools')]) ?>
  <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('contractors.field.hazards')) ?></legend>
    <div class="row"><?php foreach (\Blue\Modules\Contractors\ContractorService::HAZARDS as $h): ?><label class="check"><input type="checkbox" name="hazards[]" value="<?= $e($h) ?>"<?= in_array($h, $hz, true) ? ' checked' : '' ?>> <?= $e($t('contractors.hazard.' . $h)) ?></label><?php endforeach; ?></div>
    <p class="field__help"><?= $e($t('contractors.hazards_help')) ?></p>
  </fieldset>
  <?= $ui('field', ['name' => 'controls', 'type' => 'textarea', 'label' => $t('contractors.field.controls'), 'value' => $o('controls'), 'help' => $t('contractors.controls_help'), 'error' => $errors['controls'] ?? null]) ?>
  <div class="row">
    <?= $ui('button', ['label' => $t('contractors.save_draft'), 'type' => 'submit', 'variant' => 'secondary']) ?>
    <?= $ui('button', ['label' => $t('contractors.save_send'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send', 'attrs' => ['name' => 'send', 'value' => '1']]) ?>
  </div>
</form>
<?php endif; ?>
