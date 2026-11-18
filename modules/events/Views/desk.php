<?php
/**
 * Check-in desk: type an employee code, pick a name, scan the e-ID QR or tap an RFID card (the reader types the card ID).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v @var array<string, mixed> $ev
 * @var array<string, mixed>|null $result @var bool $canManage @var string $here
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$pid = (string) $ev['public_id'];
$c = $ev['counts'];
$res = $result['result'] ?? null;
$tone = ['ok' => 'success', 'walk_in' => 'info', 'already' => 'neutral'][$res] ?? 'warning';
?>
<?= $ui('page-header', ['title' => $t('events.desk_title'), 'subtitle' => (string) $ev['title'], 'crumbs' => [[$t('events.manage_title'), $url('/events/manage')], [(string) $ev['title'], $url('/events/m/' . $pid)], [$t('events.desk'), null]]]) ?>
<div class="grid grid--3 ev-counts" aria-live="polite">
  <section class="card stack-sm"><h2 class="card__title"><?= $e($t('events.came')) ?></h2><p class="kz-big"><?= (int) $c['came'] ?> / <?= (int) $c['expected'] ?></p></section>
  <section class="card stack-sm"><h2 class="card__title"><?= $e($t('events.walk_ins')) ?></h2><p class="kz-big"><?= (int) $c['walk_in'] ?></p></section>
  <section class="card stack-sm"><h2 class="card__title"><?= $e($t('events.not_yet')) ?></h2><p class="kz-big"><?= (int) $c['registered'] ?></p></section>
</div>
<?php if (!$ev['open']): ?>
  <?= $ui('alert', ['tone' => 'warning', 'title' => $t('events.desk_closed')]) ?>
<?php else: ?>
<?php if ($res !== null): ?>
  <div role="status"><?= $ui('alert', ['tone' => $tone, 'title' => $t('events.checkin.' . $res, ['name' => (string) ($result['name'] ?? '')])]) ?></div>
<?php endif; ?>
<?php if ($res === 'unknown_card'): ?>
<form method="post" action="<?= $e($url('/events/m/' . $pid . '/desk')) ?>" class="card row fm-filter" novalidate><?= $csrf ?>
  <input type="hidden" name="who" value="<?= $e((string) $result['card']) ?>">
  <?= $ui('field', ['name' => 'pair', 'label' => $t('events.field.pair'), 'required' => true, 'autofocus' => true, 'help' => $t('events.pair_help')]) ?>
  <?= $ui('button', ['label' => $t('events.pair_and_check_in'), 'type' => 'submit', 'variant' => 'primary']) ?>
</form>
<?php endif; ?>
<form method="post" action="<?= $e($url('/events/m/' . $pid . '/desk')) ?>" class="card row fm-filter ev-desk" autocomplete="off" novalidate><?= $csrf ?>
  <div class="field ev-desk__field">
    <label class="field__label" for="f-who"><?= $e($t('events.field.who')) ?></label>
    <input class="input" id="f-who" name="who" list="ev-people" autocomplete="off" aria-describedby="f-who-help"<?= $res !== 'unknown_card' ? ' autofocus' : '' ?>>
    <p class="field__help" id="f-who-help"><?= $e($t('events.who_help')) ?></p>
    <datalist id="ev-people"><?php foreach ($ev['people'] as $p): if ($p['status'] === 'cancelled') { continue; } ?><option value="<?= $e($p['employee_code'] . ' — ' . $p['name_th']) ?>"></option><?php endforeach; ?></datalist>
  </div>
  <?= $ui('button', ['label' => $t('events.check_in'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'checks']) ?>
</form>
<?php endif; ?>
