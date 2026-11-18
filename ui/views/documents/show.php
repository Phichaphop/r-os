<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $doc @var list<array{version: int, result: string}>|null $verify
 */
$cur = $doc['versions'][0] ?? null;
$isHtml = $cur !== null && $cur['file_ext'] === 'html';
$isImage = $cur !== null && in_array($cur['file_ext'], ['png', 'jpg', 'gif', 'webp'], true);
$disposed = $doc['lifecycle_status'] === 'disposed';
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$st = (string) $doc['lifecycle_status'];
$primary = '';
if (!$disposed && $cur !== null) {
    $primary = $isHtml
        ? $ui('button', ['label' => $t('document.view_print'), 'href' => $url('/documents/' . $doc['public_id'] . '/view'), 'variant' => 'primary', 'icon' => 'file-type-pdf'])
        : $ui('button', ['label' => $t('document.download'), 'href' => $url('/documents/' . $doc['public_id'] . '/download'), 'variant' => 'primary', 'icon' => 'download']);
}
ob_start(); include __DIR__ . '/_status.php'; $badge = (string) ob_get_clean();
?>
<?= $ui('page-header', ['title' => (string) $doc['title'], 'subtitle' => $t('document.class.' . $doc['document_class']) . ' · ' . $t('document.classification.' . $doc['classification']),
    'crumbs' => [[$t('document.title'), $url('/documents')], [(string) $doc['title'], null]], 'actions' => $primary]) ?>
<p class="row">
  <?php if ($doc['holds'] !== []): ?><?= $ui('badge', ['tone' => 'warning', 'icon' => 'lock', 'label' => $t('records.on_hold_n', ['n' => count($doc['holds'])])]) ?><?php endif; ?>
  <?php if ((int) $doc['is_demo'] === 1): ?><?= $ui('badge', ['tone' => 'neutral', 'label' => 'DEMO']) ?><?php endif; ?></p>

<?php if ($doc['superseded_by'] !== null): ?>
  <?= $ui('alert', ['tone' => 'info', 'html' => $e($t('document.superseded_by')) . ' <a href="' . $e($url('/documents/' . $doc['superseded_by']['public_id'])) . '">' . $e($doc['superseded_by']['title']) . '</a>']) ?>
<?php endif; ?>
<?php if ($disposed): ?><?= $ui('alert', ['tone' => 'warning', 'text' => $t('document.disposed_note')]) ?><?php endif; ?>
<?php if ($doc['lifecycle_status'] === 'final'): ?><p class="text-sm text-muted"><?= $ui('icon', ['name' => 'lock', 'size' => 'sm']) ?> <?= $e($t('document.final_note')) ?></p><?php endif; ?>

