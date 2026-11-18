<?php
/**
 * Moderators: reports to handle (uphold = hide with the reason, or dismiss), handled ones, and the board's categories.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v @var array<string, string> $companies @var string $lePid
 * @var array{open: list<array<string, mixed>>, done: list<array<string, mixed>>} $reports @var list<array<string, mixed>> $categories
 * @var array<string, string> $errors @var array<string, mixed> $old @var bool $canModerate @var string $here
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$editing = (string) ($old['_pid'] ?? '');
?>
<?= $ui('page-header', ['title' => $t('forum.moderate_title'), 'subtitle' => $t('forum.moderate_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('forum.pick_company')) ?>"><?php foreach ($companies as $cp => $code): ?><a href="<?= $e($url('/forum/moderate?le=' . rawurlencode($cp))) ?>"<?= $cp === $lePid ? ' aria-current="page"' : '' ?>><?= $e($code) ?></a><?php endforeach; ?></nav>
<?php endif; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<section class="card stack-sm" aria-labelledby="fm-rq">
  <h2 id="fm-rq" class="card__title"><?= $e($t('forum.reports_open', ['n' => (string) count($reports['open'])])) ?></h2>
  <?php if ($reports['open'] === []): ?><p class="text-sm text-muted"><?= $e($t('forum.no_reports')) ?></p><?php endif; ?>
  <ul class="plain-list stack">
  <?php foreach ($reports['open'] as $rp): $rid = (string) $rp['public_id']; ?>
    <li class="stack-sm fm-report">
      <p><strong><?= $e($t('forum.target.' . $rp['target'])) ?></strong> · <a href="<?= $e($url('/forum/t/' . $rp['thread_pid'] . ($rp['post_pid'] !== null ? '#p-' . $rp['post_pid'] : ''))) ?>"><?= $e((string) $rp['thread_title']) ?></a> · <?= $e($t('forum.by', ['name' => (string) $rp['author']])) ?></p>
      <blockquote class="fm-quote"><?= $e(mb_substr((string) $rp['body'], 0, 400)) ?></blockquote>
      <p class="text-sm"><?= $e($t('forum.reported_by', ['name' => (string) $rp['reporter'], 'reason' => (string) $rp['reason']])) ?> <span class="text-muted"><?= $e($date((string) $rp['created_at'])) ?></span></p>
      <form method="post" class="row fm-filter" novalidate><?= $csrf ?>
        <?= $ui('field', ['name' => 'note', 'id' => 'f-note-' . $rid, 'label' => $t('forum.field.decision_note'), 'required' => true, 'help' => $t('forum.note_help')]) ?>
        <?= $ui('button', ['label' => $t('forum.uphold'), 'type' => 'submit', 'variant' => 'danger', 'size' => 'sm', 'attrs' => ['formaction' => $url('/forum/moderate/r/' . $rid . '/uphold')]]) ?>
        <?= $ui('button', ['label' => $t('forum.dismiss'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm', 'attrs' => ['formaction' => $url('/forum/moderate/r/' . $rid . '/dismiss')]]) ?>
      </form>
    </li>
  <?php endforeach; ?>
  </ul>
</section>
<?php if ($reports['done'] !== []): ?>
<section class="card stack-sm" aria-labelledby="fm-rd">
  <h2 id="fm-rd" class="card__title"><?= $e($t('forum.reports_done')) ?></h2>
  <ul class="plain-list stack-sm"><?php foreach (array_slice($reports['done'], 0, 30) as $rp): ?><li class="text-sm"><?= $ui('badge', ['tone' => $rp['status'] === 'upheld' ? 'danger' : 'neutral', 'label' => $t('forum.report_status.' . $rp['status'])]) ?> <?= $e($rp['thread_title'] . ' · ' . $rp['reason'] . ' → ' . $rp['note'] . ' (' . $rp['handler'] . ')') ?></li><?php endforeach; ?></ul>
</section>
<?php endif; ?>
<section class="card stack-sm" aria-labelledby="fm-c">
  <h2 id="fm-c" class="card__title"><?= $e($t('forum.categories')) ?></h2>
  <?php if ($categories === []): ?><p class="text-sm"><?= $e($t('forum.no_categories')) ?></p><?php endif; ?>
  <ul class="plain-list stack-sm">
  <?php foreach ($categories as $c): $cp = (string) $c['public_id']; $mine = $editing === $cp; ?>
    <li><details<?= $mine ? ' open' : '' ?>><summary><?= $e((string) $c['name']) ?> <span class="text-sm text-muted">(<?= (int) $c['threads'] ?>)</span><?= (int) $c['active'] === 1 ? '' : ' ' . $ui('badge', ['tone' => 'neutral', 'label' => $t('forum.inactive')]) ?></summary>
      <form method="post" action="<?= $e($url('/forum/moderate/category/' . $cp)) ?>" class="row fm-filter" novalidate><?= $csrf ?><input type="hidden" name="le" value="<?= $e($lePid) ?>">
        <?= $ui('field', ['name' => 'name', 'id' => 'f-name-' . $cp, 'label' => $t('forum.field.category_name'), 'required' => true, 'value' => $mine ? (string) ($old['name'] ?? '') : (string) $c['name'], 'error' => $mine ? ($errors['name'] ?? null) : null]) ?>
        <?= $ui('field', ['name' => 'sort', 'id' => 'f-sort-' . $cp, 'type' => 'number', 'label' => $t('forum.field.sort'), 'value' => (string) $c['sort']]) ?>
        <label class="check"><input type="checkbox" name="active" value="1"<?= (int) $c['active'] === 1 ? ' checked' : '' ?>> <?= $e($t('forum.active')) ?></label>
        <?= $ui('button', ['label' => $t('forum.save'), 'type' => 'submit', 'variant' => 'secondary', 'attrs' => ['aria-label' => $t('forum.save_category', ['name' => (string) $c['name']])]]) ?>
      </form>
    </details></li>
  <?php endforeach; ?>
  </ul>
  <form method="post" action="<?= $e($url('/forum/moderate/category')) ?>" class="row fm-filter" novalidate><?= $csrf ?><input type="hidden" name="le" value="<?= $e($lePid) ?>">
    <?= $ui('field', ['name' => 'name', 'label' => $t('forum.field.category_name'), 'required' => true, 'value' => $editing === '' ? (string) ($old['name'] ?? '') : '', 'placeholder' => $t('forum.category_example'), 'error' => $editing === '' ? ($errors['name'] ?? null) : null]) ?>
    <?= $ui('field', ['name' => 'sort', 'type' => 'number', 'label' => $t('forum.field.sort'), 'value' => '0']) ?>
    <?= $ui('button', ['label' => $t('forum.add_category'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?>
  </form>
</section>
