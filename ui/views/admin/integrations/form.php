<?php
/** @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var ?string $pid @var array<string, string> $old @var array<string, string> $errors */
$opt = static fn (array $vals, string $prefix) => array_combine($vals, array_map(static fn ($x) => $t($prefix . $x), $vals));
$f = static fn (string $name, array $extra = []) => $ui('field', ['name' => $name, 'label' => $t('integration.field.' . $name), 'value' => (string) ($old[$name] ?? ''), 'error' => $errors[$name] ?? null] + $extra);
?>
<?= $ui('page-header', ['title' => $t($pid === null ? 'integration.new' : 'integration.edit'), 'crumbs' => [[$t('integration.title'), $url('/admin/integrations')], [$t($pid === null ? 'integration.new' : 'integration.edit'), null]]]) ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors'), 'live' => true]) ?><?php endif; ?>
<form method="post" action="<?= $e($url($pid === null ? '/admin/integrations/new' : '/admin/integrations/' . $pid . '/edit')) ?>" novalidate>
  <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
  <section class="card form-grid">
    <?= $f('code', ['required' => true, 'readonly' => $pid !== null, 'help' => $t('integration.help.code')]) ?>
    <?= $f('name', ['required' => true]) ?>
    <?= $f('direction', ['type' => 'select', 'options' => $opt(['inbound', 'outbound', 'file_exchange'], 'integration.direction.')]) ?>
    <?= $f('system_type', ['placeholder' => 'attendance / bank / email']) ?>
    <?= $f('owner', ['help' => $t('integration.help.owner')]) ?>
    <?= $f('incident_contact') ?>
    <?= $f('auth_method', ['type' => 'select', 'options' => $opt(['none', 'api_key', 'hmac', 'basic', 'oauth', 'manual'], 'integration.auth.')]) ?>
    <?= $f('scopes') ?>
    <?= $f('data_classification', ['type' => 'select', 'options' => ['P' => $t('document.classification.P'), 'I' => $t('document.classification.I'), 'C' => $t('document.classification.C'), 'R' => $t('document.classification.R')]]) ?>
    <?= $f('schema_version') ?>
    <?= $f('endpoint', ['full' => true, 'help' => $t('integration.help.endpoint')]) ?>
    <?= $f('rate_limit_per_min', ['type' => 'number']) ?>
    <?= $f('timeout_seconds', ['type' => 'number']) ?>
    <?= $f('retry_policy') ?>
    <?= $f('idempotency') ?>
    <?= $f('monitoring') ?>
    <?= $f('cost_note', ['help' => $t('integration.help.cost')]) ?>
    <?= $f('privacy_review', ['type' => 'select', 'options' => $opt(['not_required', 'pending', 'approved', 'rejected'], 'integration.privacy.')]) ?>
    <?= $f('privacy_ref') ?>
    <?= $f('status', ['type' => 'select', 'options' => $opt(['planned', 'active', 'suspended', 'retired'], 'integration.status.'), 'help' => $t('integration.help.status')]) ?>
    <?= $f('notes', ['type' => 'textarea', 'full' => true]) ?>
    <div class="row field--full"><?= $ui('button', ['label' => $t('common.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'checks']) ?>
      <?= $ui('button', ['label' => $t('common.cancel'), 'href' => $url($pid === null ? '/admin/integrations' : '/admin/integrations/' . $pid), 'variant' => 'ghost']) ?></div>
  </section>
</form>
