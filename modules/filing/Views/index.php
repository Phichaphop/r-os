<?php
/**
 * Government filing of a company (M58 · 6E): each month of a year with its withholding tax and social security
 * filings (latest revision, due day, overdue) · make a revision.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var int $year @var list<array<string, mixed>> $months
 * @var bool $canPrepare @var string $today @var array<string, string> $errors @var array<string, mixed> $old
 * @var array{year: int, latest: ?array<string, mixed>, done: bool, runs: int, ended: bool} $annual
 */
use Blue\Modules\Filing\FilingCalc;

$o = static fn (string $k, string $d = ''): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$now = (int) substr($today, 0, 4);
$periods = [];
foreach ($months as $m) {
    $periods[(string) $m['period']] = (string) $m['period'];
}
?>
<?= $ui('page-header', ['title' => $t('filing.title'), 'subtitle' => $t('filing.subtitle')]) ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('filing.pick_company')) ?>">
  <?php foreach ($companies as $pid => $code): ?><a href="<?= $e($url('/filing?le=' . rawurlencode($pid) . '&year=' . $year)) ?>"<?= $pid === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<nav class="segmented-links" aria-label="<?= $e($t('filing.year')) ?>">
  <?php foreach ([$now - 1, $now] as $y): ?><a href="<?= $e($url('/filing?le=' . rawurlencode($lePid) . '&year=' . $y)) ?>"<?= $y === $year ? ' aria-current="page"' : '' ?>><?= (int) $y ?></a><?php endforeach; ?>
</nav>
<?= $ui('alert', ['tone' => 'warning', 'title' => $t('filing.no_official_format_title'), 'text' => $t('filing.no_official_format')]) ?>
<section class="card card--flush" aria-labelledby="fl-m">
  <div class="card__head"><h2 id="fl-m" class="card__title"><?= $e($t('filing.months', ['year' => (string) $year])) ?></h2></div>
  <div class="table-wrap" role="region" aria-labelledby="fl-m" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('filing.field.period')) ?></th><th scope="col"><?= $e($t('filing.col.runs')) ?></th>
        <?php foreach (FilingCalc::MONTHLY as $k): ?><th scope="col"><?= $e($t('filing.kind.' . $k)) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($months as $m): ?>
        <tr><th scope="row" data-label="<?= $e($t('filing.field.period')) ?>"><?= $e((string) $m['period']) ?></th>
          <td data-label="<?= $e($t('filing.col.runs')) ?>"><?= (int) $m['runs'] ?></td>
          <?php foreach (FilingCalc::MONTHLY as $k): $c = $m['kinds'][$k]; $l = $c['latest']; ?>
          <td data-label="<?= $e($t('filing.kind.' . $k)) ?>">
            <?php if ($l !== null): $status = (string) $l['status']; ?>
              <a href="<?= $e($url('/filing/r/' . $l['public_id'])) ?>"><?= $e('#' . $l['revision_no']) ?></a> <?php include __DIR__ . '/_status.php'; ?>
            <?php else: ?><span class="text-muted"><?= $e($t('filing.none_yet')) ?></span><?php endif; ?>
            <?php if ($c['overdue']): ?> <?= $ui('badge', ['tone' => 'danger', 'label' => $t('filing.overdue')]) ?><?php endif; ?>
            <?php if ($c['due'] !== null): ?><br><span class="text-sm text-muted"><?= $e($t('filing.due_on', ['d' => $date((string) $c['due'], false)])) ?></span><?php endif; ?>
          </td>
          <?php endforeach; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<section class="card stack" aria-labelledby="fl-y">
  <h2 id="fl-y" class="card__title"><?= $e($t('filing.annual_title', ['year' => (string) $year])) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('filing.annual_help')) ?></p>
  <dl class="facts">
    <div><dt><?= $e($t('filing.col.runs')) ?></dt><dd><?= (int) $annual['runs'] ?></dd></div>
    <div><dt><?= $e($t('filing.annual_latest')) ?></dt><dd>
      <?php if ($annual['latest'] !== null): $l = $annual['latest']; $status = (string) $l['status']; ?>
        <a href="<?= $e($url('/filing/r/' . $l['public_id'])) ?>"><?= $e('#' . $l['revision_no']) ?></a> <?php include __DIR__ . '/_status.php'; ?>
      <?php else: ?><span class="text-muted"><?= $e($t('filing.none_yet')) ?></span><?php endif; ?>
    </dd></div>
  </dl>
  <?php if (!$annual['ended']): ?><p class="text-sm"><?= $ui('badge', ['tone' => 'warning', 'label' => $t('filing.annual_open')]) ?></p><?php endif; ?>
  <?php if ($canPrepare): ?>
  <form method="post" action="<?= $e($url('/filing/generate')) ?>" class="stack" novalidate><?= $csrf ?>
    <input type="hidden" name="le" value="<?= $e($lePid) ?>"><input type="hidden" name="kind" value="wht_annual"><input type="hidden" name="period" value="<?= (int) $year ?>"><input type="hidden" name="year" value="<?= (int) $year ?>">
    <?= $ui('field', ['name' => 'reason', 'id' => 'fl-y-reason', 'label' => $t('filing.field.reason'), 'value' => $o('kind') === 'wht_annual' ? $o('reason') : '', 'help' => $t('filing.reason_help'),
        'error' => $o('kind') === 'wht_annual' ? ($errors['reason'] ?? $errors['period'] ?? null) : null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('filing.annual_generate', ['year' => (string) $year]), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'refresh']) ?></div>
  </form>
  <?php endif; ?>
</section>
<?php if ($canPrepare): ?>
<section class="card stack" aria-labelledby="fl-g">
  <h2 id="fl-g" class="card__title"><?= $e($t('filing.generate_title')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('filing.generate_help')) ?></p>
  <form method="post" action="<?= $e($url('/filing/generate')) ?>" class="stack" novalidate><?= $csrf ?>
    <input type="hidden" name="le" value="<?= $e($lePid) ?>">
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'kind', 'type' => 'select', 'label' => $t('filing.field.kind'), 'required' => true, 'value' => $o('kind', 'wht_monthly'),
          'options' => array_combine(FilingCalc::MONTHLY, array_map(static fn (string $k): string => $t('filing.kind.' . $k), FilingCalc::MONTHLY)), 'error' => $errors['kind'] ?? null]) ?>
      <?= $ui('field', ['name' => 'period', 'type' => 'select', 'label' => $t('filing.field.period'), 'required' => true, 'value' => $o('period', (string) array_key_first($periods)), 'options' => $periods, 'error' => $o('kind') !== 'wht_annual' ? ($errors['period'] ?? null) : null]) ?>
    </div>
    <?= $ui('field', ['name' => 'reason', 'label' => $t('filing.field.reason'), 'value' => $o('kind') !== 'wht_annual' ? $o('reason') : '', 'help' => $t('filing.reason_help'), 'error' => $o('kind') !== 'wht_annual' ? ($errors['reason'] ?? null) : null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('filing.generate'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'refresh']) ?></div>
  </form>
</section>
<?php endif; ?>
