<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var string $kind @var bool $isNew @var array<string, mixed> $row @var array<string, mixed>|null $parent
 * @var array<string, string> $errors @var array<string, string> $old @var array<string, string> $sites @var array<string, string> $depts @var string $action
 */
$val = static fn (string $k, mixed $fallback = '') => $old[$k] ?? $fallback;
$names = $row['names'] ?? [];
$title = $t('org.form.' . $kind . ($isNew ? '.new' : '.edit'));
?>
<?= $ui('page-header', ['title' => $title, 'subtitle' => $parent !== null ? $t('org.under', ['name' => (string) ($parent['names']['th'] ?? $parent['code'])]) : null,
    'crumbs' => [[$t('org.title'), $url('/admin/org')], [$title, null]]]) ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'text' => $t('form.has_errors')]) ?><?php endif; ?>
<section class="card" aria-label="<?= $e($title) ?>">
  <form method="post" action="<?= $e($url($action)) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?php if (!$isNew): ?><input type="hidden" name="row_version" value="<?= (int) $row['row_version'] ?>"><?php endif; ?>
    <div class="form-grid">
      <?= $ui('field', ['name' => 'code', 'label' => $t('org.field.code'), 'required' => true, 'value' => $val('code', $row['code'] ?? ''), 'error' => $errors['code'] ?? null,
          'help' => $t('org.field.code_help'), 'readonly' => !$isNew && $kind !== 'legal-entity']) ?>
      <?php if ($kind === 'legal-entity'): ?>
        <?= $ui('field', ['name' => 'registration_no', 'label' => $t('org.field.registration_no'), 'value' => $val('registration_no', $row['registration_no'] ?? ''), 'error' => $errors['registration_no'] ?? null, 'help' => $t('org.field.registration_help')]) ?>
      <?php endif; ?>
      <?= $ui('field', ['name' => 'name_th', 'label' => $t('org.field.name_th'), 'required' => true, 'value' => $val('name_th', $names['th'] ?? ''), 'error' => $errors['name_th'] ?? null]) ?>
      <?= $ui('field', ['name' => 'name_en', 'label' => $t('org.field.name_en'), 'value' => $val('name_en', $names['en'] ?? ''), 'error' => $errors['name_en'] ?? null]) ?>
      <?php if ($kind === 'legal-entity'): ?>
        <?= $ui('field', ['name' => 'country_code', 'label' => $t('org.field.country'), 'type' => 'select', 'value' => 'TH', 'options' => ['TH' => $t('org.country.TH')], 'help' => $t('org.field.country_help'), 'disabled' => !$isNew]) ?>
        <?= $ui('field', ['name' => 'fiscal_year_start_month', 'label' => $t('org.field.fiscal_start'), 'type' => 'select', 'value' => (string) $val('fiscal_year_start_month', $row['fiscal_year_start_month'] ?? 1),
            'options' => array_combine(range(1, 12), array_map(static fn ($m) => $t('month.' . $m), range(1, 12))), 'error' => $errors['fiscal_year_start_month'] ?? null]) ?>
      <?php elseif ($kind === 'site'): ?>
        <?= $ui('field', ['name' => 'site_type', 'label' => $t('org.field.site_type'), 'type' => 'select', 'value' => $val('site_type', $row['site_type'] ?? 'branch'),
            'options' => array_combine(\Blue\Core\Org\OrgService::SITE_TYPES, array_map(static fn ($s) => $t('org.site_type.' . $s), \Blue\Core\Org\OrgService::SITE_TYPES))]) ?>
        <?php if ($isNew): ?><?= $ui('field', ['name' => 'branch_no', 'label' => $t('org.field.branch_no'), 'value' => $val('branch_no'), 'error' => $errors['branch_no'] ?? null, 'help' => $t('org.field.branch_help')]) ?><?php endif; ?>
      <?php else: ?>
        <?php
        $levelOpts = [];
        foreach (\Blue\Core\Org\OrgService::UNIT_LEVELS as $lv) {
            $levelOpts[$lv] = $t('org.level.' . $lv);
        }
        ?>
        <?= $ui('field', ['name' => 'level', 'label' => $t('org.field.level'), 'type' => 'select', 'required' => true, 'value' => $val('level', $row['unit_level'] ?? 'department'),
            'options' => $levelOpts, 'error' => $errors['level'] ?? null, 'help' => $t('org.field.level_help')]) ?>
        <?= $ui('field', ['name' => 'parent', 'label' => $t('org.field.parent'), 'type' => 'select', 'value' => $val('parent', (string) ($row['parent_public_id'] ?? '')),
            'options' => ['' => $t('org.parent_company')] + $depts, 'error' => $errors['parent'] ?? null, 'help' => $t('org.field.parent_help')]) ?>
        <?= $ui('field', ['name' => 'site', 'label' => $t('org.field.site'), 'type' => 'select', 'value' => $val('site', (string) ($row['site_public_id'] ?? '')), 'options' => ['' => $t('org.site_all')] + $sites,
            'error' => $errors['site'] ?? null, 'help' => $t('org.field.site_help')]) ?>
      <?php endif; ?>
      <?php if (!$isNew): ?>
        <?= $ui('field', ['name' => 'status', 'label' => $t('iam.field.status'), 'type' => 'select', 'value' => $val('status', $row['status'] ?? 'active'),
            'options' => ['active' => $t('org.status.active'), 'inactive' => $t('org.status.inactive')]]) ?>
      <?php endif; ?>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('common.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?>
      <?= $ui('button', ['label' => $t('common.cancel'), 'href' => $url('/admin/org'), 'variant' => 'ghost']) ?></div>
  </form>
</section>
