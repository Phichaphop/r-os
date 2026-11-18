<?php
/**
 * "My information" (self-service, 6.19.3/6.19.4): my job, my personal data (read), my contacts and emergency contacts
 * (I keep them), my ID numbers (last 4 only) and the files HR made visible to me.
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, mixed>|null $emp @var array<string, mixed> $profile @var list<array<string, mixed>> $files
 * @var array<string, array<int, string>> $org @var array<string, mixed>|null $manager @var array<string, string> $errors @var array<string, mixed> $old
 */
use Blue\Core\I18n\DateFormat;

$loc = (string) ($v['locale'] ?? 'th');
$day = static fn (?string $d): string => $d === null || $d === '' ? '—' : DateFormat::day($d, $loc);
?>
<?= $ui('page-header', ['title' => $t('employees.me_title'), 'subtitle' => $t('employees.me_subtitle')]) ?>
<?php if ($emp === null): ?>
  <?= $ui('states', ['icon' => 'user', 'title' => $t('employees.me_not_linked'), 'text' => $t('employees.me_not_linked_help')]) ?>
<?php else: ?>
<?php
include __DIR__ . '/_helpers.php';
$job = $emp['job'];
$pp = (array) $profile['personal'];
$contacts = $old !== [] ? (array) ($old['contacts'] ?? []) : (array) ($profile['contacts'] ?? []);
$emerg = $old !== [] ? (array) ($old['emergency'] ?? []) : (array) ($profile['emergency'] ?? []);
while (count($emerg) < 2) {
    $emerg[] = ['name' => '', 'relation' => '', 'phone' => ''];
}
?>
<section class="card stack-sm" aria-labelledby="me-job">
  <h2 id="me-job" class="card__title"><?= $e($t('employees.section.now')) ?> <?= $statusBadge((string) $emp['status']) ?></h2>
  <dl class="dl">
    <dt><?= $e($t('employees.field.code')) ?></dt><dd><?= $e((string) $emp['employee_code']) ?></dd>
    <dt><?= $e($t('employees.field.name')) ?></dt><dd><?= $e(trim(($pp['title'] ?? '') . ' ' . $emp['name_th'])) ?><?= $emp['name_en'] !== null ? ' · ' . $e((string) $emp['name_en']) : '' ?></dd>
    <dt><?= $e($t('employees.field.company')) ?></dt><dd><?= $e($org['legal_entity'][(int) ($job['legal_entity_id'] ?? 0)] ?? '—') ?></dd>
    <dt><?= $e($t('employees.field.site')) ?></dt><dd><?= $e($org['site'][(int) ($job['site_id'] ?? 0)] ?? '—') ?></dd>
    <dt><?= $e($t('employees.field.department')) ?></dt><dd><?= $e($org['department'][(int) ($job['department_id'] ?? 0)] ?? '—') ?></dd>
    <dt><?= $e($t('employees.field.position')) ?></dt><dd><?= $e((string) ($job['position_title'] ?? '—')) ?></dd>
    <dt><?= $e($t('employees.field.manager')) ?></dt><dd><?= $manager !== null ? $e($manager['name_th'] . ($manager['work_email'] !== null ? ' · ' . $manager['work_email'] : '')) : '—' ?></dd>
    <dt><?= $e($t('employees.field.type')) ?></dt><dd><?= $job !== null ? $e($t('employees.type.' . $job['employment_type'])) : '—' ?></dd>
    <dt><?= $e($t('employees.field.hire_date')) ?></dt><dd><?= $e($day((string) $emp['hire_date'])) ?></dd>
    <dt><?= $e($t('employees.field.birth_date')) ?></dt><dd><?= $e($day($pp['birth_date'] ?? null)) ?></dd>
  </dl>
  <p class="text-sm text-muted"><?= $e($t('employees.me_wrong_help')) ?></p>
</section>

<section class="card stack" aria-labelledby="me-c">
  <h2 id="me-c" class="card__title"><?= $e($t('employees.section.contacts')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('employees.me_contacts_help')) ?></p>
  <?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors'), 'live' => true]) ?><?php endif; ?>
  <form method="post" action="<?= $e($url('/me/profile/contacts')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?php include __DIR__ . '/_contacts_fields.php'; ?>
    <div class="row"><?= $ui('button', ['label' => $t('employees.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
  </form>
</section>

<?php if ((array) $profile['identifiers'] !== []): ?>
<section class="card stack-sm" aria-labelledby="me-id">
  <h2 id="me-id" class="card__title"><?= $e($t('employees.section.identifiers')) ?></h2>
  <ul class="list-plain">
    <?php foreach ((array) $profile['identifiers'] as $i): ?><?php if ($i['status'] === 'current'): ?>
      <li><?= $e($t('employees.id_type.' . $i['id_type'])) ?> · <span class="mono">•••• <?= $e((string) $i['last4']) ?></span>
        <?= $i['expiry_date'] !== null ? ' · ' . $e($t('employees.field.expiry_date')) . ' ' . $e($day((string) $i['expiry_date'])) : '' ?></li>
    <?php endif; ?><?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<section class="card card--flush" aria-labelledby="me-f">
  <div class="card__head"><h2 id="me-f" class="card__title"><?= $e($t('employees.me_files')) ?></h2></div>
  <?php if ($files === []): ?>
    <p class="card__pad text-sm text-muted"><?= $e($t('employees.me_files_none')) ?></p>
  <?php else: ?>
  <ul class="list-plain card__pad">
    <?php foreach ($files as $fi): ?>
      <li><a href="<?= $e($url('/me/profile/files/' . $fi['public_id'])) ?>"><?= $ui('icon', ['name' => 'download', 'size' => 'sm']) ?> <?= $e($t('employees.doc.' . $fi['doc_type'])) ?><?= $fi['title'] !== null ? ' · ' . $e((string) $fi['title']) : '' ?></a>
        <?= $fi['expiry_date'] !== null ? '<span class="text-sm text-muted"> · ' . $e($t('employees.field.expiry_date')) . ' ' . $e($day((string) $fi['expiry_date'])) . '</span>' : '' ?></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php endif; ?>
