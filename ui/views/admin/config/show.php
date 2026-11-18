<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $def @var list<array<string, mixed>> $versions @var ?\Blue\Core\Registry\ResolvedConfig $resolved
 * @var array{le: ?int, site: ?int, at: \DateTimeImmutable} $resolveFor @var array<string, array<int, string>> $scopeOptions
 * @var int $orgId @var bool $canPropose @var array<string, string> $errors @var array<string, string> $old @var \DateTimeZone $tz
 */
use Blue\Core\Registry\ConfigValue;

$rules = ConfigValue::rules($def);
$levels = explode(',', (string) $def['allowed_scope_levels']);
$scopeChoices = [];
if (in_array('organization', $levels, true)) {
    $scopeChoices['organization:' . $orgId] = $t('config.scope.organization');
}
foreach (['legal_entity', 'site'] as $lv) {
    if (in_array($lv, $levels, true)) {
        foreach ($scopeOptions[$lv] ?? [] as $id => $label) {
            $scopeChoices[$lv . ':' . $id] = $t('config.scope.' . $lv) . ' ' . $label;
        }
    }
}
$valueHelp = match ((string) $def['data_type']) {
    'decimal' => $t('config.help.decimal', ['precision' => (string) ($rules['precision'] ?? $def['precision_digits'] ?? 4), 'min' => (string) ($rules['min'] ?? '—'), 'max' => (string) ($rules['max'] ?? '—')]),
    'integer' => $t('config.help.integer', ['min' => (string) ($rules['min'] ?? '—'), 'max' => (string) ($rules['max'] ?? '—')]),
    'enum' => $t('config.help.enum', ['values' => implode(', ', (array) ($rules['values'] ?? []))]),
    'color' => $t('config.help.color'),
    'boolean' => $t('config.help.boolean'),
    default => '',
};
$today = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
?>
<?= $ui('page-header', ['title' => (string) $def['config_key'], 'subtitle' => $t('config.desc.' . $def['config_key']),
    'actions' => $ui('button', ['label' => $t('common.back'), 'href' => $url('/admin/config'), 'variant' => 'ghost', 'icon' => 'arrow-back-up'])]) ?>

<div class="grid grid--2">
  <section class="card" aria-labelledby="def-h">
    <h2 id="def-h" class="card__title"><?= $e($t('config.definition')) ?></h2>
    <dl class="dl">
      <dt><?= $e($t('config.field.type')) ?></dt><dd><?= $e($t('config.type.' . $def['data_type'])) ?><?= $def['unit'] !== null ? ' · ' . $e((string) $def['unit']) : '' ?></dd>
      <dt><?= $e($t('config.field.source')) ?></dt><dd><?= $e($t('config.source.' . $def['source_type'])) ?></dd>
      <dt><?= $e($t('config.field.risk')) ?></dt><dd><?= $e($t('config.risk.' . $def['risk_level'])) ?></dd>
      <dt><?= $e($t('config.field.scopes')) ?></dt><dd><?= $e(implode(', ', array_map(static fn ($l) => $t('config.scope.' . $l), $levels))) ?></dd>
      <dt><?= $e($t('config.field.required')) ?></dt><dd><?= $e($t((int) $def['is_required'] === 1 ? 'common.yes' : 'common.no')) ?></dd>
    </dl>
  </section>

  <section class="card" aria-labelledby="res-h">
    <h2 id="res-h" class="card__title"><?= $e($t('config.resolve.title')) ?></h2>
    <form method="get" action="<?= $e($url('/admin/config/key/' . $def['config_key'])) ?>" class="filter-bar">
      <?= $ui('field', ['name' => 'le', 'label' => $t('config.scope.legal_entity'), 'type' => 'select', 'value' => (string) ($resolveFor['le'] ?? ''), 'options' => ['' => '—'] + ($scopeOptions['legal_entity'] ?? [])]) ?>
      <?= $ui('field', ['name' => 'site', 'label' => $t('config.scope.site'), 'type' => 'select', 'value' => (string) ($resolveFor['site'] ?? ''), 'options' => ['' => '—'] + ($scopeOptions['site'] ?? [])]) ?>
      <?= $ui('field', ['name' => 'at', 'label' => $t('config.resolve.at'), 'type' => 'date', 'value' => $resolveFor['at']->setTimezone($tz)->format('Y-m-d')]) ?>
      <?= $ui('button', ['label' => $t('config.resolve.go'), 'type' => 'submit', 'icon' => 'search']) ?>
    </form>
    <?php if ($resolved === null): ?>
      <?= $ui('alert', ['tone' => (int) $def['is_required'] === 1 ? 'danger' : 'info', 'text' => $t((int) $def['is_required'] === 1 ? 'config.resolve.missing_required' : 'config.resolve.missing')]) ?>
    <?php else: ?>
      <p class="kpi mono"><?= $e(ConfigValue::display($resolved->value)) ?> <?= $e((string) $resolved->unit) ?></p>
      <p class="text-sm text-muted"><?= $e($t('config.resolve.from', ['scope' => $t('config.scope.' . $resolved->sourceScope), 'since' => $date($resolved->effectiveFrom)])) ?>
        · <a href="<?= $e($url('/admin/config/version/' . $resolved->versionPublicId)) ?>"><?= $e($t('config.resolve.version')) ?></a></p>
      <p class="text-sm text-muted"><?= $e($t('config.resolve.path')) ?> <span class="mono"><?= $e(implode(' → ', $resolved->path)) ?></span></p>
    <?php endif; ?>
  </section>
