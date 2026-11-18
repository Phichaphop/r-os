<?php
/**
 * My personal data: what each module keeps about me (counts), the export, and my requests to the privacy officer.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var list<array{module: string, label: string, rows: int}> $coverage @var list<array<string, mixed>> $requests
 * @var array<string, string> $errors @var array<string, mixed> $old
 */
include __DIR__ . '/_status.php';
$types = [];
foreach (['access', 'correction', 'deletion', 'objection', 'other'] as $k) {
    $types[$k] = $t('privacy.type.' . $k);
}
?>
<?= $ui('page-header', ['title' => $t('privacy.my_title'), 'subtitle' => $t('privacy.my_subtitle'), 'crumbs' => [[$t('profile.title'), $url('/me')], [$t('privacy.my_title'), null]]]) ?>
<section class="card stack-sm" aria-labelledby="pm-c">
  <h2 id="pm-c" class="card__title"><?= $e($t('privacy.what_we_keep')) ?></h2>
  <?php if ($coverage === []) :
        ?><p class="text-sm text-muted"><?= $e($t('privacy.nothing')) ?></p><?php
  else : ?>
  <ul class="plain-list stack-sm">
        <?php foreach ($coverage as $c) :
            ?><li><?= $e($t($c['label'])) ?> · <?= $e($t('privacy.rows_n', ['n' => (string) $c['rows']])) ?></li><?php
        endforeach; ?>
  </ul>
  <?php endif; ?>
  <p class="text-sm text-muted"><?= $e($t('privacy.coverage_note')) ?></p>
  <div class="row"><?= $ui('button', ['label' => $t('privacy.export'), 'href' => $url('/me/data/export'), 'variant' => 'primary', 'icon' => 'download']) ?></div>
  <p class="text-sm text-muted"><?= $e($t('privacy.export_help')) ?></p>
</section>
<section class="card stack" aria-labelledby="pm-r">
  <h2 id="pm-r" class="card__title"><?= $e($t('privacy.new_request')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('privacy.request_help')) ?></p>
  <form method="post" action="<?= $e($url('/me/privacy')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?= $ui('field', ['name' => 'type', 'type' => 'select', 'label' => $t('privacy.field.type'), 'value' => is_scalar($old['type'] ?? null) ? (string) $old['type'] : 'access', 'options' => $types, 'error' => $errors['type'] ?? null]) ?>
    <?= $ui('field', ['name' => 'details', 'type' => 'textarea', 'label' => $t('privacy.field.details'), 'required' => true, 'value' => is_scalar($old['details'] ?? null) ? (string) $old['details'] : '', 'help' => $t('privacy.details_help'), 'error' => $errors['details'] ?? null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('privacy.submit'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'send']) ?></div>
  </form>
</section>
<?php if ($requests !== []) : ?>
<section class="card stack-sm" aria-labelledby="pm-l">
  <h2 id="pm-l" class="card__title"><?= $e($t('privacy.my_requests')) ?></h2>
  <ul class="plain-list stack-sm">
    <?php foreach ($requests as $q) :
        ?><li><a href="<?= $e($url('/me/privacy/' . $q['public_id'])) ?>"><?= $e($q['request_no'] . ' · ' . $t('privacy.type.' . $q['request_type'])) ?></a> <?= $dsrBadge((string) $q['status']) ?>
      <span class="d-block text-sm text-muted"><?= $e($date((string) $q['created_at'])) ?></span></li><?php
    endforeach; ?>
  </ul>
</section>
<?php endif; ?>
