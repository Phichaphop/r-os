<?php
/**
 * Verify a file: is this exactly a signed (or original) document of a request I may see? (SHA-256 match)
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed>|null $result @var bool $checked @var string|null $error
 */
?>
<?= $ui('page-header', ['title' => $t('esign.verify_title'), 'subtitle' => $t('esign.verify_subtitle'), 'crumbs' => [[$t('esign.title'), $url('/esign')], [$t('esign.verify_title'), null]]]) ?>
<section class="card stack" aria-labelledby="ev-f">
  <h2 id="ev-f" class="card__title"><?= $e($t('esign.verify_pick')) ?></h2>
  <form method="post" action="<?= $e($url('/esign/verify')) ?>" enctype="multipart/form-data" class="stack-sm" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?= $ui('field', ['name' => 'file', 'type' => 'file', 'label' => $t('esign.field.file'), 'required' => true, 'accept' => 'application/pdf,.pdf', 'help' => $t('esign.verify_help'), 'error' => $error]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('esign.verify'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'shield-check']) ?></div>
  </form>
</section>
<?php if ($checked): ?>
  <?php if ($result === null): ?>
    <?= $ui('alert', ['tone' => 'warning', 'text' => $t('esign.verify_none')]) ?>
  <?php else: $q = $result['request']; ?>
    <section class="card stack-sm" aria-labelledby="ev-r">
      <h2 id="ev-r" class="card__title"><?= $ui('icon', ['name' => 'circle-check']) ?> <?= $e($t($result['match'] === 'signed' ? 'esign.verify_signed' : 'esign.verify_source')) ?></h2>
      <dl class="facts">
        <div><dt><?= $e($t('esign.field.title')) ?></dt><dd><a href="<?= $e($url('/esign/r/' . $q['public_id'])) ?>"><?= $e(($q['request_no'] ?? '') . ' · ' . $q['title']) ?></a></dd></div>
        <div><dt><?= $e($t('esign.col.status')) ?></dt><dd><?= $e($t('esign.status.' . $q['status'])) ?></dd></div>
        <?php if ($q['completed_at'] !== null): ?><div><dt><?= $e($t('esign.completed_at')) ?></dt><dd><?= $e($date((string) $q['completed_at'])) ?></dd></div><?php endif; ?>
      </dl>
      <?php if ($q['status'] === 'voided'): ?><?= $ui('alert', ['tone' => 'warning', 'text' => $t('esign.verify_voided')]) ?><?php endif; ?>
    </section>
  <?php endif; ?>
<?php endif; ?>
