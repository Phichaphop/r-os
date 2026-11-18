<?php
/**
 * Add a person to the pool: CV upload, walk-in or referral — with the consent date and basis.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var array<string, string> $errors @var array<string, mixed> $old @var string $today @var string|null $until
 */
use Blue\Modules\Recruit\Lists;
use Blue\Modules\Talent\TalentService;

$o = static fn (string $k, string $d = ''): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
?>
<?= $ui('page-header', ['title' => $t('talent.new_title'), 'subtitle' => $t('talent.new_subtitle'), 'crumbs' => [[$t('talent.title'), $url('/talent?le=' . rawurlencode($lePid))], [$t('talent.new_title'), null]]]) ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'text' => $t('form.has_errors')]) ?><?php endif; ?>
<form method="post" action="<?= $e($url('/talent/new')) ?>" enctype="multipart/form-data" class="card stack" novalidate>
  <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
  <div class="grid grid--3">
    <?php if (count($companies) > 1): ?>
      <?= $ui('field', ['name' => 'le', 'type' => 'select', 'label' => $t('talent.company'), 'value' => $lePid, 'options' => $companies]) ?>
    <?php else: ?><input type="hidden" name="le" value="<?= $e($lePid) ?>"><?php endif; ?>
    <?= $ui('field', ['name' => 'source', 'type' => 'select', 'label' => $t('talent.field.source'), 'value' => $o('source', 'upload'),
        'options' => array_combine(TalentService::SOURCES, array_map(static fn (string $x): string => $t('talent.source.' . $x), TalentService::SOURCES))]) ?>
    <?= $ui('field', ['name' => 'referred_by', 'label' => $t('talent.field.referred_by'), 'value' => $o('referred_by'), 'help' => $t('talent.referred_help'), 'error' => $errors['referred_by'] ?? null]) ?>
  </div>
  <div class="grid grid--3">
    <?= $ui('field', ['name' => 'name', 'label' => $t('talent.field.name'), 'required' => true, 'value' => $o('name'), 'autocomplete' => 'off', 'error' => $errors['name'] ?? null]) ?>
    <?= $ui('field', ['name' => 'email', 'type' => 'email', 'label' => $t('talent.field.email'), 'value' => $o('email'), 'autocomplete' => 'off', 'error' => $errors['email'] ?? null]) ?>
    <?= $ui('field', ['name' => 'phone', 'type' => 'tel', 'label' => $t('talent.field.phone'), 'value' => $o('phone'), 'inputmode' => 'tel', 'autocomplete' => 'off', 'error' => $errors['phone'] ?? null, 'help' => $t('talent.contact_help')]) ?>
  </div>
  <div class="grid grid--3">
    <?= $ui('field', ['name' => 'positions', 'label' => $t('talent.field.positions'), 'value' => $o('positions'), 'help' => $t('talent.positions_help')]) ?>
    <?= $ui('field', ['name' => 'experience_years', 'label' => $t('talent.field.experience_years'), 'value' => $o('experience_years'), 'inputmode' => 'decimal', 'error' => $errors['experience_years'] ?? null]) ?>
    <?= $ui('field', ['name' => 'education_level', 'type' => 'select', 'label' => $t('talent.field.education_level'), 'value' => $o('education_level'),
        'options' => ['' => '—'] + array_combine(Lists::EDU_LEVELS, array_map(static fn (string $x): string => $t('recruit.edu.' . $x), Lists::EDU_LEVELS))]) ?>
  </div>
  <?= $ui('field', ['name' => 'skills', 'type' => 'textarea', 'label' => $t('talent.field.skills'), 'value' => $o('skills'), 'help' => $t('talent.skills_help')]) ?>
  <?= $ui('field', ['name' => 'cv', 'type' => 'file', 'label' => $t('talent.field.cv'), 'accept' => '.pdf,.docx,.jpg,.jpeg,.png', 'help' => $t('talent.cv_help'), 'error' => $errors['cv'] ?? null]) ?>
  <fieldset class="stack-sm">
    <legend class="card__title"><?= $e($t('talent.consent')) ?></legend>
    <p class="text-sm text-muted"><?= $e($t('talent.consent_help')) ?></p>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'consent_at', 'type' => 'date', 'label' => $t('talent.field.consent_at'), 'required' => true, 'value' => $o('consent_at', $today), 'max' => $today, 'error' => $errors['consent_at'] ?? null]) ?>
      <?= $ui('field', ['name' => 'consent_basis', 'label' => $t('talent.field.consent_basis'), 'required' => true, 'value' => $o('consent_basis'), 'placeholder' => $t('talent.basis_hint'), 'error' => $errors['consent_basis'] ?? null]) ?>
    </div>
    <p class="text-sm"><?= $ui('icon', ['name' => 'hourglass', 'size' => 'sm']) ?> <?= $e($until !== null ? $t('talent.until_preview', ['d' => $date($until, false)]) : $t('talent.no_rule')) ?></p>
  </fieldset>
  <?= $ui('field', ['name' => 'notes', 'type' => 'textarea', 'label' => $t('talent.field.notes'), 'value' => $o('notes')]) ?>
  <p class="text-sm text-muted"><?= $e($t('talent.dup_help')) ?></p>
  <div class="row"><?= $ui('button', ['label' => $t('talent.save_new'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></div>
</form>
