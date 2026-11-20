<?php
/**
 * Personal tab (employees.personal / employees.identifiers): personal data, contacts, emergency contacts,
 * ID numbers (last 4; full number after a fresh sign-in check), education, previous jobs.
 * Uses the variables of show.php + $profile, $revealed.
 */
use Blue\Modules\Employees\FileRules;
use Blue\Modules\Employees\ProfileService;

$pp = $profile['personal'];
$po = $form === 'personal' ? $old : ($pp ?? []) + ['work_email' => $emp['work_email'], 'work_phone' => $emp['work_phone']];
$g = static fn (array $a, string $k): string => is_scalar($a[$k] ?? null) ? (string) $a[$k] : '';
$genders = ['' => '—', 'male' => $t('employees.gender.male'), 'female' => $t('employees.gender.female'), 'other' => $t('employees.gender.other'), 'unspecified' => $t('employees.gender.unspecified')];
$contacts = $form === 'contacts' ? (array) ($old['contacts'] ?? []) : (array) ($profile['contacts'] ?? []);
$emerg = $form === 'contacts' ? (array) ($old['emergency'] ?? []) : (array) ($profile['emergency'] ?? []);
while (count($emerg) < 2) {
    $emerg[] = ['name' => '', 'relation' => '', 'phone' => ''];
}
$idTypes = [];
foreach (FileRules::IDENTIFIER_TYPES as $it) {
    $idTypes[$it] = $t('employees.id_type.' . $it);
}
$levels = [];
foreach (ProfileService::EDUCATION as $lv) {
    $levels[$lv] = $t('employees.edu.' . $lv);
}
?>
<?php if ($pp !== null && !$acc['manage']): ?>
<section class="card stack-sm" aria-labelledby="ep-p">
  <h2 id="ep-p" class="card__title"><?= $e($t('employees.section.personal')) ?></h2>
  <dl class="dl">
    <dt><?= $e($t('employees.field.title')) ?></dt><dd><?= $e($g($pp, 'title') ?: '—') ?></dd>
    <dt><?= $e($t('employees.field.birth_date')) ?></dt><dd><?= $e($day($pp['birth_date'] ?? null)) ?></dd>
    <dt><?= $e($t('employees.field.gender')) ?></dt><dd><?= $e($genders[$g($pp, 'gender')] ?? '—') ?></dd>
    <dt><?= $e($t('employees.field.nationality')) ?></dt><dd><?= $e($g($pp, 'nationality') ?: '—') ?></dd>
    <?php foreach (ProfileService::CONTACTS as $ct): ?>
      <dt><?= $e($t('employees.contact.' . $ct)) ?></dt><dd><?= $e((string) ($contacts[$ct] ?? '—')) ?></dd>
    <?php endforeach; ?>
    <dt><?= $e($t('employees.section.tax_address')) ?></dt><dd><?= $e(\Blue\Modules\Employees\ProfileService::addressLine($profile['tax_address'] ?? null) ?? '—') ?></dd>
    <?php foreach ((array) $profile['emergency'] as $em): ?>
      <dt><?= $e($t('employees.section.emergency')) ?></dt><dd><?= $e($em['name'] . ' (' . $em['relation'] . ') ' . $em['phone']) ?></dd>
    <?php endforeach; ?>
  </dl>
</section>
<?php endif; ?>

