<?php
/**
 * @var callable $e @var callable $t @var callable $partial @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $cal @var string $month @var list<array<string, mixed>> $days @var string $prev @var string $next
 * @var list<array<string, mixed>> $exceptions @var int $year @var string $today @var array<string, string> $bases
 * @var array<string, string> $errors @var array<string, array<string, string>> $old
 */
use Blue\Core\I18n\DateFormat;

$locale = (string) ($v['locale'] ?? 'th');
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$can = (bool) $cal['can_manage'];
$settings = $old['settings'] ?? ['name' => (string) $cal['name'], 'pattern' => (string) $cal['weekly_pattern'], 'base' => (string) ($cal['base_public_id'] ?? ''), 'reason' => ''];
$day = $old['day'] ?? ['date' => $month === substr($today, 0, 7) ? $today : $month . '-01', 'type' => 'holiday', 'kind' => 'company', 'name' => '', 'note' => ''];
$actions = $ui('button', ['label' => $t('common.export_excel'), 'href' => $url('/calendars/' . $cal['public_id'] . '/export') . '?year=' . $year, 'variant' => 'secondary', 'icon' => 'download'])
    . ($can ? $ui('button', ['label' => $t('calendar.import.button'), 'href' => $url('/import/calendar.days') . '?calendar=' . $cal['public_id'], 'variant' => 'secondary', 'icon' => 'upload']) : '');
?>
<?= $ui('page-header', ['title' => (string) $cal['name'], 'subtitle' => $t('calendar.type.' . $cal['calendar_type']) . ' · ' . $cal['code'] . ($cal['base_code'] !== null ? ' · ' . $t('calendar.based_on', ['code' => (string) $cal['base_code']]) : ''),
    'crumbs' => [[$t('calendar.manage_title'), $url('/admin/calendars')], [(string) $cal['name'], null]], 'actions' => $actions]) ?>
<?php if ((int) $cal['is_demo'] === 1): ?><p><?= $ui('badge', ['tone' => 'neutral', 'label' => 'DEMO']) ?></p><?php endif; ?>

<?= $partial('calendars/_month', ['days' => $days, 'month' => $month, 'prev' => $prev, 'next' => $next, 'today' => $today, 'nav' => $url('/admin/calendars/' . $cal['public_id']) . '?month=']) ?>

<div class="grid grid--2">
  <section class="card" aria-labelledby="ex-h">
    <h2 id="ex-h" class="card__title"><?= $e($t('calendar.exceptions', ['year' => $locale === 'th' ? $year + 543 : $year])) ?></h2>
    <ul class="stack-sm">
    <?php foreach ($exceptions as $x): ?>
      <li class="row"><span><strong><?= $e(DateFormat::day((string) $x['day_date'], $locale)) ?></strong> · <?= $e($t('calendar.day_type.' . $x['day_type'])) ?><?= $x['holiday_kind'] !== null ? ' (' . $e($t('calendar.kind.' . $x['holiday_kind'])) . ')' : '' ?>
        <?= $x['name'] !== null ? ' — ' . $e($x['name']) : '' ?><?= $x['source'] === 'import' ? ' ' . $ui('badge', ['tone' => 'info', 'icon' => 'upload', 'label' => $t('calendar.imported')]) : '' ?></span>
        <?php if ($can): ?><form method="post" action="<?= $e($url('/admin/calendars/' . $cal['public_id'] . '/days/' . $x['day_date'] . '/delete')) ?>"><?= $csrf ?>
          <?= $ui('button', ['label' => $t('calendar.remove_day', ['date' => DateFormat::day((string) $x['day_date'], $locale)]), 'type' => 'submit', 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'trash', 'iconOnly' => true]) ?></form><?php endif; ?></li>
    <?php endforeach; ?>
    <?php if ($exceptions === []): ?><li class="text-muted"><?= $e($t('calendar.no_exceptions')) ?></li><?php endif; ?>
    </ul>
    <p class="text-sm text-muted"><?= $e($t('calendar.no_public_holidays_seeded')) ?></p>
  </section>
  <?php if ($can): ?>
  <section class="card" aria-labelledby="day-h">
    <h2 id="day-h" class="card__title"><?= $e($t('calendar.set_day')) ?></h2>
    <form method="post" action="<?= $e($url('/admin/calendars/' . $cal['public_id'] . '/days')) ?>" class="form-grid" novalidate><?= $csrf ?>
      <?= $ui('field', ['name' => 'date', 'label' => $t('calendar.field.date'), 'type' => 'date', 'required' => true, 'value' => $day['date'], 'error' => $errors['date'] ?? null]) ?>
      <?= $ui('field', ['name' => 'day_type', 'label' => $t('calendar.field.day_type'), 'type' => 'select', 'value' => $day['type'], 'error' => $errors['type'] ?? null,
          'options' => ['holiday' => $t('calendar.day_type.holiday'), 'working' => $t('calendar.day_type.working'), 'weekend' => $t('calendar.day_type.weekend')]]) ?>
      <?= $ui('field', ['name' => 'kind', 'label' => $t('calendar.field.kind'), 'type' => 'select', 'value' => $day['kind'], 'error' => $errors['kind'] ?? null,
          'options' => ['public' => $t('calendar.kind.public'), 'company' => $t('calendar.kind.company'), 'special' => $t('calendar.kind.special')], 'help' => $t('calendar.help.kind')]) ?>
      <?= $ui('field', ['name' => 'name', 'label' => $t('calendar.field.holiday_name'), 'value' => $day['name'], 'error' => $errors['name'] ?? null]) ?>
      <?= $ui('field', ['name' => 'note', 'label' => $t('calendar.field.note'), 'full' => true, 'value' => $day['note']]) ?>
      <div class="row field--full"><?= $ui('button', ['label' => $t('common.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'checks']) ?></div>
    </form>
  </section>
  <section class="card" aria-labelledby="set-h">
    <h2 id="set-h" class="card__title"><?= $e($t('calendar.settings')) ?></h2>
    <form method="post" action="<?= $e($url('/admin/calendars/' . $cal['public_id'])) ?>" class="form-grid" novalidate><?= $csrf ?>
      <?= $ui('field', ['name' => 'name', 'label' => $t('calendar.field.name'), 'required' => true, 'full' => true, 'value' => $settings['name'], 'error' => $errors['name'] ?? null]) ?>
      <?= $ui('field', ['name' => 'base', 'label' => $t('calendar.field.base'), 'type' => 'select', 'full' => true, 'value' => $settings['base'], 'options' => ['' => '—'] + $bases, 'error' => $errors['base'] ?? null]) ?>
      <?php $pattern = $settings['pattern']; $legend = $t('calendar.field.pattern'); include __DIR__ . '/_pattern.php'; ?>
      <?= $ui('field', ['name' => 'reason', 'label' => $t('calendar.field.pattern_reason'), 'full' => true, 'value' => $settings['reason'], 'error' => $errors['reason'] ?? null, 'help' => $t('calendar.help.pattern_reason')]) ?>
      <div class="row field--full"><?= $ui('button', ['label' => $t('common.save'), 'type' => 'submit', 'icon' => 'checks']) ?></div>
    </form>
  </section>
  <?php endif; ?>
</div>
