<?php
/**
 * Form managers: list + new form. @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $rows @var array{sites: array<string, string>, departments: array<string, string>} $audOptions
 * @var array<string, string> $errors @var array<string, mixed> $old
 */
$audIn = $old;
$audError = $errors['audience'] ?? null;
$audId = 'nf';
?>
<?= $ui('page-header', ['title' => $t('forms.admin_title'), 'subtitle' => $t('forms.admin_subtitle'), 'crumbs' => [[$t('forms.title'), $url('/forms')], [$t('forms.admin_title'), null]]]) ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('forms.pick_company')) ?>">
  <?php foreach ($companies as $pid => $code): ?><a href="<?= $e($url('/forms/admin?le=' . rawurlencode($pid))) ?>"<?= $pid === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<section class="card stack-sm" aria-labelledby="fa-h">
  <h2 id="fa-h" class="card__title"><?= $e($t('forms.all_forms')) ?></h2>
  <?php if ($rows === []): ?><p class="text-sm text-muted"><?= $e($t('forms.none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="fa-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('forms.field.code')) ?></th><th scope="col"><?= $e($t('forms.field.title')) ?></th><th scope="col"><?= $e($t('forms.field.category')) ?></th>
        <th scope="col"><?= $e($t('forms.field.status')) ?></th><th scope="col"><?= $e($t('forms.field.version')) ?></th><th scope="col"><?= $e($t('forms.field.downloads')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $f): ?>
        <tr><th scope="row" data-label="<?= $e($t('forms.field.code')) ?>"><a href="<?= $e($url('/forms/admin/f/' . $f['public_id'])) ?>"><?= $e((string) $f['code']) ?></a></th>
          <td data-label="<?= $e($t('forms.field.title')) ?>"><?= $e((string) $f['title']) ?></td>
          <td data-label="<?= $e($t('forms.field.category')) ?>"><?= $e((string) $f['category']) ?></td>
          <td data-label="<?= $e($t('forms.field.status')) ?>"><?= $ui('badge', ['tone' => ['published' => 'success', 'draft' => 'warning', 'archived' => 'neutral'][$f['status']] ?? 'neutral', 'label' => $t('forms.status.' . $f['status'])]) ?><?= (int) $f['open_versions'] > 0 ? ' ' . $ui('badge', ['tone' => 'info', 'label' => $t('forms.has_open')]) : '' ?></td>
          <td data-label="<?= $e($t('forms.field.version')) ?>" class="num"><?= $e($f['current_no'] !== null ? (string) $f['current_no'] : '—') ?></td>
          <td data-label="<?= $e($t('forms.field.downloads')) ?>" class="num"><?= (int) $f['download_count'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<section class="card stack" aria-labelledby="fn-h">
  <h2 id="fn-h" class="card__title"><?= $e($t('forms.new')) ?></h2>
  <form method="post" action="<?= $e($url('/forms/admin')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <input type="hidden" name="legal_entity" value="<?= $e($lePid) ?>">
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'code', 'id' => 'nf-code', 'label' => $t('forms.field.code'), 'required' => true, 'value' => (string) ($old['code'] ?? ''), 'placeholder' => 'HR-01', 'error' => $errors['code'] ?? null]) ?>
      <?= $ui('field', ['name' => 'category', 'id' => 'nf-cat', 'label' => $t('forms.field.category'), 'required' => true, 'value' => (string) ($old['category'] ?? ''), 'placeholder' => $t('forms.category_example'), 'error' => $errors['category'] ?? null]) ?>
      <?= $ui('field', ['name' => 'language', 'id' => 'nf-lang', 'type' => 'select', 'label' => $t('forms.field.language'), 'value' => (string) ($old['language'] ?? 'th'), 'options' => ['th' => $t('forms.lang.th'), 'en' => $t('forms.lang.en'), 'both' => $t('forms.lang.both')]]) ?>
    </div>
    <?= $ui('field', ['name' => 'title', 'id' => 'nf-title', 'label' => $t('forms.field.title'), 'required' => true, 'value' => (string) ($old['title'] ?? ''), 'error' => $errors['title'] ?? null]) ?>
    <?= $ui('field', ['name' => 'description', 'id' => 'nf-desc', 'type' => 'textarea', 'label' => $t('forms.field.description'), 'value' => (string) ($old['description'] ?? ''), 'error' => $errors['description'] ?? null]) ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'starts_on', 'id' => 'nf-from', 'type' => 'date', 'label' => $t('forms.field.starts_on'), 'value' => (string) ($old['starts_on'] ?? ''), 'error' => $errors['starts_on'] ?? null]) ?>
      <?= $ui('field', ['name' => 'ends_on', 'id' => 'nf-to', 'type' => 'date', 'label' => $t('forms.field.ends_on'), 'value' => (string) ($old['ends_on'] ?? ''), 'error' => $errors['ends_on'] ?? null]) ?>
    </div>
    <?php include __DIR__ . '/../../employees/Views/_audience.php'; ?>
    <p class="text-sm text-muted"><?= $e($t('forms.new_help')) ?></p>
    <div class="row"><?= $ui('button', ['label' => $t('forms.create'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></div>
  </form>
</section>
