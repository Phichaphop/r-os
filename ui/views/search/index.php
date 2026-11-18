<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var string $q @var array<string, list<array{title: string, subtitle: string, href: string, icon: string}>> $results @var bool $tooShort
 */
?>
<?= $ui('page-header', ['title' => $t('search.title'), 'subtitle' => $t('search.subtitle')]) ?>
<form method="get" action="<?= $e($url('/search')) ?>" class="filter-bar" role="search">
  <?= $ui('field', ['name' => 'q', 'label' => $t('search.field'), 'value' => $q, 'autofocus' => $q === '', 'placeholder' => $t('nav.search_placeholder')]) ?>
  <?= $ui('button', ['label' => $t('nav.search'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'search']) ?>
</form>
<?php if ($tooShort): ?><?= $ui('alert', ['tone' => 'info', 'text' => $t('search.too_short')]) ?><?php endif; ?>
<?php if ($q !== '' && !$tooShort && $results === []): ?>
  <?= $ui('states', ['icon' => 'search', 'title' => $t('search.none', ['q' => $q]), 'text' => $t('search.none_text')]) ?>
<?php endif; ?>
<?php foreach ($results as $source => $hits): ?>
<section class="card" aria-labelledby="s-<?= $e($source) ?>">
  <h2 id="s-<?= $e($source) ?>" class="card__title"><?= $e($t('search.source.' . $source)) ?> <span class="text-sm text-muted">(<?= count($hits) ?>)</span></h2>
  <ul class="search-hits">
    <?php foreach ($hits as $h): ?>
      <li><a href="<?= $e($url($h['href'])) ?>"><?= $ui('icon', ['name' => $h['icon'], 'size' => 'sm']) ?><span><?= $e($h['title']) ?></span></a>
        <?php if ($h['subtitle'] !== ''): ?><span class="text-sm text-muted"><?= $e($h['subtitle']) ?></span><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endforeach; ?>
<p class="text-sm text-muted"><?= $e($t('search.note')) ?></p>