</div>

<section class="card" aria-labelledby="ver-h">
  <h2 id="ver-h" class="card__title"><?= $e($t('config.versions')) ?></h2>
  <div class="table-wrap" role="region" aria-label="<?= $e($t('config.versions')) ?>" tabindex="0">
    <table class="table table--stack">
      <caption class="sr-only"><?= $e($t('config.versions')) ?></caption>
      <thead><tr><th scope="col"><?= $e($t('config.field.scope')) ?></th><th scope="col"><?= $e($t('config.field.value')) ?></th>
        <th scope="col"><?= $e($t('config.field.effective')) ?></th><th scope="col"><?= $e($t('config.field.status')) ?></th><th scope="col"><?= $e($t('config.field.people')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($versions as $ver): ?>
        <tr>
          <td data-label="<?= $e($t('config.field.scope')) ?>"><?= $e($t('config.scope.' . $ver['scope_level'])) ?><?= $ver['scope_level'] !== 'organization' ? ' #' . (int) $ver['scope_id'] : '' ?></td>
          <td data-label="<?= $e($t('config.field.value')) ?>"><a class="mono" href="<?= $e($url('/admin/config/version/' . $ver['public_id'])) ?>"><?= $e(ConfigValue::display(json_decode((string) $ver['value_json'], true))) ?></a></td>
          <td data-label="<?= $e($t('config.field.effective')) ?>"><?= $e($date($ver['effective_from'])) ?> → <?= $ver['effective_to'] !== null ? $e($date($ver['effective_to'])) : $e($t('config.open_ended')) ?></td>
          <td data-label="<?= $e($t('config.field.status')) ?>"><?php $st = (string) $ver['display_status']; include __DIR__ . '/_status.php'; ?></td>
          <td data-label="<?= $e($t('config.field.people')) ?>" class="text-sm"><?= $e($t('config.by', ['who' => (string) $ver['proposer_name']])) ?><?= $ver['decider_name'] !== null ? '<br>' . $e($t('config.decided_by', ['who' => (string) $ver['decider_name']])) : '' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($versions === []): ?><tr><td colspan="5"><?= $ui('states', ['title' => $t('config.no_versions')]) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php if ($canPropose): ?>
<section class="card" id="propose" aria-labelledby="prop-h">
  <h2 id="prop-h" class="card__title"><?= $e($t('config.propose.title')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t($def['risk_level'] === 'high' ? 'config.propose.help_high' : 'config.propose.help_normal')) ?></p>
  <form method="post" action="<?= $e($url('/admin/config/key/' . $def['config_key'] . '/propose')) ?>" class="form-grid" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?= $ui('field', ['name' => 'value', 'label' => $t('config.field.value') . ($def['unit'] !== null ? ' (' . $def['unit'] . ')' : ''), 'required' => true, 'value' => $old['value'] ?? '', 'error' => $errors['value'] ?? null, 'help' => $valueHelp]) ?>
    <?= $ui('field', ['name' => 'scope', 'label' => $t('config.field.scope'), 'type' => 'select', 'required' => true, 'value' => $old['scope'] ?? '', 'options' => $scopeChoices, 'error' => $errors['scope'] ?? null]) ?>
    <?= $ui('field', ['name' => 'from_date', 'label' => $t('config.field.from_date'), 'type' => 'date', 'required' => true, 'value' => $old['from_date'] ?? $today, 'error' => $errors['effective_from'] ?? null, 'help' => $t('config.help.tz', ['tz' => $tz->getName()])]) ?>
    <?= $ui('field', ['name' => 'from_time', 'label' => $t('config.field.from_time'), 'value' => $old['from_time'] ?? '00:00', 'placeholder' => '00:00']) ?>
    <?= $ui('field', ['name' => 'to_date', 'label' => $t('config.field.to_date'), 'type' => 'date', 'value' => $old['to_date'] ?? '', 'error' => $errors['effective_to'] ?? null, 'help' => $t('config.help.to_date')]) ?>
    <?= $ui('field', ['name' => 'source_reference', 'label' => $t('config.field.source_reference'), 'required' => $def['source_type'] === 'LAW', 'value' => $old['source_reference'] ?? '', 'error' => $errors['source_reference'] ?? null, 'help' => $t('config.help.source_reference')]) ?>
    <?= $ui('field', ['name' => 'reason', 'label' => $t('iam.field.reason'), 'required' => true, 'value' => $old['reason'] ?? '', 'error' => $errors['reason'] ?? null, 'full' => true]) ?>
    <div class="row field--full"><?= $ui('button', ['label' => $t('config.propose.save_draft'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'pencil']) ?></div>
  </form>
</section>
<?php endif; ?>
