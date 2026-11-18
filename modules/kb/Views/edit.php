<?php
/**
 * New article (first revision as a draft) — optionally answering an employee's question.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var array<string, string> $companies @var string $lePid
 * @var list<array<string, mixed>> $categories @var array<string, mixed>|null $question @var array<string, string> $errors @var array<string, mixed> $old @var string $here
 * @var bool $canAuthor @var bool $canReview
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
?>
<?= $ui('page-header', ['title' => $t('kb.new_article'), 'subtitle' => $t('kb.new_article_help'), 'crumbs' => [[$t('kb.manage_title'), $url('/kb/manage')], [$t('kb.new_article'), null]]]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if ($categories === []): ?><?= $ui('alert', ['tone' => 'warning', 'title' => $t('kb.no_categories'), 'html' => $canReview ? '<a href="' . $e($url('/kb/settings')) . '">' . $e($t('kb.tab.settings')) . '</a>' : '']) ?><?php endif; ?>
<?php if ($question !== null): ?><?= $ui('alert', ['tone' => 'info', 'title' => $t('kb.answering'), 'html' => '<p class="kb-q">' . $e((string) $question['text']) . '</p>']) ?><?php endif; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<form method="post" action="<?= $e($url('/kb/manage/new')) ?>" class="card stack-sm" novalidate><?= $csrf ?><input type="hidden" name="le" value="<?= $e($lePid) ?>">
  <?php if ($question !== null): ?><input type="hidden" name="question" value="<?= $e((string) $question['public_id']) ?>"><?php endif; ?>
  <?php $fill = $old; $ferr = $errors; $needReason = false; include __DIR__ . '/_fields.php'; ?>
  <div class="row"><?= $ui('button', ['label' => $t('kb.save_draft'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
</form>
