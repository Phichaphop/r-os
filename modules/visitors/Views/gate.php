<?php
/**
 * Gate desk (visitors.gate): find by QR / ref, today's visits, walk-in.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var callable $partial @var array<string, mixed> $v
 * @var list<array{id: int, code: string, name: string}> $companies @var int $le @var string $day @var list<array<string, mixed>> $rows
 * @var list<array{label: string}> $hosts @var list<array{id: int, name: string}> $sites @var array{record_vehicle: bool} $settings @var string|null $notice
 * @var array<string, string> $errors @var array<string, mixed> $old
 */

$o = static fn (string $k, string $d = ''): string => (string) ($old[$k] ?? $d);
?>
<?= $ui('page-header', ['title' => $t('visitors.gate.title'), 'subtitle' => $t('visitors.gate.subtitle')]) ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('visitors.company')) ?>">
  <?php foreach ($companies as $c): ?><a href="<?= $e($url('/visitors/gate?le=' . $c['id'])) ?>"<?= $c['id'] === $le ? ' aria-current="page"' : '' ?>><?= $e($c['code']) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<section class="card stack-sm" aria-labelledby="gf-h">
  <h2 id="gf-h" class="card__title"><?= $e($t('visitors.gate.find')) ?></h2>
  <form method="get" action="<?= $e($url('/visitors/gate')) ?>" class="row">
    <input type="hidden" name="le" value="<?= $le ?>">
    <label class="sr-only" for="gf-code"><?= $e($t('visitors.gate.code')) ?></label>
    <input class="input" id="gf-code" name="code" autocomplete="off" autofocus placeholder="<?= $e($t('visitors.gate.code')) ?>">
    <?= $ui('button', ['label' => $t('visitors.gate.open'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'search']) ?>
  </form>
  <p class="text-sm text-muted"><?= $e($t('visitors.gate.find_help')) ?></p>
</section>
<section class="card stack-sm" aria-labelledby="gt-h">
  <h2 id="gt-h" class="card__title"><?= $e($t('visitors.gate.today', ['d' => $day])) ?></h2>
  <form method="get" action="<?= $e($url('/visitors/gate')) ?>" class="row">
    <input type="hidden" name="le" value="<?= $le ?>">
    <label class="field__label" for="gt-date"><?= $e($t('visitors.gate.date')) ?></label>
    <input class="input" type="date" id="gt-date" name="date" value="<?= $e($day) ?>">
    <?= $ui('button', ['label' => $t('visitors.gate.show'), 'type' => 'submit', 'variant' => 'secondary']) ?>
  </form>
  <?php if ($rows === []): ?><p class="text-sm text-muted"><?= $e($t('visitors.gate.none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="gt-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('visitors.field.ref')) ?></th><th scope="col"><?= $e($t('visitors.field.visitor_name')) ?></th><th scope="col"><?= $e($t('visitors.field.host')) ?></th><th scope="col"><?= $e($t('visitors.field.window')) ?></th><th scope="col"><?= $e($t('visitors.field.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <th scope="row" data-label="<?= $e($t('visitors.field.ref')) ?>"><a href="<?= $e($url('/visitors/v/' . $r['public_id'])) ?>"><?= $e((string) $r['ref']) ?></a></th>
          <td data-label="<?= $e($t('visitors.field.visitor_name')) ?>"><?= $e((string) ($r['visitor_name'] ?? $t('visitors.purged'))) ?><?= $r['visitor_company'] !== null ? ' · ' . $e((string) $r['visitor_company']) : '' ?> (<?= (int) $r['people'] ?>)</td>
          <td data-label="<?= $e($t('visitors.field.host')) ?>"><?= $e((string) $r['host_name']) ?></td>
          <td data-label="<?= $e($t('visitors.field.window')) ?>"><?= $e($date((string) $r['expected_from'])) ?> – <?= $e($date((string) $r['expected_to'])) ?></td>
          <td data-label="<?= $e($t('visitors.field.status')) ?>"><?= $partial('@visitors/_status', ['s' => (string) $r['status']]) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<section class="card stack" aria-labelledby="gw-h">
  <h2 id="gw-h" class="card__title"><?= $e($t('visitors.walkin.title')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('visitors.walkin.help')) ?></p>
  <form method="post" action="<?= $e($url('/visitors/gate/walk-in')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>"><input type="hidden" name="le" value="<?= $le ?>">
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'visitor_name', 'id' => 'gw-name', 'label' => $t('visitors.field.visitor_name'), 'required' => true, 'value' => $o('visitor_name'), 'autocomplete' => 'off', 'error' => $errors['visitor_name'] ?? null]) ?>
      <?= $ui('field', ['name' => 'visitor_company', 'id' => 'gw-company', 'label' => $t('visitors.field.visitor_company'), 'value' => $o('visitor_company'), 'autocomplete' => 'off']) ?>
    </div>
    <div class="grid grid--2">
      <div class="field">
        <label class="field__label" for="gw-host"><?= $e($t('visitors.field.host')) ?> <span class="field__req"><?= $e($t('form.required_tag')) ?></span></label>
        <input class="input" id="gw-host" name="host" list="gw-hosts" autocomplete="off" required value="<?= $e($o('host')) ?>"<?= isset($errors['host']) ? ' aria-invalid="true" aria-describedby="gw-host-err"' : '' ?>>
        <datalist id="gw-hosts"><?php foreach ($hosts as $h): ?><option value="<?= $e($h['label']) ?>"></option><?php endforeach; ?></datalist>
        <?php if (isset($errors['host'])): ?><p class="field__error" id="gw-host-err"><?= $e($errors['host']) ?></p><?php endif; ?>
      </div>
      <?= $ui('field', ['name' => 'purpose', 'id' => 'gw-purpose', 'label' => $t('visitors.field.purpose'), 'required' => true, 'value' => $o('purpose'), 'error' => $errors['purpose'] ?? null]) ?>
    </div>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'people', 'id' => 'gw-people', 'type' => 'number', 'label' => $t('visitors.field.people'), 'value' => $o('people', '1'), 'error' => $errors['people'] ?? null]) ?>
      <?= $ui('field', ['name' => 'hours', 'id' => 'gw-hours', 'type' => 'number', 'label' => $t('visitors.field.hours'), 'value' => $o('hours', '2'), 'error' => $errors['hours'] ?? null]) ?>
      <?php if ($sites !== []): ?><?= $ui('field', ['name' => 'site_id', 'id' => 'gw-site', 'type' => 'select', 'label' => $t('visitors.field.site'), 'value' => $o('site_id'), 'options' => ['' => $t('visitors.any_site')] + array_column($sites, 'name', 'id')]) ?><?php endif; ?>
    </div>
    <?php if ($settings['record_vehicle']): ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'vehicle_plate', 'id' => 'gw-plate', 'label' => $t('visitors.field.vehicle_plate'), 'value' => $o('vehicle_plate')]) ?>
      <?= $ui('field', ['name' => 'items', 'id' => 'gw-items', 'label' => $t('visitors.field.items'), 'value' => $o('items')]) ?>
    </div>
    <?php endif; ?>
    <div class="row"><?= $ui('button', ['label' => $t('visitors.walkin.submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></div>
  </form>
</section>
