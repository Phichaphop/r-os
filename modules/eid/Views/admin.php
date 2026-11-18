<?php
/**
 * HR: card settings + employees and their cards. @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $asset @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var array<string, mixed> $settings @var list<array<string, mixed>> $roster @var string $q @var array<string, string> $errors
 */
$loc = (string) ($v['locale'] ?? 'th');
include __DIR__ . '/_helpers.php';
$missing = count(array_filter($roster, static fn (array $x): bool => in_array($x['emp_status'], ['active', 'on_leave'], true) && ($x['card'] === null || !in_array($x['card']['status'], ['active', 'suspended', 'lost'], true))));
$box = static fn (string $name, bool $on, string $label) => '<label class="check"><input type="checkbox" name="' . $name . '" value="1"' . ($on ? ' checked' : '') . '> ' . $label . '</label>';
?>
<?= $ui('page-header', ['title' => $t('eid.admin_title'), 'subtitle' => $t('eid.admin_subtitle'), 'crumbs' => [[$t('eid.title'), $url('/eid')], [$t('eid.admin_title'), null]],
    'actions' => $ui('button', ['label' => $t('eid.nav_verify'), 'href' => $url('/eid/verify'), 'variant' => 'secondary', 'icon' => 'qrcode'])]) ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('eid.pick_company')) ?>">
  <?php foreach ($companies as $pid => $code): ?><a href="<?= $e($url('/eid/admin?le=' . rawurlencode($pid))) ?>"<?= $pid === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>

<section class="card stack" aria-labelledby="ro-h">
  <h2 id="ro-h" class="card__title"><?= $e($t('eid.roster')) ?></h2>
  <div class="row row--between">
    <form method="get" action="<?= $e($url('/eid/admin')) ?>" class="row row--tight">
      <input type="hidden" name="le" value="<?= $e($lePid) ?>">
      <label class="sr-only" for="ro-q"><?= $e($t('common.search')) ?></label><input class="input" type="search" id="ro-q" name="q" value="<?= $e($q) ?>" placeholder="<?= $e($t('eid.search_hint')) ?>">
      <?= $ui('button', ['label' => $t('common.search'), 'type' => 'submit', 'size' => 'sm', 'icon' => 'search']) ?>
    </form>
    <form method="post" action="<?= $e($url('/eid/admin/issue-missing')) ?>">
      <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
      <input type="hidden" name="legal_entity" value="<?= $e($lePid) ?>">
      <?= $ui('button', ['label' => $t('eid.issue_missing', ['n' => (string) $missing]), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'id-badge-2', 'disabled' => $missing === 0, 'disabledReason' => $t('eid.issue_missing_none')]) ?>
    </form>
  </div>
  <?php if ($roster === []): ?><p class="text-sm text-muted"><?= $e($t('eid.none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="ro-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('eid.field.employee')) ?></th><th scope="col"><?= $e($t('eid.field.card_no')) ?></th><th scope="col"><?= $e($t('eid.field.status')) ?></th><th scope="col"><?= $e($t('eid.field.photo')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($roster as $x): ?>
        <tr><th scope="row" data-label="<?= $e($t('eid.field.employee')) ?>"><a href="<?= $e($url('/eid/admin/e/' . $x['public_id'])) ?>"><?= $e($x['employee_code'] . ' · ' . $x['display']) ?></a></th>
          <td data-label="<?= $e($t('eid.field.card_no')) ?>"><?= $e((string) ($x['card']['card_no'] ?? '—')) ?></td>
          <td data-label="<?= $e($t('eid.field.status')) ?>"><?= $eidStatus($x['effective']) ?></td>
          <td data-label="<?= $e($t('eid.field.photo')) ?>"><?= $e($x['card'] !== null && $x['card']['photo_document_pid'] !== null ? $t('eid.photo_yes') : $t('eid.photo_no')) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<section class="card stack" aria-labelledby="st-h">
  <h2 id="st-h" class="card__title"><?= $e($t('eid.settings')) ?></h2>
  <form method="post" action="<?= $e($url('/eid/admin/settings')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <input type="hidden" name="legal_entity" value="<?= $e($lePid) ?>">
    <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('eid.settings_show')) ?></legend>
      <div class="check-list">
        <?= $box('show_position', $settings['show_position'], $e($t('eid.show.position'))) ?>
        <?= $box('show_department', $settings['show_department'], $e($t('eid.show.department'))) ?>
        <?= $box('show_site', $settings['show_site'], $e($t('eid.show.site'))) ?>
        <?= $box('allow_print', $settings['allow_print'], $e($t('eid.show.print'))) ?>
      </div>
    </fieldset>
    <?= $ui('field', ['name' => 'validity_months', 'id' => 'st-valid', 'type' => 'number', 'label' => $t('eid.field.validity'), 'value' => $settings['validity_months'] !== null ? (string) $settings['validity_months'] : '', 'help' => $t('eid.validity_help'), 'error' => $errors['validity_months'] ?? null]) ?>
    <p class="text-sm text-muted"><?= $e($t('eid.settings_note')) ?></p>
    <div class="row"><?= $ui('button', ['label' => $t('eid.settings_save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'settings']) ?></div>
  </form>
</section>
