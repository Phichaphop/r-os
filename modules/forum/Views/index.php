<?php
/**
 * The company board: threads (pinned first), filter by category, search, latest / most popular / this week, mine.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var int|null $le @var array<string, string> $f
 * @var list<array<string, mixed>> $categories @var list<array<string, mixed>> $rows @var bool $canModerate @var string $here
 */
$copts = ['' => $t('forum.all_categories')];
foreach ($categories as $c) {
    $copts[(string) $c['public_id']] = (string) $c['name'];
}
?>
<?= $ui('page-header', ['title' => $t('forum.title'), 'subtitle' => $t('forum.subtitle'),
    'actions' => $le !== null ? $ui('button', ['label' => $t('forum.new_thread'), 'href' => $url('/forum/new'), 'variant' => 'primary', 'icon' => 'plus']) : '']) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if ($le === null): ?>
  <?= $ui('alert', ['tone' => 'info', 'title' => $t('forum.members_only')]) ?>
<?php else: ?>
<form method="get" action="<?= $e($url('/forum')) ?>" class="card row fm-filter" role="search">
  <?= $ui('field', ['name' => 'q', 'label' => $t('forum.search'), 'value' => $f['q']]) ?>
  <?= $ui('field', ['name' => 'category', 'type' => 'select', 'label' => $t('forum.field.category'), 'value' => $f['category'], 'options' => $copts]) ?>
  <?= $ui('field', ['name' => 'sort', 'type' => 'select', 'label' => $t('forum.sort'), 'value' => $f['sort'], 'options' => ['' => $t('forum.sort_latest'), 'popular' => $t('forum.sort_popular'), 'week' => $t('forum.sort_week')]]) ?>
  <label class="check"><input type="checkbox" name="mine" value="1"<?= $f['mine'] === '1' ? ' checked' : '' ?>> <?= $e($t('forum.mine')) ?></label>
  <?= $ui('button', ['label' => $t('forum.show'), 'type' => 'submit', 'variant' => 'secondary']) ?>
</form>
<section class="card card--flush" aria-labelledby="fm-l">
  <div class="card__head"><h2 id="fm-l" class="card__title"><?= $e($t('forum.threads_n', ['n' => (string) count($rows)])) ?></h2></div>
  <?php if ($rows === []): ?><div class="card__pad"><?= $ui('states', ['icon' => 'users', 'title' => $t('forum.none'), 'text' => $categories === [] ? $t('forum.no_categories') : $t('forum.none_help')]) ?></div><?php else: ?>
  <ul class="plain-list card__pad stack">
  <?php foreach ($rows as $row): ?>
    <li class="fm-item">
      <div class="stack-sm">
        <a href="<?= $e($url('/forum/t/' . $row['public_id'])) ?>"><strong><?= $e((string) $row['title']) ?></strong></a>
        <span class="text-sm text-muted"><?= $e($row['category'] . ' · ' . $row['author'] . ' · ' . $date((string) $row['last_post_at'])) ?></span>
      </div>
      <span class="row fm-badges">
        <?= (int) $row['pinned'] === 1 ? $ui('badge', ['tone' => 'info', 'label' => $t('forum.pinned')]) : '' ?>
        <?= (int) $row['locked'] === 1 ? $ui('badge', ['tone' => 'neutral', 'label' => $t('forum.locked')]) : '' ?>
        <?= (int) $row['hidden'] === 1 ? $ui('badge', ['tone' => 'danger', 'label' => $t('forum.hidden')]) : '' ?>
        <span class="text-sm"><?= $e($t('forum.counts', ['replies' => (string) $row['reply_count'], 'likes' => (string) $row['likes']])) ?></span>
      </span>
    </li>
  <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php endif; ?>
