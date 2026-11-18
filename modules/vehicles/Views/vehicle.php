<?php
/**
 * One vehicle (workplace admin): change its details, put it under repair / out of use / back in use.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, mixed> $veh @var list<array<string, mixed>> $types @var list<array<string, mixed>> $sites @var array<string, string> $errors @var array<string, mixed> $old @var string $form
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$base = '/vehicles/v/' . $veh['public_id'];
$fo = static fn (string $f, string $k, string $d = ''): string => $form === $f && is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$fe = static fn (string $f, string $k): ?string => $form === $f ? ($errors[$k] ?? null) : null;
$tone = ['available' => 'success', 'maintenance' => 'warning', 'inactive' => 'neutral'];
?>
<?= $ui('page-header', ['title' => $veh['plate'] . ' · ' . $veh['name'], 'subtitle' => $t('vehicles.vehicle_subtitle'),
    'crumbs' => [[$t('vehicles.admin_title'), $url('/vehicles/admin')], [(string) $veh['plate'], null]]]) ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<div class="grid grid--2">
<section class="card stack-sm" aria-labelledby="vv-e">
  <h2 id="vv-e" class="card__title"><?= $e($t('vehicles.vehicle_details')) ?></h2>
  <form method="post" action="<?= $e($url($base . '/edit')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'plate', 'label' => $t('vehicles.field.plate'), 'required' => true, 'value' => $fo('edit', 'plate', (string) $veh['plate']), 'error' => $fe('edit', 'plate')]) ?>
    <?= $ui('field', ['name' => 'name', 'label' => $t('vehicles.field.vehicle_name'), 'required' => true, 'value' => $fo('edit', 'name', (string) $veh['name']), 'error' => $fe('edit', 'name')]) ?>
    <?= $ui('field', ['name' => 'type', 'type' => 'select', 'label' => $t('vehicles.field.type'), 'value' => $fo('edit', 'type', (string) ($veh['type_pid'] ?? '')), 'options' => ['' => '—'] + array_column($types, 'name', 'public_id'), 'error' => $fe('edit', 'type')]) ?>
    <?= $ui('field', ['name' => 'site', 'type' => 'select', 'label' => $t('vehicles.field.site'), 'value' => $fo('edit', 'site', (string) ($veh['site_pid'] ?? '')), 'options' => ['' => $t('vehicles.all_sites')] + array_column($sites, 'code', 'public_id'), 'error' => $fe('edit', 'site')]) ?>
    <?= $ui('field', ['name' => 'seats', 'type' => 'number', 'label' => $t('vehicles.field.seats'), 'required' => true, 'value' => $fo('edit', 'seats', (string) $veh['seats']), 'help' => $t('vehicles.seats_help'), 'error' => $fe('edit', 'seats')]) ?>
    <p class="text-sm text-muted"><?= $e($t('vehicles.odo_last', ['n' => number_format((int) $veh['odometer_km'])])) ?></p>
    <div class="row"><?= $ui('button', ['label' => $t('vehicles.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
  </form>
</section>
<section class="card stack-sm" aria-labelledby="vv-s">
  <h2 id="vv-s" class="card__title"><?= $e($t('vehicles.col.status')) ?> <?= $ui('badge', ['tone' => $tone[$veh['status']] ?? 'neutral', 'label' => $t('vehicles.vstatus.' . $veh['status'])]) ?></h2>
  <?php if ($veh['status_note'] !== null): ?><p><?= $e((string) $veh['status_note']) ?></p><?php endif; ?>
  <form method="post" action="<?= $e($url($base . '/status')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'status', 'type' => 'select', 'label' => $t('vehicles.field.new_status'), 'value' => $fo('status', 'status', (string) $veh['status']),
        'options' => ['available' => $t('vehicles.vstatus.available'), 'maintenance' => $t('vehicles.vstatus.maintenance'), 'inactive' => $t('vehicles.vstatus.inactive')], 'error' => $fe('status', 'status')]) ?>
    <?= $ui('field', ['name' => 'status_note', 'label' => $t('vehicles.field.status_note'), 'value' => $fo('status', 'status_note'), 'help' => $t('vehicles.status_note_help'), 'error' => $fe('status', 'status_note')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('vehicles.save'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'tool']) ?></div>
  </form>
</section>
</div>
