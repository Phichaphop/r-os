<?php
/**
 * Directory. @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $asset @var array<string, mixed> $v
 * @var list<array<string, mixed>> $cards @var string $q @var string $view @var array<string, mixed>|null $me @var array<string, string> $errors @var bool $canAdmin
 */
$tab = static fn (string $key, string $label) => '<a href="' . $e($url('/directory?view=' . $key)) . '"' . ($view === $key ? ' aria-current="page"' : '') . '>' . $e($label) . '</a>';
?>
<?= $ui('page-header', ['title' => $t('directory.title'), 'subtitle' => $t('directory.subtitle'),
    'actions' => $canAdmin ? $ui('button', ['label' => $t('directory.nav_admin'), 'href' => $url('/directory/admin'), 'variant' => 'secondary', 'icon' => 'settings']) : '']) ?>
<form method="get" action="<?= $e($url('/directory')) ?>" class="row row--tight" role="search" data-dir-search data-partial="<?= $e($url('/directory')) ?>">
  <input type="hidden" name="view" value="<?= $e($view) ?>">
  <label class="sr-only" for="dir-q"><?= $e($t('common.search')) ?></label>
  <input class="input dir-search" type="search" id="dir-q" name="q" value="<?= $e($q) ?>" placeholder="<?= $e($t('directory.search_hint')) ?>" autocomplete="off" autofocus>
  <?= $ui('button', ['label' => $t('common.search'), 'type' => 'submit', 'size' => 'sm', 'icon' => 'search']) ?>
</form>
<nav class="segmented-links" aria-label="<?= $e($t('directory.views')) ?>"><?= $tab('all', $t('directory.view_all')) ?><?= $tab('team', $t('directory.view_team')) ?><?= $tab('favorites', $t('directory.view_pinned')) ?></nav>
<section class="card stack-sm" aria-label="<?= $e($t('directory.results')) ?>">
  <?php include __DIR__ . '/_results.php'; ?>
</section>
<?php if ($me !== null): ?>
<section class="card stack-sm" aria-labelledby="me-h">
  <h2 id="me-h" class="card__title"><?= $e($t('directory.my_contact')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('directory.my_contact_help')) ?></p>
  <form method="post" action="<?= $e($url('/directory/me')) ?>" class="row row--tight" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?= $ui('field', ['name' => 'extension', 'id' => 'me-ext', 'label' => $t('directory.field.extension'), 'value' => (string) ($me['extension'] ?? ''), 'inputmode' => 'tel', 'error' => $errors['extension'] ?? null]) ?>
    <?= $ui('button', ['label' => $t('directory.save'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'circle-check']) ?>
  </form>
</section>
<?php endif; ?>
<script src="<?= $e($asset('ui/js/directory.js')) ?>" defer></script>
