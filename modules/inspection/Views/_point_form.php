<?php
/**
 * Inspection point form: code, name, site, place, checklist, how often from which date, inspector, helpdesk category
 * for failures.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var string $csrf
 * @var array<string, mixed> $pv @var array<string, string> $pErr @var string $pAction @var string $pf @var string $pLe @var bool $pEdit
 * @var list<array<string, mixed>> $checklists @var list<array<string, mixed>> $sites @var list<array<string, mixed>> $categories
 */
$pvs = static fn (string $k): string => is_scalar($pv[$k] ?? null) ? (string) $pv[$k] : '';
$id = static fn (string $k): string => 'f-' . $pf . '-' . $k;
?>
<form method="post" action="<?= $e($url($pAction)) ?>" class="stack-sm" novalidate><?= $csrf ?>
  <?php if ($pLe !== ''): ?><input type="hidden" name="le" value="<?= $e($pLe) ?>"><?php endif; ?>
  <div class="grid grid--3">
    <?= $ui('field', ['name' => 'code', 'id' => $id('code'), 'label' => $t('inspection.field.code'), 'required' => true, 'value' => $pvs('code'), 'error' => $pErr['code'] ?? null]) ?>
    <?= $ui('field', ['name' => 'name', 'id' => $id('name'), 'label' => $t('inspection.field.name'), 'required' => true, 'value' => $pvs('name'), 'help' => $t('inspection.point_name_help'), 'error' => $pErr['name'] ?? null]) ?>
    <?= $ui('field', ['name' => 'checklist', 'id' => $id('checklist'), 'type' => 'select', 'label' => $t('inspection.field.checklist'), 'required' => true, 'value' => $pvs('checklist'), 'options' => ['' => $t('inspection.pick_checklist')] + array_column($checklists, 'name', 'public_id'), 'error' => $pErr['checklist'] ?? null]) ?>
  </div>
  <div class="grid grid--3">
    <?= $ui('field', ['name' => 'site', 'id' => $id('site'), 'type' => 'select', 'label' => $t('inspection.field.site'), 'value' => $pvs('site'), 'options' => ['' => '—'] + array_column($sites, 'code', 'public_id'), 'error' => $pErr['site'] ?? null]) ?>
    <?= $ui('field', ['name' => 'location', 'id' => $id('location'), 'label' => $t('inspection.field.location'), 'value' => $pvs('location'), 'error' => $pErr['location'] ?? null]) ?>
    <?= $ui('field', ['name' => 'inspector', 'id' => $id('inspector'), 'label' => $t('inspection.field.inspector'), 'required' => true, 'value' => $pvs('inspector'), 'help' => $t('inspection.person_help'), 'error' => $pErr['inspector'] ?? null]) ?>
  </div>
  <div class="grid grid--3">
    <?= $ui('field', ['name' => 'frequency', 'id' => $id('frequency'), 'type' => 'select', 'label' => $t('inspection.field.frequency'), 'required' => true, 'value' => $pvs('frequency'),
        'options' => ['' => '—', 'daily' => $t('inspection.frequency.daily'), 'weekly' => $t('inspection.frequency.weekly'), 'monthly' => $t('inspection.frequency.monthly')], 'error' => $pErr['frequency'] ?? null]) ?>
    <?= $ui('field', ['name' => 'start_date', 'id' => $id('start_date'), 'type' => 'date', 'label' => $t('inspection.field.start_date'), 'required' => true, 'value' => $pvs('start_date'), 'help' => $t('inspection.start_help'), 'error' => $pErr['start_date'] ?? null]) ?>
  </div>
  <div class="grid grid--2">
    <?= $ui('field', ['name' => 'ticket_category', 'id' => $id('ticket_category'), 'type' => 'select', 'label' => $t('inspection.field.ticket_category'), 'value' => $pvs('ticket_category_pid') !== '' ? $pvs('ticket_category_pid') : $pvs('ticket_category'),
        'options' => ['' => $t('inspection.no_ticket')] + array_column($categories, 'name', 'public_id'), 'help' => $t('inspection.ticket_help'), 'error' => $pErr['ticket_category'] ?? null]) ?>
    <?= $ui('field', ['name' => 'ticket_priority', 'id' => $id('ticket_priority'), 'type' => 'select', 'label' => $t('inspection.field.ticket_priority'), 'value' => $pvs('ticket_priority') !== '' ? $pvs('ticket_priority') : 'normal',
        'options' => ['low' => $t('helpdesk.priority.low'), 'normal' => $t('helpdesk.priority.normal'), 'high' => $t('helpdesk.priority.high'), 'urgent' => $t('helpdesk.priority.urgent')], 'error' => $pErr['ticket_priority'] ?? null]) ?>
  </div>
  <?php if ($pEdit): ?><label class="check"><input type="checkbox" name="active" value="1"<?= $pvs('active') !== '' && $pvs('active') !== '0' ? ' checked' : '' ?>> <?= $e($t('inspection.field.active')) ?></label><?php endif; ?>
  <div class="row"><?= $ui('button', ['label' => $t('inspection.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
</form>
