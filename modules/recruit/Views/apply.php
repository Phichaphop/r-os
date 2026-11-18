<?php
/**
 * Application form (public, phone first): 8 steps with a progress bar (JavaScript shows one step at a time and checks
 * it before moving on; without JavaScript every step shows and the server checks everything).
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $asset @var array<string, mixed> $v
 * @var array<string, mixed> $job @var array<string, string> $errors @var array<string, mixed> $old
 */
use Blue\Modules\Recruit\Lists;

$o = static fn (string $k): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : '';
$row = static fn (string $set, int $i, string $k): string => is_scalar(((array) (((array) ($old[$set] ?? []))[$i] ?? []))[$k] ?? null) ? (string) ((array) ((array) $old[$set])[$i])[$k] : '';
$opts = static function (array $keys, string $prefix) use ($t): array {
    $out = ['' => '—'];
    foreach ($keys as $k) {
        $out[$k] = $t($prefix . $k);
    }
    return $out;
};
$steps = ['consent', 'personal', 'address', 'status', 'education', 'work', 'skills', 'start'];
$notice = (array) $job['notice'];
$stepOf = ['consent' => 'consent', 'consent_name' => 'consent', 'full_name' => 'personal', 'phone' => 'personal', 'email' => 'personal', 'birth_date' => 'personal', 'id_number' => 'personal',
    'expected_salary' => 'personal', 'province' => 'address', 'work' => 'work', 'available_from' => 'start'];
