<?php
/**
 * Time Collector (IT): is the pull set up, last pulls, pull now, interval, time clocks. No personal data here —
 * only counts, device names and technical results.
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $ready @var list<array<string, mixed>> $runs @var int $hours @var list<array<string, mixed>> $devices
 * @var array<int, string> $sites @var array<string, string> $errors @var mixed $next @var int $keys @var bool $canIntegrations
 */
$i = $ready['integration'];
$rtone = ['success' => ['success', 'circle-check'], 'partial' => ['warning', 'alert-triangle'], 'failed' => ['danger', 'circle-x'], 'running' => ['info', 'loader-2']];
$dstatus = ['new' => $t('attendance.device_status.new'), 'active' => $t('attendance.device_status.active'), 'retired' => $t('attendance.device_status.retired')];
?>
<?= $ui('page-header', ['title' => $t('attendance.collector_title'), 'subtitle' => $t('attendance.collector_subtitle'),
    'actions' => $ui('button', ['label' => $t('attendance.title'), 'href' => $url('/attendance'), 'variant' => 'ghost', 'icon' => 'arrow-back-up'])]) ?>

<section class="card stack-sm" aria-labelledby="cr-h">
  <h2 id="cr-h" class="card__title"><?= $e($t('attendance.collector_setup')) ?>
    <?= $ready['ok'] ? $ui('badge', ['tone' => 'success', 'icon' => 'circle-check', 'label' => $t('attendance.collector.ready')]) : $ui('badge', ['tone' => 'warning', 'icon' => 'alert-triangle', 'label' => $t('attendance.collector.not_ready')]) ?></h2>
  <?php if (!$ready['ok']): ?><?= $ui('alert', ['tone' => 'warning', 'text' => $t((string) $ready['reason'])]) ?><?php endif; ?>
  <dl class="dl">
    <dt><?= $e($t('attendance.collector.endpoint')) ?></dt><dd><?= $e((string) ($i['endpoint'] ?? '—')) ?></dd>
    <dt><?= $e($t('attendance.collector.status')) ?></dt><dd><?= $e($i !== null ? $t(match ((string) $i['status']) { 'active' => 'integration.status.active', 'suspended' => 'integration.status.suspended', 'retired' => 'integration.status.retired', default => 'integration.status.planned' }) : '—') ?></dd>
    <dt><?= $e($t('attendance.collector.keys')) ?></dt><dd><?= $e($t('attendance.collector.keys_n', ['n' => (string) $keys])) ?></dd>
    <dt><?= $e($t('attendance.collector.interval')) ?></dt><dd><?= $e($t('attendance.collector.every_h', ['n' => (string) $hours])) ?> · <?= $e($t('attendance.collector.next', ['time' => $date(is_string($next) ? $next : null)])) ?></dd>
  </dl>
  <ol class="att-steps text-sm">
    <li><?= $e($t('attendance.collector.step1')) ?></li>
    <li><?= $e($t('attendance.collector.step2')) ?></li>
    <li><?= $e($t('attendance.collector.step3')) ?></li>
    <li><?= $e($t('attendance.collector.step4')) ?></li>
  </ol>
  <div class="row">
    <?php if ($canIntegrations && $i !== null): ?><?= $ui('button', ['label' => $t('attendance.collector.open_integration'), 'href' => $url('/admin/integrations/' . $i['public_id']), 'variant' => 'secondary', 'icon' => 'key']) ?><?php endif; ?>
    <form method="post" action="<?= $e($url('/attendance/collector/pull')) ?>">
      <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
      <?= $ui('button', ['label' => $t('attendance.collector.pull_now'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'refresh', 'disabled' => !$ready['ok']]) ?>
    </form>
  </div>
  <form method="post" action="<?= $e($url('/attendance/collector/interval')) ?>" class="filter-bar">
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?= $ui('field', ['name' => 'hours', 'type' => 'select', 'label' => $t('attendance.collector.interval'), 'required' => true, 'value' => (string) $hours, 'error' => $errors['hours'] ?? null,
        'options' => array_combine(array_map('strval', [1, 2, 3, 4, 6, 8, 12, 24]), array_map(static fn (int $h): string => $t('attendance.collector.every_h', ['n' => (string) $h]), [1, 2, 3, 4, 6, 8, 12, 24]))]) ?>
    <?= $ui('button', ['label' => $t('attendance.save'), 'type' => 'submit', 'icon' => 'circle-check']) ?>
  </form>
</section>

<section class="card stack-sm" id="runs" aria-labelledby="ru-h">
  <h2 id="ru-h" class="card__title"><?= $e($t('attendance.collector.runs')) ?></h2>
  <?php if ($runs === []): ?>
    <p class="text-sm text-muted"><?= $e($t('attendance.collector.no_runs')) ?></p>
  <?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="ru-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('attendance.collector.started')) ?></th><th scope="col"><?= $e($t('attendance.field.status')) ?></th><th scope="col"><?= $e($t('attendance.collector.counts')) ?></th><th scope="col"><?= $e($t('attendance.collector.result')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($runs as $run): [$tone, $icon] = $rtone[(string) $run['status']] ?? ['neutral', 'info-circle']; ?>
        <tr>
          <td data-label="<?= $e($t('attendance.collector.started')) ?>"><?= $e($date((string) $run['started_at'])) ?> · <?= $e($t($run['trigger'] === 'manual' ? 'attendance.collector.manual' : 'attendance.collector.scheduled')) ?><?= $run['actor_name'] !== null ? ' (' . $e((string) $run['actor_name']) . ')' : '' ?></td>
          <td data-label="<?= $e($t('attendance.field.status')) ?>"><?= $ui('badge', ['tone' => $tone, 'icon' => $icon, 'label' => $t(match ((string) $run['status']) { 'success' => 'attendance.run.success', 'partial' => 'attendance.run.partial', 'failed' => 'attendance.run.failed', default => 'attendance.run.running' })]) ?></td>
          <td data-label="<?= $e($t('attendance.collector.counts')) ?>"><?= $e($t('attendance.collector.counts_line', ['f' => (string) $run['fetched'], 'n' => (string) $run['inserted'], 'd' => (string) $run['duplicates'], 'u' => (string) $run['unmapped'], 'x' => (string) $run['invalid']])) ?></td>
          <td data-label="<?= $e($t('attendance.collector.result')) ?>"><?= $e((string) ($run['error_summary'] ?? $t('attendance.collector.pages', ['n' => (string) $run['pages']]))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<section class="card stack-sm" id="devices" aria-labelledby="dv-h">
  <h2 id="dv-h" class="card__title"><?= $e($t('attendance.devices')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('attendance.devices_help')) ?></p>
  <?php if ($devices === []): ?>
    <p class="text-sm text-muted"><?= $e($t('attendance.no_devices')) ?></p>
  <?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="dv-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('attendance.field.device')) ?></th><th scope="col"><?= $e($t('attendance.field.site')) ?></th><th scope="col"><?= $e($t('attendance.field.last_seen')) ?></th><th scope="col"><?= $e($t('attendance.edit')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($devices as $d): ?>
        <tr>
          <td data-label="<?= $e($t('attendance.field.device')) ?>"><strong><?= $e((string) ($d['name'] ?? $d['device_code'])) ?></strong>
            <span class="att-sub"><?= $e((string) $d['device_code']) ?><?= $d['serial_no'] !== null ? ' · ' . $e((string) $d['serial_no']) : '' ?> · <?= $e($dstatus[(string) $d['status']] ?? (string) $d['status']) ?> · <?= $e($t('attendance.punches_n', ['n' => (string) $d['punches']])) ?></span></td>
          <td data-label="<?= $e($t('attendance.field.site')) ?>"><?= $e($d['site_id'] !== null ? ($sites[(int) $d['site_id']] ?? (string) $d['site_code']) : '—') ?></td>
          <td data-label="<?= $e($t('attendance.field.last_seen')) ?>"><?= $e($date($d['last_seen_at'] !== null ? (string) $d['last_seen_at'] : null)) ?><?= $d['clock_drift_seconds'] !== null ? ' · ' . $e($t('attendance.drift', ['n' => (string) $d['clock_drift_seconds']])) : '' ?></td>
          <td data-label="<?= $e($t('attendance.edit')) ?>">
            <details<?= $d['status'] === 'new' ? ' open' : '' ?>><summary><?= $e($t('attendance.edit')) ?> <span class="sr-only"><?= $e((string) $d['device_code']) ?></span></summary>
              <form method="post" action="<?= $e($url('/attendance/collector/devices/' . $d['public_id'])) ?>" class="stack-sm" novalidate>
                <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
                <?= $ui('field', ['name' => 'name', 'id' => 'dn-' . $d['public_id'], 'label' => $t('attendance.field.device_name'), 'value' => (string) ($d['name'] ?? '')]) ?>
                <?= $ui('field', ['name' => 'site', 'id' => 'ds-' . $d['public_id'], 'type' => 'select', 'label' => $t('attendance.field.site'), 'value' => (string) ($d['site_id'] ?? 0), 'options' => $sites]) ?>
                <?= $ui('field', ['name' => 'status', 'id' => 'dt-' . $d['public_id'], 'type' => 'select', 'label' => $t('attendance.field.status'), 'value' => (string) $d['status'], 'options' => $dstatus]) ?>
                <div class="row"><?= $ui('button', ['label' => $t('attendance.save'), 'type' => 'submit', 'variant' => 'primary', 'size' => 'sm', 'icon' => 'circle-check']) ?></div>
              </form>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
