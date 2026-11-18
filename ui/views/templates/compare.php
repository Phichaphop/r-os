<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var list<array{0: string, 1: string}> $diff @var array<string, mixed> $from @var array<string, mixed> $to
 */
?>
<?= $ui('page-header', ['title' => $t('template.compare.title'), 'subtitle' => 'v' . (int) $from['version_no'] . ' → v' . (int) $to['version_no'] . ' · ' . (string) $from['template_name'],
    'crumbs' => [[$t('template.title'), $url('/templates')], [(string) $from['template_name'], $url('/templates/' . $from['template_public_id'])], [$t('template.compare.title'), null]]]) ?>
<section class="card">
  <p class="text-sm text-muted"><?= $e($t('template.compare.legend')) ?></p>
  <ol class="linediff">
  <?php foreach ($diff as [$op, $line]): ?>
    <li class="linediff__line linediff__line--<?= $op === '+' ? 'add' : ($op === '-' ? 'del' : 'same') ?>"><span class="linediff__op" aria-hidden="true"><?= $op === '=' ? ' ' : $e($op) ?></span>
      <span class="sr-only"><?= $e($t($op === '+' ? 'template.compare.op_add' : ($op === '-' ? 'template.compare.op_del' : 'template.compare.op_same'))) ?></span><code><?= $e($line) ?></code></li>
  <?php endforeach; ?>
  <?php if ($diff === []): ?><li><?= $e($t('template.compare.same')) ?></li><?php endif; ?>
  </ol>
</section>