$firstError = null;
foreach ($steps as $s) {
    foreach ($errors as $k => $_) {
        if (($stepOf[$k] ?? (str_starts_with((string) $k, 'file_') ? 'start' : '')) === $s) {
            $firstError ??= $s;
        }
    }
}
?>
<?= $ui('page-header', ['title' => $t('recruit.apply_title'), 'subtitle' => (string) $job['title'] . ' · ' . $job['company'], 'crumbs' => [[$t('recruit.jobs_title'), $url('/recruit/jobs')], [(string) $job['title'], $url('/recruit/jobs/' . $job['public_id'])], [$t('recruit.apply_title'), null]]]) ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('recruit.apply_fix'), 'text' => implode(' · ', array_values($errors))]) ?><?php endif; ?>
<form method="post" action="<?= $e($url('/recruit/jobs/' . $job['public_id'] . '/apply')) ?>" enctype="multipart/form-data" class="apply card stack" novalidate data-apply data-start="<?= $e((string) ($firstError ?? 'consent')) ?>">
  <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
  <div class="apply__trap" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
  <div class="apply__progress" data-apply-progress hidden>
    <p class="text-sm"><span data-apply-count></span> · <strong data-apply-name></strong></p>
    <div class="progress" role="progressbar" aria-label="<?= $e($t('recruit.progress_label')) ?>" aria-valuemin="1" aria-valuemax="<?= count($steps) ?>" aria-valuenow="1" data-apply-bar><span class="progress__bar" data-apply-fill></span></div>
  </div>

  <fieldset class="apply__step stack" data-step="consent" data-title="<?= $e($t('recruit.step.consent')) ?>">
    <legend class="apply__legend">1. <?= $e($t('recruit.step.consent')) ?></legend>
    <div class="notice-box pre-line" tabindex="0" aria-label="<?= $e($t('recruit.notice_title')) ?>"><?= $e((string) $notice['notice_text']) ?></div>
    <p class="text-sm text-muted"><?= $e($t('recruit.notice_version', ['n' => (string) $notice['notice_version']])) ?></p>
    <label class="check"><input type="checkbox" name="consent" value="1" required<?= $o('consent') === '1' ? ' checked' : '' ?>> <?= $e($t('recruit.consent_text')) ?></label>
    <?php if (isset($errors['consent'])): ?><p class="field__error" role="alert"><?= $e($errors['consent']) ?></p><?php endif; ?>
    <?= $ui('field', ['name' => 'consent_name', 'label' => $t('recruit.field.consent_name'), 'required' => true, 'value' => $o('consent_name'), 'help' => $t('recruit.consent_name_help'), 'error' => $errors['consent_name'] ?? null, 'autocomplete' => 'name']) ?>
    <label class="check"><input type="checkbox" name="talent_consent" value="1"<?= $o('talent_consent') === '1' ? ' checked' : '' ?>> <?= $e($t('recruit.talent_consent')) ?></label>
  </fieldset>

  <fieldset class="apply__step stack" data-step="personal" data-title="<?= $e($t('recruit.step.personal')) ?>">
    <legend class="apply__legend">2. <?= $e($t('recruit.step.personal')) ?></legend>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'full_name', 'label' => $t('recruit.field.full_name'), 'required' => true, 'value' => $o('full_name'), 'error' => $errors['full_name'] ?? null, 'autocomplete' => 'name']) ?>
      <?= $ui('field', ['name' => 'nickname', 'label' => $t('recruit.field.nickname'), 'value' => $o('nickname')]) ?>
      <?= $ui('field', ['name' => 'phone', 'type' => 'tel', 'label' => $t('recruit.field.phone'), 'required' => true, 'value' => $o('phone'), 'error' => $errors['phone'] ?? null, 'inputmode' => 'tel', 'autocomplete' => 'tel']) ?>
      <?= $ui('field', ['name' => 'email', 'type' => 'email', 'label' => $t('recruit.field.email'), 'value' => $o('email'), 'error' => $errors['email'] ?? null, 'autocomplete' => 'email']) ?>
      <?= $ui('field', ['name' => 'birth_date', 'type' => 'date', 'label' => $t('recruit.field.birth_date'), 'value' => $o('birth_date'), 'error' => $errors['birth_date'] ?? null]) ?>
      <?= $ui('field', ['name' => 'gender', 'type' => 'select', 'label' => $t('recruit.field.gender'), 'value' => $o('gender'), 'options' => $opts(['male', 'female', 'other'], 'recruit.gender.')]) ?>
      <?= $ui('field', ['name' => 'nationality', 'label' => $t('recruit.field.nationality'), 'value' => $o('nationality')]) ?>
      <?= $ui('field', ['name' => 'expected_salary', 'type' => 'money', 'label' => $t('recruit.field.expected_salary'), 'value' => $o('expected_salary'), 'error' => $errors['expected_salary'] ?? null]) ?>
      <?= $ui('field', ['name' => 'id_type', 'type' => 'select', 'label' => $t('recruit.field.id_type'), 'value' => $o('id_type') ?: 'TH_CITIZEN_ID',
          'options' => ['TH_CITIZEN_ID' => $t('employees.id_type.TH_CITIZEN_ID'), 'TH_PASSPORT' => $t('employees.id_type.TH_PASSPORT'), 'FOREIGN_PASSPORT' => $t('employees.id_type.FOREIGN_PASSPORT'), 'TH_PINK_CARD' => $t('employees.id_type.TH_PINK_CARD')]]) ?>
      <?= $ui('field', ['name' => 'id_number', 'label' => $t('recruit.field.id_number'), 'required' => true, 'value' => $o('id_number'), 'error' => $errors['id_number'] ?? null, 'help' => $t('recruit.id_help'), 'inputmode' => 'numeric', 'autocomplete' => 'off']) ?>
    </div>
  </fieldset>

  <fieldset class="apply__step stack" data-step="address" data-title="<?= $e($t('recruit.step.address')) ?>">
    <legend class="apply__legend">3. <?= $e($t('recruit.step.address')) ?></legend>
    <?= $ui('field', ['name' => 'address', 'type' => 'textarea', 'label' => $t('recruit.field.address'), 'value' => $o('address'), 'autocomplete' => 'street-address']) ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'province', 'type' => 'select', 'label' => $t('recruit.field.province'), 'value' => $o('province'), 'error' => $errors['province'] ?? null,
          'options' => ['' => '—'] + array_combine(Lists::PROVINCES, Lists::PROVINCES)]) ?>
      <?= $ui('field', ['name' => 'postcode', 'label' => $t('recruit.field.postcode'), 'value' => $o('postcode'), 'inputmode' => 'numeric', 'autocomplete' => 'postal-code']) ?>
    </div>
  </fieldset>

  <fieldset class="apply__step stack" data-step="status" data-title="<?= $e($t('recruit.step.status')) ?>">
    <legend class="apply__legend">4. <?= $e($t('recruit.step.status')) ?></legend>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'marital', 'type' => 'select', 'label' => $t('recruit.field.marital'), 'value' => $o('marital'), 'options' => $opts(Lists::MARITAL, 'recruit.marital.')]) ?>
      <?= $ui('field', ['name' => 'military', 'type' => 'select', 'label' => $t('recruit.field.military'), 'value' => $o('military'), 'options' => $opts(Lists::MILITARY, 'recruit.military.')]) ?>
    </div>
    <h3 class="text-sm"><?= $e($t('recruit.emergency')) ?></h3>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'emergency_name', 'label' => $t('recruit.field.emergency_name'), 'value' => $o('emergency_name')]) ?>
      <?= $ui('field', ['name' => 'emergency_phone', 'type' => 'tel', 'label' => $t('recruit.field.phone'), 'value' => $o('emergency_phone'), 'id' => 'f-emergency-phone', 'inputmode' => 'tel']) ?>
      <?= $ui('field', ['name' => 'emergency_relation', 'label' => $t('recruit.field.relation'), 'value' => $o('emergency_relation')]) ?>
    </div>
  </fieldset>

  <fieldset class="apply__step stack" data-step="education" data-title="<?= $e($t('recruit.step.education')) ?>">
    <legend class="apply__legend">5. <?= $e($t('recruit.step.education')) ?></legend>
    <?php for ($i = 0; $i < 3; $i++): ?>
    <div class="apply__row grid grid--3" role="group" aria-label="<?= $e($t('recruit.edu_row', ['n' => (string) ($i + 1)])) ?>">
      <?= $ui('field', ['name' => "edu[$i][level]", 'id' => "edu-$i-level", 'type' => 'select', 'label' => $t('recruit.field.edu_level'), 'value' => $row('edu', $i, 'level'), 'options' => $opts(Lists::EDU_LEVELS, 'recruit.edu.')]) ?>
      <?= $ui('field', ['name' => "edu[$i][institution]", 'id' => "edu-$i-inst", 'label' => $t('recruit.field.institution'), 'value' => $row('edu', $i, 'institution')]) ?>
      <?= $ui('field', ['name' => "edu[$i][major]", 'id' => "edu-$i-major", 'label' => $t('recruit.field.major'), 'value' => $row('edu', $i, 'major')]) ?>
      <?= $ui('field', ['name' => "edu[$i][year]", 'id' => "edu-$i-year", 'label' => $t('recruit.field.grad_year'), 'value' => $row('edu', $i, 'year'), 'inputmode' => 'numeric', 'placeholder' => '2020']) ?>
      <?= $ui('field', ['name' => "edu[$i][gpa]", 'id' => "edu-$i-gpa", 'label' => $t('recruit.field.gpa'), 'value' => $row('edu', $i, 'gpa'), 'inputmode' => 'decimal', 'placeholder' => '3.25']) ?>
    </div>
    <?php endfor; ?>
  </fieldset>

  <fieldset class="apply__step stack" data-step="work" data-title="<?= $e($t('recruit.step.work')) ?>">
    <legend class="apply__legend">6. <?= $e($t('recruit.step.work')) ?></legend>
    <?php if (isset($errors['work'])): ?><p class="field__error" role="alert"><?= $e($errors['work']) ?></p><?php endif; ?>
    <?php for ($i = 0; $i < 3; $i++): ?>
    <div class="apply__row grid grid--3" role="group" aria-label="<?= $e($t('recruit.work_row', ['n' => (string) ($i + 1)])) ?>">
      <?= $ui('field', ['name' => "work[$i][company]", 'id' => "work-$i-co", 'label' => $t('recruit.field.company'), 'value' => $row('work', $i, 'company')]) ?>
      <?= $ui('field', ['name' => "work[$i][position]", 'id' => "work-$i-pos", 'label' => $t('recruit.field.position'), 'value' => $row('work', $i, 'position')]) ?>
      <?= $ui('field', ['name' => "work[$i][from]", 'id' => "work-$i-from", 'type' => 'month', 'label' => $t('recruit.field.from'), 'value' => $row('work', $i, 'from'), 'placeholder' => '2021-06']) ?>
      <?= $ui('field', ['name' => "work[$i][to]", 'id' => "work-$i-to", 'type' => 'month', 'label' => $t('recruit.field.to'), 'value' => $row('work', $i, 'to'), 'help' => $t('recruit.to_help')]) ?>
      <?= $ui('field', ['name' => "work[$i][reason]", 'id' => "work-$i-why", 'label' => $t('recruit.field.leave_reason'), 'value' => $row('work', $i, 'reason')]) ?>
    </div>
    <?php endfor; ?>
    <p class="text-sm" data-apply-years data-label="<?= $e($t('recruit.years_total')) ?>" aria-live="polite"></p>
  </fieldset>

  <fieldset class="apply__step stack" data-step="skills" data-title="<?= $e($t('recruit.step.skills')) ?>">
    <legend class="apply__legend">7. <?= $e($t('recruit.step.skills')) ?></legend>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'lang_th', 'type' => 'select', 'label' => $t('recruit.lang_th'), 'value' => $o('lang_th'), 'options' => $opts(Lists::LANG_LEVELS, 'recruit.lang.')]) ?>
      <?= $ui('field', ['name' => 'lang_en', 'type' => 'select', 'label' => $t('recruit.lang_en'), 'value' => $o('lang_en'), 'options' => $opts(Lists::LANG_LEVELS, 'recruit.lang.')]) ?>
      <?= $ui('field', ['name' => 'lang_other', 'label' => $t('recruit.field.lang_other'), 'value' => $o('lang_other')]) ?>
    </div>
    <?= $ui('field', ['name' => 'skills', 'type' => 'textarea', 'label' => $t('recruit.field.skills'), 'value' => $o('skills'), 'help' => $t('recruit.skills_help')]) ?>
  </fieldset>

  <fieldset class="apply__step stack" data-step="start" data-title="<?= $e($t('recruit.step.start')) ?>">
    <legend class="apply__legend">8. <?= $e($t('recruit.step.start')) ?></legend>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'available_from', 'type' => 'date', 'label' => $t('recruit.field.available_from'), 'value' => $o('available_from'), 'error' => $errors['available_from'] ?? null]) ?>
      <?= $ui('field', ['name' => 'heard_from', 'label' => $t('recruit.field.heard_from'), 'value' => $o('heard_from')]) ?>
    </div>
    <h3 class="text-sm"><?= $e($t('recruit.files_title')) ?></h3>
    <p class="text-sm text-muted"><?= $e($t('recruit.files_help')) ?></p>
    <div class="grid grid--2">
      <?php foreach (array_keys(Lists::DOC_GROUPS) as $g): ?>
        <?= $ui('field', ['name' => 'file_' . $g, 'type' => 'file', 'label' => $t('recruit.group.' . $g) . (in_array($g, (array) $notice['required_docs'], true) ? ' *' : ''), 'accept' => '.pdf,.jpg,.jpeg,.png', 'error' => $errors['file_' . $g] ?? null]) ?>
      <?php endforeach; ?>
    </div>
    <p class="text-sm text-muted">* <?= $e($t('recruit.files_required_later')) ?></p>
  </fieldset>

  <div class="apply__nav row" data-apply-nav hidden>
    <button type="button" class="btn btn--ghost" data-apply-back><?= $ui('icon', ['name' => 'chevron-left', 'size' => 'sm']) ?><?= $e($t('recruit.back')) ?></button>
    <button type="button" class="btn btn--primary" data-apply-next><?= $e($t('recruit.next')) ?><?= $ui('icon', ['name' => 'chevron-right', 'size' => 'sm']) ?></button>
  </div>
  <div class="row" data-apply-submit><?= $ui('button', ['label' => $t('recruit.apply_submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
</form>
<script src="<?= $e($asset('ui/js/recruit.js')) ?>" defer></script>
