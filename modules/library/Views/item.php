<?php
/**
 * One item for a reader: details, rights, read online / download, my place and bookmarks, matching pages; books: copies and reservation.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v @var array<string, mixed> $it
 * @var array<string, string> $errors @var string $form @var string $q @var bool $canManage @var string $here
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$pid = (string) $it['public_id'];
$file = $it['file'];
$read = $it['read'];
$fe = static fn (string $f, string $k): ?string => $form === $f ? ($errors[$k] ?? null) : null;
$size = static fn (int $b): string => $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : number_format($b / 1024, 0) . ' KB';
?>
<?= $ui('page-header', ['title' => (string) $it['title'], 'subtitle' => (string) ($it['author'] ?? ''), 'crumbs' => [[$t('library.title'), $url('/library')], [(string) $it['title'], null]],
    'actions' => $it['can_manage'] ? $ui('button', ['label' => $t('library.manage_item'), 'href' => $url('/library/m/' . $pid), 'variant' => 'secondary', 'icon' => 'pencil']) : '']) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<?php if ($it['status'] !== 'published'): ?><?= $ui('alert', ['tone' => 'warning', 'title' => $t('library.not_published_note', ['status' => $t('library.status.' . $it['status'])])]) ?><?php endif; ?>
<div class="lb-detail">
<section class="card stack-sm" aria-labelledby="lb-d">
  <h2 id="lb-d" class="card__title"><?= $e($t('library.details')) ?></h2>
  <dl class="lb-dl">
    <dt><?= $e($t('library.field.kind')) ?></dt><dd><?= $e($t('library.kind.' . $it['kind'])) ?></dd>
    <?php if ($it['category'] !== null): ?><dt><?= $e($t('library.field.category')) ?></dt><dd><?= $e((string) $it['category']) ?></dd><?php endif; ?>
    <?php if (($it['publisher'] ?? '') !== '' || $it['pub_year'] !== null): ?><dt><?= $e($t('library.field.publisher')) ?></dt><dd><?= $e(trim(($it['publisher'] ?? '') . ' ' . ($it['pub_year'] ?? ''))) ?></dd><?php endif; ?>
    <dt><?= $e($t('library.field.language')) ?></dt><dd><?= $e($t('library.lang.' . $it['language'])) ?></dd>
    <?php if (($it['tags'] ?? '') !== ''): ?><dt><?= $e($t('library.field.tags')) ?></dt><dd><?= $e((string) $it['tags']) ?></dd><?php endif; ?>
    <dt><?= $e($t('library.field.rights_holder')) ?></dt><dd><?= $e((string) ($it['rights_holder'] ?? '—')) ?></dd>
    <dt><?= $e($t('library.field.license')) ?></dt><dd><?= $e((string) ($it['license'] ?? '—')) ?></dd>
    <?php if ($file !== null): ?><dt><?= $e($t('library.file')) ?></dt><dd><?= $e(strtoupper((string) $file['file_type']) . ' · ' . $size((int) $file['size_bytes']) . ($file['pages'] !== null ? ' · ' . $t('library.pages_n', ['n' => (string) $file['pages']]) : '')) ?></dd><?php endif; ?>
  </dl>
  <?php if (($it['description'] ?? '') !== ''): ?><p class="lb-desc"><?= nl2br($e((string) $it['description']), false) ?></p><?php endif; ?>
  <p class="text-sm text-muted"><?= $e($t('library.copyright_note')) ?></p>
</section>

<?php if ($it['kind'] === 'digital'): ?>
<section class="card stack-sm" aria-labelledby="lb-r">
  <h2 id="lb-r" class="card__title"><?= $e($t('library.reading')) ?></h2>
  <?php if ($file === null): ?><p class="text-sm text-muted"><?= $e($t('library.no_file')) ?></p><?php endif; ?>
  <div class="row">
    <?php if ($it['can_read_online']): ?>
      <?= $ui('button', ['label' => $read !== null && $read['last_page'] !== null ? $t('library.continue_page', ['page' => (string) $read['last_page']]) : $t('library.read_online'), 'href' => $url('/library/i/' . $pid . '/read'), 'variant' => 'primary', 'icon' => 'book']) ?>
    <?php endif; ?>
    <?php if ($it['can_download']): ?>
      <?= $ui('button', ['label' => $t('library.download'), 'href' => $url('/library/i/' . $pid . '/download'), 'variant' => 'secondary', 'icon' => 'download']) ?>
    <?php elseif ($file !== null && (int) $it['read_only'] === 1): ?>
      <span class="text-sm text-muted"><?= $e($t('library.read_only_note')) ?></span>
    <?php endif; ?>
  </div>
  <?php if ($file !== null && $file['file_type'] === 'pdf' && $file['text_status'] === 'no_text'): ?><p class="text-sm text-muted"><?= $e($t('library.no_text_note')) ?></p><?php endif; ?>
  <?php if ($it['hits'] !== []): ?>
    <h3 class="text-sm"><?= $e($t('library.found_in', ['q' => $q])) ?></h3>
    <ul class="plain-list stack-sm">
      <?php foreach ($it['hits'] as $h): ?><li><a class="lb-hit text-sm" href="<?= $e($url('/library/i/' . $pid . '/read?page=' . $h['page'])) ?>"><strong><?= $e($t('library.on_page', ['page' => (string) $h['page']])) ?></strong> <?= $e($h['snippet']) ?></a></li><?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?php if ($it['can_read_online']): ?>
  <h3 class="text-sm"><?= $e($t('library.bookmarks')) ?></h3>
  <?php if ($it['bookmarks'] === []): ?><p class="text-sm text-muted"><?= $e($t('library.no_bookmarks')) ?></p><?php else: ?>
  <ul class="plain-list stack-sm">
    <?php foreach ($it['bookmarks'] as $b): ?>
    <li class="lb-bm">
      <a href="<?= $e($url('/library/i/' . $pid . '/read?page=' . $b['page'])) ?>"><?= $e($t('library.reader_page', ['page' => (string) $b['page']])) ?></a>
      <span class="text-sm"><?= $e((string) ($b['note'] ?? '')) ?></span>
      <form method="post" action="<?= $e($url('/library/i/' . $pid . '/unbookmark')) ?>"><?= $csrf ?><input type="hidden" name="bookmark" value="<?= (int) $b['id'] ?>"><?= $ui('button', ['label' => $t('library.remove'), 'type' => 'submit', 'variant' => 'ghost', 'size' => 'sm', 'attrs' => ['aria-label' => $t('library.remove_bookmark', ['page' => (string) $b['page']])]]) ?></form>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <details<?= $form === 'bookmark' ? ' open' : '' ?>><summary><?= $e($t('library.add_bookmark')) ?></summary>
    <form method="post" action="<?= $e($url('/library/i/' . $pid . '/bookmark')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'page', 'id' => 'f-bm-page', 'type' => 'number', 'label' => $t('library.field.page'), 'required' => true, 'value' => (string) ($read['last_page'] ?? 1), 'error' => $fe('bookmark', 'page')]) ?>
        <?= $ui('field', ['name' => 'note', 'id' => 'f-bm-note', 'label' => $t('library.field.note'), 'error' => $fe('bookmark', 'note')]) ?>
      </div>
      <div class="row"><?= $ui('button', ['label' => $t('library.save'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm']) ?></div>
    </form>
  </details>
  <?php endif; ?>
</section>
<?php else: ?>
<section class="card stack-sm" aria-labelledby="lb-b">
  <h2 id="lb-b" class="card__title"><?= $e($t('library.borrow')) ?></h2>
  <p><?= $e($t('library.available_n', ['n' => (string) $it['copies']['available'], 'total' => (string) $it['copies']['total']])) ?> · <?= $e($t('library.loan_terms', ['loan' => (string) $it['loan_days'], 'pickup' => (string) $it['pickup_days']])) ?></p>
  <?php $l = $it['my_loan']; if ($l !== null): ?>
    <?php if ($l['status'] === 'ready'): ?><?= $ui('alert', ['tone' => 'success', 'title' => $t('library.ready_for_you', ['until' => (string) $l['ready_until']])]) ?>
    <?php elseif ($l['status'] === 'waiting'): ?><?= $ui('alert', ['tone' => 'info', 'title' => $t('library.in_queue', ['n' => (string) $it['queue_pos']])]) ?>
    <?php elseif ((string) $l['due_on'] < (string) $it['today']): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('library.overdue_since', ['due' => (string) $l['due_on']])]) ?>
    <?php else: ?><?= $ui('alert', ['tone' => 'info', 'title' => $t('library.on_loan', ['due' => (string) $l['due_on']])]) ?><?php endif; ?>
    <?php if ($l['status'] !== 'loaned'): ?>
      <form method="post" action="<?= $e($url('/library/l/' . $l['public_id'] . '/cancel')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('library.cancel_reservation'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm']) ?></form>
    <?php endif; ?>
  <?php elseif ($it['can_reserve']): ?>
    <?php if ($it['copies']['available'] === 0): ?><p class="text-sm text-muted"><?= $e($t('library.queue_n', ['n' => (string) $it['waiting']])) ?></p><?php endif; ?>
    <form method="post" action="<?= $e($url('/library/i/' . $pid . '/reserve')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $it['copies']['available'] > 0 ? $t('library.reserve') : $t('library.join_queue'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'bookmark']) ?></form>
  <?php endif; ?>
  <p class="text-sm text-muted"><?= $e($t('library.no_fines_note')) ?></p>
</section>
<?php endif; ?>
</div>
