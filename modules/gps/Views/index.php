<?php
/**
 * Check in / out. @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $asset @var array<string, mixed> $v
 * @var array{me: array<string, mixed>|null, policy: array<string, mixed>|null, eligible: bool, needs_notice: bool, points: int} $state
 * @var list<array<string, mixed>> $punches @var int $toReview @var bool $canAdmin @var bool $canReport
 */
$loc = (string) ($v['locale'] ?? 'th');
include __DIR__ . '/_helpers.php';
$me = $state['me'];
$policy = $state['policy'];
$actions = ($toReview > 0 ? $ui('button', ['label' => $t('gps.review_n', ['n' => (string) $toReview]), 'href' => $url('/gps/review'), 'variant' => 'primary', 'icon' => 'checks']) : '')
    . ($canReport ? $ui('button', ['label' => $t('gps.report_title'), 'href' => $url('/gps/report'), 'variant' => 'secondary', 'icon' => 'report-money']) : '')
    . ($canAdmin ? $ui('button', ['label' => $t('gps.nav_admin'), 'href' => $url('/gps/admin'), 'variant' => 'secondary', 'icon' => 'settings']) : '');
?>
<?= $ui('page-header', ['title' => $t('gps.title'), 'subtitle' => $t('gps.subtitle'), 'actions' => $actions]) ?>
<?php if ($me === null || $me['job'] === null): ?>
  <?= $ui('alert', ['tone' => 'info', 'title' => $t('gps.no_employee_title'), 'text' => $t('gps.no_employee_text')]) ?>
<?php elseif ($policy === null): ?>
  <?= $ui('alert', ['tone' => 'info', 'title' => $t('gps.off_title'), 'text' => $t('gps.off_text')]) ?>
<?php elseif (!$state['eligible']): ?>
  <?= $ui('alert', ['tone' => 'info', 'title' => $t('gps.not_group_title'), 'text' => $t('gps.not_group_text')]) ?>
<?php elseif ($state['needs_notice']): ?>
<section class="card stack" aria-labelledby="nt-h">
  <h2 id="nt-h" class="card__title"><?= $ui('icon', ['name' => 'shield-check']) ?> <?= $e($t('gps.notice_title')) ?></h2>
  <p class="gps-notice"><?= nl2br($e((string) $policy['notice'])) ?></p>
  <ul class="text-sm text-muted stack-sm">
    <li><?= $e($t('gps.notice_fact_press')) ?></li>
    <li><?= $e($t('gps.notice_fact_who')) ?></li>
    <li><?= $e($t('gps.notice_fact_review')) ?></li>
  </ul>
  <form method="post" action="<?= $e($url('/gps/notice')) ?>">
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?= $ui('button', ['label' => $t('gps.notice_ack'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?>
  </form>
</section>
<?php else: ?>
<section class="card stack gps-punch" aria-labelledby="pc-h">
  <h2 id="pc-h" class="card__title"><?= $e($t('gps.punch_now')) ?></h2>
  <?php if ($state['points'] === 0): ?><?= $ui('alert', ['tone' => 'warning', 'text' => $t('gps.no_points')]) ?><?php endif; ?>
  <noscript><?= $ui('alert', ['tone' => 'warning', 'text' => $t('gps.needs_js')]) ?></noscript>
  <form method="post" action="<?= $e($url('/gps/punch')) ?>" class="stack" data-gps-form data-offline-hours="<?= (int) ($policy['offline_hours'] ?? 0) ?>" novalidate
        data-msg-locating="<?= $e($t('gps.js.locating')) ?>" data-msg-denied="<?= $e($t('gps.js.denied')) ?>" data-msg-unavailable="<?= $e($t('gps.js.unavailable')) ?>"
        data-msg-timeout="<?= $e($t('gps.js.timeout')) ?>" data-msg-unsupported="<?= $e($t('gps.js.unsupported')) ?>" data-msg-queued="<?= $e($t('gps.js.queued')) ?>"
        data-msg-offline-off="<?= $e($t('gps.error.offline_off')) ?>" data-msg-synced="<?= $e($t('gps.js.synced')) ?>" data-msg-pending="<?= $e($t('gps.js.pending')) ?>">
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <input type="hidden" name="direction" value="">
    <input type="hidden" name="lat" value="">
    <input type="hidden" name="lng" value="">
    <input type="hidden" name="accuracy" value="">
    <input type="hidden" name="captured" value="">
    <input type="hidden" name="event" value="">
    <input type="hidden" name="offline" value="0">
    <?= $ui('field', ['name' => 'note', 'id' => 'gp-note', 'label' => $t('gps.field.note'), 'help' => $t('gps.note_help')]) ?>
    <div class="gps-punch__buttons">
      <button type="submit" class="btn btn--primary btn--lg" data-direction="in" disabled><?= $ui('icon', ['name' => 'map-pin']) ?><span><?= $e($t('gps.check_in')) ?></span></button>
      <button type="submit" class="btn btn--secondary btn--lg" data-direction="out" disabled><?= $ui('icon', ['name' => 'logout']) ?><span><?= $e($t('gps.check_out')) ?></span></button>
    </div>
    <p class="gps-punch__status text-sm" data-gps-status role="status" aria-live="polite"></p>
    <p class="text-sm text-muted"><?= $ui('icon', ['name' => 'lock', 'size' => 'sm']) ?> <?= $e($t('gps.privacy_line')) ?></p>
  </form>
  <details><summary><?= $e($t('gps.notice_title')) ?></summary><p class="gps-notice"><?= nl2br($e((string) $policy['notice'])) ?></p></details>
</section>
<script src="<?= $e($asset('ui/js/gps.js')) ?>" defer></script>
<?php endif; ?>

<?php if ($me !== null): ?>
<section class="card stack-sm" aria-labelledby="ls-h">
  <h2 id="ls-h" class="card__title"><?= $e($t('gps.mine')) ?></h2>
  <?php if ($punches === []): ?><p class="text-sm text-muted"><?= $e($t('gps.none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="ls-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('gps.field.when')) ?></th><th scope="col"><?= $e($t('gps.field.direction')) ?></th><th scope="col"><?= $e($t('gps.field.status')) ?></th>
        <th scope="col"><?= $e($t('gps.field.point')) ?></th><th scope="col"><?= $e($t('gps.field.flags')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($punches as $p): ?>
        <tr><td data-label="<?= $e($t('gps.field.when')) ?>"><a href="<?= $e($url('/gps/p/' . $p['public_id'])) ?>"><?= $e($gpsWhen($p)) ?></a></td>
          <td data-label="<?= $e($t('gps.field.direction')) ?>"><?= $e($t('gps.dir.' . $p['direction'])) ?></td>
          <td data-label="<?= $e($t('gps.field.status')) ?>"><?= $gpsStatus((string) $p['status']) ?></td>
          <td data-label="<?= $e($t('gps.field.point')) ?>"><?= $e($p['point_name'] !== null ? $t('gps.point_distance', ['point' => (string) $p['point_name'], 'm' => (string) (int) $p['distance_m']]) : '—') ?></td>
          <td data-label="<?= $e($t('gps.field.flags')) ?>"><?= $e($gpsFlags((string) $p['flags'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>
