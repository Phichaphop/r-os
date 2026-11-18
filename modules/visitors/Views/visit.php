<?php
/**
 * One visit: details, the invitation link + QR for the host, gate actions (check-in with notice + blacklist, check-out,
 * deny), cancel (host), history.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var callable $partial @var array<string, mixed> $v
 * @var array<string, mixed> $visit @var array<string, string> $errors @var bool $isHost @var string|null $link @var string|null $qr
 * @var bool $gate @var bool $manage @var string|null $notice
 */
$vv = $visit;
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$pid = (string) $vv['public_id'];
$act = static fn (string $a): string => '/visitors/v/' . $pid . '/' . $a;
?>
<?= $ui('page-header', ['title' => $t('visitors.visit.title', ['ref' => (string) $vv['ref']]), 'subtitle' => (string) ($vv['visitor_name'] ?? $t('visitors.purged')),
    'crumbs' => [[$gate ? $t('visitors.gate.title') : $t('visitors.mine.title'), $url($gate ? '/visitors/gate?le=' . (int) $vv['legal_entity_id'] : '/visitors')], [(string) $vv['ref'], null]]]) ?>
<div class="row"><?= $partial('@visitors/_status', ['s' => (string) $vv['status']]) ?> <?= $ui('badge', ['tone' => 'neutral', 'label' => $t('visitors.kind.' . $vv['kind'])]) ?></div>
<?php if ($vv['blacklisted'] !== null && $vv['status'] === 'invited' && ($gate || $manage)): ?>
  <?= $ui('alert', ['tone' => 'danger', 'title' => $t('visitors.blacklist_hit'), 'text' => (string) $vv['blacklisted']['reason']]) ?>
<?php endif; ?>
<section class="card stack-sm" aria-labelledby="vd-h">
  <h2 id="vd-h" class="card__title"><?= $e($t('visitors.visit.details')) ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('visitors.field.visitor_name')) ?></dt><dd><?= $e((string) ($vv['visitor_name'] ?? $t('visitors.purged'))) ?></dd></div>
    <div><dt><?= $e($t('visitors.field.visitor_company')) ?></dt><dd><?= $e((string) ($vv['visitor_company'] ?? '—')) ?></dd></div>
    <div><dt><?= $e($t('visitors.field.purpose')) ?></dt><dd><?= $e((string) ($vv['purpose'] ?? '—')) ?></dd></div>
    <div><dt><?= $e($t('visitors.field.host')) ?></dt><dd><?= $e((string) $vv['host_name']) ?></dd></div>
    <div><dt><?= $e($t('visitors.field.window')) ?></dt><dd><?= $e($date((string) $vv['expected_from'])) ?> – <?= $e($date((string) $vv['expected_to'])) ?></dd></div>
    <div><dt><?= $e($t('visitors.field.people')) ?></dt><dd><?= (int) $vv['people'] ?></dd></div>
    <?php if ($vv['site_code'] !== null): ?><div><dt><?= $e($t('visitors.field.site')) ?></dt><dd><?= $e((string) $vv['site_code']) ?></dd></div><?php endif; ?>
    <?php if ($vv['vehicle_plate'] !== null): ?><div><dt><?= $e($t('visitors.field.vehicle_plate')) ?></dt><dd><?= $e((string) $vv['vehicle_plate']) ?></dd></div><?php endif; ?>
    <?php if ($vv['items'] !== null): ?><div><dt><?= $e($t('visitors.field.items')) ?></dt><dd><?= $e((string) $vv['items']) ?></dd></div><?php endif; ?>
    <?php if ($vv['checked_in_at'] !== null): ?><div><dt><?= $e($t('visitors.field.checked_in_at')) ?></dt><dd><?= $e($date((string) $vv['checked_in_at'])) ?><?= $vv['badge_no'] !== null ? ' · ' . $e($t('visitors.field.badge_no')) . ' ' . $e((string) $vv['badge_no']) : '' ?></dd></div><?php endif; ?>
    <?php if ($vv['checked_out_at'] !== null): ?><div><dt><?= $e($t('visitors.field.checked_out_at')) ?></dt><dd><?= $e($date((string) $vv['checked_out_at'])) ?></dd></div><?php endif; ?>
    <?php if ($vv['closed_reason'] !== null): ?><div><dt><?= $e($t('visitors.field.reason')) ?></dt><dd><?= $e((string) $vv['closed_reason']) ?></dd></div><?php endif; ?>
  </dl>
</section>

<?php if ($link !== null): ?>
<section class="card stack-sm" aria-labelledby="vl-h">
  <h2 id="vl-h" class="card__title"><?= $e($t('visitors.link.title')) ?></h2>
  <p class="text-sm"><?= $e($t('visitors.link.help')) ?></p>
  <label class="field__label" for="vl-link"><?= $e($t('visitors.link.label')) ?></label>
  <input class="input" id="vl-link" readonly value="<?= $e($link) ?>">
  <div class="visit-qr"><?= $qr ?></div>
  <p class="text-sm text-muted"><?= $e($t('visitors.link.note')) ?></p>
</section>
<?php endif; ?>

