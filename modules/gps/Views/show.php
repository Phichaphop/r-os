<?php
/**
 * One check-in / check-out + review. @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, mixed> $p @var array<string, string> $errors
 */
$loc = (string) ($v['locale'] ?? 'th');
include __DIR__ . '/_helpers.php';
$name = (string) $p['employee_code'] . ' · ' . (string) $p['name_th'];
$canDecide = $p['access'] === 'reviewer' && $p['status'] === 'flagged';
?>
<?= $ui('page-header', ['title' => $t('gps.punch_title'), 'subtitle' => $name . ' — ' . $t('gps.dir.' . $p['direction']) . ' ' . $gpsWhen($p),
    'crumbs' => [[$t('gps.title'), $url('/gps')], [$t('gps.punch_title'), null]], 'actions' => $gpsStatus((string) $p['status'])]) ?>
<div class="grid grid--2">
<section class="card stack-sm" aria-labelledby="d-h">
  <h2 id="d-h" class="card__title"><?= $e($t('gps.details')) ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('gps.field.when')) ?></dt><dd><?= $e($gpsWhen($p)) ?><?= (int) $p['offline'] === 1 ? ' · ' . $e($t('gps.flag.offline')) : '' ?></dd></div>
    <div><dt><?= $e($t('gps.field.point')) ?></dt><dd><?= $e($p['point_name'] !== null ? (string) $p['point_name'] : $t('gps.flag.no_point')) ?></dd></div>
    <div><dt><?= $e($t('gps.field.distance')) ?></dt><dd><?= $e($p['distance_m'] !== null ? $t('gps.metres', ['m' => (string) (int) $p['distance_m']]) . ' · ' . $t((int) $p['inside'] === 1 ? 'gps.inside' : 'gps.outside_area') : '—') ?></dd></div>
    <div><dt><?= $e($t('gps.field.accuracy')) ?></dt><dd><?= $e($p['accuracy_m'] !== null ? '± ' . $t('gps.metres', ['m' => (string) (int) $p['accuracy_m']]) : ($p['anonymized_at'] !== null ? $t('gps.cleared') : '—')) ?></dd></div>
    <div><dt><?= $e($t('gps.field.flags')) ?></dt><dd><?= $e($gpsFlags((string) $p['flags'])) ?></dd></div>
    <?php if ($p['employee_note'] !== null): ?><div><dt><?= $e($t('gps.field.note')) ?></dt><dd><?= $e((string) $p['employee_note']) ?></dd></div><?php endif; ?>
    <?php if ($p['reviewed_at'] !== null): ?><div><dt><?= $e($t('gps.field.reviewed')) ?></dt><dd><?= $e((string) ($p['reviewer_name'] ?? '—')) ?><?= $p['review_note'] !== null ? ' — ' . $e((string) $p['review_note']) : '' ?></dd></div><?php endif; ?>
    <div><dt><?= $e($t('gps.field.attendance')) ?></dt><dd><?= $e($p['attendance_sent_at'] !== null ? $t('gps.sent_to_attendance') : $t('gps.not_sent')) ?></dd></div>
    <?php if ($p['show_location'] && $p['access'] === 'own'): ?><div><dt><?= $e($t('gps.field.coords')) ?></dt><dd><?= $e((string) $p['lat'] . ', ' . (string) $p['lng']) ?></dd></div><?php endif; ?>
  </dl>
</section>
<section class="card stack-sm" aria-labelledby="m-h">
  <h2 id="m-h" class="card__title"><?= $e($t('gps.map')) ?></h2>
  <?php if ($p['show_location']): ?>
    <?= $gpsMap($p) ?>
    <p class="text-sm text-muted"><?= $e($t('gps.map_help')) ?></p>
  <?php else: ?>
    <p class="text-sm text-muted"><?= $ui('icon', ['name' => 'lock', 'size' => 'sm']) ?> <?= $e($t($p['anonymized_at'] !== null ? 'gps.map_cleared' : 'gps.map_hidden')) ?></p>
  <?php endif; ?>
</section>
</div>
<?php if ($canDecide): ?>
<section class="card stack" aria-labelledby="r-h">
  <h2 id="r-h" class="card__title"><?= $e($t('gps.review')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('gps.review_help')) ?></p>
  <form method="post" action="<?= $e($url('/gps/p/' . $p['public_id'] . '/review')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?= $ui('field', ['name' => 'review_note', 'type' => 'textarea', 'label' => $t('gps.field.review_note'), 'help' => $t('gps.review_note_help'), 'error' => $errors['review_note'] ?? null]) ?>
    <div class="row">
      <button type="submit" name="action" value="approve" class="btn btn--primary"><?= $ui('icon', ['name' => 'circle-check']) ?><span><?= $e($t('gps.approve')) ?></span></button>
      <button type="submit" name="action" value="reject" class="btn btn--danger"><?= $ui('icon', ['name' => 'circle-x']) ?><span><?= $e($t('gps.reject')) ?></span></button>
    </div>
  </form>
</section>
<?php endif; ?>
