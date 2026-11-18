<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, int> $counts @var array<string, int> $journals @var list<array<string, mixed>> $recent @var int $le @var bool $canManage
 */
$money = static fn ($v): string => \Blue\Core\Support\Money::format(\Blue\Core\Support\Money::fromDb($v));
?>
<?= $ui('page-header', ['title' => $t('finance.title'), 'subtitle' => $t('finance.subtitle'),
    'actions' => $canManage ? $ui('button', ['label' => $t('finance.journal.new'), 'href' => $url('/finance/journals/new?le=' . $le), 'variant' => 'primary', 'icon' => 'plus']) : '']) ?>
<?php include __DIR__ . '/_tabs.php'; ?>
<section class="card">
  <div class="grid grid--4">
    <a class="stat-link" href="<?= $e($url('/finance/requests?le=' . $le . '&status=needs_attention')) ?>"><div class="stat__label"><?= $e($t('finance.stat.waiting')) ?></div>
      <div class="stat__value<?= ($counts['needs_attention'] ?? 0) > 0 ? ' text-danger' : '' ?>"><?= (int) ($counts['needs_attention'] ?? 0) ?></div></a>
    <a class="stat-link" href="<?= $e($url('/finance/journals?le=' . $le . '&status=draft')) ?>"><div class="stat__label"><?= $e($t('finance.stat.drafts')) ?></div><div class="stat__value"><?= (int) ($journals['draft'] ?? 0) + (int) ($journals['returned'] ?? 0) ?></div></a>
    <a class="stat-link" href="<?= $e($url('/finance/journals?le=' . $le . '&status=in_review')) ?>"><div class="stat__label"><?= $e($t('finance.stat.in_review')) ?></div><div class="stat__value"><?= (int) ($journals['in_review'] ?? 0) ?></div></a>
    <a class="stat-link" href="<?= $e($url('/finance/journals?le=' . $le . '&status=posted')) ?>"><div class="stat__label"><?= $e($t('finance.stat.posted')) ?></div><div class="stat__value"><?= (int) ($journals['posted'] ?? 0) + (int) ($journals['reversed'] ?? 0) ?></div></a>
  </div>
</section>
<section class="card" aria-labelledby="how-h">
  <h2 id="how-h" class="card__title"><?= $e($t('finance.how.title')) ?></h2>
  <ol class="steps-inline">
    <li><?= $e($t('finance.how.1')) ?></li><li><?= $e($t('finance.how.2')) ?></li><li><?= $e($t('finance.how.3')) ?></li><li><?= $e($t('finance.how.4')) ?></li>
  </ol>
</section>
<section class="card" aria-labelledby="recent-h">
  <h2 id="recent-h" class="card__title"><?= $e($t('finance.recent')) ?></h2>
  <?php include __DIR__ . '/_journal_table.php'; ?>
</section>
