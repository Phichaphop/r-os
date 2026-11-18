<?php
/**
 * One event for an employee: details, places, join (with the choice) / cancel my place, waitlist position.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v @var array<string, mixed> $ev
 * @var array<string, string> $errors @var array<string, mixed> $old @var bool $canManage @var string $here
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$pid = (string) $ev['public_id'];
$eph = (string) $ev['phase'];
$mine = $ev['mine'];
$st = $mine['status'] ?? null;
$dt = static fn (string $x): string => $date(substr($x, 0, 10), false) . ' ' . substr($x, 11, 5);
?>
<?= $ui('page-header', ['title' => (string) $ev['title'], 'subtitle' => $dt((string) $ev['starts_at']) . ' – ' . $dt((string) $ev['ends_at']) . ' · ' . $ev['venue'],
    'crumbs' => [[$t('events.title'), $url('/events')], [(string) $ev['title'], null]],
    'actions' => $ev['is_manager'] ? $ui('button', ['label' => $t('events.manage_this'), 'href' => $url('/events/m/' . $pid), 'variant' => 'secondary', 'icon' => 'list-check']) : '']) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if ($ev['status'] === 'cancelled'): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('events.cancelled_note', ['reason' => (string) $ev['cancel_reason']])]) ?><?php endif; ?>
<div class="grid grid--2">
  <section class="card stack-sm" aria-labelledby="ev-d">
    <h2 id="ev-d" class="card__title"><?= $e($t('events.details')) ?> <?php include __DIR__ . '/_phase.php'; ?></h2>
    <dl class="facts">
      <?php if ($ev['kind'] !== null): ?><div><dt><?= $e($t('events.field.kind')) ?></dt><dd><?= $e((string) $ev['kind']) ?></dd></div><?php endif; ?>
      <div><dt><?= $e($t('events.field.venue')) ?></dt><dd><?= $e((string) $ev['venue']) ?></dd></div>
      <div><dt><?= $e($t('events.field.reg_until')) ?></dt><dd><?= $e($dt((string) $ev['reg_until'])) ?></dd></div>
      <div><dt><?= $e($t('events.places')) ?></dt><dd><?= $ev['quota'] === null ? $e($t('events.places_unlimited', ['n' => (string) $ev['taken']])) : $e($t('events.places_of', ['n' => (string) $ev['taken'], 'q' => (string) $ev['quota']])) ?><?= (int) $ev['waiting'] > 0 ? ' · ' . $e($t('events.waiting_n', ['n' => (string) $ev['waiting']])) : '' ?></dd></div>
      <?php if ($ev['cost_note'] !== null): ?><div><dt><?= $e($t('events.field.cost_note')) ?></dt><dd><?= $e((string) $ev['cost_note']) ?></dd></div><?php endif; ?>
    </dl>
    <?php if ($ev['description'] !== null): ?><div class="ev-text"><?= $e((string) $ev['description']) ?></div><?php endif; ?>
  </section>
  <section class="card stack-sm" aria-labelledby="ev-m">
    <h2 id="ev-m" class="card__title"><?= $e($t('events.my_place')) ?></h2>
    <?php if ($st === 'registered' || $st === 'waitlisted' || $st === 'checked_in'): ?>
      <p><?= $ui('badge', ['tone' => $st === 'waitlisted' ? 'warning' : 'success', 'label' => $t('events.reg_status.' . $st)]) ?><?= $ev['position'] !== null ? ' ' . $e($t('events.position', ['n' => (string) $ev['position']])) : '' ?><?= $mine['choice'] !== null ? ' · ' . $e($ev['option_label'] . ': ' . $mine['choice']) : '' ?></p>
      <?php if ($st !== 'checked_in' && in_array($eph, ['open', 'full', 'closed'], true)): ?>
      <form method="post" action="<?= $e($url('/events/e/' . $pid . '/leave')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('events.leave'), 'type' => 'submit', 'variant' => 'secondary']) ?></form>
      <?php endif; ?>
    <?php elseif (in_array($eph, ['open', 'full'], true) && $ev['can_join']): ?>
      <?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
      <?php if ($eph === 'full'): ?><p class="text-sm"><?= $e((int) $ev['waitlist'] === 1 ? $t('events.full_waitlist') : $t('events.error.full')) ?></p><?php endif; ?>
      <?php if ($eph === 'open' || (int) $ev['waitlist'] === 1): ?>
      <form method="post" action="<?= $e($url('/events/e/' . $pid . '/join')) ?>" class="stack-sm" novalidate><?= $csrf ?>
        <?php if ($ev['choices'] !== []): ?><?= $ui('field', ['name' => 'choice', 'type' => 'select', 'label' => (string) $ev['option_label'], 'required' => true, 'value' => (string) ($old['choice'] ?? ''), 'options' => ['' => $t('events.pick')] + array_combine($ev['choices'], $ev['choices']), 'error' => $errors['choice'] ?? null]) ?><?php endif; ?>
        <div class="row"><?= $ui('button', ['label' => $eph === 'full' ? $t('events.join_waitlist') : $t('events.join'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></div>
      </form>
      <?php endif; ?>
    <?php else: ?>
      <p class="text-sm text-muted"><?= $e($t('events.cannot_join_now')) ?></p>
    <?php endif; ?>
  </section>
</div>