<?php if ($pp !== null && $acc['manage']): ?>
<section class="card stack" aria-labelledby="ep-p">
  <h2 id="ep-p" class="card__title"><?= $e($t('employees.section.personal')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('employees.personal_help')) ?></p>
  <form method="post" action="<?= $e($url($base . '/personal')) ?>" class="stack" novalidate>
    <?= $csrf ?>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'title', 'label' => $t('employees.field.title'), 'value' => $g($po, 'title'), 'error' => $errors['title'] ?? null, 'placeholder' => $t('employees.title_example')]) ?>
      <?= $ui('field', ['name' => 'birth_date', 'type' => 'date', 'label' => $t('employees.field.birth_date'), 'value' => $g($po, 'birth_date'), 'error' => $errors['birth_date'] ?? null]) ?>
      <?= $ui('field', ['name' => 'gender', 'type' => 'select', 'label' => $t('employees.field.gender'), 'value' => $g($po, 'gender'), 'options' => $genders, 'error' => $errors['gender'] ?? null]) ?>
      <?= $ui('field', ['name' => 'nationality', 'label' => $t('employees.field.nationality'), 'value' => $g($po, 'nationality'), 'error' => $errors['nationality'] ?? null, 'help' => $t('employees.country_help')]) ?>
      <?= $ui('field', ['name' => 'work_email', 'type' => 'email', 'label' => $t('employees.field.work_email'), 'value' => $g($po, 'work_email'), 'error' => $errors['work_email'] ?? null]) ?>
      <?= $ui('field', ['name' => 'work_phone', 'label' => $t('employees.field.work_phone'), 'value' => $g($po, 'work_phone'), 'error' => $errors['work_phone'] ?? null]) ?>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('employees.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
  </form>
</section>

<section class="card stack" aria-labelledby="ep-c">
  <h2 id="ep-c" class="card__title"><?= $e($t('employees.section.contacts')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('employees.contacts_help')) ?></p>
  <form method="post" action="<?= $e($url($base . '/contacts')) ?>" class="stack" novalidate>
    <?= $csrf ?>
    <?php include __DIR__ . '/_contacts_fields.php'; ?>
    <div class="row"><?= $ui('button', ['label' => $t('employees.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
  </form>
</section>
<?php endif; ?>

<?php if ($profile['identifiers'] !== null): ?>
<section class="card card--flush" aria-labelledby="ep-id">
  <div class="card__head"><h2 id="ep-id" class="card__title"><?= $e($t('employees.section.identifiers')) ?></h2></div>
  <p class="card__pad text-sm text-muted"><?= $e($t('employees.identifiers_help')) ?></p>
  <div class="table-wrap" role="region" aria-labelledby="ep-id" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('employees.field.id_type')) ?></th><th scope="col"><?= $e($t('employees.field.id_value')) ?></th>
        <th scope="col"><?= $e($t('employees.field.expiry_date')) ?></th><th scope="col"><?= $e($t('employees.field.status')) ?></th><th scope="col"><span class="sr-only"><?= $e($t('employees.actions')) ?></span></th></tr></thead>
      <tbody>
      <?php foreach ($profile['identifiers'] as $i): ?>
        <tr>
          <td data-label="<?= $e($t('employees.field.id_type')) ?>"><?= $e($t('employees.id_type.' . $i['id_type'])) ?><?= $i['issue_country'] !== null ? ' · ' . $e((string) $i['issue_country']) : '' ?></td>
          <td data-label="<?= $e($t('employees.field.id_value')) ?>" class="mono"><?= isset($revealed[$i['public_id']]) ? $e($revealed[$i['public_id']]) : '•••• ' . $e((string) $i['last4']) ?></td>
          <td data-label="<?= $e($t('employees.field.expiry_date')) ?>"><?= $e($day($i['expiry_date'] !== null ? (string) $i['expiry_date'] : null)) ?></td>
          <td data-label="<?= $e($t('employees.field.status')) ?>"><?= $ui('badge', ['tone' => $i['status'] === 'current' ? 'success' : 'neutral', 'label' => $t('employees.id_status.' . $i['status'])]) ?></td>
          <td>
            <?php if ($acc['identifiers'] && !isset($revealed[$i['public_id']])): ?>
              <form method="post" action="<?= $e($url($base . '/identifiers/' . $i['public_id'] . '/reveal')) ?>"><?= $csrf ?><input type="hidden" name="_back" value="<?= $e($base . '?tab=personal') ?>">
                <?= $ui('button', ['label' => $t('employees.reveal'), 'type' => 'submit', 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'eye']) ?></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($profile['identifiers'] === []): ?><tr><td colspan="5"><?= $e($t('employees.none_yet')) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($acc['identifiers'] && $acc['manage']): ?>
  <details class="card__pad"<?= $form === 'identifier' ? ' open' : '' ?>>
    <summary><?= $e($t('employees.add_identifier')) ?></summary>
    <form method="post" action="<?= $e($url($base . '/identifiers')) ?>" class="stack" novalidate autocomplete="off">
      <?= $csrf ?>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'id_type', 'type' => 'select', 'label' => $t('employees.field.id_type'), 'required' => true, 'value' => (string) ($form === 'identifier' ? ($old['id_type'] ?? '') : 'TH_CITIZEN_ID'),
            'options' => $idTypes, 'error' => $errors['id_type'] ?? null]) ?>
        <?= $ui('field', ['name' => 'id_value', 'label' => $t('employees.field.id_value'), 'required' => true, 'value' => '', 'error' => $errors['id_value'] ?? null, 'autocomplete' => 'off',
            'help' => $t('employees.id_value_help')]) ?>
        <?= $ui('field', ['name' => 'issue_country', 'label' => $t('employees.field.issue_country'), 'value' => (string) ($form === 'identifier' ? ($old['issue_country'] ?? '') : ''), 'error' => $errors['issue_country'] ?? null,
            'help' => $t('employees.country_help')]) ?>
        <?= $ui('field', ['name' => 'expiry_date', 'type' => 'date', 'label' => $t('employees.field.expiry_date'), 'value' => (string) ($form === 'identifier' ? ($old['expiry_date'] ?? '') : ''), 'error' => $errors['expiry_date'] ?? null]) ?>
      </div>
      <div class="row"><?= $ui('button', ['label' => $t('employees.add_identifier'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></div>
    </form>
  </details>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($pp !== null): ?>
<section class="card card--flush" aria-labelledby="ep-ed">
  <div class="card__head"><h2 id="ep-ed" class="card__title"><?= $e($t('employees.section.education')) ?></h2></div>
  <div class="table-wrap" role="region" aria-labelledby="ep-ed" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('employees.field.level')) ?></th><th scope="col"><?= $e($t('employees.field.institution')) ?></th><th scope="col"><?= $e($t('employees.field.edu_field')) ?></th>
        <th scope="col" class="num"><?= $e($t('employees.field.graduated_year')) ?></th><th scope="col"><span class="sr-only"><?= $e($t('employees.actions')) ?></span></th></tr></thead>
      <tbody>
      <?php foreach ($profile['education'] as $ed): ?>
        <tr>
          <td data-label="<?= $e($t('employees.field.level')) ?>"><?= $e($t('employees.edu.' . $ed['level'])) ?></td>
          <td data-label="<?= $e($t('employees.field.institution')) ?>"><?= $e((string) $ed['institution']) ?></td>
          <td data-label="<?= $e($t('employees.field.edu_field')) ?>"><?= $e((string) ($ed['field'] ?? '—')) ?></td>
          <td data-label="<?= $e($t('employees.field.graduated_year')) ?>" class="num"><?= $ed['graduated_year'] !== null ? $e((string) ($loc === 'th' ? (int) $ed['graduated_year'] + 543 : (int) $ed['graduated_year'])) : '—' ?></td>
          <td><?php if ($acc['manage']): ?><form method="post" action="<?= $e($url($base . '/education/' . $ed['public_id'] . '/remove')) ?>"><?= $csrf ?>
            <?= $ui('button', ['label' => $t('employees.remove'), 'type' => 'submit', 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'trash']) ?></form><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($profile['education'] === []): ?><tr><td colspan="5"><?= $e($t('employees.none_yet')) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($acc['manage']): ?>
  <details class="card__pad"<?= $form === 'education' ? ' open' : '' ?>>
    <summary><?= $e($t('employees.add_education')) ?></summary>
    <form method="post" action="<?= $e($url($base . '/education')) ?>" class="stack" novalidate>
      <?= $csrf ?>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'level', 'type' => 'select', 'label' => $t('employees.field.level'), 'required' => true, 'value' => (string) ($form === 'education' ? ($old['level'] ?? '') : 'bachelor'), 'options' => $levels]) ?>
        <?= $ui('field', ['name' => 'institution', 'label' => $t('employees.field.institution'), 'required' => true, 'value' => (string) ($form === 'education' ? ($old['institution'] ?? '') : ''), 'error' => $errors['institution'] ?? null]) ?>
        <?= $ui('field', ['name' => 'field', 'label' => $t('employees.field.edu_field'), 'value' => (string) ($form === 'education' ? ($old['field'] ?? '') : '')]) ?>
        <?= $ui('field', ['name' => 'graduated_year', 'label' => $t('employees.field.graduated_year'), 'inputmode' => 'numeric', 'value' => (string) ($form === 'education' ? ($old['graduated_year'] ?? '') : ''),
            'error' => $errors['graduated_year'] ?? null, 'help' => $t('employees.year_help')]) ?>
      </div>
      <div class="row"><?= $ui('button', ['label' => $t('employees.add_education'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'plus']) ?></div>
    </form>
  </details>
  <?php endif; ?>
</section>

<section class="card card--flush" aria-labelledby="ep-pj">
  <div class="card__head"><h2 id="ep-pj" class="card__title"><?= $e($t('employees.section.prior_jobs')) ?></h2></div>
  <div class="table-wrap" role="region" aria-labelledby="ep-pj" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('employees.field.prior_company')) ?></th><th scope="col"><?= $e($t('employees.field.position')) ?></th><th scope="col"><?= $e($t('employees.field.period')) ?></th>
        <th scope="col"><span class="sr-only"><?= $e($t('employees.actions')) ?></span></th></tr></thead>
      <tbody>
      <?php foreach ($profile['prior_jobs'] as $pj): ?>
        <tr>
          <td data-label="<?= $e($t('employees.field.prior_company')) ?>"><?= $e((string) $pj['company']) ?></td>
          <td data-label="<?= $e($t('employees.field.position')) ?>"><?= $e((string) ($pj['position'] ?? '—')) ?></td>
          <td data-label="<?= $e($t('employees.field.period')) ?>"><?= $e((string) ($pj['from_month'] ?? '…')) ?> – <?= $e((string) ($pj['to_month'] ?? '…')) ?></td>
          <td><?php if ($acc['manage']): ?><form method="post" action="<?= $e($url($base . '/prior_job/' . $pj['public_id'] . '/remove')) ?>"><?= $csrf ?>
            <?= $ui('button', ['label' => $t('employees.remove'), 'type' => 'submit', 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'trash']) ?></form><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($profile['prior_jobs'] === []): ?><tr><td colspan="4"><?= $e($t('employees.none_yet')) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($acc['manage']): ?>
  <details class="card__pad"<?= $form === 'prior_job' ? ' open' : '' ?>>
    <summary><?= $e($t('employees.add_prior_job')) ?></summary>
    <form method="post" action="<?= $e($url($base . '/prior-jobs')) ?>" class="stack" novalidate>
      <?= $csrf ?>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'company', 'label' => $t('employees.field.prior_company'), 'required' => true, 'value' => (string) ($form === 'prior_job' ? ($old['company'] ?? '') : ''), 'error' => $errors['company'] ?? null]) ?>
        <?= $ui('field', ['name' => 'position', 'label' => $t('employees.field.position'), 'value' => (string) ($form === 'prior_job' ? ($old['position'] ?? '') : '')]) ?>
        <?= $ui('field', ['name' => 'from_month', 'label' => $t('employees.field.from_month'), 'placeholder' => '2020-01', 'value' => (string) ($form === 'prior_job' ? ($old['from_month'] ?? '') : ''), 'error' => $errors['from_month'] ?? null]) ?>
        <?= $ui('field', ['name' => 'to_month', 'label' => $t('employees.field.to_month'), 'placeholder' => '2023-12', 'value' => (string) ($form === 'prior_job' ? ($old['to_month'] ?? '') : ''), 'error' => $errors['to_month'] ?? null]) ?>
      </div>
      <div class="row"><?= $ui('button', ['label' => $t('employees.add_prior_job'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'plus']) ?></div>
    </form>
  </details>
  <?php endif; ?>
</section>
<?php endif; ?>
