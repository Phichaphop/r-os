<?php
/**
 * "Work calendar" for every employee (0.6.1): the calendar that applies to me, this month, today and this year's
 * holidays. Read-only — calendars are managed under Administration → Work calendars.
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $partial @var array<string, mixed> $v
 * @var array<string, mixed>|null $cal @var string $via @var bool $isMine @var string $month @var list<array<string, mixed>> $days
 * @var array<string, int> $count @var string $today @var array<string, mixed>|null $todayInfo @var string $prev @var string $next
 * @var int $year @var list<array<string, mixed>> $exceptions @var array<string, string> $others @var bool $canManage
 */
use Blue\Core\I18n\DateFormat;

$locale = (string) ($v['locale'] ?? 'th');
$beYear = $locale === 'th' ? $year + 543 : $year;
$actions = $canManage ? $ui('button', ['label' => $t('calendar.manage_title'), 'href' => $url('/admin/calendars'), 'variant' => 'secondary', 'icon' => 'settings']) : '';
$navBase = $url('/calendars') . '?' . ($cal !== null && !$isMine ? 'cal=' . rawurlencode((string) $cal['public_id']) . '&' : '') . 'month=';
?>
<?= $ui('page-header', ['title' => $t('calendar.title'), 'subtitle' => $t('calendar.mine_subtitle'), 'actions' => $actions]) ?>

<?php if ($others !== [] && count($others) > 1): ?>
<form method="get" action="<?= $e($url('/calendars')) ?>" class="filter-bar" aria-label="<?= $e($t('calendar.pick')) ?>">
  <?= $ui('field', ['name' => 'cal', 'label' => $t('calendar.pick'), 'type' => 'select', 'value' => $cal !== null ? (string) $cal['public_id'] : '', 'options' => $others]) ?>
  <input type="hidden" name="month" value="<?= $e($month) ?>">
  <?= $ui('button', ['label' => $t('calendar.pick_show'), 'type' => 'submit', 'icon' => 'eye']) ?>
  <?php if (!$isMine): ?><?= $ui('button', ['label' => $t('calendar.back_mine'), 'href' => $url('/calendars'), 'variant' => 'ghost', 'icon' => 'arrow-back-up']) ?><?php endif; ?>
</form>
<?php endif; ?>

<?php if ($cal === null): ?>
  <?= $ui('states', ['icon' => 'calendar-event', 'title' => $t('calendar.mine_none'), 'text' => $t($canManage ? 'calendar.mine_none_admin' : 'calendar.mine_none_text')]) ?>
<?php else: ?>
<section class="card cal-mine-head" aria-labelledby="cal-h">
  <div class="cal-mine-head__main">
    <h2 id="cal-h" class="card__title"><?= $e((string) $cal['name']) ?> <span class="text-sm text-muted mono"><?= $e((string) $cal['code']) ?></span></h2>
    <p class="text-sm text-muted"><?= $e($t('calendar.via.' . $via)) ?></p>
    <div class="row" aria-label="<?= $e($t('calendar.field.pattern')) ?>">
      <?php for ($i = 0; $i < 7; $i++): ?><span class="wd-chip<?= $cal['weekly_pattern'][$i] === '1' ? ' wd-chip--on' : '' ?>" title="<?= $e($t('calendar.weekday.' . $i)) ?>"><?= $e($t('calendar.weekday_short.' . $i)) ?></span><?php endfor; ?>
      <span class="sr-only"><?= $e(implode(', ', array_map(static fn ($i) => $t('calendar.weekday.' . $i), array_keys(array_filter(str_split((string) $cal['weekly_pattern']), static fn ($x) => $x === '1'))))) ?></span>
    </div>
  </div>
  <?php if ($todayInfo !== null): ?>
  <div class="cal-today cal-today--<?= $e($todayInfo['type']) ?>">
    <span class="cal-today__label"><?= $e($t('calendar.today')) ?> · <?= $e(DateFormat::day($today, $locale)) ?></span>
    <strong class="cal-today__value"><?= $e($todayInfo['name'] ?? $t('calendar.day_type.' . $todayInfo['type'])) ?></strong>
  </div>
  <?php endif; ?>
</section>

<section class="grid grid--3" aria-label="<?= $e($t('calendar.month_summary')) ?>">
  <?php foreach (['working' => 'briefcase', 'holiday' => 'gift', 'weekend' => 'sun'] as $k => $icon): ?>
  <div class="card stack-sm cal-stat cal-stat--<?= $e($k) ?>">
    <div class="stat__label"><?= $ui('icon', ['name' => $icon, 'size' => 'sm']) ?> <?= $e($t('calendar.count.' . $k)) ?></div>
    <div class="stat__value"><?= (int) ($count[$k] ?? 0) ?> <span class="text-sm text-muted"><?= $e($t('calendar.days_unit')) ?></span></div>
  </div>
  <?php endforeach; ?>
</section>

<div class="cal-mine">
  <?= $partial('calendars/_month', ['days' => $days, 'month' => $month, 'prev' => $prev, 'next' => $next, 'today' => $today, 'nav' => $navBase]) ?>
  <section class="card" aria-labelledby="hol-h">
    <h2 id="hol-h" class="card__title"><?= $e($t('calendar.mine_holidays', ['year' => (string) $beYear])) ?></h2>
    <?php if ($exceptions === []): ?>
      <p class="text-muted"><?= $e($t('calendar.no_exceptions')) ?></p>
    <?php else: ?>
    <ul class="cal-hol-list">
      <?php foreach ($exceptions as $x): $past = $x['date'] < $today; ?>
      <li class="cal-hol<?= $past ? ' cal-hol--past' : '' ?><?= $x['date'] === $today ? ' cal-hol--today' : '' ?>">
        <span class="cal-hol__date"><?= $e(DateFormat::day($x['date'], $locale)) ?></span>
        <span class="cal-hol__name"><?= $e($x['name'] ?? $t('calendar.day_type.' . $x['type'])) ?></span>
        <span class="cal-hol__kind"><?= $ui('badge', ['tone' => $x['type'] === 'working' ? 'info' : ($x['kind'] === 'public' ? 'success' : 'neutral'),
            'label' => $x['kind'] !== null ? $t('calendar.kind.' . $x['kind']) : $t('calendar.day_type.' . $x['type'])]) ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <p class="text-sm text-muted"><?= $e($t('calendar.no_public_holidays_seeded')) ?></p>
  </section>
</div>
<?php endif; ?>
