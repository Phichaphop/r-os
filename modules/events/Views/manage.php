<?php
/**
 * Organisers: one event — state, publish / cancel, check-in desk, report, edit (draft), the people.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v @var array<string, mixed> $ev @var array<string, mixed> $fill
 * @var array{sites: array<string, string>, departments: array<string, string>} $audOptions @var array<string, mixed> $audIn
 * @var array<string, string> $errors @var array<string, mixed> $old @var string $form @var bool $canManage @var string $here
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$pid = (string) $ev['public_id'];
$eph = (string) $ev['phase'];
$dt = static fn (string $x): string => $date(substr($x, 0, 10), false) . ' ' . substr($x, 11, 5);
$act = static fn (string $x): string => $url('/events/m/' . $pid . '/' . $x);
?>
<?= $ui('page-header', ['title' => (string) $ev['title'], 'subtitle' => $dt((string) $ev['starts_at']) . ' – ' . $dt((string) $ev['ends_at']) . ' · ' . $ev['venue'],
    'crumbs' => [[$t('events.manage_title'), $url('/events/manage')], [(string) $ev['title'], null]],
    'actions' => $ev['status'] !== 'draft' ? $ui('button', ['label' => $t('events.report'), 'href' => $act('report'), 'variant' => 'secondary', 'icon' => 'layout-dashboard']) . ' ' . $ui('button', ['label' => $t('events.desk'), 'href' => $act('desk'), 'variant' => 'primary', 'icon' => 'checks']) : '']) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<div class="grid grid--2">
  <section class="card stack-sm" aria-labelledby="ev-s">
    <h2 id="ev-s" class="card__title"><?= $e($t('events.col.status')) ?>: <?php include __DIR__ . '/_phase.php'; ?></h2>
    <dl class="facts">
      <div><dt><?= $e($t('events.audience')) ?></dt><dd><?= $e((string) $ev['audience_text']) ?></dd></div>
      <div><dt><?= $e($t('events.field.reg_until')) ?></dt><dd><?= $e($dt((string) $ev['reg_until'])) ?></dd></div>
      <div><dt><?= $e($t('events.places')) ?></dt><dd><?= $ev['quota'] === null ? $e($t('events.places_unlimited', ['n' => (string) $ev['taken']])) : $e($t('events.places_of', ['n' => (string) $ev['taken'], 'q' => (string) $ev['quota']])) ?></dd></div>
      <div><dt><?= $e($t('events.rules')) ?></dt><dd><?= $e(((int) $ev['waitlist'] === 1 ? $t('events.field.waitlist') : $t('events.no_waitlist')) . ' · ' . ((int) $ev['walk_in'] === 1 ? $t('events.field.walk_in') : $t('events.no_walk_in'))) ?></dd></div>
      <?php if ($ev['option_label'] !== null): ?><div><dt><?= $e((string) $ev['option_label']) ?></dt><dd><?= $e(implode(' / ', $ev['choices'])) ?></dd></div><?php endif; ?>
      <?php if ($ev['status'] === 'cancelled'): ?><div><dt><?= $e($t('events.field.reason')) ?></dt><dd><?= $e((string) $ev['cancel_reason']) ?></dd></div><?php endif; ?>
    </dl>
  </section>
  <section class="card stack-sm" aria-labelledby="ev-n">
    <h2 id="ev-n" class="card__title"><?= $e($t('events.next_step')) ?></h2>
    <?php if ($ev['status'] === 'draft'): ?>
      <p class="text-sm"><?= $e($t('events.publish_help')) ?></p>
      <form method="post" action="<?= $e($act('publish')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('events.publish'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></form>
    <?php elseif ($ev['status'] === 'published'): ?>
      <p class="text-sm"><?= $e($t('events.published_help')) ?></p>
    <?php else: ?><p class="text-sm text-muted"><?= $e($t('events.no_next')) ?></p><?php endif; ?>
    <?php if (in_array($ev['status'], ['draft', 'published'], true) && $eph !== 'completed'): ?>
    <details<?= $form === 'cancel' ? ' open' : '' ?>><summary><?= $e($t('events.cancel_event')) ?></summary>
      <form method="post" action="<?= $e($act('cancel')) ?>" class="stack-sm" novalidate><?= $csrf ?>
        <?= $ui('field', ['name' => 'reason', 'label' => $t('events.field.reason'), 'required' => true, 'help' => $t('events.cancel_help'), 'error' => $form === 'cancel' ? ($errors['reason'] ?? null) : null]) ?>
        <div class="row"><?= $ui('button', ['label' => $t('events.cancel_event'), 'type' => 'submit', 'variant' => 'danger']) ?></div>
      </form>
    </details>
    <?php endif; ?>
  </section>
</div>
<?php if ($ev['status'] === 'draft'): ?>
<section class="card stack-sm" aria-labelledby="ev-e">
  <h2 id="ev-e" class="card__title"><?= $e($t('events.edit_draft')) ?></h2>
  <form method="post" action="<?= $e($act('edit')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?php $ferr = $form === 'edit' ? $errors : []; include __DIR__ . '/_fields.php'; ?>
    <div class="row"><?= $ui('button', ['label' => $t('events.save_draft'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
  </form>
</section>
<?php endif; ?>
<section class="card card--flush" aria-labelledby="ev-pp">
  <div class="card__head"><h2 id="ev-pp" class="card__title"><?= $e($t('events.people_n', ['n' => (string) count($ev['people'])])) ?></h2></div>
  <?php if ($ev['people'] === []): ?><p class="card__pad text-sm text-muted"><?= $e($t('events.no_people')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="ev-pp" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('events.csv.name')) ?></th><th scope="col"><?= $e($t('events.csv.department')) ?></th><th scope="col"><?= $e($t('events.csv.status')) ?></th><th scope="col"><?= $e($t('events.csv.choice')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($ev['people'] as $p): $ps = $p['status'] === 'registered' && in_array($eph, ['in_progress', 'completed'], true) ? 'no_show' : (string) $p['status']; ?>
        <tr><th scope="row" data-label="<?= $e($t('events.csv.name')) ?>"><?= $e($p['employee_code'] . ' · ' . $p['name_th']) ?></th>
          <td data-label="<?= $e($t('events.csv.department')) ?>"><?= $e((string) ($p['dept_code'] ?? '—')) ?></td>
          <td data-label="<?= $e($t('events.csv.status')) ?>"><?= $ui('badge', ['tone' => ['checked_in' => 'success', 'registered' => 'info', 'waitlisted' => 'warning', 'no_show' => 'danger'][$ps] ?? 'neutral', 'label' => $t('events.reg_status.' . $ps)]) ?><?= (int) $p['walk_in'] === 1 ? ' ' . $ui('badge', ['tone' => 'neutral', 'label' => $t('events.walk_in')]) : '' ?></td>
          <td data-label="<?= $e($t('events.csv.choice')) ?>"><?= $e((string) ($p['choice'] ?? '—')) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
