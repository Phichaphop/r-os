<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $asset @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $f @var list<array<string, mixed>> $files @var bool $canManage
 */
$kb = static fn (int $b): string => number_format($b / 1024, 0) . ' KB';
?>
<?= $ui('page-header', ['title' => (string) $f['name'], 'subtitle' => $t('fonts.category.' . $f['category']) . ' · ' . $t('fonts.downloads', ['n' => number_format((int) $f['download_count'])]),
    'actions' => ($canManage ? $ui('button', ['label' => $t('fonts.manage.edit'), 'href' => $url('/fonts/manage/' . $f['public_id']), 'variant' => 'ghost', 'icon' => 'pencil']) : '')
        . ($files !== [] ? $ui('button', ['label' => $t('fonts.download_zip'), 'href' => $url('/fonts/' . $f['public_id'] . '/zip'), 'variant' => 'primary', 'icon' => 'download']) : '')]) ?>
<p><a href="<?= $e($url('/fonts')) ?>"><?= $ui('icon', ['name' => 'chevron-left', 'size' => 'sm']) ?><?= $e($t('fonts.back')) ?></a></p>
<?php if ($f['status'] === 'deprecated'): ?>
  <?= $ui('alert', ['tone' => 'warning', 'title' => $t('fonts.deprecated_title'), 'text' => $t('fonts.deprecated_text') . ((string) ($f['status_note'] ?? '') !== '' ? ' — ' . $f['status_note'] : '')]) ?>
  <?php if ($f['replacement'] !== null): ?><p><?= $e($t('fonts.use_instead')) ?> <a href="<?= $e($url('/fonts/' . $f['replacement']['public_id'])) ?>"><?= $e($f['replacement']['name']) ?></a></p><?php endif; ?>
<?php endif; ?>
<div class="settings-grid">
  <section class="card stack" aria-labelledby="fp-h">
    <h2 id="fp-h" class="card__title"><?= $e($t('fonts.preview.title')) ?></h2>
    <?php include __DIR__ . '/_preview_controls.php'; ?>
    <ul class="plain-list font-styles">
      <?php foreach ($files as $x): ?>
      <li class="stack-sm font-style">
        <div class="row row--between">
          <strong><?= $e(($x['style_name'] ?? '') !== '' ? $x['style_name'] : $x['original_name']) ?></strong>
          <span class="text-sm text-muted"><?= $e(strtoupper((string) $x['format'])) ?> · <?= $e($kb((int) $x['size_bytes'])) ?><?= ($x['font_version'] ?? '') !== '' ? ' · ' . $e($x['font_version']) : '' ?></span>
        </div>
        <p class="font-sample" data-font-sample data-font-src="<?= $e($url('/fonts/file/' . $x['public_id'] . '/preview')) ?>" lang="th"><?= $e($t('fonts.preview.default')) ?></p>
        <div class="row">
          <?= $ui('button', ['label' => $t('fonts.download_file', ['name' => (string) $x['original_name']]), 'href' => $url('/fonts/file/' . $x['public_id']), 'variant' => 'secondary', 'icon' => 'download']) ?>
          <?php if ($x['embedding'] === 'restricted'): ?><?= $ui('badge', ['tone' => 'warning', 'icon' => 'lock', 'label' => $t('fonts.embedding.restricted')]) ?><?php endif; ?>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <div class="stack">
    <section class="card stack-sm" aria-labelledby="fl-h">
      <h2 id="fl-h" class="card__title"><?= $e($t('fonts.license.title')) ?></h2>
      <dl class="facts">
        <div><dt><?= $e($t('fonts.field.license_name')) ?></dt><dd><?= $e((string) ($f['license_name'] ?? '—')) ?></dd></div>
        <div><dt><?= $e($t('fonts.field.license_source')) ?></dt><dd class="break-all"><?= $e((string) ($f['license_source'] ?? '—')) ?></dd></div>
      </dl>
      <?php if ((string) ($f['license_terms'] ?? '') !== ''): ?><p class="pre-line"><?= $e($f['license_terms']) ?></p><?php endif; ?>
      <?= $ui('alert', ['tone' => 'info', 'text' => $t('fonts.internal_only')]) ?>
    </section>
    <?php if ((string) ($f['description'] ?? '') !== '' || (string) ($f['dos'] ?? '') !== '' || (string) ($f['donts'] ?? '') !== ''): ?>
    <section class="card stack-sm" aria-labelledby="fg-h">
      <h2 id="fg-h" class="card__title"><?= $e($t('fonts.guide')) ?></h2>
      <?php if ((string) ($f['description'] ?? '') !== ''): ?><p class="pre-line"><?= $e($f['description']) ?></p><?php endif; ?>
      <?php if ((string) ($f['dos'] ?? '') !== ''): ?><h3 class="text-sm font-do"><?= $ui('icon', ['name' => 'circle-check', 'size' => 'sm']) ?> <?= $e($t('fonts.field.dos')) ?></h3><p class="pre-line"><?= $e($f['dos']) ?></p><?php endif; ?>
      <?php if ((string) ($f['donts'] ?? '') !== ''): ?><h3 class="text-sm font-dont"><?= $ui('icon', ['name' => 'circle-x', 'size' => 'sm']) ?> <?= $e($t('fonts.field.donts')) ?></h3><p class="pre-line"><?= $e($f['donts']) ?></p><?php endif; ?>
    </section>
    <?php endif; ?>
  </div>
</div>
<script src="<?= $e($asset('ui/js/fonts.js')) ?>" defer></script>
