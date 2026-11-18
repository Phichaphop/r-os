<?php
/**
 * The company's published scoring criteria for ideas (name, weight) — no defaults.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var array<string, string> $companies @var string $lePid
 * @var list<array<string, mixed>> $criteria @var array<string, string> $errors @var array<string, mixed> $old @var string $here @var bool $canManage @var bool $canSurvey
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$editing = (string) ($old['_pid'] ?? '');
?>
<?= $ui('page-header', ['title' => $t('kaizen.settings_title'), 'subtitle' => $t('kaizen.settings_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php include __DIR__ . '/_companies.php'; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<section class="card stack-sm" aria-labelledby="kz-c">
  <h2 id="kz-c" class="card__title"><?= $e($t('kaizen.criteria')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('kaizen.criteria_help')) ?></p>
  <?php if ($criteria === []): ?><p class="text-sm"><?= $e($t('kaizen.criteria_none')) ?></p><?php endif; ?>
  <ul class="plain-list stack-sm">
  <?php foreach ($criteria as $c): $cp = (string) $c['public_id']; $mine = $editing === $cp; ?>
    <li>
      <details<?= $mine ? ' open' : '' ?>><summary><?= $e($c['name'] . ' · ×' . $c['weight']) ?><?= (int) $c['active'] === 1 ? '' : ' ' . $ui('badge', ['tone' => 'neutral', 'label' => $t('kaizen.inactive')]) ?></summary>
        <form method="post" action="<?= $e($url('/kaizen/settings/' . $cp)) ?>" class="row kz-filter" novalidate><?= $csrf ?><input type="hidden" name="le" value="<?= $e($lePid) ?>">
          <?= $ui('field', ['name' => 'name', 'id' => 'f-name-' . $cp, 'label' => $t('kaizen.field.criterion'), 'required' => true, 'value' => $mine ? (string) ($old['name'] ?? '') : (string) $c['name'], 'error' => $mine ? ($errors['name'] ?? null) : null]) ?>
          <?= $ui('field', ['name' => 'weight', 'id' => 'f-weight-' . $cp, 'type' => 'number', 'label' => $t('kaizen.field.weight'), 'required' => true, 'value' => $mine ? (string) ($old['weight'] ?? '') : (string) $c['weight'], 'error' => $mine ? ($errors['weight'] ?? null) : null]) ?>
          <label class="check"><input type="checkbox" name="active" value="1"<?= (int) $c['active'] === 1 ? ' checked' : '' ?>> <?= $e($t('kaizen.active')) ?></label>
          <?= $ui('button', ['label' => $t('kaizen.save'), 'type' => 'submit', 'variant' => 'secondary', 'attrs' => ['aria-label' => $t('kaizen.save_criterion', ['name' => (string) $c['name']])]]) ?>
        </form>
      </details>
    </li>
  <?php endforeach; ?>
  </ul>
  <form method="post" action="<?= $e($url('/kaizen/settings')) ?>" class="row kz-filter" novalidate><?= $csrf ?><input type="hidden" name="le" value="<?= $e($lePid) ?>">
    <?= $ui('field', ['name' => 'name', 'label' => $t('kaizen.field.criterion'), 'required' => true, 'value' => $editing === '' ? (string) ($old['name'] ?? '') : '', 'placeholder' => $t('kaizen.criterion_example'), 'error' => $editing === '' ? ($errors['name'] ?? null) : null]) ?>
    <?= $ui('field', ['name' => 'weight', 'type' => 'number', 'label' => $t('kaizen.field.weight'), 'required' => true, 'value' => $editing === '' ? (string) ($old['weight'] ?? '') : '', 'help' => $t('kaizen.weight_help'), 'error' => $editing === '' ? ($errors['weight'] ?? null) : null]) ?>
    <?= $ui('button', ['label' => $t('kaizen.add_criterion'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?>
  </form>
</section>
