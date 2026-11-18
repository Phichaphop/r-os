<?php
/**
 * Month grid shared by "my work calendar" and the calendar administration page.
 *
 * @var callable $e @var callable $t @var callable $ui @var array<string, mixed> $v
 * @var list<array<string, mixed>> $days @var string $month YYYY-MM @var string $prev @var string $next @var string $today @var string $nav URL prefix for ?month=
 */
$locale = (string) ($v['locale'] ?? 'th');
[$y, $m] = array_map('intval', explode('-', $month));
$monthLabel = $t('calendar.month.' . $m) . ' ' . ($locale === 'th' ? $y + 543 : $y);
$lead = (int) (new DateTimeImmutable($month . '-01'))->format('w');
$cells = array_merge(array_fill(0, $lead, null), $days);
while (count($cells) % 7 !== 0) {
    $cells[] = null;
}
?>
<section class="card" aria-labelledby="m-h">
  <div class="row cal-nav">
    <?= $ui('button', ['label' => $t('calendar.prev'), 'href' => $nav . $prev, 'variant' => 'ghost', 'icon' => 'chevron-left', 'size' => 'sm']) ?>
    <h2 id="m-h" class="card__title"><?= $e($monthLabel) ?></h2>
    <?= $ui('button', ['label' => $t('calendar.next'), 'href' => $nav . $next, 'variant' => 'ghost', 'icon' => 'chevron-right', 'size' => 'sm']) ?>
  </div>
  <div class="table-wrap" role="region" aria-label="<?= $e($monthLabel) ?>" tabindex="0">
  <table class="cal-grid">
    <caption class="sr-only"><?= $e($monthLabel) ?></caption>
    <thead><tr><?php for ($i = 0; $i < 7; $i++): ?><th scope="col"><abbr title="<?= $e($t('calendar.weekday.' . $i)) ?>"><?= $e($t('calendar.weekday_short.' . $i)) ?></abbr></th><?php endfor; ?></tr></thead>
    <tbody>
    <?php foreach (array_chunk($cells, 7) as $week): ?><tr>
      <?php foreach ($week as $c): if ($c === null): ?><td class="cal-day cal-day--empty"></td><?php continue; endif; ?>
        <td class="cal-day cal-day--<?= $e($c['type']) ?><?= $c['date'] === $today ? ' cal-day--today' : '' ?>">
          <span class="cal-day__num"><?= (int) substr($c['date'], 8) ?></span>
          <span class="cal-day__label<?= in_array($c['type'], ['working', 'weekend'], true) && $c['name'] === null ? ' sr-only' : '' ?>"><?= $e($c['name'] ?? $t('calendar.day_type.' . $c['type'])) ?></span>
          <?php if ($c['source'] !== 'pattern' && !$c['own']): ?><span class="cal-day__src text-muted"><?= $e($c['source']) ?></span><?php endif; ?>
        </td>
      <?php endforeach; ?></tr><?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="text-sm text-muted"><?= $e($t('calendar.legend')) ?></p>
</section>
