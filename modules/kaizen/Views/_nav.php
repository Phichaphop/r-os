<?php
/**
 * Kaizen section links.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var string $here @var bool $canManage @var bool $canSurvey
 */
$links = ['/kaizen' => ['kaizen.tab.ideas', 'pencil'], '/kaizen/surveys' => ['kaizen.tab.surveys', 'clipboard-check']];
if ($canManage) {
    $links['/kaizen/admin'] = ['kaizen.tab.admin', 'list-check'];
    $links['/kaizen/report'] = ['kaizen.tab.report', 'layout-dashboard'];
}
if ($canSurvey) {
    $links['/kaizen/surveys/admin'] = ['kaizen.tab.survey_admin', 'layout-dashboard'];
}
if ($canManage) {
    $links['/kaizen/settings'] = ['kaizen.tab.settings', 'settings'];
}
?>
<nav class="segmented-links" aria-label="<?= $e($t('kaizen.sections')) ?>">
  <?php foreach ($links as $href => [$label, $icon]): ?><a href="<?= $e($url($href)) ?>"<?= $href === $here ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => $icon, 'size' => 'sm']) ?> <?= $e($t($label)) ?></a><?php endforeach; ?>
</nav>
