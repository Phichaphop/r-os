<?php
/**
 * Librarian: one item — publish / withdraw / back to draft, details, file and versions (digital), copies + desk + queue (physical).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v @var array<string, mixed> $it @var array<string, mixed> $fill
 * @var array<string, mixed> $audIn @var array{sites: array<string, string>, departments: array<string, string>} $audOptions @var list<array<string, mixed>> $categories
 * @var array<string, string> $errors @var array<string, mixed> $old @var string $form @var bool $canManage @var string $here
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$pid = (string) $it['public_id'];
$fe = static fn (string $f, string $k): ?string => $form === $f ? ($errors[$k] ?? null) : null;
$act = static fn (string $a): string => $url('/library/m/' . $pid . '/' . $a);
$size = static fn (int $b): string => $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : number_format($b / 1024, 0) . ' KB';
$tone = ['draft' => 'neutral', 'published' => 'success', 'archived' => 'warning'];
$current = null;
foreach ($it['files'] as $f) {
    if ((int) $f['is_current'] === 1) {
        $current = $f;
    }
}
$kindLocked = $it['files'] !== [] || $it['copy_list'] !== [];
?>
<?= $ui('page-header', ['title' => (string) $it['title'], 'subtitle' => $t('library.kind.' . $it['kind']), 'crumbs' => [[$t('library.manage_title'), $url('/library/manage')], [(string) $it['title'], null]],
    'actions' => $it['status'] !== 'draft' ? $ui('button', ['label' => $t('library.view_as_reader'), 'href' => $url('/library/i/' . $pid), 'variant' => 'secondary']) : '']) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors'), 'text' => in_array($form, ['publish'], true) ? implode(' · ', $errors) : '']) ?><?php endif; ?>

<section class="card stack-sm" aria-labelledby="lb-st">
  <h2 id="lb-st" class="card__title"><?= $e($t('library.col.status')) ?> <?= $ui('badge', ['tone' => $tone[$it['status']] ?? 'neutral', 'label' => $t('library.status.' . $it['status'])]) ?></h2>
  <?php if ($it['status'] === 'archived' && $it['archive_reason'] !== null): ?><p class="text-sm"><?= $e($t('library.archived_because', ['reason' => (string) $it['archive_reason']])) ?></p><?php endif; ?>
  <p class="text-sm text-muted"><?= $e($t('library.last30', ['views' => number_format((int) $it['last30']['views']), 'downloads' => number_format((int) $it['last30']['downloads'])])) ?> · <?= $e($t('library.audience_is', ['who' => (string) $it['audience_text']])) ?></p>
  <div class="row">
    <?php if ($it['status'] === 'draft'): ?>
      <form method="post" action="<?= $e($act('publish')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('library.publish'), 'type' => 'submit', 'variant' => 'primary']) ?></form>
      <span class="text-sm text-muted"><?= $e($t('library.publish_help')) ?></span>
    <?php elseif ($it['status'] === 'archived'): ?>
      <form method="post" action="<?= $e($act('restore')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('library.restore'), 'type' => 'submit', 'variant' => 'secondary']) ?></form>
    <?php endif; ?>
  </div>
  <?php if ($it['status'] === 'published'): ?>
  <details<?= $form === 'archive' ? ' open' : '' ?>><summary><?= $e($t('library.archive')) ?></summary>
    <form method="post" action="<?= $e($act('archive')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <?= $ui('field', ['name' => 'reason', 'id' => 'f-archive-reason', 'label' => $t('library.field.reason'), 'required' => true, 'help' => $t('library.archive_help'), 'error' => $fe('archive', 'reason')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('library.archive'), 'type' => 'submit', 'variant' => 'danger', 'size' => 'sm']) ?></div>
    </form>
  </details>
  <?php endif; ?>
</section>

<?php if ($it['kind'] === 'digital'): ?>
<section class="card stack-sm" id="file" aria-labelledby="lb-f">
  <h2 id="lb-f" class="card__title"><?= $e($t('library.file')) ?></h2>
  <?php if ($current === null): ?><p class="text-sm text-muted"><?= $e($t('library.no_file')) ?></p><?php else: ?>
    <p><?= $e((string) $current['filename']) ?> · <?= $e(strtoupper((string) $current['file_type']) . ' · ' . $size((int) $current['size_bytes'])) ?><?= $current['pages'] !== null ? ' · ' . $e($t('library.pages_n', ['n' => (string) $current['pages']])) : '' ?></p>
    <p class="text-sm"><?= $ui('badge', ['tone' => $current['text_status'] === 'indexed' ? 'success' : ($current['text_status'] === 'not_pdf' ? 'neutral' : 'warning'), 'label' => $t('library.text.' . $current['text_status'])]) ?> <?= $e($t('library.text_help.' . $current['text_status'])) ?></p>
  <?php endif; ?>
  <?php if ($it['status'] !== 'archived'): ?>
  <form method="post" action="<?= $e($act('upload')) ?>" enctype="multipart/form-data" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('dropzone', ['name' => 'file', 'id' => 'f-file', 'label' => $current === null ? $t('library.upload') : $t('library.upload_new_version'), 'required' => true, 'accept' => '.pdf,.docx,.xlsx,.pptx,.png,.jpg,.jpeg', 'help' => $t('library.upload_help'), 'error' => $fe('upload', 'file')]) ?>
    <?= $ui('field', ['name' => 'note', 'id' => 'f-file-note', 'label' => $t('library.field.version_note'), 'error' => $fe('upload', 'note')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('library.upload'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'upload']) ?></div>
  </form>
  <?php endif; ?>
  <?php if (count($it['files']) > 0): ?>
  <details><summary><?= $e($t('library.versions_n', ['n' => (string) count($it['files'])])) ?></summary>
    <ul class="plain-list stack-sm">
      <?php foreach ($it['files'] as $f): ?>
      <li class="text-sm"><a href="<?= $e($url('/library/m/' . $pid . '/v/' . $f['version_no'])) ?>"><?= $e($t('library.version_n', ['n' => (string) $f['version_no']])) ?> · <?= $e((string) $f['filename']) ?></a> · <?= $e($date((string) $f['uploaded_at']) . ' · ' . ($f['uploader'] ?? '')) ?><?= $f['note'] !== null ? ' · ' . $e((string) $f['note']) : '' ?><?= (int) $f['is_current'] === 1 ? ' ' . $ui('badge', ['tone' => 'info', 'label' => $t('library.current')]) : '' ?></li>
      <?php endforeach; ?>
    </ul>
  </details>
  <?php endif; ?>
</section>
<?php else: ?>
<section class="card stack-sm" id="loans" aria-labelledby="lb-desk">
  <h2 id="lb-desk" class="card__title"><?= $e($t('library.desk')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('library.available_n', ['n' => (string) $it['copies']['available'], 'total' => (string) $it['copies']['total']])) ?> · <?= $e($t('library.loan_terms', ['loan' => (string) $it['loan_days'], 'pickup' => (string) $it['pickup_days']])) ?></p>
  <?php if ($it['status'] === 'published'): ?>
  <div class="grid grid--2">
    <form method="post" action="<?= $e($act('lend')) ?>" class="stack-sm lb-deskform" novalidate><?= $csrf ?>
      <h3 class="text-sm"><?= $e($t('library.lend')) ?></h3>
      <?= $ui('field', ['name' => 'who', 'id' => 'f-lend-who', 'label' => $t('library.field.who'), 'required' => true, 'help' => $t('library.who_help'), 'value' => $form === 'lend' ? (string) ($old['who'] ?? '') : '', 'error' => $fe('lend', 'who')]) ?>
      <?= $ui('field', ['name' => 'copy', 'id' => 'f-lend-copy', 'label' => $t('library.field.copy'), 'help' => $t('library.copy_optional_help'), 'value' => $form === 'lend' ? (string) ($old['copy'] ?? '') : '', 'error' => $fe('lend', 'copy')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('library.lend'), 'type' => 'submit', 'variant' => 'primary', 'size' => 'sm']) ?></div>
    </form>
    <form method="post" action="<?= $e($act('return')) ?>" class="stack-sm lb-deskform" novalidate><?= $csrf ?>
      <h3 class="text-sm"><?= $e($t('library.return')) ?></h3>
      <?= $ui('field', ['name' => 'copy', 'id' => 'f-return-copy', 'label' => $t('library.field.copy'), 'required' => true, 'value' => $form === 'return' ? (string) ($old['copy'] ?? '') : '', 'error' => $fe('return', 'copy')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('library.return'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm']) ?></div>
    </form>
  </div>
  <?php else: ?><p class="text-sm text-muted"><?= $e($t('library.desk_after_publish')) ?></p><?php endif; ?>
  <h3 class="text-sm"><?= $e($t('library.loans_and_queue')) ?></h3>
  <?php if ($it['loans'] === []): ?><p class="text-sm text-muted"><?= $e($t('library.no_active_loans')) ?></p><?php else: ?>
  <ul class="plain-list stack-sm">
    <?php foreach ($it['loans'] as $l): $late = $l['status'] === 'loaned' && (string) $l['due_on'] < (string) $it['today']; $lp = (string) $l['public_id']; ?>
    <li class="lb-loan stack-sm">
      <div class="row">
        <?= $ui('badge', ['tone' => $late ? 'danger' : (['ready' => 'success', 'loaned' => 'info', 'waiting' => 'warning'][$l['status']] ?? 'neutral'), 'label' => $late ? $t('library.loan.overdue') : $t('library.loan.' . $l['status'])]) ?>
        <span><?= $e($l['employee_code'] . ' ' . $l['name_th']) ?></span>
        <span class="text-sm text-muted"><?= $e(($l['copy_code'] !== null ? $t('library.copy_x', ['code' => (string) $l['copy_code']]) . ' · ' : '') . ($l['status'] === 'loaned' ? $t('library.due_on', ['date' => (string) $l['due_on']]) : ($l['status'] === 'ready' ? $t('library.pick_up_by', ['date' => (string) $l['ready_until']]) : $date((string) $l['queued_at'])))) ?></span>
      </div>
      <details><summary><?= $e($l['status'] === 'loaned' ? $t('library.mark_lost') : $t('library.cancel_reservation')) ?></summary>
        <form method="post" action="<?= $e($url('/library/loan/' . $lp . '/' . ($l['status'] === 'loaned' ? 'lost' : 'cancel'))) ?>" class="stack-sm" novalidate><?= $csrf ?>
          <?= $ui('field', ['name' => 'reason', 'id' => 'f-loan-reason-' . $lp, 'label' => $t('library.field.reason'), 'required' => true]) ?>
          <div class="row"><?= $ui('button', ['label' => $l['status'] === 'loaned' ? $t('library.mark_lost') : $t('library.cancel_reservation'), 'type' => 'submit', 'variant' => 'danger', 'size' => 'sm']) ?></div>
        </form>
      </details>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<section class="card stack-sm" id="copies" aria-labelledby="lb-c">
  <h2 id="lb-c" class="card__title"><?= $e($t('library.copies')) ?></h2>
  <?php if ($it['copy_list'] === []): ?><p class="text-sm text-muted"><?= $e($t('library.no_copies')) ?></p><?php else: ?>
  <ul class="plain-list stack-sm">
    <?php foreach ($it['copy_list'] as $c): $cp = (string) $c['public_id']; ?>
    <li class="stack-sm">
      <div class="row"><strong><?= $e((string) $c['code']) ?></strong> <span class="text-sm text-muted"><?= $e((string) ($c['location'] ?? '')) ?></span> <?= $ui('badge', ['tone' => ['available' => 'success', 'reserved' => 'warning', 'loaned' => 'info'][$c['status']] ?? 'neutral', 'label' => $t('library.copy.' . $c['status'])]) ?></div>
      <?php if ($c['status'] === 'available'): ?>
      <details><summary><?= $e($t('library.withdraw_copy')) ?></summary>
        <form method="post" action="<?= $e($url('/library/copy/' . $cp . '/withdraw')) ?>" class="stack-sm" novalidate><?= $csrf ?>
          <?= $ui('field', ['name' => 'reason', 'id' => 'f-copy-reason-' . $cp, 'label' => $t('library.field.reason'), 'required' => true]) ?>
          <div class="row"><?= $ui('button', ['label' => $t('library.withdraw_copy'), 'type' => 'submit', 'variant' => 'danger', 'size' => 'sm']) ?></div>
        </form>
      </details>
      <?php elseif (in_array($c['status'], ['withdrawn', 'lost'], true) && $c['note'] !== null): ?><p class="text-sm text-muted"><?= $e((string) $c['note']) ?></p><?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php if ($it['status'] !== 'archived'): ?>
  <form method="post" action="<?= $e($act('copy')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'code', 'id' => 'f-copy-code', 'label' => $t('library.field.copy_code'), 'required' => true, 'help' => $t('library.copy_code_help'), 'value' => $form === 'copy' ? (string) ($old['code'] ?? '') : '', 'error' => $fe('copy', 'code')]) ?>
      <?= $ui('field', ['name' => 'location', 'id' => 'f-copy-location', 'label' => $t('library.field.location'), 'value' => $form === 'copy' ? (string) ($old['location'] ?? '') : '', 'error' => $fe('copy', 'location')]) ?>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('library.add_copy'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'plus']) ?></div>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="card stack-sm" aria-labelledby="lb-e">
  <h2 id="lb-e" class="card__title"><?= $e($t('library.edit_details')) ?></h2>
  <form method="post" action="<?= $e($act('save')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?php $ferr = $form === 'save' ? $errors : []; include __DIR__ . '/_fields.php'; ?>
    <div class="row"><?= $ui('button', ['label' => $t('library.save'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
  </form>
</section>
