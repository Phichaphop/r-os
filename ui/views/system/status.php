<?php
/**
 * @var callable $e @var callable $t @var callable $ui
 * @var list<array{version:string, module:string, state:string}> $migrations @var ?string $dbError
 * @var array{last_run:?string, trigger:?string} $scheduler @var string $aiProvider @var string $env @var string $release
 */
$pending = count(array_filter($migrations, static fn ($m) => $m['state'] !== 'applied'));
?>
<?= $ui('page-header', ['title' => $t('status.title'), 'subtitle' => $t('status.subtitle'), 'crumbs' => [[$t('nav.home'), $url('/')], [$t('nav.group_system'), null], [$t('status.title'), null]]]) ?>
<?php if ($dbError !== null): ?><?= $ui('alert', ['tone' => 'danger', 'text' => $dbError]) ?><?php endif; ?>
<section class="grid grid--3" aria-label="<?= $e($t('home.status_cards')) ?>">
  <div class="card stack-sm">
    <div class="stat__label"><?= $e($t('home.release')) ?></div>
    <div class="stat__value"><?= $e(explode(' ', $release)[0]) ?></div>
    <div class="stat__meta"><?= $ui('badge', ['status' => 'processing', 'label' => $t('home.phase_name')]) ?></div>
  </div>
  <div class="card stack-sm">
    <div class="stat__label"><?= $e($t('home.scheduler')) ?></div>
    <div class="stat__value stat__value--sm"><?= $e($scheduler['last_run'] ?? $t('home.scheduler_never')) ?></div>
    <div class="stat__meta"><?= $ui('icon', ['name' => 'clock', 'size' => 'sm']) ?><?= $e($t('home.scheduler_note')) ?> · <?= $e((string) ($scheduler['trigger'] ?? '—')) ?></div>
  </div>
  <div class="card stack-sm">
    <div class="stat__label"><?= $e($t('home.ai')) ?></div>
    <div class="stat__value"><?= $ui('icon', ['name' => 'robot-off', 'size' => 'lg']) ?> <span class="text-sm mono"><?= $e($aiProvider) ?></span></div>
    <div class="stat__meta"><?= $e($t('home.ai_note')) ?></div>
  </div>
</section>
<section class="grid grid--2" aria-label="<?= $e($t('status.title')) ?>">
  <div class="card"><div class="stat__label"><?= $e($t('status.env')) ?></div><div class="stat__value"><?= $e(strtoupper($env)) ?></div></div>
  <div class="card"><div class="stat__label"><?= $e($t('status.migrations_pending')) ?></div><div class="stat__value num"><?= $e((string) $pending) ?></div></div>
</section>
<section class="card card--flush" aria-labelledby="mig-h">
  <div class="card__head"><h2 id="mig-h" class="card__title"><?= $e($t('status.migrations')) ?></h2></div>
  <div class="table-wrap" tabindex="0" role="region" aria-labelledby="mig-h">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('status.col.module')) ?></th><th scope="col"><?= $e($t('status.col.version')) ?></th><th scope="col"><?= $e($t('status.col.state')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($migrations as $m): ?>
        <tr><td data-label="<?= $e($t('status.col.module')) ?>"><?= $e($m['module']) ?></td><td data-label="<?= $e($t('status.col.version')) ?>" class="mono"><?= $e($m['version']) ?></td>
          <td data-label="<?= $e($t('status.col.state')) ?>"><?= $ui('badge', match ($m['state']) {
              'applied' => ['tone' => 'success', 'icon' => 'circle-check', 'label' => $t('status.mig_applied')],
              'pending' => ['tone' => 'warning', 'icon' => 'hourglass', 'label' => $t('status.mig_pending')],
              default => ['tone' => 'danger', 'icon' => 'alert-triangle', 'label' => $t('status.mig_drift')],
          }) ?></td></tr>
      <?php endforeach; ?>
      <?php if ($migrations === []): ?><tr><td colspan="3"><?= $ui('states', ['title' => $t('status.no_migrations')]) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
