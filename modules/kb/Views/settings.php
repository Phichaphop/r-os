<?php
/**
 * Reviewers: categories (Thai / English names, order, active) and synonym groups for search.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var array<string, string> $companies @var string $lePid
 * @var list<array<string, mixed>> $categories @var string $synonyms @var array<string, string> $errors @var array<string, mixed> $old @var string $here @var bool $canAuthor @var bool $canReview
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$editing = (string) ($old['_pid'] ?? '');
?>
<?= $ui('page-header', ['title' => $t('kb.settings_title'), 'subtitle' => $t('kb.settings_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php include __DIR__ . '/_companies.php'; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<section class="card stack-sm" aria-labelledby="kb-sc">
  <h2 id="kb-sc" class="card__title"><?= $e($t('kb.categories')) ?></h2>
  <?php if ($categories === []): ?><p class="text-sm"><?= $e($t('kb.no_categories')) ?></p><?php endif; ?>
  <ul class="plain-list stack-sm">
  <?php foreach ($categories as $c): $cp = (string) $c['public_id']; $mine = $editing === $cp; ?>
    <li><details<?= $mine ? ' open' : '' ?>><summary><?= $e($c['name_th'] . ($c['name_en'] !== null ? ' / ' . $c['name_en'] : '')) ?> <span class="text-sm text-muted">(<?= (int) $c['published'] ?>)</span><?= (int) $c['active'] === 1 ? '' : ' ' . $ui('badge', ['tone' => 'neutral', 'label' => $t('kb.inactive')]) ?></summary>
      <form method="post" action="<?= $e($url('/kb/settings/category/' . $cp)) ?>" class="row kb-search" novalidate><?= $csrf ?><input type="hidden" name="le" value="<?= $e($lePid) ?>">
        <?= $ui('field', ['name' => 'name_th', 'id' => 'f-th-' . $cp, 'label' => $t('kb.field.name_th'), 'required' => true, 'value' => $mine ? (string) ($old['name_th'] ?? '') : (string) $c['name_th'], 'error' => $mine ? ($errors['name_th'] ?? null) : null]) ?>
        <?= $ui('field', ['name' => 'name_en', 'id' => 'f-en-' . $cp, 'label' => $t('kb.field.name_en'), 'value' => $mine ? (string) ($old['name_en'] ?? '') : (string) ($c['name_en'] ?? '')]) ?>
        <?= $ui('field', ['name' => 'sort', 'id' => 'f-sort-' . $cp, 'type' => 'number', 'label' => $t('kb.field.sort'), 'value' => (string) $c['sort']]) ?>
        <label class="check"><input type="checkbox" name="active" value="1"<?= (int) $c['active'] === 1 ? ' checked' : '' ?>> <?= $e($t('kb.active')) ?></label>
        <?= $ui('button', ['label' => $t('kb.save'), 'type' => 'submit', 'variant' => 'secondary', 'attrs' => ['aria-label' => $t('kb.save_category', ['name' => (string) $c['name_th']])]]) ?>
      </form>
    </details></li>
  <?php endforeach; ?>
  </ul>
  <form method="post" action="<?= $e($url('/kb/settings/category')) ?>" class="row kb-search" novalidate><?= $csrf ?><input type="hidden" name="le" value="<?= $e($lePid) ?>">
    <?= $ui('field', ['name' => 'name_th', 'label' => $t('kb.field.name_th'), 'required' => true, 'value' => $editing === '' ? (string) ($old['name_th'] ?? '') : '', 'placeholder' => $t('kb.category_example'), 'error' => $editing === '' ? ($errors['name_th'] ?? null) : null]) ?>
    <?= $ui('field', ['name' => 'name_en', 'label' => $t('kb.field.name_en'), 'value' => $editing === '' ? (string) ($old['name_en'] ?? '') : '']) ?>
    <?= $ui('field', ['name' => 'sort', 'type' => 'number', 'label' => $t('kb.field.sort'), 'value' => '0']) ?>
    <?= $ui('button', ['label' => $t('kb.add_category'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?>
  </form>
</section>
<section class="card stack-sm" aria-labelledby="kb-sy">
  <h2 id="kb-sy" class="card__title"><?= $e($t('kb.synonyms')) ?></h2>
  <form method="post" action="<?= $e($url('/kb/settings/synonyms')) ?>" class="stack-sm" novalidate><?= $csrf ?><input type="hidden" name="le" value="<?= $e($lePid) ?>">
    <?= $ui('field', ['name' => 'synonyms', 'type' => 'textarea', 'label' => $t('kb.field.synonyms'), 'value' => $synonyms, 'help' => $t('kb.synonyms_help'), 'error' => $errors['synonyms'] ?? null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('kb.save'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
  </form>
</section>
