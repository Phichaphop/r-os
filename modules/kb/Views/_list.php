<?php
/**
 * A list of published answers. Needs $items.
 * @var callable $e @var callable $t @var callable $url @var callable $ui @var array<string, mixed> $v @var list<array<string, mixed>> $items
 */
$loc = (string) ($v['locale'] ?? 'th');
?>
<ul class="plain-list stack-sm kb-list">
<?php foreach ($items as $it): $qt = $loc === 'en' && $it['question_en'] !== null ? (string) $it['question_en'] : (string) $it['question_th']; ?>
  <li><a href="<?= $e($url('/kb/a/' . $it['public_id'])) ?>"><?= $e($qt) ?></a> <span class="text-sm text-muted"><?= $e(($loc === 'en' && $it['category_en'] !== null ? $it['category_en'] : $it['category_th']) . ' · ' . $t('kb.views_n', ['n' => (string) $it['views']])) ?></span></li>
<?php endforeach; ?>
</ul>
