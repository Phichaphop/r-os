<?php
/**
 * One verification campaign: scope and figures, the next step (start · count · end counting · close), items expected vs
 * observed, and the discrepancy cases with their resolution.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $camp @var array<string, string> $errors @var array<string, mixed> $old @var string $form
 * @var string $here @var bool $canView @var bool $canManage @var bool $canCount
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$c = $camp;
$pid = (string) $c['public_id'];
$cst = (string) $c['status'];
$s = $c['stats'];
$dt = static fn (?string $x): string => $x === null ? '—' : $date(substr($x, 0, 10), false) . (strlen($x) > 10 ? ' ' . substr($x, 11, 5) : '');
$rtone = static fn (string $r): string => ['matched' => 'success', 'pending' => 'neutral', 'not_applicable' => 'neutral', 'not_found' => 'danger', 'unexpected' => 'info'][$r] ?? 'warning';
$pct = static fn (?float $x): string => $x === null ? '—' : number_format($x, 1) . '%';
$caseOld = ($old['case'] ?? '') !== '' ? (string) $old['case'] : '';
?>
<?= $ui('page-header', ['title' => (string) $c['name'], 'subtitle' => $t('assets.campaign_subtitle', ['cutoff' => $date((string) $c['cutoff_date'], false), 'scope' => ($c['site_code'] ?? $t('assets.all')) . ' · ' . ($c['type_name'] ?? $t('assets.all'))]),
    'crumbs' => [[$t('assets.campaigns_title'), $url('/assets/campaigns')], [(string) $c['name'], null]],
    'actions' => $cst !== 'draft' ? $ui('button', ['label' => $t('assets.export_pack'), 'href' => $url('/assets/campaigns/' . $pid . '/export'), 'variant' => 'secondary', 'icon' => 'download']) : '']) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<div class="grid grid--2">
  <section class="card stack-sm" aria-labelledby="cp-s">
    <h2 id="cp-s" class="card__title"><?= $e($t('assets.col.status')) ?>: <?php include __DIR__ . '/_cstatus.php'; ?></h2>
    <dl class="facts">
      <div><dt><?= $e($t('assets.col.in_scope')) ?></dt><dd><?= $cst === 'draft' ? $e($t('assets.not_frozen')) : (int) $s['scope'] ?></dd></div>
      <div><dt><?= $e($t('assets.col.counted')) ?></dt><dd><?= $e($s['counted'] . ' / ' . $s['applicable']) ?> (<?= $e($pct($s['coverage'])) ?>)</dd></div>
      <div><dt><?= $e($t('assets.col.matched_pct')) ?></dt><dd><?= $e($s['by']['matched'] . ' · ' . $pct($s['matched_pct'])) ?></dd></div>
      <div><dt><?= $e($t('assets.result.unexpected')) ?></dt><dd><?= (int) $s['by']['unexpected'] ?></dd></div>
      <div><dt><?= $e($t('assets.col.double')) ?></dt><dd><?= $e($s['double_done'] . ' / ' . $s['double']) ?></dd></div>
      <div><dt><?= $e($t('assets.col.started')) ?></dt><dd><?= $e($dt($c['started_at']) . ($c['started_name'] !== null ? ' · ' . $c['started_name'] : '')) ?></dd></div>
      <?php if ($c['report_sha256'] !== null): ?><div><dt><?= $e($t('assets.col.report_hash')) ?></dt><dd class="as-hash"><?= $e((string) $c['report_sha256']) ?></dd></div><?php endif; ?>
      <?php if ($c['exception_note'] !== null): ?><div><dt><?= $e($t('assets.field.exception_note')) ?></dt><dd><?= $e($c['exception_note'] . ' — ' . $c['exception_name']) ?></dd></div><?php endif; ?>
    </dl>
    <p class="text-sm text-muted"><?= $e($t('assets.coverage_note')) ?></p>
  </section>
  <section class="card stack-sm" aria-labelledby="cp-n">
    <h2 id="cp-n" class="card__title"><?= $e($t('assets.next_step')) ?></h2>
    <?php if ($cst === 'draft'): ?>
      <p class="text-sm"><?= $e($t('assets.start_help')) ?></p>
      <?php if ($c['can_manage']): ?><form method="post" action="<?= $e($url('/assets/campaigns/' . $pid . '/start')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('assets.start_campaign'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'lock']) ?></form><?php endif; ?>
    <?php elseif ($cst === 'counting'): ?>
      <?php if ($c['can_count']): ?><p><?= $ui('button', ['label' => $t('assets.open_count'), 'href' => $url('/assets/campaigns/' . $pid . '/count'), 'variant' => 'primary', 'icon' => 'qrcode']) ?></p><?php endif; ?>
      <p class="text-sm"><?= $e($t('assets.finish_help')) ?></p>
      <?php if ($c['can_manage']): ?><form method="post" action="<?= $e($url('/assets/campaigns/' . $pid . '/finish')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('assets.finish_counting'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'checks']) ?></form><?php endif; ?>
    <?php elseif ($cst === 'reviewing'): $openCases = count(array_filter($c['cases'], static fn (array $k): bool => $k['status'] === 'open')); ?>
      <p class="text-sm"><?= $e($t('assets.review_help', ['n' => (string) $openCases])) ?></p>
      <?php if ($openCases === 0 && $c['can_manage']): ?>
        <form method="post" action="<?= $e($url('/assets/campaigns/' . $pid . '/close')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('assets.close_campaign'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></form>
      <?php elseif ($openCases > 0 && $c['can_except']): ?>
        <form method="post" action="<?= $e($url('/assets/campaigns/' . $pid . '/close')) ?>" class="stack-sm" novalidate><?= $csrf ?>
          <?= $ui('field', ['name' => 'exception_note', 'type' => 'textarea', 'label' => $t('assets.field.exception_note'), 'required' => true, 'value' => $form === 'close' ? (string) ($old['exception_note'] ?? '') : '', 'help' => $t('assets.exception_help'), 'error' => $form === 'close' ? ($errors['exception_note'] ?? null) : null]) ?>
          <div class="row"><?= $ui('button', ['label' => $t('assets.close_with_exception'), 'type' => 'submit', 'variant' => 'danger']) ?></div>
        </form>
      <?php endif; ?>
    <?php else: ?>
      <p class="text-sm"><?= $e($t('assets.closed_help', ['at' => $dt($c['closed_at'])])) ?></p>
    <?php endif; ?>
  </section>
</div>
<?php if ($c['cases'] !== []): ?>
<section class="card stack-sm" id="cases" aria-labelledby="cp-c">
  <h2 id="cp-c" class="card__title"><?= $e($t('assets.cases')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('assets.cases_help')) ?></p>
  <ul class="plain-list stack">
  <?php foreach ($c['cases'] as $k): $kp = (string) $k['public_id']; $mine = $caseOld === $kp; ?>
    <li class="as-case<?= $k['status'] === 'open' ? ' as-case--open' : '' ?>">
      <p><strong><?= $e((string) ($k['code'] !== null ? $k['code'] . ' · ' . $k['name'] : $k['unexpected_label'])) ?></strong> <?= $ui('badge', ['tone' => $rtone((string) $k['kind']), 'label' => $t('assets.result.' . $k['kind'])]) ?>
        <?= $k['status'] === 'resolved' ? $ui('badge', ['tone' => 'success', 'label' => $t('assets.resolution.' . $k['resolution'])]) : '' ?></p>
      <?php if ($k['status'] === 'resolved'): ?>
        <p class="text-sm"><?= $e((string) $k['resolution_note']) ?> <span class="text-muted">· <?= $e($k['resolver'] . ' · ' . $dt($k['resolved_at'])) ?></span></p>
      <?php elseif ($c['can_manage'] && $cst === 'reviewing' && $k['counted_by_me']): ?>
        <p class="text-sm text-muted"><?= $e($t('assets.own_count_note')) ?></p>
      <?php elseif ($c['can_manage'] && $cst === 'reviewing'): ?>
        <form method="post" action="<?= $e($url('/assets/cases/' . $kp)) ?>" class="stack-sm" novalidate><?= $csrf ?>
          <div class="grid grid--2">
            <?= $ui('field', ['name' => 'resolution', 'id' => 'rs-' . $kp, 'type' => 'select', 'label' => $t('assets.field.resolution'), 'required' => true, 'value' => $mine ? (string) ($old['resolution'] ?? '') : '',
                'options' => ['' => $t('assets.pick')] + array_combine(\Blue\Modules\Assets\CampaignService::RESOLUTIONS[$k['kind']] ?? [], array_map(static fn (string $x): string => $t('assets.resolution.' . $x), \Blue\Modules\Assets\CampaignService::RESOLUTIONS[$k['kind']] ?? [])),
                'error' => $mine ? ($errors['resolution'] ?? null) : null]) ?>
            <?= $ui('field', ['name' => 'resolution_note', 'id' => 'rn-' . $kp, 'label' => $t('assets.field.resolution_note'), 'required' => true, 'value' => $mine ? (string) ($old['resolution_note'] ?? '') : '', 'error' => $mine ? ($errors['resolution_note'] ?? null) : null]) ?>
          </div>
          <div class="row"><?= $ui('button', ['label' => $t('assets.resolve'), 'type' => 'submit', 'variant' => 'secondary', 'attrs' => ['aria-label' => $t('assets.resolve_for', ['code' => (string) ($k['code'] ?? $k['unexpected_label'])])]]) ?></div>
        </form>
      <?php endif; ?>
    </li>
  <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<?php if ($c['items'] !== []): ?>
<section class="card card--flush" aria-labelledby="cp-i">
  <div class="card__head"><h2 id="cp-i" class="card__title"><?= $e($t('assets.items_expected', ['n' => (string) count($c['items'])])) ?></h2></div>
  <div class="table-wrap" role="region" aria-labelledby="cp-i" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('assets.field.code')) ?></th><th scope="col"><?= $e($t('assets.col.expected')) ?></th><th scope="col"><?= $e($t('assets.col.observed')) ?></th><th scope="col"><?= $e($t('assets.col.result')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($c['items'] as $i): $x = (array) json_decode((string) ($i['expected_json'] ?? '{}'), true); ?>
        <tr><th scope="row" data-label="<?= $e($t('assets.field.code')) ?>"><?= $i['asset_pid'] !== null ? '<a href="' . $e($url('/assets/a/' . $i['asset_pid'])) . '">' . $e((string) $i['code']) . '</a> ' . $e((string) $i['name']) : $e((string) $i['unexpected_label']) ?></th>
          <td data-label="<?= $e($t('assets.col.expected')) ?>"><?= (int) $i['in_scope'] === 1 ? $e(($x['site'] ?? '') . (($x['location'] ?? null) !== null ? ' · ' . $x['location'] : '') . ' · ' . ($x['custodian'] ?? '—') . ' · ' . $t('assets.condition.' . ($x['condition'] ?? 'good'))) : $e($t('assets.out_of_scope')) ?></td>
          <td data-label="<?= $e($t('assets.col.observed')) ?>"><?= $i['counted_by'] !== null && $i['result'] !== 'not_applicable' ? $e(($i['obs_site_code'] ?? '') . ($i['observed_location'] !== null ? ' · ' . $i['observed_location'] : '') . ' · ' . ($i['obs_custodian'] ?? '—') . ' · ' . $t('assets.condition.' . ($i['observed_condition'] ?? 'good')) . ((int) $i['tag_ok'] === 0 ? ' · ' . $t('assets.tag_damaged_short') : '')) : '—' ?>
            <?= $i['counter'] !== null ? '<div class="text-sm text-muted">' . $e($i['counter'] . ($i['second_counter'] !== null ? ' + ' . $i['second_counter'] : '') . ((int) $i['scans'] > 1 ? ' · ' . $t('assets.scans_n', ['n' => (string) $i['scans']]) : '')) . '</div>' : '' ?>
            <?= $i['note'] !== null ? '<div class="text-sm">' . $e((string) $i['note']) . '</div>' : '' ?></td>
          <td data-label="<?= $e($t('assets.col.result')) ?>"><?= $ui('badge', ['tone' => $rtone((string) $i['result']), 'label' => $t('assets.result.' . $i['result'])]) ?>
            <?= (int) $i['double_needed'] === 1 ? ' ' . $ui('badge', ['tone' => $i['second_by'] !== null ? 'success' : 'warning', 'label' => $t('assets.needs_second')]) : '' ?>
            <?php if ($cst === 'counting' && $c['can_manage'] && $i['result'] === 'pending'): ?>
            <details class="as-na"><summary><?= $e($t('assets.mark_na')) ?></summary>
              <form method="post" action="<?= $e($url('/assets/campaigns/' . $pid . '/na/' . $i['id'])) ?>" class="stack-sm" novalidate><?= $csrf ?>
                <?= $ui('field', ['name' => 'na_reason', 'id' => 'na-' . $i['id'], 'label' => $t('assets.field.reason'), 'required' => true]) ?>
                <?= $ui('button', ['label' => $t('assets.save'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm']) ?></form></details>
            <?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>
