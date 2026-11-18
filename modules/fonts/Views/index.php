<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $asset @var array<string, mixed> $v
 * @var list<array<string, mixed>> $rows @var string $q @var string $category @var bool $canManage
 */
use Blue\Modules\Fonts\FontsService;

$pick = static function (array $files): ?array {
    foreach ($files as $x) {
        if ((int) ($x['weight'] ?? 400) === 400 && (int) $x['italic'] === 0) {
            return $x;
        }
    }
    return $files[0] ?? null;
};
$cats = ['' => $t('fonts.category.all')];
foreach (FontsService::CATEGORIES as $c) {
    $cats[$c] = $t('fonts.category.' . $c);
}
?>
<?= $ui('page-header', ['title' => $t('fonts.title'), 'subtitle' => $t('fonts.subtitle'),
    'actions' => $canManage ? $ui('button', ['label' => $t('fonts.manage.title'), 'href' => $url('/fonts/manage'), 'variant' => 'secondary', 'icon' => 'settings']) : '']) ?>
<section class="card stack" aria-labelledby="ff-h">
  <h2 id="ff-h" class="sr-only"><?= $e($t('fonts.search')) ?></h2>
  <form method="get" action="<?= $e($url('/fonts')) ?>" class="row row--end" role="search">
    <?= $ui('field', ['name' => 'q', 'label' => $t('fonts.search'), 'value' => $q, 'placeholder' => $t('fonts.search_placeholder')]) ?>
    <?= $ui('field', ['name' => 'category', 'type' => 'select', 'label' => $t('fonts.field.category'), 'value' => $category, 'options' => $cats]) ?>
    <?= $ui('button', ['label' => $t('fonts.search'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'search']) ?>
  </form>
  <?php include __DIR__ . '/_preview_controls.php'; ?>
</section>

<?php if ($rows === []): ?>
  <?= $ui('states', ['kind' => 'empty', 'icon' => 'typography', 'title' => $t($q !== '' || $category !== '' ? 'fonts.none_found' : 'fonts.none_title'),
      'text' => $t($q !== '' || $category !== '' ? 'fonts.none_found_text' : ($canManage ? 'fonts.none_admin' : 'fonts.none_user'))]) ?>
<?php else: ?>
<div class="font-grid">
  <?php foreach ($rows as $f): $x = $pick($f['files']); ?>
  <article class="card stack-sm font-card" aria-labelledby="fc-<?= $e($f['public_id']) ?>">
    <div class="row row--between">
      <h2 class="card__title" id="fc-<?= $e($f['public_id']) ?>"><a href="<?= $e($url('/fonts/' . $f['public_id'])) ?>"><?= $e($f['name']) ?></a></h2>
      <span class="row">
        <?= $ui('badge', ['tone' => 'neutral', 'icon' => 'typography', 'label' => $t('fonts.category.' . $f['category'])]) ?>
        <?php if ($f['status'] === 'deprecated'): ?><?= $ui('badge', ['tone' => 'warning', 'icon' => 'alert-triangle', 'label' => $t('fonts.status.deprecated')]) ?><?php endif; ?>
        <?php if ((int) $f['restricted'] === 1): ?><?= $ui('badge', ['tone' => 'info', 'icon' => 'lock', 'label' => $t('fonts.restricted')]) ?><?php endif; ?>
      </span>
    </div>
    <?php if ($x !== null): ?>
      <p class="font-sample" data-font-sample data-font-src="<?= $e($url('/fonts/file/' . $x['public_id'] . '/preview')) ?>" lang="th"><?= $e($t('fonts.preview.default')) ?></p>
    <?php endif; ?>
    <?php if ((string) ($f['description'] ?? '') !== ''): ?><p class="text-sm text-muted"><?= $e(mb_strimwidth((string) $f['description'], 0, 160, '…')) ?></p><?php endif; ?>
    <p class="text-sm text-muted"><?= $e($t('fonts.styles_count', ['n' => count($f['files'])])) ?> · <?= $e($t('fonts.downloads', ['n' => number_format((int) $f['download_count'])])) ?></p>
  </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<script src="<?= $e($asset('ui/js/fonts.js')) ?>" defer></script>
