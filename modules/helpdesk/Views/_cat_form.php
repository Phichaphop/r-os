<?php
/**
 * Category form: name, description, SLA hours (first response, resolve per priority — blank = no target).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var string $csrf
 * @var array<string, mixed> $cv @var array<string, string> $cErr @var string $cAction @var string $cf @var string $cLe @var bool $cEdit
 */
$cvs = static fn (string $k): string => is_scalar($cv[$k] ?? null) ? (string) $cv[$k] : '';
$id = static fn (string $k): string => 'f-' . $cf . '-' . $k;
?>
<form method="post" action="<?= $e($url($cAction)) ?>" class="stack-sm" novalidate><?= $csrf ?>
  <?php if ($cLe !== ''): ?><input type="hidden" name="le" value="<?= $e($cLe) ?>"><?php endif; ?>
  <div class="grid grid--2">
    <?= $ui('field', ['name' => 'name', 'id' => $id('name'), 'label' => $t('helpdesk.field.category_name'), 'required' => true, 'value' => $cvs('name'), 'error' => $cErr['name'] ?? null]) ?>
    <?= $ui('field', ['name' => 'description', 'id' => $id('description'), 'label' => $t('helpdesk.field.category_description'), 'value' => $cvs('description'), 'error' => $cErr['description'] ?? null]) ?>
  </div>
  <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('helpdesk.sla_hours')) ?></legend>
    <p class="text-sm text-muted"><?= $e($t('helpdesk.sla_help')) ?></p>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'response_hours', 'id' => $id('response_hours'), 'type' => 'number', 'label' => $t('helpdesk.field.response_hours'), 'value' => $cvs('response_hours'), 'error' => $cErr['response_hours'] ?? null]) ?>
      <?php foreach (['low', 'normal', 'high', 'urgent'] as $p): ?>
        <?= $ui('field', ['name' => 'hours_' . $p, 'id' => $id('hours_' . $p), 'type' => 'number', 'label' => $t('helpdesk.resolve_hours_for', ['p' => $t('helpdesk.priority.' . $p)]), 'value' => $cvs('hours_' . $p), 'error' => $cErr['hours_' . $p] ?? null]) ?>
      <?php endforeach; ?>
    </div>
  </fieldset>
  <?php if ($cEdit): ?><label class="check"><input type="checkbox" name="active" value="1"<?= $cvs('active') !== '' && $cvs('active') !== '0' ? ' checked' : '' ?>> <?= $e($t('helpdesk.field.category_active')) ?></label><?php endif; ?>
  <div class="row"><?= $ui('button', ['label' => $t('helpdesk.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
</form>
