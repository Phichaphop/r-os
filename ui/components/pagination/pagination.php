<?php
/**
 * Shared pagination (6.10.1). Props: path (list URL path), query (current search/filter/sort to keep),
 * page, per, total. Renders nothing when there are no rows (the page shows its empty state instead).
 *
 * @var array<string, mixed> $p @var callable $e @var callable $t @var callable $ui @var callable $url
 */
$total = (int) ($p['total'] ?? 0);
if ($total === 0) {
    return;
}
$per = (int) ($p['per'] ?? 20);
$page = (int) ($p['page'] ?? 1);
$last = max(1, (int) ceil($total / $per));
$query = array_filter((array) ($p['query'] ?? []), static fn ($v) => $v !== '' && $v !== null);
$href = static fn (int $n): string => $url((string) $p['path']) . '?' . http_build_query($query + ['per' => $per, 'page' => $n]);
$from = ($page - 1) * $per + 1;
$to = min($total, $page * $per);
?>
<div class="pagination">
  <form method="get" action="<?= $e($url((string) $p['path'])) ?>" class="pagination__size">
    <?php foreach ($query as $k => $v): ?><input type="hidden" name="<?= $e((string) $k) ?>" value="<?= $e((string) $v) ?>"><?php endforeach; ?>
    <label for="pg-per"><?= $e($t('page.show')) ?></label>
    <select id="pg-per" name="per" class="select pagination__select" data-autosubmit>
      <?php foreach (\Blue\Core\Ui\Paging::SIZES as $s): ?><option value="<?= $s ?>"<?= $s === $per ? ' selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
    </select>
    <span><?= $e($t('page.items')) ?></span>
    <noscript><button class="btn btn--secondary btn--sm" type="submit"><?= $e($t('common.apply')) ?></button></noscript>
  </form>
  <p class="pagination__range" aria-live="polite"><?= $e($t('page.range', ['from' => number_format($from), 'to' => number_format($to), 'total' => number_format($total)])) ?></p>
  <?php if ($last > 1): ?>
  <nav aria-label="<?= $e($t('page.nav')) ?>">
    <ul class="pagination__pages">
      <li><?php if ($page > 1): ?><a class="pagination__link" href="<?= $e($href($page - 1)) ?>" rel="prev"><?= $ui('icon', ['name' => 'chevron-left', 'size' => 'sm']) ?><span><?= $e($t('common.prev')) ?></span></a>
          <?php else: ?><span class="pagination__link is-disabled" aria-disabled="true"><?= $ui('icon', ['name' => 'chevron-left', 'size' => 'sm']) ?><span><?= $e($t('common.prev')) ?></span></span><?php endif; ?></li>
      <?php foreach (\Blue\Core\Ui\Paging::window($page, $last) as $n): ?>
        <li><?php if ($n === null): ?><span class="pagination__gap" aria-hidden="true">…</span>
            <?php elseif ($n === $page): ?><span class="pagination__link is-current" aria-current="page"><?= $n ?></span>
            <?php else: ?><a class="pagination__link" href="<?= $e($href($n)) ?>" aria-label="<?= $e($t('page.go', ['n' => $n])) ?>"><?= $n ?></a><?php endif; ?></li>
      <?php endforeach; ?>
      <li><?php if ($page < $last): ?><a class="pagination__link" href="<?= $e($href($page + 1)) ?>" rel="next"><span><?= $e($t('common.next')) ?></span><?= $ui('icon', ['name' => 'chevron-right', 'size' => 'sm']) ?></a>
          <?php else: ?><span class="pagination__link is-disabled" aria-disabled="true"><span><?= $e($t('common.next')) ?></span><?= $ui('icon', ['name' => 'chevron-right', 'size' => 'sm']) ?></span><?php endif; ?></li>
    </ul>
  </nav>
  <?php endif; ?>
</div>
