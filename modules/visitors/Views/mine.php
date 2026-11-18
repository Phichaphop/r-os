<?php
/**
 * My visitors: invite someone ahead (host) and the list of my invitations.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var callable $partial @var array<string, mixed> $v
 * @var list<array<string, mixed>> $rows @var int|null $le @var list<array{id: int, name: string}> $sites
 * @var array{retention_days: int|null, overdue_minutes: int|null, record_vehicle: bool}|null $settings @var string|null $notice
 * @var array<string, string> $errors @var array<string, mixed> $old
 */
$o = static fn (string $k, string $d = ''): string => (string) ($old[$k] ?? $d);
?>
<?= $ui('page-header', ['title' => $t('visitors.mine.title'), 'subtitle' => $t('visitors.mine.subtitle')]) ?>
<?php if ($le === null): ?>
  <?= $ui('alert', ['tone' => 'warning', 'text' => $t('visitors.error.not_employee')]) ?>
<?php else: ?>
<?php if ($notice === null): ?><?= $ui('alert', ['tone' => 'warning', 'text' => $t('visitors.no_notice')]) ?><?php endif; ?>
<section class="card stack" aria-labelledby="vi-h">
  <h2 id="vi-h" class="card__title"><?= $e($t('visitors.invite.title')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('visitors.invite.help')) ?></p>
  <form method="post" action="<?= $e($url('/visitors')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'visitor_name', 'id' => 'vi-name', 'label' => $t('visitors.field.visitor_name'), 'required' => true, 'value' => $o('visitor_name'), 'autocomplete' => 'off', 'error' => $errors['visitor_name'] ?? null]) ?>
      <?= $ui('field', ['name' => 'visitor_company', 'id' => 'vi-company', 'label' => $t('visitors.field.visitor_company'), 'value' => $o('visitor_company'), 'autocomplete' => 'off']) ?>
    </div>
    <?= $ui('field', ['name' => 'purpose', 'id' => 'vi-purpose', 'label' => $t('visitors.field.purpose'), 'required' => true, 'value' => $o('purpose'), 'error' => $errors['purpose'] ?? null]) ?>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'expected_from', 'id' => 'vi-from', 'type' => 'datetime-local', 'label' => $t('visitors.field.expected_from'), 'required' => true, 'value' => $o('expected_from'), 'error' => $errors['expected_from'] ?? null]) ?>
      <?= $ui('field', ['name' => 'expected_to', 'id' => 'vi-to', 'type' => 'datetime-local', 'label' => $t('visitors.field.expected_to'), 'required' => true, 'value' => $o('expected_to'), 'help' => $t('visitors.window_help'), 'error' => $errors['expected_to'] ?? null]) ?>
      <?= $ui('field', ['name' => 'people', 'id' => 'vi-people', 'type' => 'number', 'label' => $t('visitors.field.people'), 'value' => $o('people', '1'), 'error' => $errors['people'] ?? null]) ?>
    </div>
    <?php if ($sites !== []): ?>
      <?= $ui('field', ['name' => 'site_id', 'id' => 'vi-site', 'type' => 'select', 'label' => $t('visitors.field.site'), 'value' => $o('site_id'),
          'options' => ['' => $t('visitors.any_site')] + array_column($sites, 'name', 'id'), 'error' => $errors['site_id'] ?? null]) ?>
    <?php endif; ?>
    <?php if ($settings !== null && $settings['record_vehicle']): ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'vehicle_plate', 'id' => 'vi-plate', 'label' => $t('visitors.field.vehicle_plate'), 'value' => $o('vehicle_plate')]) ?>
      <?= $ui('field', ['name' => 'items', 'id' => 'vi-items', 'label' => $t('visitors.field.items'), 'value' => $o('items')]) ?>
    </div>
    <?php endif; ?>
    <p class="text-sm text-muted"><?= $e($t('visitors.minimum_data')) ?></p>
    <div class="row"><?= $ui('button', ['label' => $t('visitors.invite.submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'user-check']) ?></div>
  </form>
</section>
<?php endif; ?>
<section class="card stack-sm" aria-labelledby="vm-h">
  <h2 id="vm-h" class="card__title"><?= $e($t('visitors.mine.list')) ?></h2>
  <?php if ($rows === []): ?><p class="text-sm text-muted"><?= $e($t('visitors.mine.none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="vm-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('visitors.field.ref')) ?></th><th scope="col"><?= $e($t('visitors.field.visitor_name')) ?></th><th scope="col"><?= $e($t('visitors.field.expected_from')) ?></th><th scope="col"><?= $e($t('visitors.field.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <th scope="row" data-label="<?= $e($t('visitors.field.ref')) ?>"><a href="<?= $e($url('/visitors/v/' . $r['public_id'])) ?>"><?= $e((string) $r['ref']) ?></a></th>
          <td data-label="<?= $e($t('visitors.field.visitor_name')) ?>"><?= $e((string) ($r['visitor_name'] ?? $t('visitors.purged'))) ?><?= $r['visitor_company'] !== null ? ' · ' . $e((string) $r['visitor_company']) : '' ?></td>
          <td data-label="<?= $e($t('visitors.field.expected_from')) ?>"><?= $e($date((string) $r['expected_from'])) ?></td>
          <td data-label="<?= $e($t('visitors.field.status')) ?>"><?= $partial('@visitors/_status', ['s' => (string) $r['status']]) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
