<?php
/**
 * HR: check-in points, company policy, retention. @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $points @var array<string, string> $sites @var array<string, mixed>|null $edit
 * @var array<string, mixed>|null $policy @var bool $canPolicy @var array<string, mixed>|null $retention
 * @var array<string, string> $errors @var array<string, mixed> $old @var string $form @var string $today
 */
$err = static fn (string $f, string $k) => $form === $f ? ($errors[$k] ?? null) : null;
$pt = $form === 'point' ? $old : ($edit !== null ? ['point' => $edit['public_id'], 'name' => $edit['name'], 'site' => (string) ($edit['site_pid'] ?? ''), 'lat' => $edit['lat'], 'lng' => $edit['lng'],
    'radius_m' => $edit['radius_m'], 'status' => $edit['status']] : []);
$po = $form === 'policy' ? $old : ($policy !== null ? ['outside' => $policy['outside'], 'max_accuracy_m' => $policy['max_accuracy_m'], 'offline_hours' => $policy['offline_hours'],
    'groups' => implode(', ', (array) $policy['groups']), 'notice' => $policy['notice']] : []);
$editing = ($pt['point'] ?? '') !== '';
?>
<?= $ui('page-header', ['title' => $t('gps.admin_title'), 'subtitle' => $t('gps.admin_subtitle'), 'crumbs' => [[$t('gps.title'), $url('/gps')], [$t('gps.admin_title'), null]],
    'actions' => $ui('button', ['label' => $t('gps.report_title'), 'href' => $url('/gps/report'), 'variant' => 'secondary', 'icon' => 'report-money'])]) ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('gps.pick_company')) ?>">
  <?php foreach ($companies as $pid => $code): ?><a href="<?= $e($url('/gps/admin?le=' . rawurlencode($pid))) ?>"<?= $pid === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>

