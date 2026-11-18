<?php
/**
 * My JD: the JD of my current position (to read and acknowledge) + every JD I acknowledged before.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date
 * @var array{current: array<string, mixed>|null, history: list<array<string, mixed>>} $mine @var bool $canManage
 */
$cur = $mine['current'];
?>
<?= $ui('page-header', ['title' => $t('jd.title'), 'subtitle' => $t('jd.subtitle'),
    'actions' => $canManage ? $ui('button', ['label' => $t('jd.nav_admin'), 'href' => $url('/jd/admin'), 'variant' => 'secondary', 'icon' => 'settings']) : '']) ?>
<section class="card stack-sm" aria-labelledby="jm-c">
  <h2 id="jm-c" class="card__title"><?= $e($t('jd.my_current')) ?></h2>
  <?php if ($cur === null): ?>
    <p class="text-sm text-muted"><?= $e($t('jd.none_for_me')) ?></p>
  <?php else: ?>
    <p><strong><?= $e((string) $cur['position_title']) ?></strong> · <?= $e($t('jd.version_n', ['n' => (string) $cur['version_no']])) ?> · <?= $e($t('jd.effective_on', ['d' => $date((string) $cur['effective_from'], false)])) ?></p>
    <?php if ($cur['ack_status'] === 'pending'): ?>
      <?= $ui('alert', ['tone' => 'warning', 'text' => $t('jd.must_ack')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('jd.read_ack'), 'href' => $url('/jd/v/' . $cur['public_id']), 'variant' => 'primary', 'icon' => 'file-text']) ?></div>
    <?php else: ?>
      <p><?= $ui('badge', ['tone' => $cur['ack_status'] === 'acknowledged' ? 'success' : 'neutral', 'label' => $t('jd.ack_status.' . ($cur['ack_status'] ?? 'pending'))]) ?>
        <?= $cur['acknowledged_at'] !== null ? $e($date((string) $cur['acknowledged_at'])) : '' ?></p>
      <div class="row"><?= $ui('button', ['label' => $t('jd.open'), 'href' => $url('/jd/v/' . $cur['public_id']), 'variant' => 'secondary', 'icon' => 'file-text']) ?></div>
    <?php endif; ?>
  <?php endif; ?>
</section>
<section class="card stack-sm" aria-labelledby="jm-h">
  <h2 id="jm-h" class="card__title"><?= $e($t('jd.history')) ?></h2>
  <?php if ($mine['history'] === []): ?><p class="text-sm text-muted"><?= $e($t('jd.no_history')) ?></p><?php else: ?>
  <ul class="plain-list stack-sm">
    <?php foreach ($mine['history'] as $h): ?>
      <li><a href="<?= $e($url('/jd/v/' . $h['public_id'])) ?>"><?= $e((string) $h['position_title']) ?></a> · <?= $e($t('jd.version_n', ['n' => (string) $h['version_no']])) ?>
        <?= $h['version_status'] !== 'effective' ? $ui('badge', ['tone' => 'neutral', 'label' => $t('jd.status.' . $h['version_status'])]) : '' ?>
        <span class="d-block text-sm text-muted"><?= $e($t('jd.ack_status.' . $h['status'])) ?><?= $h['acknowledged_at'] !== null ? ' · ' . $e($date((string) $h['acknowledged_at'])) : '' ?></span></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
