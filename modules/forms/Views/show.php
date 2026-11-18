<?php
/** One form. @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var array<string, mixed> $f @var bool $preview @var string|null $signOff */
use Blue\Core\I18n\DateFormat;

$loc = (string) ($v['locale'] ?? 'th');
$starred = (int) ($f['starred'] ?? 0) === 1;
?>
<?= $ui('page-header', ['title' => (string) $f['title'], 'subtitle' => $f['code'] . ' · ' . $f['category'], 'crumbs' => [[$t('forms.title'), $url('/forms')], [(string) $f['code'], null]],
    'actions' => $f['can_manage'] ? $ui('button', ['label' => $t('forms.manage'), 'href' => $url('/forms/admin/f/' . $f['public_id']), 'variant' => 'secondary', 'icon' => 'settings']) : '']) ?>
<?php if ($f['status'] !== 'published'): ?><?= $ui('alert', ['tone' => 'warning', 'text' => $t('forms.not_published_note')]) ?><?php endif; ?>
<section class="card stack" aria-labelledby="fd-h">
  <h2 id="fd-h" class="card__title"><?= $e($t('forms.details')) ?></h2>
  <?php if ($f['description'] !== null): ?><p><?= $e((string) $f['description']) ?></p><?php endif; ?>
  <dl class="facts">
    <div><dt><?= $e($t('forms.field.version')) ?></dt><dd><?= $e($f['current_no'] !== null ? $t('forms.version_n', ['n' => (string) $f['current_no']]) : '—') ?></dd></div>
    <div><dt><?= $e($t('forms.field.published')) ?></dt><dd><?= $e($f['published_at'] !== null ? DateFormat::day(substr((string) $f['published_at'], 0, 10), $loc) : '—') ?></dd></div>
    <div><dt><?= $e($t('forms.field.language')) ?></dt><dd><?= $e($t('forms.lang.' . $f['language'])) ?></dd></div>
    <div><dt><?= $e($t('forms.field.owner')) ?></dt><dd><?= $e((string) ($f['owner_name'] ?? '—')) ?></dd></div>
    <?php if (($f['change_note'] ?? null) !== null): ?><div><dt><?= $e($t('forms.field.change_note')) ?></dt><dd><?= $e((string) $f['change_note']) ?></dd></div><?php endif; ?>
  </dl>
  <?php if ($f['current_no'] !== null): ?>
  <div class="row">
    <?= $ui('button', ['label' => $t('forms.download'), 'href' => $url('/forms/f/' . $f['public_id'] . '/file'), 'variant' => 'primary', 'icon' => 'download']) ?>
    <?php if ($preview): ?><a class="btn btn--secondary" href="<?= $e($url('/forms/f/' . $f['public_id'] . '/file?preview=1')) ?>" target="_blank" rel="noopener"><?= $ui('icon', ['name' => 'eye']) ?><span><?= $e($t('forms.preview')) ?></span><span class="sr-only"> (<?= $e($t('home.new_tab')) ?>)</span></a><?php endif; ?>
    <?php if ($signOff === null): ?>
      <?= $ui('button', ['label' => $t('forms.send_sign'), 'href' => $url('/esign/new?form=' . rawurlencode((string) $f['public_id'])), 'variant' => 'secondary', 'icon' => 'send']) ?>
    <?php else: ?>
      <?= $ui('button', ['label' => $t('forms.send_sign'), 'variant' => 'secondary', 'icon' => 'send', 'disabled' => true, 'disabledReason' => $t($signOff)]) ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <form method="post" action="<?= $e($url('/forms/f/' . $f['public_id'] . '/star')) ?>">
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <input type="hidden" name="on" value="<?= $starred ? '0' : '1' ?>">
    <?= $ui('button', ['label' => $t($starred ? 'forms.unstar' : 'forms.star'), 'type' => 'submit', 'variant' => 'ghost', 'size' => 'sm', 'icon' => $starred ? 'x' : 'bell']) ?>
  </form>
  <p class="text-sm text-muted"><?= $e($t('forms.star_help')) ?></p>
</section>
