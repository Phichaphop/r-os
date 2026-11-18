<?php
/**
 * Manage one form. @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, mixed> $f @var list<array<string, mixed>> $versions @var array{downloads: int, previews: int, people: int} $stats
 * @var array{sites: array<string, string>, departments: array<string, string>} $audOptions @var array<string, mixed> $audIn @var string $audText @var int $members
 * @var array<string, string> $errors @var array<string, mixed> $old @var string $form
 */
use Blue\Core\I18n\DateFormat;

$loc = (string) ($v['locale'] ?? 'th');
$d = $form === 'details' ? $old : $f;
$audError = $form === 'details' ? ($errors['audience'] ?? null) : null;
$audId = 'ef';
$err = static fn (string $which, string $k) => $form === $which ? ($errors[$k] ?? null) : null;
$open = array_values(array_filter($versions, static fn (array $x): bool => in_array($x['status'], ['draft', 'review'], true)));
$vTone = ['draft' => 'warning', 'review' => 'info', 'published' => 'success', 'superseded' => 'neutral', 'rejected' => 'danger'];
?>
<?= $ui('page-header', ['title' => (string) $f['title'], 'subtitle' => $f['code'] . ' · ' . $f['category'], 'crumbs' => [[$t('forms.admin_title'), $url('/forms/admin')], [(string) $f['code'], null]],
    'actions' => $ui('badge', ['tone' => ['published' => 'success', 'draft' => 'warning', 'archived' => 'neutral'][$f['status']] ?? 'neutral', 'label' => $t('forms.status.' . $f['status'])])]) ?>
<div class="grid grid--2">
<section class="card stack" aria-labelledby="vs-h">
  <h2 id="vs-h" class="card__title"><?= $e($t('forms.versions')) ?></h2>
  <?php if ($versions === []): ?><p class="text-sm text-muted"><?= $e($t('forms.no_versions')) ?></p><?php endif; ?>
  <ul class="stack-sm" role="list">
    <?php foreach ($versions as $x): ?>
    <li class="version-row">
      <div><strong><?= $e($t('forms.version_n', ['n' => (string) $x['version_no']])) ?></strong> <?= $ui('badge', ['tone' => $vTone[$x['status']] ?? 'neutral', 'label' => $t('forms.vstatus.' . $x['status'])]) ?>
        <div class="text-sm text-muted"><?= $e($x['file_name'] . ' · ' . ($x['created_name'] ?? '—') . ' · ' . DateFormat::day(substr((string) $x['created_at'], 0, 10), $loc)) ?><?= $x['approved_name'] !== null ? $e(' · ' . $t('forms.approved_by', ['name' => (string) $x['approved_name']])) : '' ?></div>
        <?php if ($x['change_note'] !== null): ?><div class="text-sm"><?= $e((string) $x['change_note']) ?></div><?php endif; ?>
      </div>
      <div class="row row--tight">
        <a class="btn btn--ghost btn--sm" href="<?= $e($url('/forms/f/' . $f['public_id'] . '/v/' . $x['public_id'])) ?>"><?= $ui('icon', ['name' => 'download', 'size' => 'sm']) ?><span><?= $e($t('forms.download')) ?></span></a>
        <?php if ($x['status'] === 'draft'): ?>
        <form method="post" action="<?= $e($url('/forms/admin/v/' . $x['public_id'])) ?>" class="row row--tight">
          <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
          <button class="btn btn--primary btn--sm" type="submit" name="action" value="submit"><?= $ui('icon', ['name' => 'send', 'size' => 'sm']) ?><span><?= $e($t('forms.submit')) ?></span></button>
          <button class="btn btn--ghost btn--sm" type="submit" name="action" value="discard"><?= $ui('icon', ['name' => 'trash', 'size' => 'sm']) ?><span><?= $e($t('forms.discard')) ?></span></button>
        </form>
        <?php elseif ($x['status'] === 'review' && $x['instance_pid'] !== null): ?>
        <a class="btn btn--ghost btn--sm" href="<?= $e($url('/approvals/' . $x['instance_pid'])) ?>"><?= $e($t('forms.approval_link')) ?></a>
        <?php endif; ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php if ($open === [] && $f['status'] !== 'archived'): ?>
  <form method="post" action="<?= $e($url('/forms/admin/f/' . $f['public_id'] . '/upload')) ?>" enctype="multipart/form-data" class="stack-sm" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?= $ui('field', ['name' => 'file', 'id' => 'up-file', 'type' => 'file', 'label' => $t('forms.field.file'), 'required' => true, 'accept' => '.pdf,.docx,.xlsx,.pptx,.png,.jpg,.jpeg', 'help' => $t('forms.file_help'), 'error' => $err('upload', 'file')]) ?>
    <?= $ui('field', ['name' => 'change_note', 'id' => 'up-note', 'label' => $t('forms.field.change_note'), 'value' => (string) ($old['change_note'] ?? ''), 'error' => $err('upload', 'change_note')]) ?>
    <?= $ui('button', ['label' => $t('forms.upload'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'upload']) ?>
  </form>
  <?php elseif ($open !== []): ?><p class="text-sm text-muted"><?= $e($t('forms.open_note')) ?></p><?php endif; ?>
  <p class="text-sm text-muted"><?= $e($t('forms.versions_help')) ?></p>
</section>
<section class="card stack-sm" aria-labelledby="st-h">
  <h2 id="st-h" class="card__title"><?= $e($t('forms.usage')) ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('forms.field.downloads')) ?></dt><dd><?= (int) $stats['downloads'] ?></dd></div>
    <div><dt><?= $e($t('forms.field.previews')) ?></dt><dd><?= (int) $stats['previews'] ?></dd></div>
    <div><dt><?= $e($t('forms.field.people')) ?></dt><dd><?= (int) $stats['people'] ?></dd></div>
    <div><dt><?= $e($t('employees.audience')) ?></dt><dd><?= $e($audText) ?> · <?= $e($t('forms.members_n', ['n' => (string) $members])) ?></dd></div>
  </dl>
  <?php if ($f['status'] === 'published'): ?><p class="text-sm"><a href="<?= $e($url('/forms/f/' . $f['public_id'])) ?>"><?= $e($t('forms.view_as_user')) ?></a></p><?php endif; ?>
