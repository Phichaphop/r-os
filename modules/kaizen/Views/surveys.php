<?php
/**
 * Surveys for me: open ones to answer (once) and the summaries of analyzed ones.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var list<array<string, mixed>> $rows @var string $here
 * @var bool $canManage @var bool $canSurvey
 */
?>
<?= $ui('page-header', ['title' => $t('kaizen.surveys_title'), 'subtitle' => $t('kaizen.surveys_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<section class="card card--flush" aria-labelledby="kz-s">
  <div class="card__head"><h2 id="kz-s" class="card__title"><?= $e($t('kaizen.surveys_for_me')) ?></h2></div>
  <?php if ($rows === []): ?><div class="card__pad"><?= $ui('states', ['icon' => 'clipboard-check', 'title' => $t('kaizen.surveys_none'), 'text' => $t('kaizen.surveys_none_help')]) ?></div><?php else: ?>
  <ul class="plain-list card__pad stack">
    <?php foreach ($rows as $s): $sst = (string) $s['status']; ?>
    <li class="kz-item">
      <div class="stack-sm">
        <a href="<?= $e($url('/kaizen/surveys/s/' . $s['public_id'])) ?>"><strong><?= $e((string) $s['title']) ?></strong></a>
        <span class="text-sm text-muted"><?= $e($t('kaizen.window', ['from' => $date((string) $s['opens_on'], false), 'to' => $date((string) $s['closes_on'], false)])) ?> · <?= $e((int) $s['anonymous'] === 1 ? $t('kaizen.anonymous_survey') : $t('kaizen.named_survey')) ?></span>
      </div>
      <span><?php if ($sst === 'open'): ?><?= $s['answered'] ? $ui('badge', ['tone' => 'success', 'label' => $t('kaizen.answered')]) : $ui('badge', ['tone' => 'warning', 'label' => $t('kaizen.to_answer')]) ?><?php else: ?><?php include __DIR__ . '/_sstatus.php'; ?><?php endif; ?></span>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
