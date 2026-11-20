<?php
/**
 * Personal contacts + up to three emergency contacts (HR form and self-service share it).
 * Also the registered address in parts for tax forms (0.47.0).
 * @var callable $e @var callable $t @var callable $ui @var array<string, string> $contacts @var list<array<string, string>> $emerg @var array<string, string> $errors
 * @var array<string, mixed> $old @var array<string, mixed> $profile
 */
$ta = (array) ($old['tax_address'] ?? $profile['tax_address'] ?? []);
$provinces = ['' => '—'] + array_combine(\Blue\Core\Support\ThaiProvinces::ALL, \Blue\Core\Support\ThaiProvinces::ALL);
?>
<div class="grid grid--2">
  <?= $ui('field', ['name' => 'contacts[personal_phone]', 'id' => 'c-phone', 'label' => $t('employees.contact.personal_phone'), 'value' => (string) ($contacts['personal_phone'] ?? ''),
      'error' => $errors['contacts_personal_phone'] ?? null, 'autocomplete' => 'tel']) ?>
  <?= $ui('field', ['name' => 'contacts[personal_email]', 'id' => 'c-email', 'type' => 'email', 'label' => $t('employees.contact.personal_email'), 'value' => (string) ($contacts['personal_email'] ?? ''),
      'error' => $errors['contacts_personal_email'] ?? null, 'autocomplete' => 'email']) ?>
  <?= $ui('field', ['name' => 'contacts[address_current]', 'id' => 'c-addr', 'type' => 'textarea', 'label' => $t('employees.contact.address_current'), 'value' => (string) ($contacts['address_current'] ?? ''),
      'error' => $errors['contacts_address_current'] ?? null]) ?>
  <?= $ui('field', ['name' => 'contacts[address_registered]', 'id' => 'c-reg', 'type' => 'textarea', 'label' => $t('employees.contact.address_registered'), 'value' => (string) ($contacts['address_registered'] ?? ''),
      'error' => $errors['contacts_address_registered'] ?? null]) ?>
</div>
<fieldset class="stack-sm" aria-describedby="ta-help">
  <legend class="card__title"><?= $e($t('employees.section.tax_address')) ?></legend>
  <p id="ta-help" class="text-sm text-muted"><?= $e($t('employees.tax_address_help')) ?></p>
  <div class="grid grid--2">
    <?php foreach (\Blue\Modules\Employees\ProfileService::TAX_ADDRESS as $k): ?>
      <?= $ui('field', ['name' => 'tax_address[' . $k . ']', 'id' => 'ta-' . $k, 'label' => $t('employees.tax_address.' . $k), 'value' => (string) ($ta[$k] ?? ''),
          'type' => $k === 'province' ? 'select' : 'text', 'options' => $k === 'province' ? $provinces : null, 'inputmode' => $k === 'postcode' ? 'numeric' : null,
          'autocomplete' => $k === 'postcode' ? 'postal-code' : null, 'error' => $errors['tax_address_' . $k] ?? null]) ?>
    <?php endforeach; ?>
  </div>
</fieldset>
<fieldset class="stack-sm">
  <legend class="card__title"><?= $e($t('employees.section.emergency')) ?></legend>
  <?php foreach (array_slice($emerg, 0, 3) as $i => $em): ?>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'emergency[' . $i . '][name]', 'id' => 'em-' . $i . '-name', 'label' => $t('employees.field.em_name', ['n' => (string) ($i + 1)]), 'value' => (string) ($em['name'] ?? ''),
          'error' => $errors['emergency_' . $i] ?? null]) ?>
      <?= $ui('field', ['name' => 'emergency[' . $i . '][relation]', 'id' => 'em-' . $i . '-rel', 'label' => $t('employees.field.em_relation'), 'value' => (string) ($em['relation'] ?? '')]) ?>
      <?= $ui('field', ['name' => 'emergency[' . $i . '][phone]', 'id' => 'em-' . $i . '-phone', 'label' => $t('employees.field.em_phone'), 'value' => (string) ($em['phone'] ?? '')]) ?>
    </div>
  <?php endforeach; ?>
</fieldset>
