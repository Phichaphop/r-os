<?php
/**
 * HR: OT rules, payroll batches. @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var int $le @var array<string, mixed>|null $rules @var bool $canPolicy
 * @var list<array<string, mixed>> $batches @var list<array<string, mixed>> $preview @var string $from @var string $to
 * @var array<string, string> $errors @var array<string, mixed> $old @var string $form @var string $today
 */
use Blue\Core\I18n\DateFormat;
use Blue\Modules\Overtime\OtRules;

$loc = (string) ($v['locale'] ?? 'th');
include __DIR__ . '/_helpers.php';
$day = static fn (string $d): string => DateFormat::day($d, $loc);
$err = static fn (string $f, string $k) => $form === $f ? ($errors[$k] ?? null) : null;
$ru = $form === 'rules' ? $old : [];
$pvMin = array_sum(array_map(static fn (array $x): int => (int) $x['payable_minutes'], $preview));
?>
<?= $ui('page-header', ['title' => $t('overtime.admin_title'), 'subtitle' => $t('overtime.admin_subtitle'),
    'actions' => $ui('button', ['label' => $t('overtime.report_title'), 'href' => $url('/overtime/report'), 'variant' => 'secondary', 'icon' => 'report-money'])]) ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('overtime.pick_company')) ?>">
  <?php foreach ($companies as $pid => $code): ?><a href="<?= $e($url('/overtime/admin?le=' . rawurlencode($pid))) ?>"<?= $pid === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>