<div class="grid grid--2">
  <section class="card" aria-labelledby="meta-h">
    <h2 id="meta-h" class="card__title"><?= $e($t('document.metadata')) ?></h2>
    <dl class="dl">
      <dt><?= $e($t('document.field.number')) ?></dt><dd class="mono"><?= $e($doc['document_number'] ?? '—') ?></dd>
      <dt><?= $e($t('document.field.status')) ?></dt><dd><?= $badge ?></dd>
      <dt><?= $e($t('document.field.source')) ?></dt><dd><?= $e($t('document.source.' . $doc['source_channel'])) ?></dd>
      <dt><?= $e($t('document.field.created')) ?></dt><dd><?= $e($date($doc['created_at'])) ?></dd>
      <dt><?= $e($t('document.field.finalized')) ?></dt><dd><?= $e($date($doc['finalized_at'])) ?></dd>
      <dt><?= $e($t('document.field.description')) ?></dt><dd><?= $e($doc['description'] ?? '—') ?></dd>
      <?php if ($doc['supersedes'] !== null): ?><dt><?= $e($t('document.field.supersedes')) ?></dt><dd><a href="<?= $e($url('/documents/' . $doc['supersedes']['public_id'])) ?>"><?= $e($doc['supersedes']['title']) ?></a></dd><?php endif; ?>
      <dt><?= $e($t('document.field.retention')) ?></dt><dd><?= $doc['scheduled_disposition_date'] !== null ? $e(\Blue\Core\I18n\DateFormat::day((string) $doc['scheduled_disposition_date'], (string) ($v['locale'] ?? 'th'))) : $e($t('document.retention_none')) ?></dd>
    </dl>
  </section>
  <section class="card stack-sm" aria-labelledby="act-h">
    <h2 id="act-h" class="card__title"><?= $e($t('document.actions')) ?></h2>
    <?php if ($isImage && !$disposed): ?><img class="doc-thumb" src="<?= $e($url('/documents/' . $doc['public_id'] . '/download') . '?inline=1') ?>" alt="<?= $e($doc['title']) ?>"><?php endif; ?>
    <?php if ($doc['can']['finalize']): ?>
      <form method="post" action="<?= $e($url('/documents/' . $doc['public_id'] . '/finalize')) ?>"><?= $csrf ?>
        <?= $ui('button', ['label' => $t('document.finalize'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'lock']) ?>
        <p class="text-sm text-muted"><?= $e($t('document.finalize_help')) ?></p></form>
    <?php endif; ?>
    <?php if ($doc['can']['new_version']): ?>
      <form method="post" action="<?= $e($url('/documents/' . $doc['public_id'] . '/version')) ?>" enctype="multipart/form-data" class="stack-sm"><?= $csrf ?>
        <?= $ui('field', ['name' => 'file', 'type' => 'file', 'label' => $t('document.new_version'), 'required' => true]) ?>
        <?= $ui('field', ['name' => 'note', 'label' => $t('document.field.change_note')]) ?>
        <?= $ui('button', ['label' => $t('document.add_version'), 'type' => 'submit', 'icon' => 'upload']) ?></form>
    <?php endif; ?>
    <?php if ($doc['can']['supersede']): ?>
      <details><summary><?= $e($t('document.supersede')) ?></summary>
      <form method="post" action="<?= $e($url('/documents/' . $doc['public_id'] . '/supersede')) ?>" enctype="multipart/form-data" class="stack-sm"><?= $csrf ?>
        <p class="text-sm text-muted"><?= $e($t('document.supersede_help')) ?></p>
        <?= $ui('field', ['name' => 'file', 'type' => 'file', 'label' => $t('document.field.file'), 'required' => true]) ?>
        <?= $ui('field', ['name' => 'reason', 'label' => $t('iam.field.reason'), 'required' => true]) ?>
        <?= $ui('button', ['label' => $t('document.supersede'), 'type' => 'submit', 'icon' => 'refresh']) ?></form></details>
    <?php endif; ?>
    <?php if (!$disposed): ?>
      <form method="post" action="<?= $e($url('/documents/' . $doc['public_id'] . '/verify')) ?>"><?= $csrf ?>
        <?= $ui('button', ['label' => $t('document.verify'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'shield-check']) ?></form>
    <?php endif; ?>
    <?php if ($verify !== null): ?>
      <ul class="stack-sm text-sm"><?php foreach ($verify as $vr): ?><li>v<?= (int) $vr['version'] ?>: <?= $e($t('document.verify.' . $vr['result'])) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($doc['can']['hold'] && !$disposed): ?>
      <details><summary><?= $e($t('records.hold.place')) ?></summary>
      <form method="post" action="<?= $e($url('/documents/' . $doc['public_id'] . '/hold')) ?>" class="stack-sm"><?= $csrf ?>
        <?= $ui('field', ['name' => 'reference', 'label' => $t('records.field.reference'), 'required' => true]) ?>
        <?= $ui('field', ['name' => 'reason', 'label' => $t('iam.field.reason'), 'required' => true]) ?>
        <?= $ui('field', ['name' => 'review_date', 'label' => $t('records.field.review_date'), 'type' => 'date']) ?>
        <?= $ui('button', ['label' => $t('records.hold.place'), 'type' => 'submit', 'icon' => 'lock']) ?></form></details>
    <?php endif; ?>
  </section>
</div>

<?php if ($doc['holds'] !== []): ?>
<section class="card" aria-labelledby="hold-h">
  <h2 id="hold-h" class="card__title"><?= $e($t('records.holds.title')) ?></h2>
  <ul class="stack-sm"><?php foreach ($doc['holds'] as $h): ?><li><?= $ui('badge', ['tone' => 'warning', 'icon' => 'lock', 'label' => $t('records.hold_status.' . $h['status'])]) ?> <span class="mono"><?= $e($h['reference_code']) ?></span> — <?= $e($h['reason']) ?> (<?= $e($t('records.scope.' . $h['scope_type'])) ?>)</li><?php endforeach; ?></ul>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="ver-h">
  <h2 id="ver-h" class="card__title"><?= $e($t('document.versions')) ?></h2>
  <div class="table-wrap" role="region" aria-label="<?= $e($t('document.versions')) ?>" tabindex="0">
    <table class="table table--stack">
      <caption class="sr-only"><?= $e($t('document.versions')) ?></caption>
      <thead><tr><th scope="col">#</th><th scope="col"><?= $e($t('document.field.filename')) ?></th><th scope="col"><?= $e($t('document.field.size')) ?></th>
        <th scope="col">SHA-256</th><th scope="col"><?= $e($t('document.field.checks')) ?></th><th scope="col"><?= $e($t('document.field.created')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($doc['versions'] as $ver): ?>
        <tr>
          <td data-label="#" class="num"><?= (int) $ver['version_no'] ?></td>
          <td data-label="<?= $e($t('document.field.filename')) ?>">
            <?php if ($ver['purged_at'] === null && !$disposed): ?><a href="<?= $e($url('/documents/' . $doc['public_id'] . '/download') . '?v=' . (int) $ver['version_no']) ?>"><?= $e($ver['original_filename']) ?></a><?php else: ?><?= $e($t('document.purged')) ?><?php endif; ?>
            <?php if ($ver['change_note'] !== null): ?><div class="text-sm text-muted"><?= $e($ver['change_note']) ?></div><?php endif; ?></td>
          <td data-label="<?= $e($t('document.field.size')) ?>" class="num"><?= $e(number_format((int) $ver['size_bytes'] / 1024, 1)) ?> KB<?= $ver['page_count'] !== null ? ' · ' . (int) $ver['page_count'] . ' ' . $e($t('document.pages')) : '' ?></td>
          <td data-label="SHA-256"><span class="mono text-sm" title="<?= $e($ver['sha256']) ?>"><?= $e(substr((string) $ver['sha256'], 0, 16)) ?>…</span></td>
          <td data-label="<?= $e($t('document.field.checks')) ?>" class="text-sm"><?= $e($t('document.check.type_ok', ['type' => strtoupper((string) $ver['file_ext'])])) ?>
            · <?= $e($t((int) $ver['encrypted'] === 1 ? 'document.check.encrypted' : 'document.check.not_encrypted')) ?> · <?= $e($t($ver['malware_scan'] === 'clean' ? 'document.check.malware_clean' : 'document.check.malware_not_available')) ?></td>
          <td data-label="<?= $e($t('document.field.created')) ?>"><?= $e($date($ver['created_at'])) ?><div class="text-sm text-muted"><?= $e($ver['created_by_name'] ?? '—') ?></div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
