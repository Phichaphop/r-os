<?php
/**
 * Librarian: all items of a company (filter), new item, categories.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v @var array<string, string> $companies @var string $lePid
 * @var array<string, string> $f @var list<array<string, mixed>> $rows @var list<array<string, mixed>> $categories @var array<string, string> $errors @var array<string, mixed> $old
 * @var bool $canManage @var string $here
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$tone = ['draft' => 'neutral', 'published' => 'success', 'archived' => 'warning'];
$editing = (string) ($old['_pid'] ?? '');
?>
<?= $ui('page-header', ['title' => $t('library.manage_title'), 'subtitle' => $t('library.manage_subtitle'),
    'actions' => $ui('button', ['label' => $t('library.new_item'), 'href' => $url('/library/manage/new?le=' . rawurlencode($lePid)), 'variant' => 'primary', 'icon' => 'plus'])]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php include __DIR__ . '/_companies.php'; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<form method="get" action="<?= $e($url('/library/manage')) ?>" class="card stack-sm" role="search"><input type="hidden" name="le" value="<?= $e($lePid) ?>">
  <div class="lb-filter">
    <?= $ui('field', ['name' => 'q', 'label' => $t('library.search'), 'value' => $f['q']]) ?>
    <?= $ui('field', ['name' => 'status', 'type' => 'select', 'label' => $t('library.col.status'), 'value' => $f['status'], 'options' => ['' => $t('library.all_statuses'), 'draft' => $t('library.status.draft'), 'published' => $t('library.status.published'), 'archived' => $t('library.status.archived')]]) ?>
    <?= $ui('field', ['name' => 'kind', 'type' => 'select', 'label' => $t('library.field.kind'), 'value' => $f['kind'], 'options' => ['' => $t('library.all_kinds'), 'digital' => $t('library.kind.digital'), 'physical' => $t('library.kind.physical')]]) ?>
  </div>
  <div class="row"><?= $ui('button', ['label' => $t('library.show'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
</form>
<section class="card card--flush" aria-labelledby="lb-ms">
  <div class="card__head"><h2 id="lb-ms" class="card__title"><?= $e($t('library.items_n', ['n' => (string) count($rows)])) ?></h2></div>
  <?php if ($rows === []): ?><div class="card__pad"><?= $ui('states', ['icon' => 'book', 'title' => $t('library.none'), 'text' => $t('library.manage_none_help')]) ?></div><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th scope="col"><?= $e($t('library.field.title')) ?></th><th scope="col"><?= $e($t('library.field.kind')) ?></th><th scope="col"><?= $e($t('library.col.status')) ?></th><th scope="col"><?= $e($t('library.col.content')) ?></th><th scope="col" class="num"><?= $e($t('library.col.views')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="<?= $e($url('/library/m/' . $r['public_id'])) ?>"><?= $e((string) $r['title']) ?></a><br><span class="text-sm text-muted"><?= $e((string) ($r['category'] ?? '')) ?></span></td>
        <td><?= $e($t('library.kind.' . $r['kind'])) ?></td>
        <td><?= $ui('badge', ['tone' => $tone[$r['status']] ?? 'neutral', 'label' => $t('library.status.' . $r['status'])]) ?></td>
        <td class="text-sm"><?php if ($r['kind'] === 'digital'): ?><?= $r['file_type'] === null ? $e($t('library.no_file_short')) : $e(strtoupper((string) $r['file_type']) . ($r['pages'] !== null ? ' · ' . $t('library.pages_n', ['n' => (string) $r['pages']]) : '') . ' · ' . $t('library.text.' . $r['text_status'])) ?><?php else: ?><?= $e($t('library.copies_loans', ['copies' => (string) $r['copies'], 'loans' => (string) $r['active_loans']])) ?><?php endif; ?></td>
        <td class="num"><?= $e(number_format((int) $r['views']) . ' / ' . number_format((int) $r['downloads'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
<section class="card stack-sm" id="categories" aria-labelledby="lb-cat">
  <h2 id="lb-cat" class="card__title"><?= $e($t('library.categories')) ?></h2>
  <?php if ($categories === []): ?><p class="text-sm text-muted"><?= $e($t('library.no_categories')) ?></p><?php endif; ?>
  <ul class="plain-list stack-sm">
  <?php foreach ($categories as $c): $cp = (string) $c['public_id']; $mine = $editing === $cp; ?>
    <li>
      <details<?= $mine ? ' open' : '' ?>><summary><?= $e((string) $c['name']) ?> <span class="text-sm text-muted">(<?= (int) $c['items'] ?>)</span><?= (int) $c['active'] === 0 ? ' ' . $ui('badge', ['tone' => 'neutral', 'label' => $t('library.inactive')]) : '' ?></summary>
        <form method="post" action="<?= $e($url('/library/manage/category/' . $cp)) ?>" class="stack-sm" novalidate><?= $csrf ?><input type="hidden" name="le" value="<?= $e($lePid) ?>">
          <div class="grid grid--2">
            <?= $ui('field', ['name' => 'name', 'id' => 'f-cat-name-' . $cp, 'label' => $t('library.field.name'), 'required' => true, 'value' => $mine ? (string) ($old['name'] ?? '') : (string) $c['name'], 'error' => $mine ? ($errors['name'] ?? null) : null]) ?>
            <?= $ui('field', ['name' => 'sort', 'id' => 'f-cat-sort-' . $cp, 'type' => 'number', 'label' => $t('library.field.sort'), 'value' => $mine ? (string) ($old['sort'] ?? '') : (string) $c['sort'], 'error' => $mine ? ($errors['sort'] ?? null) : null]) ?>
          </div>
          <label class="check"><input type="checkbox" name="active" value="1"<?= (int) $c['active'] === 1 ? ' checked' : '' ?>> <?= $e($t('library.field.active')) ?></label>
          <div class="row"><?= $ui('button', ['label' => $t('library.save'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm']) ?></div>
        </form>
      </details>
    </li>
  <?php endforeach; ?>
  </ul>
  <form method="post" action="<?= $e($url('/library/manage/category')) ?>" class="stack-sm" novalidate><?= $csrf ?><input type="hidden" name="le" value="<?= $e($lePid) ?>">
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'name', 'id' => 'f-cat-new', 'label' => $t('library.new_category'), 'required' => true, 'value' => $editing === '' ? (string) ($old['name'] ?? '') : '', 'error' => $editing === '' ? ($errors['name'] ?? null) : null]) ?>
      <?= $ui('field', ['name' => 'sort', 'id' => 'f-cat-new-sort', 'type' => 'number', 'label' => $t('library.field.sort'), 'value' => $editing === '' ? (string) ($old['sort'] ?? '0') : '0', 'error' => $editing === '' ? ($errors['sort'] ?? null) : null]) ?>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('library.add_category'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'plus']) ?></div>
  </form>
</section>
