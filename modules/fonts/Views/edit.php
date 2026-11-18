<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $f @var list<array<string, mixed>> $files @var list<array<string, mixed>> $events @var array<string, string> $errors
 * @var array<string, mixed> $old @var list<array<string, mixed>> $others @var int $me
 */
$tone = ['draft' => ['neutral', 'pencil'], 'published' => ['success', 'circle-check'], 'deprecated' => ['warning', 'alert-triangle'], 'archived' => ['neutral', 'history']];
[$tn, $ic] = $tone[$f['status']];
$active = array_values(array_filter($files, static fn (array $x): bool => $x['status'] === 'active'));
$hasLicense = trim((string) ($f['license_name'] ?? '')) !== '' && trim((string) ($f['license_source'] ?? '')) !== '';
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$kb = static fn (int $b): string => number_format($b / 1024, 0) . ' KB';
$err = static fn (string $k): ?string => isset($errors[$k]) ? $t($errors[$k]) : null;
?>
<?= $ui('page-header', ['title' => (string) $f['name'], 'subtitle' => $t('fonts.manage.edit_subtitle'),
    'actions' => $ui('badge', ['tone' => $tn, 'icon' => $ic, 'label' => $t('fonts.status.' . $f['status'])])
        . (in_array($f['status'], ['published', 'deprecated'], true) ? $ui('button', ['label' => $t('fonts.manage.view_public'), 'href' => $url('/fonts/' . $f['public_id']), 'variant' => 'ghost', 'icon' => 'eye']) : '')]) ?>
<p><a href="<?= $e($url('/fonts/manage')) ?>"><?= $ui('icon', ['name' => 'chevron-left', 'size' => 'sm']) ?><?= $e($t('fonts.manage.back')) ?></a></p>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'text' => $t('form.has_errors')]) ?><?php endif; ?>