</section>
</div>
<section class="card stack" aria-labelledby="dt-h">
  <h2 id="dt-h" class="card__title"><?= $e($t('forms.details')) ?></h2>
  <form method="post" action="<?= $e($url('/forms/admin/f/' . $f['public_id'])) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'code', 'id' => 'ef-code', 'label' => $t('forms.field.code'), 'required' => true, 'value' => (string) ($d['code'] ?? ''), 'error' => $err('details', 'code')]) ?>
      <?= $ui('field', ['name' => 'category', 'id' => 'ef-cat', 'label' => $t('forms.field.category'), 'required' => true, 'value' => (string) ($d['category'] ?? ''), 'error' => $err('details', 'category')]) ?>
      <?= $ui('field', ['name' => 'language', 'id' => 'ef-lang', 'type' => 'select', 'label' => $t('forms.field.language'), 'value' => (string) ($d['language'] ?? 'th'), 'options' => ['th' => $t('forms.lang.th'), 'en' => $t('forms.lang.en'), 'both' => $t('forms.lang.both')]]) ?>
    </div>
    <?= $ui('field', ['name' => 'title', 'id' => 'ef-title', 'label' => $t('forms.field.title'), 'required' => true, 'value' => (string) ($d['title'] ?? ''), 'error' => $err('details', 'title')]) ?>
    <?= $ui('field', ['name' => 'description', 'id' => 'ef-desc', 'type' => 'textarea', 'label' => $t('forms.field.description'), 'value' => (string) ($d['description'] ?? ''), 'error' => $err('details', 'description')]) ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'starts_on', 'id' => 'ef-from', 'type' => 'date', 'label' => $t('forms.field.starts_on'), 'value' => (string) ($d['starts_on'] ?? ''), 'error' => $err('details', 'starts_on')]) ?>
      <?= $ui('field', ['name' => 'ends_on', 'id' => 'ef-to', 'type' => 'date', 'label' => $t('forms.field.ends_on'), 'value' => (string) ($d['ends_on'] ?? ''), 'error' => $err('details', 'ends_on')]) ?>
    </div>
    <?php include __DIR__ . '/../../employees/Views/_audience.php'; ?>
    <div class="row"><?= $ui('button', ['label' => $t('forms.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
  </form>
</section>
<?php if ($f['status'] !== 'archived'): ?>
<section class="card stack-sm" aria-labelledby="ar-h">
  <h2 id="ar-h" class="card__title"><?= $e($t('forms.archive')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('forms.archive_help')) ?></p>
  <form method="post" action="<?= $e($url('/forms/admin/f/' . $f['public_id'] . '/archive')) ?>" class="row row--tight" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?= $ui('field', ['name' => 'reason', 'id' => 'ar-reason', 'label' => $t('forms.field.reason'), 'required' => true, 'error' => $err('archive', 'reason')]) ?>
    <?= $ui('button', ['label' => $t('forms.archive'), 'type' => 'submit', 'variant' => 'danger', 'icon' => 'trash']) ?>
  </form>
</section>
<?php endif; ?>