<section class="card stack" aria-labelledby="pt-h">
  <h2 id="pt-h" class="card__title"><?= $e($t('gps.points')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('gps.points_help')) ?></p>
  <?php if ($points === []): ?><p class="text-sm text-muted"><?= $e($t('gps.points_none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="pt-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('gps.field.name')) ?></th><th scope="col"><?= $e($t('gps.field.site')) ?></th><th scope="col"><?= $e($t('gps.field.centre')) ?></th>
        <th scope="col"><?= $e($t('gps.field.radius')) ?></th><th scope="col"><?= $e($t('gps.field.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($points as $g): ?>
        <tr><th scope="row" data-label="<?= $e($t('gps.field.name')) ?>"><a href="<?= $e($url('/gps/admin?le=' . rawurlencode($lePid) . '&point=' . $g['public_id'] . '#pf-h')) ?>"><?= $e((string) $g['name']) ?></a></th>
          <td data-label="<?= $e($t('gps.field.site')) ?>"><?= $e((string) ($g['site_code'] ?? $t('gps.all_sites'))) ?></td>
          <td data-label="<?= $e($t('gps.field.centre')) ?>"><?= $e($g['lat'] . ', ' . $g['lng']) ?></td>
          <td data-label="<?= $e($t('gps.field.radius')) ?>" class="num"><?= $e($t('gps.metres', ['m' => (string) (int) $g['radius_m']])) ?></td>
          <td data-label="<?= $e($t('gps.field.status')) ?>"><?= $ui('badge', ['tone' => $g['status'] === 'active' ? 'success' : 'neutral', 'label' => $t('gps.point_status.' . $g['status'])]) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <details<?= $form === 'point' || $edit !== null ? ' open' : '' ?>>
    <summary id="pf-h"><?= $e($t($editing ? 'gps.point_edit' : 'gps.point_add')) ?></summary>
    <form method="post" action="<?= $e($url('/gps/admin/point')) ?>" class="stack" novalidate>
      <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
      <input type="hidden" name="legal_entity" value="<?= $e($lePid) ?>">
      <input type="hidden" name="point" value="<?= $e((string) ($pt['point'] ?? '')) ?>">
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'name', 'id' => 'pf-name', 'label' => $t('gps.field.name'), 'required' => true, 'value' => (string) ($pt['name'] ?? ''), 'error' => $err('point', 'name')]) ?>
        <?= $ui('field', ['name' => 'site', 'id' => 'pf-site', 'type' => 'select', 'label' => $t('gps.field.site'), 'value' => (string) ($pt['site'] ?? ''), 'options' => ['' => $t('gps.all_sites')] + $sites, 'error' => $err('point', 'site')]) ?>
      </div>
      <div class="grid grid--3">
        <?= $ui('field', ['name' => 'lat', 'id' => 'pf-lat', 'label' => $t('gps.field.lat'), 'required' => true, 'inputmode' => 'decimal', 'value' => (string) ($pt['lat'] ?? ''), 'placeholder' => '13.756300', 'error' => $err('point', 'lat')]) ?>
        <?= $ui('field', ['name' => 'lng', 'id' => 'pf-lng', 'label' => $t('gps.field.lng'), 'required' => true, 'inputmode' => 'decimal', 'value' => (string) ($pt['lng'] ?? ''), 'placeholder' => '100.501800', 'error' => $err('point', 'lng')]) ?>
        <?= $ui('field', ['name' => 'radius_m', 'id' => 'pf-radius', 'type' => 'number', 'label' => $t('gps.field.radius'), 'required' => true, 'value' => (string) ($pt['radius_m'] ?? ''), 'help' => $t('gps.radius_help'), 'error' => $err('point', 'radius_m')]) ?>
      </div>
      <p class="text-sm text-muted"><?= $e($t('gps.coords_help')) ?></p>
      <?php if ($editing): ?>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'status', 'id' => 'pf-status', 'type' => 'select', 'label' => $t('gps.field.status'), 'value' => (string) ($pt['status'] ?? 'active'), 'options' => ['active' => $t('gps.point_status.active'), 'inactive' => $t('gps.point_status.inactive')]]) ?>
        <?= $ui('field', ['name' => 'reason', 'id' => 'pf-reason', 'label' => $t('gps.field.reason'), 'required' => true, 'value' => (string) ($old['reason'] ?? ''), 'error' => $err('point', 'reason')]) ?>
      </div>
      <?php endif; ?>
      <div class="row"><?= $ui('button', ['label' => $t('gps.point_save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'map-pin']) ?>
        <?php if ($editing): ?><?= $ui('button', ['label' => $t('common.cancel'), 'href' => $url('/gps/admin?le=' . rawurlencode($lePid)), 'variant' => 'ghost']) ?><?php endif; ?></div>
    </form>
  </details>
</section>

<section class="card stack" aria-labelledby="po-h">
  <h2 id="po-h" class="card__title"><?= $e($t('gps.policy.title')) ?></h2>
  <?php if ($policy === null): ?>
    <?= $ui('alert', ['tone' => 'info', 'text' => $t('gps.policy.none')]) ?>
  <?php else: ?>
    <dl class="facts">
      <div><dt><?= $e($t('gps.policy.outside')) ?></dt><dd><?= $e($t('gps.outside.' . $policy['outside'])) ?></dd></div>
      <div><dt><?= $e($t('gps.policy.max_accuracy_m')) ?></dt><dd><?= $e($t('gps.metres', ['m' => (string) $policy['max_accuracy_m']])) ?></dd></div>
      <div><dt><?= $e($t('gps.policy.offline_hours')) ?></dt><dd><?= $e((int) $policy['offline_hours'] === 0 ? $t('gps.offline_off') : (string) $policy['offline_hours']) ?></dd></div>
      <div><dt><?= $e($t('gps.policy.groups')) ?></dt><dd><?= $e((array) $policy['groups'] === [] ? $t('gps.groups_all') : implode(', ', (array) $policy['groups'])) ?></dd></div>
      <div><dt><?= $e($t('gps.policy.from')) ?></dt><dd><?= $e(substr((string) $policy['from'], 0, 10)) ?></dd></div>
    </dl>
  <?php endif; ?>
  <?php if ($canPolicy): ?>
  <details<?= $form === 'policy' ? ' open' : '' ?>>
    <summary><?= $e($t('gps.policy.propose')) ?></summary>
    <form method="post" action="<?= $e($url('/gps/admin/policy')) ?>" class="stack" novalidate>
      <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
      <input type="hidden" name="legal_entity" value="<?= $e($lePid) ?>">
      <?= $ui('alert', ['tone' => 'warning', 'title' => $t('gps.policy.warn_title'), 'text' => $t('gps.policy.warn_text')]) ?>
      <div class="grid grid--3">
        <?= $ui('field', ['name' => 'outside', 'id' => 'po-out', 'type' => 'select', 'label' => $t('gps.policy.outside'), 'required' => true, 'value' => (string) ($po['outside'] ?? 'flag'),
            'options' => ['flag' => $t('gps.outside.flag'), 'warn' => $t('gps.outside.warn'), 'block' => $t('gps.outside.block')], 'error' => $err('policy', 'outside')]) ?>
        <?= $ui('field', ['name' => 'max_accuracy_m', 'id' => 'po-acc', 'type' => 'number', 'label' => $t('gps.policy.max_accuracy_m'), 'required' => true, 'value' => (string) ($po['max_accuracy_m'] ?? ''), 'help' => $t('gps.policy.accuracy_help'), 'error' => $err('policy', 'max_accuracy_m')]) ?>
        <?= $ui('field', ['name' => 'offline_hours', 'id' => 'po-off', 'type' => 'number', 'label' => $t('gps.policy.offline_hours'), 'required' => true, 'value' => (string) ($po['offline_hours'] ?? ''), 'help' => $t('gps.policy.offline_help'), 'error' => $err('policy', 'offline_hours')]) ?>
      </div>
      <?= $ui('field', ['name' => 'groups', 'id' => 'po-groups', 'label' => $t('gps.policy.groups'), 'value' => (string) ($po['groups'] ?? ''), 'help' => $t('gps.policy.groups_help'), 'error' => $err('policy', 'groups')]) ?>
      <?= $ui('field', ['name' => 'notice', 'id' => 'po-notice', 'type' => 'textarea', 'label' => $t('gps.policy.notice'), 'required' => true, 'value' => (string) ($po['notice'] ?? ''), 'help' => $t('gps.policy.notice_help'), 'error' => $err('policy', 'notice')]) ?>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'effective_from', 'id' => 'po-from', 'type' => 'date', 'label' => $t('gps.policy.effective_from'), 'required' => true, 'value' => (string) ($old['effective_from'] ?? $today), 'error' => $err('policy', 'effective_from')]) ?>
        <?= $ui('field', ['name' => 'source_reference', 'id' => 'po-src', 'label' => $t('gps.policy.source'), 'required' => true, 'value' => (string) ($old['source_reference'] ?? ''), 'help' => $t('gps.policy.source_help'), 'error' => $err('policy', 'source_reference')]) ?>
      </div>
      <?= $ui('field', ['name' => 'reason', 'id' => 'po-reason', 'label' => $t('gps.field.reason'), 'required' => true, 'value' => (string) ($old['reason'] ?? ''), 'error' => $err('policy', 'reason')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('gps.policy.submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
    </form>
  </details>
  <?php endif; ?>
</section>

<section class="card stack-sm" aria-labelledby="rt-h">
  <h2 id="rt-h" class="card__title"><?= $e($t('gps.retention_title')) ?></h2>
  <?php if ($retention === null): ?>
    <?= $ui('alert', ['tone' => 'warning', 'text' => $t('gps.retention_none')]) ?>
  <?php else: ?>
    <p><?= $e($t('gps.retention_rule', ['months' => (string) (int) $retention['duration_months'], 'action' => $t('gps.retention_action.' . (in_array($retention['disposition_action'], ['anonymize', 'destroy'], true) ? $retention['disposition_action'] : 'review'))])) ?></p>
  <?php endif; ?>
  <p class="text-sm"><a href="<?= $e($url('/admin/records/rules/gps_selfie')) ?>"><?= $e($t('gps.retention_link')) ?></a></p>
</section>
