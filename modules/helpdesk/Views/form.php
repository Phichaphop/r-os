<?php
/**
 * A new ticket: work category, urgency, what is wrong, where, which equipment, files. Blocked while a closed ticket of
 * mine waits for a rating.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $asset @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $categories @var list<array<string, mixed>> $sites
 * @var ?array<string, mixed> $unrated @var bool $staff @var bool $canManage @var bool $kbSuggest @var array<string, string> $errors @var array<string, mixed> $old
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$o = static fn (string $k, string $d = ''): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$here = '/helpdesk/new';
$prio = [];
foreach (['low', 'normal', 'high', 'urgent'] as $p) {
    $prio[$p] = $t('helpdesk.priority.' . $p) . ' — ' . $t('helpdesk.priority_help.' . $p);
}
?>
<?= $ui('page-header', ['title' => $t('helpdesk.new_title'), 'subtitle' => $t('helpdesk.new_subtitle'), 'crumbs' => [[$t('helpdesk.title'), $url('/helpdesk')], [$t('helpdesk.tab.new'), null]]]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('helpdesk.pick_company')) ?>">
  <?php foreach ($companies as $cp => $code): ?><a href="<?= $e($url('/helpdesk/new?le=' . rawurlencode($cp))) ?>"<?= $cp === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<?php if ($unrated !== null): ?>
  <?= $ui('alert', ['tone' => 'warning', 'title' => $t('helpdesk.rate_first_title'), 'html' => $e($t('helpdesk.rate_first_text', ['ref' => (string) $unrated['ref']])) . ' <a href="' . $e($url('/helpdesk/t/' . $unrated['public_id'] . '#hd-rate')) . '">' . $e($t('helpdesk.rate_now')) . '</a>']) ?>
<?php elseif ($categories === []): ?>
  <?= $ui('states', ['icon' => 'tool', 'title' => $t('helpdesk.no_categories'), 'text' => $t('helpdesk.no_categories_help')]) ?>
<?php else: ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<form method="post" action="<?= $e($url('/helpdesk/new')) ?>" enctype="multipart/form-data" class="card stack" novalidate><?= $csrf ?>
  <input type="hidden" name="le" value="<?= $e($lePid) ?>">
  <div class="grid grid--2">
    <?= $ui('field', ['name' => 'category', 'type' => 'select', 'label' => $t('helpdesk.field.category'), 'required' => true, 'value' => $o('category'), 'options' => ['' => $t('helpdesk.pick_category')] + array_column($categories, 'name', 'public_id'), 'error' => $errors['category'] ?? null]) ?>
    <?= $ui('field', ['name' => 'priority', 'type' => 'select', 'label' => $t('helpdesk.field.priority'), 'required' => true, 'value' => $o('priority', 'normal'), 'options' => $prio, 'error' => $errors['priority'] ?? null]) ?>
  </div>
  <?= $ui('field', ['name' => 'title', 'label' => $t('helpdesk.field.title'), 'required' => true, 'value' => $o('title'), 'full' => true, 'help' => $t('helpdesk.title_help'), 'error' => $errors['title'] ?? null]) ?>
  <?php if ($kbSuggest): ?><div data-kb-suggest="<?= $e($url('/kb/suggest')) ?>" aria-live="polite"><div data-kb-results></div></div><?php endif; ?>
  <?= $ui('field', ['name' => 'description', 'type' => 'textarea', 'label' => $t('helpdesk.field.description'), 'required' => true, 'value' => $o('description'), 'help' => $t('helpdesk.description_help'), 'error' => $errors['description'] ?? null]) ?>
  <div class="grid grid--3">
    <?= $ui('field', ['name' => 'site', 'type' => 'select', 'label' => $t('helpdesk.field.site'), 'value' => $o('site'), 'options' => ['' => '—'] + array_column($sites, 'code', 'public_id'), 'error' => $errors['site'] ?? null]) ?>
    <?= $ui('field', ['name' => 'location', 'label' => $t('helpdesk.field.location'), 'value' => $o('location'), 'error' => $errors['location'] ?? null]) ?>
    <?= $ui('field', ['name' => 'asset', 'label' => $t('helpdesk.field.asset'), 'value' => $o('asset'), 'error' => $errors['asset'] ?? null]) ?>
  </div>
  <?= $ui('dropzone', ['name' => 'files[]', 'id' => 'f-files', 'label' => $t('helpdesk.field.files'), 'multiple' => true, 'accept' => '.pdf,.png,.jpg,.jpeg,.webp,image/*', 'help' => $t('helpdesk.files_help'), 'error' => $errors['files'] ?? null]) ?>
  <p class="text-sm text-muted"><?= $ui('icon', ['name' => 'lock', 'size' => 'sm']) ?> <?= $e($t('helpdesk.no_secrets')) ?></p>
  <div class="row"><?= $ui('button', ['label' => $t('helpdesk.send'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
</form>
<?php endif; ?>
<?php if ($kbSuggest): ?><script src="<?= $e($asset('ui/js/kb.js')) ?>" defer></script><?php endif; ?>
