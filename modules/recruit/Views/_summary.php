<?php
/** Education, work history, languages and skills of an application. @var callable $e @var callable $t @var array<string, mixed> $sum */
?>
<h3 class="text-sm"><?= $e($t('recruit.step.education')) ?></h3>
<?php if (($sum['education'] ?? []) === []): ?><p class="text-sm text-muted">—</p><?php else: ?>
<ul class="plain-list text-sm"><?php foreach ((array) $sum['education'] as $row): ?>
  <li><?= $e(($row['level'] !== '' ? $t('recruit.edu.' . $row['level']) . ' · ' : '') . $row['institution'] . ($row['major'] !== '' ? ' · ' . $row['major'] : '') . ($row['year'] !== '' ? ' · ' . $row['year'] : '') . ($row['gpa'] !== '' ? ' · GPA ' . $row['gpa'] : '')) ?></li>
<?php endforeach; ?></ul><?php endif; ?>
<h3 class="text-sm"><?= $e($t('recruit.step.work')) ?></h3>
<?php if (($sum['work'] ?? []) === []): ?><p class="text-sm text-muted">—</p><?php else: ?>
<ul class="plain-list text-sm"><?php foreach ((array) $sum['work'] as $row): ?>
  <li><?= $e($row['company'] . ($row['position'] !== '' ? ' · ' . $row['position'] : '') . ' · ' . ($row['from'] !== '' ? $row['from'] : '?') . ' – ' . ($row['to'] !== '' ? $row['to'] : $t('recruit.now'))) ?></li>
<?php endforeach; ?></ul><?php endif; ?>
<h3 class="text-sm"><?= $e($t('recruit.step.skills')) ?></h3>
<p class="text-sm"><?= $e($t('recruit.lang_th') . ': ' . (($sum['lang_th'] ?? '') !== '' ? $t('recruit.lang.' . $sum['lang_th']) : '—') . ' · ' . $t('recruit.lang_en') . ': ' . (($sum['lang_en'] ?? '') !== '' ? $t('recruit.lang.' . $sum['lang_en']) : '—')) ?></p>
<?php if (($sum['skills'] ?? '') !== ''): ?><p class="text-sm pre-line"><?= $e((string) $sum['skills']) ?></p><?php endif; ?>
