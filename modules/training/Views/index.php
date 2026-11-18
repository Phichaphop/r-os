<?php
/**
 * Training for an employee: open sessions to enrol in, courses my position needs, my training and certificates.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var list<array<string, mixed>> $open @var list<array<string, mixed>> $mine @var list<array{title: string, code: string, state: string}> $required
 * @var array<string, mixed>|null $employee @var bool $canManage @var string $today
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
?>
<?= $ui('page-header', ['title' => $t('training.title'), 'subtitle' => $t('training.subtitle'),
    'actions' => $canManage ? $ui('button', ['label' => $t('training.nav_admin'), 'href' => $url('/training/admin'), 'variant' => 'secondary', 'icon' => 'calendar-event']) : '']) ?>
<?php if ($employee === null || $employee['job'] === null): ?><?= $ui('alert', ['tone' => 'info', 'text' => $t('training.not_employee')]) ?><?php endif; ?>
<?php if ($required !== []): ?>
<section class="card stack-sm" aria-labelledby="ti-r">
  <h2 id="ti-r" class="card__title"><?= $e($t('training.required_for_me')) ?></h2>
  <ul class="plain-list stack-sm"><?php foreach ($required as $q): ?>
    <li><?= $e($q['code'] . ' · ' . $q['title']) ?> <?= $ui('badge', ['tone' => match ($q['state']) { 'valid' => 'success', 'expiring', 'booked' => 'warning', default => 'danger' }, 'label' => $t('training.matrix.' . $q['state'])]) ?></li>
  <?php endforeach; ?></ul>
</section>
<?php endif; ?>
<section class="card stack-sm" aria-labelledby="ti-o">
  <h2 id="ti-o" class="card__title"><?= $e($t('training.open_sessions')) ?></h2>
  <?php if ($open === []): ?><p class="text-sm text-muted"><?= $e($t('training.no_open')) ?></p><?php else: ?>
  <ul class="job-list">
    <?php foreach ($open as $x): $free = (int) $x['seats'] - (int) $x['taken']; ?>
      <li class="card job-card">
        <h3 class="job-card__title"><a href="<?= $e($url('/training/s/' . $x['public_id'])) ?>"><?= $e((string) $x['title']) ?></a></h3>
        <p class="job-card__text"><?php include __DIR__ . '/_when.php'; ?> · <?= $e((string) $x['location']) ?></p>
        <p class="text-sm"><?= $e($free > 0 ? $t('training.seats_left', ['n' => (string) $free]) : $t('training.full_waitlist')) ?><?= (int) $x['needs_approval'] === 1 ? ' · ' . $e($t('training.needs_approval')) : '' ?></p>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<section class="card stack-sm" aria-labelledby="ti-m">
  <h2 id="ti-m" class="card__title"><?= $e($t('training.my_training')) ?></h2>
  <?php if ($mine === []): ?><p class="text-sm text-muted"><?= $e($t('training.none')) ?></p><?php else: ?>
  <ul class="plain-list stack-sm">
    <?php foreach ($mine as $x): $st = (string) $x['status']; ?>
      <li><a href="<?= $e($url('/training/s/' . $x['session_pid'])) ?>"><?= $e($x['code'] . ' · ' . $x['title']) ?></a> <?php include __DIR__ . '/_enr.php'; ?>
        <span class="d-block text-sm text-muted"><?php include __DIR__ . '/_when.php'; ?><?= $x['score'] !== null ? ' · ' . $e($t('training.score_n', ['s' => (string) (float) $x['score']])) : '' ?></span>
        <?php if ($x['cert_pid'] !== null): ?><a class="text-sm" href="<?= $e($url('/training/c/' . $x['cert_pid'])) ?>"><?= $ui('icon', ['name' => 'file-text', 'size' => 'sm']) ?> <?= $e($t('training.cert_link', ['no' => (string) $x['cert_no']])) ?><?= $x['cert_expires'] !== null ? ' · ' . $e($t('training.expires_on', ['d' => $date((string) $x['cert_expires'], false)])) : '' ?></a><?php endif; ?>
        <?php if (in_array($st, ['pending', 'enrolled', 'waitlisted'], true) && (string) $x['start_date'] > $today): ?>
          <form method="post" action="<?= $e($url('/training/e/' . $x['public_id'] . '/cancel')) ?>" class="d-inline"><?= $csrf ?><?= $ui('button', ['label' => $t('training.cancel_mine'), 'type' => 'submit', 'variant' => 'ghost', 'size' => 'sm']) ?></form>
        <?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
