<?php
/**
 * Search the library (words in the details, or the text inside PDF pages), continue reading.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var int|null $le @var array<string, string> $f @var int $pg @var int $per
 * @var array{rows: list<array<string, mixed>>, total: int} $res @var list<array<string, mixed>> $categories @var list<array<string, mixed>> $recent @var bool $canManage @var string $here
 */
$copts = ['' => $t('library.all_categories')];
foreach ($categories as $c) {
    $copts[(string) $c['public_id']] = (string) $c['name'];
}
$q = $f['q'];
?>
<?= $ui('page-header', ['title' => $t('library.title'), 'subtitle' => $t('library.subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if ($le === null): ?>
  <?= $ui('alert', ['tone' => 'info', 'title' => $t('library.members_only')]) ?>
<?php else: ?>
<?php if ($recent !== []): ?>
<section class="card stack-sm" aria-labelledby="lb-recent">
  <h2 id="lb-recent" class="card__title"><?= $e($t('library.continue')) ?></h2>
  <ul class="plain-list lb-recent">
  <?php foreach ($recent as $r): ?>
    <li>
      <?php if ($r['gone']): ?>
        <span class="text-muted"><?= $e((string) $r['title']) ?></span> <?= $ui('badge', ['tone' => 'neutral', 'label' => $t('library.withdrawn')]) ?>
      <?php else: ?>
        <a href="<?= $e($url('/library/i/' . $r['public_id'] . ($r['last_page'] !== null ? '/read?page=' . (int) $r['last_page'] : ''))) ?>"><?= $e((string) $r['title']) ?></a>
        <span class="text-sm text-muted"><?= $r['last_page'] !== null ? $e($t('library.page_of', ['page' => (string) $r['last_page'], 'pages' => (string) ($r['read_pages'] ?? '?')])) : '' ?></span>
      <?php endif; ?>
    </li>
  <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<form method="get" action="<?= $e($url('/library')) ?>" class="card stack-sm" role="search">
  <div class="lb-filter">
    <?= $ui('field', ['name' => 'q', 'label' => $t('library.search'), 'value' => $q, 'help' => $t('library.search_help')]) ?>
    <?= $ui('field', ['name' => 'category', 'type' => 'select', 'label' => $t('library.field.category'), 'value' => $f['category'], 'options' => $copts]) ?>
    <?= $ui('field', ['name' => 'kind', 'type' => 'select', 'label' => $t('library.field.kind'), 'value' => $f['kind'], 'options' => ['' => $t('library.all_kinds'), 'digital' => $t('library.kind.digital'), 'physical' => $t('library.kind.physical')]]) ?>
    <?= $ui('field', ['name' => 'lang', 'type' => 'select', 'label' => $t('library.field.language'), 'value' => $f['lang'], 'options' => ['' => $t('library.all_languages'), 'th' => $t('library.lang.th'), 'en' => $t('library.lang.en'), 'other' => $t('library.lang.other')]]) ?>
    <?= $ui('field', ['name' => 'sort', 'type' => 'select', 'label' => $t('library.sort'), 'value' => $f['sort'], 'options' => ['' => $t('library.sort_new'), 'popular' => $t('library.sort_popular'), 'title' => $t('library.sort_title')]]) ?>
  </div>
  <div class="row"><?= $ui('button', ['label' => $t('library.show'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'search']) ?></div>
</form>
<section class="card card--flush" aria-labelledby="lb-l">
  <div class="card__head"><h2 id="lb-l" class="card__title"><?= $e($t('library.results_n', ['n' => number_format($res['total'])])) ?></h2></div>
  <?php if ($res['rows'] === []): ?><div class="card__pad"><?= $ui('states', ['icon' => 'book', 'title' => $q !== '' ? $t('library.no_match') : $t('library.none'), 'text' => $q !== '' ? $t('library.no_match_help') : $t('library.none_help')]) ?></div><?php else: ?>
  <ul class="plain-list card__pad stack">
  <?php foreach ($res['rows'] as $row): $href = '/library/i/' . $row['public_id'] . ($q !== '' ? '?q=' . rawurlencode($q) : ''); ?>
    <li class="lb-item stack-sm">
      <div class="lb-item__head">
        <a href="<?= $e($url($href)) ?>"><strong><?= $e((string) $row['title']) ?></strong></a>
        <span class="row lb-badges">
          <?= $ui('badge', ['tone' => $row['kind'] === 'digital' ? 'info' : 'neutral', 'label' => $t('library.kind.' . $row['kind'])]) ?>
          <?php if ($row['copies'] !== null): ?><?= $ui('badge', ['tone' => $row['copies']['available'] > 0 ? 'success' : 'warning', 'label' => $t('library.available_n', ['n' => (string) $row['copies']['available'], 'total' => (string) $row['copies']['total']])]) ?><?php endif; ?>
        </span>
      </div>
      <span class="text-sm text-muted"><?= $e(implode(' · ', array_filter([(string) ($row['author'] ?? ''), (string) ($row['category'] ?? ''), $t('library.lang.' . $row['language']), $row['pub_year'] !== null ? (string) $row['pub_year'] : '']))) ?></span>
      <?php foreach ($row['hits'] as $h): ?>
        <a class="lb-hit text-sm" href="<?= $e($url('/library/i/' . $row['public_id'] . '/read?page=' . $h['page'])) ?>"><strong><?= $e($t('library.on_page', ['page' => (string) $h['page']])) ?></strong> <?= $e($h['snippet']) ?></a>
      <?php endforeach; ?>
    </li>
  <?php endforeach; ?>
  </ul>
  <div class="card__pad"><?= $ui('pagination', ['path' => '/library', 'query' => $f, 'page' => $pg, 'per' => $per, 'total' => $res['total']]) ?></div>
  <?php endif; ?>
</section>
<?php endif; ?>
