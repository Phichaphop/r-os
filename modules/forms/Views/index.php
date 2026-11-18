<?php
/**
 * Find a form. @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var list<array<string, mixed>> $rows @var list<string> $categories @var string $q @var string $cat @var string $view @var bool $canManage @var array<string, mixed>|null $me
 */
use Blue\Core\I18n\DateFormat;

$loc = (string) ($v['locale'] ?? 'th');
$kb = static fn (int $b): string => $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : max(1, (int) round($b / 1024)) . ' KB';
$type = static fn (string $mime): string => match (true) { $mime === 'application/pdf' => 'PDF', str_contains($mime, 'word') => 'Word', str_contains($mime, 'sheet') => 'Excel', str_contains($mime, 'presentation') => 'PowerPoint', str_starts_with($mime, 'image/') => $t('forms.type_image'), default => $t('forms.type_file') };
$tab = static fn (string $key, string $label) => '<a href="' . $e($url('/forms?view=' . $key . ($q !== '' ? '&q=' . rawurlencode($q) : '') . ($cat !== '' ? '&cat=' . rawurlencode($cat) : ''))) . '"' . ($view === $key ? ' aria-current="page"' : '') . '>' . $e($label) . '</a>';
?>
<?= $ui('page-header', ['title' => $t('forms.title'), 'subtitle' => $t('forms.subtitle'),
    'actions' => $canManage ? $ui('button', ['label' => $t('forms.nav_admin'), 'href' => $url('/forms/admin'), 'variant' => 'secondary', 'icon' => 'settings']) : '']) ?>
<?php if ($me === null): ?>
  <?= $ui('alert', ['tone' => 'info', 'title' => $t('forms.no_employee_title'), 'text' => $t('forms.no_employee_text')]) ?>
<?php else: ?>
<form method="get" action="<?= $e($url('/forms')) ?>" class="row row--tight" role="search">
  <input type="hidden" name="view" value="<?= $e($view) ?>">
  <label class="sr-only" for="fq"><?= $e($t('common.search')) ?></label>
  <input class="input" type="search" id="fq" name="q" value="<?= $e($q) ?>" placeholder="<?= $e($t('forms.search_hint')) ?>">
  <label class="sr-only" for="fc"><?= $e($t('forms.field.category')) ?></label>
  <select class="select" id="fc" name="cat"><option value=""><?= $e($t('forms.all_categories')) ?></option><?php foreach ($categories as $c): ?><option value="<?= $e($c) ?>"<?= $c === $cat ? ' selected' : '' ?>><?= $e($c) ?></option><?php endforeach; ?></select>
  <?= $ui('button', ['label' => $t('common.search'), 'type' => 'submit', 'size' => 'sm', 'icon' => 'search']) ?>
</form>
<nav class="segmented-links" aria-label="<?= $e($t('forms.views')) ?>"><?= $tab('all', $t('forms.view_all')) ?><?= $tab('starred', $t('forms.view_starred')) ?><?= $tab('recent', $t('forms.view_recent')) ?></nav>
<section class="card stack-sm" aria-labelledby="fl-h">
  <h2 id="fl-h" class="card__title"><?= $e($t('forms.count', ['n' => (string) count($rows)])) ?></h2>
  <?php if ($rows === []): ?><p class="text-sm text-muted"><?= $e($t('forms.none')) ?></p><?php else: ?>
  <ul class="form-list" role="list">
    <?php foreach ($rows as $f): ?>
    <li class="form-item">
      <span class="form-item__icon" aria-hidden="true"><?= $ui('icon', ['name' => $f['mime'] === 'application/pdf' ? 'file-type-pdf' : 'file-text']) ?></span>
      <div class="form-item__body">
        <a class="form-item__title" href="<?= $e($url('/forms/f/' . $f['public_id'])) ?>"><?= $e((string) $f['title']) ?></a>
        <span class="text-sm text-muted"><?= $e($f['code'] . ' · ' . $f['category'] . ' · ' . $type((string) $f['mime']) . ' ' . $kb((int) $f['size_bytes']) . ' · ' . $t('forms.version_n', ['n' => (string) $f['current_no']]) . ' · ' . DateFormat::day(substr((string) $f['published_at'], 0, 10), $loc)) ?></span>
      </div>
      <?php if ((int) $f['starred'] === 1): ?><span class="badge badge--info"><?= $e($t('forms.starred')) ?></span><?php endif; ?>
      <a class="btn btn--secondary btn--sm" href="<?= $e($url('/forms/f/' . $f['public_id'] . '/file')) ?>"><?= $ui('icon', ['name' => 'download', 'size' => 'sm']) ?><span><?= $e($t('forms.download')) ?></span></a>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php endif; ?>
