<?php
/**
 * Authors / reviewers: one article — the working revision, send for review, review (not the author), withdraw, owner, files, history.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v @var array<string, mixed> $a
 * @var list<array<string, mixed>> $categories @var array<string, mixed> $fill @var array<string, string> $errors @var array<string, mixed> $old  @var string $form @var int $me
 * @var string $here @var bool $canAuthor @var bool $canReview
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$pid = (string) $a['public_id'];
$w = $a['working'];
$pub = $a['published'];
$archived = $a['status'] === 'archived';
$act = static fn (string $x): string => $url('/kb/manage/a/' . $pid . '/' . $x);
$fe = static fn (string $f, string $k): ?string => $form === $f ? ($errors[$k] ?? null) : null;
$rtone = ['draft' => 'neutral', 'review' => 'info', 'returned' => 'warning', 'published' => 'success', 'superseded' => 'neutral', 'withdrawn' => 'neutral'];
?>
<?= $ui('page-header', ['title' => (string) (($w ?? $pub)['question_th'] ?? ''), 'subtitle' => $a['category']['name_th'] . ' · ' . $t('kb.owner_is', ['name' => (string) $a['owner_name']]),
    'crumbs' => [[$t('kb.manage_title'), $url('/kb/manage')], [$t('kb.article'), null]],
    'actions' => $pub !== null && !$archived ? $ui('button', ['label' => $t('kb.view_published'), 'href' => $url('/kb/a/' . $pid), 'variant' => 'secondary', 'icon' => 'eye']) : '']) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<?php if ($archived): ?><?= $ui('alert', ['tone' => 'warning', 'title' => $t('kb.archived_note', ['reason' => (string) $a['archived_reason']])]) ?><?php endif; ?>
<div class="grid grid--2">
  <section class="card stack-sm" aria-labelledby="kb-st">
    <h2 id="kb-st" class="card__title"><?= $e($t('kb.state')) ?></h2>
    <dl class="facts">
      <div><dt><?= $e($t('kb.published_version')) ?></dt><dd><?= $pub === null ? $e($t('kb.not_published')) : $e($t('kb.version_n', ['n' => (string) $pub['version'], 'date' => $date((string) $pub['decided_at'])])) ?></dd></div>
      <div><dt><?= $e($t('kb.working_version')) ?></dt><dd><?php if ($w === null): ?>—<?php else: ?><?= $e('v' . $w['version']) ?> <?= $ui('badge', ['tone' => $rtone[$w['status']] ?? 'neutral', 'label' => $t('kb.status.' . $w['status'])]) ?><?php endif; ?></dd></div>
      <?php if ($w !== null && $w['review_note'] !== null): ?><div><dt><?= $e($t('kb.review_note')) ?></dt><dd><?= $e((string) $w['review_note']) ?></dd></div><?php endif; ?>
      <div><dt><?= $e($t('kb.field.review_due')) ?></dt><dd><?= $a['review_due'] === null ? '—' : $e($date((string) $a['review_due'], false)) ?></dd></div>
      <div><dt><?= $e($t('kb.col.reads')) ?></dt><dd><?= (int) $a['views'] ?> · <?= $e($t('kb.readers_n', ['n' => (string) $a['viewers']])) ?></dd></div>
    </dl>
  </section>
  <section class="card stack-sm" aria-labelledby="kb-nx">
    <h2 id="kb-nx" class="card__title"><?= $e($t('kb.next_step')) ?></h2>
    <?php if ($w !== null && in_array($w['status'], ['draft', 'returned'], true) && !$archived): ?>
      <form method="post" action="<?= $e($act('submit')) ?>" class="stack-sm" novalidate><?= $csrf ?>
        <?= $ui('field', ['name' => 'review_due', 'type' => 'date', 'label' => $t('kb.field.review_due'), 'required' => true, 'value' => $form === 'submit' ? (string) ($old['review_due'] ?? '') : '', 'help' => $t('kb.review_due_help'), 'error' => $fe('submit', 'review_due')]) ?>
        <div class="row"><?= $ui('button', ['label' => $t('kb.send_review'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
      </form>
    <?php elseif ($w !== null && $w['status'] === 'review'): ?>
      <?php if (!$a['can_review']): ?><p class="text-sm"><?= $e($t('kb.waiting_review')) ?></p>
      <?php elseif ((int) $w['author_id'] === $me): ?><p class="text-sm"><?= $e($t('kb.error.own_revision')) ?></p>
      <?php else: ?>
      <form method="post" action="<?= $e($act('approve')) ?>" class="stack-sm" novalidate><?= $csrf ?>
        <?= $ui('field', ['name' => 'note', 'id' => 'f-approve-note', 'label' => $t('kb.field.review_note'), 'value' => '']) ?>
        <div class="row"><?= $ui('button', ['label' => $t('kb.approve'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
      </form>
      <details<?= $form === 'return' ? ' open' : '' ?>><summary><?= $e($t('kb.return')) ?></summary>
        <form method="post" action="<?= $e($act('return')) ?>" class="stack-sm" novalidate><?= $csrf ?>
          <?= $ui('field', ['name' => 'note', 'id' => 'f-return-note', 'label' => $t('kb.field.reason'), 'required' => true, 'error' => $fe('return', 'note')]) ?>
          <div class="row"><?= $ui('button', ['label' => $t('kb.return'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
        </form>
      </details>
      <?php endif; ?>
    <?php else: ?><p class="text-sm text-muted"><?= $e($archived ? $t('kb.archived_short') : $t('kb.edit_to_change')) ?></p><?php endif; ?>
    <?php if ($a['can_review'] && !$archived): ?>
      <details<?= $form === 'withdraw' ? ' open' : '' ?>><summary><?= $e($t('kb.withdraw')) ?></summary>
        <form method="post" action="<?= $e($act('withdraw')) ?>" class="stack-sm" novalidate><?= $csrf ?>
          <?= $ui('field', ['name' => 'reason', 'label' => $t('kb.field.reason'), 'required' => true, 'error' => $fe('withdraw', 'reason')]) ?>
          <label class="check"><input type="checkbox" name="tell" value="1"> <?= $e($t('kb.tell_readers', ['n' => (string) $a['viewers']])) ?></label>
          <div class="row"><?= $ui('button', ['label' => $t('kb.withdraw'), 'type' => 'submit', 'variant' => 'danger']) ?></div>
        </form>
      </details>
      <details<?= $form === 'owner' ? ' open' : '' ?>><summary><?= $e($t('kb.change_owner')) ?></summary>
        <form method="post" action="<?= $e($act('owner')) ?>" class="row kb-search" novalidate><?= $csrf ?>
          <?= $ui('field', ['name' => 'owner', 'label' => $t('kb.field.owner'), 'required' => true, 'help' => $t('kb.owner_help'), 'error' => $fe('owner', 'owner')]) ?>
          <?= $ui('button', ['label' => $t('kb.save'), 'type' => 'submit', 'variant' => 'secondary']) ?>
        </form>
      </details>
    <?php endif; ?>
  </section>
</div>
<?php if (!$archived && ($w === null || $w['status'] !== 'review')): ?>
<section class="card stack-sm" aria-labelledby="kb-ed">
  <h2 id="kb-ed" class="card__title"><?= $e($w === null ? $t('kb.new_revision') : $t('kb.edit_working', ['n' => (string) $w['version']])) ?></h2>
  <?php if ($w === null && $pub !== null): ?><p class="text-sm text-muted"><?= $e($t('kb.new_revision_help')) ?></p><?php endif; ?>
  <form method="post" action="<?= $e($act('edit')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?php $ferr = $form === 'edit' ? $errors : []; $needReason = $pub !== null; include __DIR__ . '/_fields.php'; ?>
    <div class="row"><?= $ui('button', ['label' => $t('kb.save_draft'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
  </form>
</section>
<?php elseif ($w !== null): ?>
<section class="card stack-sm" aria-labelledby="kb-pv">
  <h2 id="kb-pv" class="card__title"><?= $e($t('kb.in_review_text', ['n' => (string) $w['version']])) ?></h2>
  <p><strong><?= $e((string) $w['question_th']) ?></strong></p>
  <div class="kb-body"><?= \Blue\Modules\Kb\KbService::render((string) $w['answer_th']) ?></div>
  <?php if ($w['change_reason'] !== null): ?><p class="text-sm text-muted"><?= $e($t('kb.field.change_reason') . ': ' . $w['change_reason']) ?></p><?php endif; ?>
</section>
<?php endif; ?>
<div class="grid grid--2">
  <section class="card stack-sm" aria-labelledby="kb-fl">
    <h2 id="kb-fl" class="card__title"><?= $e($t('kb.files')) ?></h2>
    <?php if ($a['files'] === []): ?><p class="text-sm text-muted"><?= $e($t('kb.no_files')) ?></p><?php else: ?>
    <ul class="plain-list stack-sm"><?php foreach ($a['files'] as $f): ?><li><a href="<?= $e($url('/kb/a/' . $pid . '/f/' . $f['id'])) ?>"><?= $e((string) $f['filename']) ?></a></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if (!$archived): ?>
    <form method="post" action="<?= $e($act('attach')) ?>" enctype="multipart/form-data" class="stack-sm" novalidate><?= $csrf ?>
      <?= $ui('field', ['name' => 'file', 'type' => 'file', 'label' => $t('kb.field.file'), 'accept' => '.pdf,.png,.jpg,.jpeg,.webp,.docx,.xlsx', 'help' => $t('kb.file_help'), 'error' => $fe('attach', 'file')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('kb.upload'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'upload']) ?></div>
    </form>
    <?php endif; ?>
  </section>
  <section class="card stack-sm" aria-labelledby="kb-fbk">
    <h2 id="kb-fbk" class="card__title"><?= $e($t('kb.feedback')) ?></h2>
    <?php if ($a['feedback'] === []): ?><p class="text-sm text-muted"><?= $e($t('kb.no_feedback')) ?></p><?php else: ?>
    <ul class="plain-list stack-sm"><?php foreach ($a['feedback'] as $fb): ?><li><?= $ui('badge', ['tone' => (int) $fb['helpful'] === 1 ? 'success' : 'warning', 'label' => (int) $fb['helpful'] === 1 ? $t('kb.helpful_yes') : $t('kb.helpful_no')]) ?><?= $fb['comment'] !== null ? ' ' . $e((string) $fb['comment']) : '' ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($a['linked_questions'] !== []): ?>
    <h3 class="text-md"><?= $e($t('kb.linked_questions')) ?></h3>
    <ul class="plain-list stack-sm"><?php foreach ($a['linked_questions'] as $lq): ?><li><?= $e($lq['asker_name'] . ': ' . $lq['text']) ?> · <?= $e($t('kb.question_status.' . $lq['status'])) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </section>
</div>
<section class="card stack-sm" aria-labelledby="kb-rv">
  <h2 id="kb-rv" class="card__title"><?= $e($t('kb.revisions')) ?></h2>
  <ul class="plain-list stack-sm">
  <?php foreach ($a['revisions'] as $r): ?>
    <li><?= $e('v' . $r['version']) ?> <?= $ui('badge', ['tone' => $rtone[$r['status']] ?? 'neutral', 'label' => $t('kb.status.' . $r['status'])]) ?> · <?= $e($t('kb.written_by', ['name' => (string) $r['author_name']])) ?><?= $r['reviewer_name'] !== null ? ' · ' . $e($t('kb.reviewed_by', ['name' => (string) $r['reviewer_name']])) : '' ?>
      <span class="text-sm text-muted"><?= $e($date((string) ($r['decided_at'] ?? $r['created_at']))) ?></span><?= $r['change_reason'] !== null ? ' — ' . $e((string) $r['change_reason']) : '' ?></li>
  <?php endforeach; ?>
  </ul>
</section>