<section class="card stack" id="files" aria-labelledby="fe-files">
  <h2 id="fe-files" class="card__title"><?= $e($t('fonts.files.title')) ?></h2>
  <form method="post" action="<?= $e($url('/fonts/manage/' . $f['public_id'] . '/files')) ?>" enctype="multipart/form-data" class="stack" novalidate>
    <?= $csrf ?>
    <div class="grid grid--2">
    <?= $ui('field', ['name' => 'file', 'type' => 'file', 'label' => $t('fonts.files.upload'), 'required' => true, 'accept' => '.ttf,.otf,.woff,.woff2,.zip', 'error' => $err('file'), 'help' => $t('fonts.files.upload_help')]) ?>
    <?php if ($active !== []): ?>
      <?php $opts = ['' => $t('fonts.files.replaces_none')]; foreach ($active as $x) { $opts[(string) $x['public_id']] = (string) $x['original_name']; } ?>
      <?= $ui('field', ['name' => 'replaces', 'type' => 'select', 'label' => $t('fonts.files.replaces'), 'value' => '', 'options' => $opts, 'error' => $err('replaces'), 'help' => $t('fonts.files.replaces_help')]) ?>
    <?php endif; ?>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('fonts.files.upload_submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'upload', 'disabled' => $f['status'] === 'archived', 'disabledReason' => $t('fonts.error.archived')]) ?></div>
  </form>
  <?php if ($files === []): ?>
    <p class="text-sm text-muted"><?= $e($t('fonts.files.none')) ?></p>
  <?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="fe-files" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('fonts.files.file')) ?></th><th scope="col"><?= $e($t('fonts.files.style')) ?></th><th scope="col"><?= $e($t('fonts.files.version')) ?></th>
        <th scope="col"><?= $e($t('fonts.files.status')) ?></th><th scope="col"><?= $e($t('fonts.files.uploaded')) ?></th><th scope="col"><?= $e($t('fonts.field.downloads')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($files as $x): ?>
        <tr>
          <td data-label="<?= $e($t('fonts.files.file')) ?>"><a href="<?= $e($url('/fonts/file/' . $x['public_id'])) ?>"><?= $e($x['original_name']) ?></a>
            <span class="d-block text-sm text-muted"><?= $e(strtoupper((string) $x['format'])) ?> · <?= $e($kb((int) $x['size_bytes'])) ?></span>
            <span class="d-block text-xs text-muted mono break-all" title="SHA-256">SHA-256 <?= $e(substr((string) $x['sha256'], 0, 16)) ?>…</span></td>
          <td data-label="<?= $e($t('fonts.files.style')) ?>"><?= $e(trim(((string) ($x['font_family'] ?? '')) . ' ' . ((string) ($x['style_name'] ?? '')))) ?>
            <?php if ($x['weight'] !== null): ?><span class="d-block text-sm text-muted"><?= $e($t('fonts.files.weight', ['w' => (string) $x['weight']])) ?><?= (int) $x['italic'] === 1 ? ' · ' . $e($t('fonts.files.italic')) : '' ?></span><?php endif; ?>
            <?php if ($x['embedding'] !== null): ?><span class="d-block text-sm text-muted"><?= $e($t('fonts.embedding.' . $x['embedding'])) ?></span><?php endif; ?></td>
          <td data-label="<?= $e($t('fonts.files.version')) ?>"><?= $e((string) ($x['font_version'] ?? '—')) ?></td>
          <td data-label="<?= $e($t('fonts.files.status')) ?>"><?= $x['status'] === 'active' ? $ui('badge', ['tone' => 'success', 'icon' => 'circle-check', 'label' => $t('fonts.files.active')])
              : $ui('badge', ['tone' => 'neutral', 'icon' => 'history', 'label' => $t('fonts.files.replaced')]) . '<span class="d-block text-sm text-muted">' . $e($date((string) $x['replaced_at'])) . '</span>' ?></td>
          <td data-label="<?= $e($t('fonts.files.uploaded')) ?>"><?= $e((string) ($x['uploaded_name'] ?? '—')) ?><span class="d-block text-sm text-muted"><?= $e($date((string) $x['created_at'])) ?></span></td>
          <td data-label="<?= $e($t('fonts.field.downloads')) ?>"><?= number_format((int) $x['download_count']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="text-sm text-muted"><?= $e($t('fonts.files.never_deleted')) ?></p>
  <?php endif; ?>
</section>

<div class="settings-grid">
  <div class="stack">

    <section class="card stack" aria-labelledby="fe-meta">
      <h2 id="fe-meta" class="card__title"><?= $e($t('fonts.manage.details')) ?></h2>
      <form method="post" action="<?= $e($url('/fonts/manage/' . $f['public_id'])) ?>" class="stack" novalidate>
        <?= $csrf ?>
        <?php include __DIR__ . '/_form.php'; ?>
        <div class="row"><?= $ui('button', ['label' => $t('common.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
      </form>
    </section>
  </div>

  <div class="stack">
    <section class="card stack" aria-labelledby="fe-status">
      <h2 id="fe-status" class="card__title"><?= $e($t('fonts.manage.status_title')) ?></h2>
      <?php if (in_array($f['status'], ['draft', 'deprecated'], true)): ?>
        <ul class="plain-list checklist">
          <li class="<?= $hasLicense ? 'is-ok' : 'is-missing' ?>"><?= $ui('icon', ['name' => $hasLicense ? 'circle-check' : 'circle-x', 'size' => 'sm', 'label' => $t($hasLicense ? 'fonts.check.ok' : 'fonts.check.missing')]) ?> <?= $e($t('fonts.check.license')) ?></li>
          <li class="<?= $active !== [] ? 'is-ok' : 'is-missing' ?>"><?= $ui('icon', ['name' => $active !== [] ? 'circle-check' : 'circle-x', 'size' => 'sm', 'label' => $t($active !== [] ? 'fonts.check.ok' : 'fonts.check.missing')]) ?> <?= $e($t('fonts.check.files')) ?></li>
        </ul>
        <form method="post" action="<?= $e($url('/fonts/manage/' . $f['public_id'] . '/publish')) ?>">
          <?= $csrf ?>
          <?= $ui('button', ['label' => $t('fonts.manage.publish'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send', 'disabled' => !$hasLicense || $active === [],
              'disabledReason' => $t('fonts.manage.publish_blocked')]) ?>
        </form>
      <?php endif; ?>
      <?php if ($f['status'] === 'published'): ?>
        <form method="post" action="<?= $e($url('/fonts/manage/' . $f['public_id'] . '/status')) ?>" class="stack-sm" novalidate>
          <?= $csrf ?><input type="hidden" name="to" value="deprecated">
          <?= $ui('field', ['name' => 'reason', 'id' => 'f-dep-reason', 'label' => $t('fonts.manage.deprecate_reason'), 'required' => true, 'error' => $err('reason')]) ?>
          <?php if ($others !== []): ?>
            <?php $ro = ['' => $t('fonts.manage.no_replacement')]; foreach ($others as $o) { $ro[(string) $o['public_id']] = (string) $o['name']; } ?>
            <?= $ui('field', ['name' => 'replacement', 'type' => 'select', 'label' => $t('fonts.manage.replacement'), 'value' => '', 'options' => $ro, 'error' => $err('replacement')]) ?>
          <?php endif; ?>
          <?= $ui('button', ['label' => $t('fonts.manage.deprecate'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'alert-triangle']) ?>
        </form>
      <?php endif; ?>
      <?php if ($f['status'] !== 'archived'): ?>
        <form method="post" action="<?= $e($url('/fonts/manage/' . $f['public_id'] . '/status')) ?>" class="stack-sm" novalidate>
          <?= $csrf ?><input type="hidden" name="to" value="archived">
          <?= $ui('field', ['name' => 'reason', 'id' => 'f-arc-reason', 'label' => $t('fonts.manage.archive_reason'), 'required' => true, 'help' => $t('fonts.manage.archive_help')]) ?>
          <?= $ui('button', ['label' => $t('fonts.manage.archive'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'history']) ?>
        </form>
      <?php else: ?>
        <form method="post" action="<?= $e($url('/fonts/manage/' . $f['public_id'] . '/status')) ?>" class="stack-sm" novalidate>
          <?= $csrf ?><input type="hidden" name="to" value="draft">
          <?= $ui('field', ['name' => 'reason', 'id' => 'f-res-reason', 'label' => $t('fonts.manage.restore_reason'), 'required' => true]) ?>
          <?= $ui('button', ['label' => $t('fonts.manage.restore'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'refresh']) ?>
        </form>
      <?php endif; ?>
      <?php if ((string) ($f['status_note'] ?? '') !== ''): ?><p class="text-sm text-muted"><?= $e($t('fonts.manage.status_note', ['note' => (string) $f['status_note']])) ?></p><?php endif; ?>
    </section>

    <section class="card stack" id="license" aria-labelledby="fe-lic">
      <h2 id="fe-lic" class="card__title"><?= $e($t('fonts.license.proof')) ?></h2>
      <?php if ($f['license_doc_pid'] !== null): ?>
        <p><?= $ui('icon', ['name' => 'file-text', 'size' => 'sm']) ?> <a href="<?= $e($url('/fonts/manage/' . $f['public_id'] . '/license')) ?>"><?= $e($t('fonts.license.download_proof')) ?></a></p>
      <?php else: ?>
        <p class="text-sm text-muted"><?= $e($t('fonts.license.no_proof')) ?></p>
      <?php endif; ?>
      <form method="post" action="<?= $e($url('/fonts/manage/' . $f['public_id'] . '/license')) ?>" enctype="multipart/form-data" class="stack-sm" novalidate>
        <?= $csrf ?>
        <?= $ui('field', ['name' => 'license_file', 'type' => 'file', 'label' => $t('fonts.license.upload'), 'accept' => '.pdf,.png,.jpg,.jpeg,.webp', 'error' => $err('license_file'), 'help' => $t('fonts.license.upload_help')]) ?>
        <?= $ui('button', ['label' => $t('fonts.license.upload_submit'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'upload']) ?>
      </form>
    </section>

    <section class="card stack-sm" aria-labelledby="fe-hist">
      <h2 id="fe-hist" class="card__title"><?= $e($t('fonts.history')) ?></h2>
      <ol class="plain-list">
        <?php foreach ($events as $ev): $d = (array) json_decode((string) ($ev['detail_json'] ?? ''), true); ?>
          <li><strong><?= $e($t('fonts.event.' . $ev['action'])) ?></strong><?= isset($d['file']) ? ' · ' . $e((string) $d['file']) : '' ?><?= isset($d['reason']) ? ' · ' . $e((string) $d['reason']) : '' ?>
            <span class="d-block text-sm text-muted"><?= $e((string) ($ev['actor_name'] ?? '—')) ?> · <?= $e($date((string) $ev['occurred_at'])) ?></span></li>
        <?php endforeach; ?>
      </ol>
    </section>
  </div>
</div>
