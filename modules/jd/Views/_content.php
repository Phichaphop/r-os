<?php
/**
 * The JD sheet (A4 when printed). @var callable $e @var callable $t @var callable $date @var array<string, mixed> $ver @var string $lang
 */
use Blue\Modules\Jd\JdService;

$c = (array) ($ver['content'][$lang] ?? []);
?>
<article class="paper-doc" lang="<?= $e($lang) ?>" aria-label="<?= $e($t('jd.sheet')) ?>">
  <header class="paper-doc__head">
    <p class="paper-doc__kicker"><?= $e($t('jd.sheet')) ?> · <?= $e((string) $ver['code']) ?></p>
    <h2 class="paper-doc__title"><?= $e((string) $ver['position_title']) ?></h2>
    <p class="paper-doc__meta"><?= $e($t('jd.version_n', ['n' => (string) $ver['version_no']])) ?> · <?= $e($t('jd.effective_on', ['d' => $date((string) $ver['effective_from'], false)])) ?><?= $ver['dept_code'] !== null ? ' · ' . $e((string) $ver['dept_code']) : '' ?></p>
  </header>
  <?php foreach (JdService::SECTIONS as $s): if (($c[$s] ?? '') === '') { continue; } ?>
    <section><h3><?= $e($t('jd.section.' . $s)) ?></h3>
      <?php if ($s === 'summary'): ?><p class="pre-line"><?= $e((string) $c[$s]) ?></p><?php else: ?>
      <ul><?php foreach (array_filter(array_map('trim', explode("\n", (string) $c[$s])), static fn (string $x): bool => $x !== '') as $line): ?><li><?= $e(ltrim($line, "-•* \t")) ?></li><?php endforeach; ?></ul>
      <?php endif; ?></section>
  <?php endforeach; ?>
  <footer class="paper-doc__foot"><?= $e($t('jd.hash', ['h' => substr((string) $ver['content_sha256'], 0, 16)])) ?></footer>
</article>
