<?php
/**
 * One driver (workplace admin): change the details, licence and working hours, or take the driver off the list.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, mixed> $d @var list<array<string, mixed>> $sites @var array<string, string> $errors @var array<string, mixed> $old
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$dv = $old !== [] ? $old : ['name' => $d['name'], 'phone' => $d['phone'], 'account' => $d['username'], 'site' => $d['site_pid'], 'licence_class' => $d['licence_class'], 'licence_expiry' => $d['licence_expiry'],
    'work_from' => substr((string) $d['work_from'], 0, 5), 'work_to' => substr((string) $d['work_to'], 0, 5), 'work_days' => explode(',', (string) $d['work_days']), 'active' => (string) $d['active']];
$dErr = $errors;
$dAction = '/vehicles/d/' . $d['public_id'] . '/edit';
$df = 'ed';
$dLe = '';
$dEdit = true;
?>
<?= $ui('page-header', ['title' => (string) $d['name'], 'subtitle' => $t('vehicles.driver_subtitle'),
    'crumbs' => [[$t('vehicles.admin_title'), $url('/vehicles/admin')], [(string) $d['name'], null]]]) ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<section class="card stack-sm" aria-labelledby="vd-e">
  <h2 id="vd-e" class="card__title"><?= $e($t('vehicles.driver_details')) ?></h2>
  <?php include __DIR__ . '/_driver_form.php'; ?>
</section>