<section class="card stack" aria-labelledby="bt-h">
  <h2 id="bt-h" class="card__title"><?= $e($t('overtime.batches')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('overtime.batches_help')) ?></p>
  <form method="get" action="<?= $e($url('/overtime/admin')) ?>" class="row row--tight">
    <input type="hidden" name="le" value="<?= $e($lePid) ?>">
    <label class="sr-only" for="pv-from"><?= $e($t('overtime.field.period_start')) ?></label><input class="input" type="date" id="pv-from" name="from" value="<?= $e($from) ?>">
    <label class="sr-only" for="pv-to"><?= $e($t('overtime.field.period_end')) ?></label><input class="input" type="date" id="pv-to" name="to" value="<?= $e($to) ?>">
    <?= $ui('button', ['label' => $t('overtime.preview'), 'type' => 'submit', 'size' => 'sm', 'icon' => 'filter']) ?>
  </form>
  <p><?= $e($t('overtime.preview_text', ['n' => (string) count($preview), 'hm' => $hm($pvMin), 'from' => $day($from), 'to' => $day($to)])) ?></p>
  <form method="post" action="<?= $e($url('/overtime/admin/batch')) ?>" class="stack-sm" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <input type="hidden" name="legal_entity" value="<?= $e($lePid) ?>">
    <input type="hidden" name="period_start" value="<?= $e($from) ?>">
    <input type="hidden" name="period_end" value="<?= $e($to) ?>">
    <?php if (($e0 = $err('batch', 'period_end') ?? $err('batch', 'period_start')) !== null): ?><p class="field__error"><?= $e($e0) ?></p><?php endif; ?>
    <?= $ui('field', ['name' => 'note', 'id' => 'bt-note', 'label' => $t('overtime.field.batch_note'), 'value' => (string) ($old['note'] ?? '')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('overtime.batch_create'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send', 'disabled' => $preview === [], 'disabledReason' => $t('overtime.error.batch_empty')]) ?></div>
  </form>
  <?php if ($batches !== []): ?>
  <div class="table-wrap" role="region" aria-labelledby="bt-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('overtime.field.batch')) ?></th><th scope="col"><?= $e($t('overtime.field.period')) ?></th><th scope="col"><?= $e($t('overtime.field.lines')) ?></th><th scope="col"><?= $e($t('overtime.field.total')) ?></th><th scope="col"><?= $e($t('overtime.field.by')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($batches as $b): ?>
        <tr><td data-label="<?= $e($t('overtime.field.batch')) ?>"><a href="<?= $e($url('/overtime/batch/' . $b['public_id'])) ?>"><?= $e($t('overtime.batch_title', ['n' => (string) $b['batch_no']])) ?></a></td>
          <td data-label="<?= $e($t('overtime.field.period')) ?>"><?= $e($day((string) $b['period_start']) . ' – ' . $day((string) $b['period_end'])) ?></td>
          <td data-label="<?= $e($t('overtime.field.lines')) ?>" class="num"><?= (int) $b['line_count'] ?></td>
          <td data-label="<?= $e($t('overtime.field.total')) ?>" class="num"><?= $e($hm((int) $b['total_minutes'])) ?></td>
          <td data-label="<?= $e($t('overtime.field.by')) ?>"><?= $e((string) ($b['created_name'] ?? '—')) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<section class="card stack" aria-labelledby="ru-h">
  <h2 id="ru-h" class="card__title"><?= $e($t('overtime.rules.title')) ?></h2>
  <?php if ($rules === null): ?>
    <?= $ui('alert', ['tone' => 'info', 'text' => $t('overtime.rules.none')]) ?>
  <?php else: ?>
    <dl class="facts">
      <?php foreach (OtRules::DAY_TYPES as $d): $rr = (array) ($rules['rates'][$d] ?? []); ?>
        <div><dt><?= $e($t('overtime.day_type.' . $d)) ?></dt><dd><?= $e(trim(($rr['class'] ?? '') . ' × ' . (($rr['multiplier'] ?? '') !== '' ? $rr['multiplier'] : '—'))) ?></dd></div>
      <?php endforeach; ?>
      <div><dt><?= $e($t('overtime.rules.monthly_cap_hours')) ?></dt><dd><?= $e(($rules['monthly_cap_hours'] ?? null) === null ? '—' : $rules['monthly_cap_hours'] . ' · ' . $t('overtime.cap_mode.' . (($rules['cap_mode'] ?? 'warn') === 'block' ? 'block' : 'warn'))) ?></dd></div>
      <div><dt><?= $e($t('overtime.rules.backdate_days')) ?></dt><dd><?= $e(($rules['backdate_days'] ?? null) === null ? '—' : (string) $rules['backdate_days']) ?></dd></div>
      <div><dt><?= $e($t('overtime.rules.round_minutes')) ?></dt><dd><?= $e((string) ($rules['round_minutes'] ?? 1)) ?></dd></div>
      <div><dt><?= $e($t('overtime.rules.min_minutes')) ?></dt><dd><?= $e(($rules['min_minutes'] ?? null) === null ? '—' : (string) $rules['min_minutes']) ?></dd></div>
    </dl>
  <?php endif; ?>
  <?php if ($canPolicy): ?>
  <details<?= $form === 'rules' ? ' open' : '' ?>>
    <summary><?= $e($t('overtime.rules.propose')) ?></summary>
    <form method="post" action="<?= $e($url('/overtime/admin/rules')) ?>" class="stack" novalidate>
      <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
      <input type="hidden" name="legal_entity" value="<?= $e($lePid) ?>">
      <?= $ui('alert', ['tone' => 'warning', 'title' => $t('overtime.rules.warn_title'), 'text' => $t('overtime.rules.warn_text')]) ?>
      <div class="grid grid--3">
        <?php foreach (OtRules::DAY_TYPES as $d): ?>
          <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('overtime.day_type.' . $d)) ?></legend>
            <?= $ui('field', ['name' => 'rates[' . $d . '][class]', 'id' => 'ru-' . $d . '-c', 'label' => $t('overtime.rules.class'), 'value' => (string) ($ru['rates'][$d]['class'] ?? ''), 'error' => $errors['rates.' . $d . '.class'] ?? null]) ?>
            <?= $ui('field', ['name' => 'rates[' . $d . '][multiplier]', 'id' => 'ru-' . $d . '-m', 'label' => $t('overtime.rules.multiplier'), 'value' => (string) ($ru['rates'][$d]['multiplier'] ?? ''), 'inputmode' => 'decimal', 'error' => $errors['rates.' . $d . '.multiplier'] ?? null]) ?>
          </fieldset>
        <?php endforeach; ?>
      </div>
      <div class="grid grid--3">
        <?= $ui('field', ['name' => 'monthly_cap_hours', 'id' => 'ru-cap', 'type' => 'number', 'label' => $t('overtime.rules.monthly_cap_hours'), 'value' => (string) ($ru['monthly_cap_hours'] ?? ''), 'error' => $err('rules', 'monthly_cap_hours')]) ?>
        <?= $ui('field', ['name' => 'cap_mode', 'id' => 'ru-mode', 'type' => 'select', 'label' => $t('overtime.rules.cap_mode'), 'value' => (string) ($ru['cap_mode'] ?? 'warn'), 'options' => ['warn' => $t('overtime.cap_mode.warn'), 'block' => $t('overtime.cap_mode.block')]]) ?>
        <?= $ui('field', ['name' => 'backdate_days', 'id' => 'ru-back', 'type' => 'number', 'label' => $t('overtime.rules.backdate_days'), 'value' => (string) ($ru['backdate_days'] ?? ''), 'error' => $err('rules', 'backdate_days')]) ?>
        <?= $ui('field', ['name' => 'round_minutes', 'id' => 'ru-round', 'type' => 'select', 'label' => $t('overtime.rules.round_minutes'), 'value' => (string) ($ru['round_minutes'] ?? '1'), 'options' => ['1' => '1', '5' => '5', '10' => '10', '15' => '15', '30' => '30']]) ?>
        <?= $ui('field', ['name' => 'min_minutes', 'id' => 'ru-min', 'type' => 'number', 'label' => $t('overtime.rules.min_minutes'), 'value' => (string) ($ru['min_minutes'] ?? ''), 'error' => $err('rules', 'min_minutes')]) ?>
        <?= $ui('field', ['name' => 'effective_from', 'id' => 'ru-from', 'type' => 'date', 'label' => $t('overtime.rules.effective_from'), 'required' => true, 'value' => (string) ($ru['effective_from'] ?? $today), 'error' => $err('rules', 'effective_from')]) ?>
      </div>
      <?= $ui('field', ['name' => 'source_reference', 'id' => 'ru-src', 'label' => $t('overtime.rules.source'), 'required' => true, 'value' => (string) ($ru['source_reference'] ?? ''), 'help' => $t('overtime.rules.source_help'), 'error' => $err('rules', 'source_reference')]) ?>
      <?= $ui('field', ['name' => 'reason', 'id' => 'ru-reason', 'label' => $t('overtime.field.reason'), 'required' => true, 'value' => (string) ($ru['reason'] ?? ''), 'error' => $err('rules', 'reason')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('overtime.rules.submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
    </form>
  </details>
  <?php endif; ?>
</section>
