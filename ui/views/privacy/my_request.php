<?php
/**
 * One of my data-subject requests: status, due day and the answer.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $req
 */
include __DIR__ . '/_status.php';
?>
<?= $ui('page-header', ['title' => (string) $req['request_no'], 'subtitle' => $t('privacy.type.' . $req['request_type']), 'crumbs' => [[$t('privacy.my_title'), $url('/me/data')], [(string) $req['request_no'], null]]]) ?>
<section class="card stack-sm" aria-labelledby="pr-d">
  <h2 id="pr-d" class="card__title"><?= $e($t('privacy.details')) ?> <?= $dsrBadge((string) $req['status']) ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('privacy.field.details')) ?></dt><dd class="pre-line"><?= $e((string) $req['details']) ?></dd></div>
    <div><dt><?= $e($t('privacy.received_at')) ?></dt><dd><?= $e($date((string) $req['created_at'])) ?></dd></div>
    <div><dt><?= $e($t('privacy.due_on')) ?></dt><dd><?= $e($req['due_on'] !== null ? $date((string) $req['due_on'], false) : $t('privacy.due_unset')) ?></dd></div>
    <?php if ($req['answer'] !== null) :
        ?><div><dt><?= $e($t('privacy.answer')) ?></dt><dd class="pre-line"><?= $e((string) $req['answer']) ?></dd></div><?php
    endif; ?>
  </dl>
</section>