<?php if ($gate && $vv['status'] === 'invited'): ?>
<section class="card stack" aria-labelledby="vc-h">
  <h2 id="vc-h" class="card__title"><?= $e($t('visitors.checkin.title')) ?></h2>
  <p class="text-sm"><?= $e($t('visitors.checkin.help')) ?></p>
  <form method="post" action="<?= $e($url($act('checkin'))) ?>" class="stack" novalidate>
    <?= $csrf ?>
    <label class="check"><input type="checkbox" name="notice_given" value="1"<?= isset($errors['notice_given']) ? ' aria-invalid="true" aria-describedby="vc-notice-err"' : '' ?>> <?= $e($t('visitors.checkin.notice_given')) ?></label>
    <?php if (isset($errors['notice_given'])): ?><p class="field__error" id="vc-notice-err"><?= $e($errors['notice_given']) ?></p><?php endif; ?>
    <?php if ($notice !== null): ?><p class="text-sm"><a href="<?= $e($url($notice)) ?>" target="_blank" rel="noopener"><?= $e($t('visitors.notice_link')) ?><span class="sr-only"> (<?= $e($t('home.new_tab')) ?>)</span></a></p>
    <?php else: ?><?= $ui('alert', ['tone' => 'warning', 'text' => $t('visitors.no_notice')]) ?><?php endif; ?>
    <?= $ui('field', ['name' => 'badge_no', 'id' => 'vc-badge', 'label' => $t('visitors.field.badge_no'), 'autocomplete' => 'off']) ?>
    <?php if ($vv['blacklisted'] !== null && $manage): ?>
      <?= $ui('field', ['name' => 'override_note', 'id' => 'vc-override', 'label' => $t('visitors.field.override_note'), 'required' => true, 'help' => $t('visitors.override_help'), 'error' => $errors['override_note'] ?? null]) ?>
    <?php endif; ?>
    <div class="row"><?= $ui('button', ['label' => $t('visitors.checkin.submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'login', 'disabled' => $vv['blacklisted'] !== null && !$manage]) ?></div>
    <?php if ($vv['blacklisted'] !== null && !$manage): ?><p class="text-sm"><?= $e($t('visitors.blacklist_call')) ?></p><?php endif; ?>
  </form>
  <form method="post" action="<?= $e($url($act('deny'))) ?>" class="row" novalidate>
    <?= $csrf ?>
    <label class="sr-only" for="vc-deny"><?= $e($t('visitors.deny_reason')) ?></label>
    <input class="input" id="vc-deny" name="reason" maxlength="500" placeholder="<?= $e($t('visitors.deny_reason')) ?>"<?= isset($errors['reason']) ? ' aria-invalid="true"' : '' ?>>
    <?= $ui('button', ['label' => $t('visitors.deny'), 'type' => 'submit', 'variant' => 'danger', 'icon' => 'ban']) ?>
  </form>
</section>
<?php endif; ?>

<?php if ($gate && $vv['status'] === 'checked_in'): ?>
<section class="card stack-sm" aria-labelledby="vo-h">
  <h2 id="vo-h" class="card__title"><?= $e($t('visitors.checkout.title')) ?></h2>
  <form method="post" action="<?= $e($url($act('checkout'))) ?>" class="stack-sm" novalidate>
    <?= $csrf ?>
    <?php if ($vv['badge_no'] !== null): ?><label class="check"><input type="checkbox" name="badge_returned" value="1" checked> <?= $e($t('visitors.checkout.badge_returned', ['no' => (string) $vv['badge_no']])) ?></label><?php endif; ?>
    <div class="row"><?= $ui('button', ['label' => $t('visitors.checkout.submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'logout']) ?></div>
  </form>
</section>
<?php endif; ?>

<?php if ($isHost && !$gate && $vv['status'] === 'invited'): ?>
<section class="card stack-sm" aria-labelledby="vx-h">
  <h2 id="vx-h" class="card__title"><?= $e($t('visitors.cancel.title')) ?></h2>
  <form method="post" action="<?= $e($url($act('cancel'))) ?>" class="row" novalidate>
    <?= $csrf ?>
    <label class="sr-only" for="vx-reason"><?= $e($t('visitors.cancel.reason')) ?></label>
    <input class="input" id="vx-reason" name="reason" maxlength="500" placeholder="<?= $e($t('visitors.cancel.reason')) ?>"<?= isset($errors['reason']) ? ' aria-invalid="true"' : '' ?>>
    <?= $ui('button', ['label' => $t('visitors.cancel.submit'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'x']) ?>
  </form>
  <?php if (isset($errors['reason'])): ?><p class="field__error"><?= $e($errors['reason']) ?></p><?php endif; ?>
</section>
<?php endif; ?>

<section class="card stack-sm" aria-labelledby="vh-h">
  <h2 id="vh-h" class="card__title"><?= $e($t('visitors.history')) ?></h2>
  <ul class="plain-list">
    <?php foreach ($vv['history'] as $h): ?><li><?= $e($date((string) $h['created_at'])) ?> · <?= $e($t('visitors.event.' . $h['action'])) ?> · <?= $e((string) ($h['actor'] ?? $t('visitors.system'))) ?><?= $h['note'] !== null ? ' — ' . $e((string) $h['note']) : '' ?></li><?php endforeach; ?>
  </ul>
</section>
