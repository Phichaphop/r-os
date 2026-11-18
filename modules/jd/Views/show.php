<?php
/**
 * Read a JD version (+ print), and acknowledge it when it is mine and pending.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var callable $asset @var array<string, mixed> $v
 * @var array<string, mixed> $ver @var array<string, string> $errors
 */
$ack = $ver['my_ack'];
$langs = array_values(array_filter(['th', 'en'], static fn (string $l): bool => isset($ver['content'][$l])));
$want = (string) ($v['locale'] ?? 'th');
$lang = in_array($want, $langs, true) ? $want : ($langs[0] ?? 'th');
?>
<?= $ui('page-header', ['title' => (string) $ver['position_title'], 'subtitle' => $t('jd.version_n', ['n' => (string) $ver['version_no']]) . ' · ' . $t('jd.status.' . $ver['status']),
    'crumbs' => [[$t('jd.title'), $url('/jd')], [(string) $ver['code'], null]],
    'actions' => $ui('button', ['label' => $t('jd.print'), 'type' => 'button', 'variant' => 'secondary', 'icon' => 'printer', 'attrs' => ['data-print' => '1']])
        . ($ver['can_manage'] ? $ui('button', ['label' => $t('jd.manage'), 'href' => $url('/jd/admin/p/' . $ver['profile_pid']), 'variant' => 'ghost', 'icon' => 'settings']) : '')]) ?>
<?php if ($ver['status'] !== 'effective'): ?><?= $ui('alert', ['tone' => 'info', 'text' => $t('jd.not_current')]) ?><?php endif; ?>
<?php include __DIR__ . '/_content.php'; ?>
<?php if (count($langs) > 1): ?>
  <?php $lang = $lang === 'th' ? 'en' : 'th'; ?>
  <details class="card stack-sm print-hide"><summary class="card__title"><?= $e($t('jd.other_language')) ?></summary><?php include __DIR__ . '/_content.php'; ?></details>
<?php endif; ?>
<?php if ($ack !== null): ?>
<section class="card stack-sm print-hide" aria-labelledby="ja-h">
  <h2 id="ja-h" class="card__title"><?= $e($t('jd.acknowledgement')) ?></h2>
  <?php if ($ack['status'] === 'acknowledged'): ?>
    <p><?= $ui('badge', ['tone' => 'success', 'icon' => 'circle-check', 'label' => $t('jd.acked_at', ['at' => $date((string) $ack['acknowledged_at'])])]) ?></p>
    <p class="text-sm text-muted"><?= $e((string) $ack['statement']) ?></p>
  <?php elseif ($ack['status'] === 'exception'): ?>
    <p><?= $ui('badge', ['tone' => 'neutral', 'label' => $t('jd.ack_status.exception')]) ?> <?= $e((string) $ack['exception_reason']) ?></p>
  <?php elseif ($ver['status'] === 'effective'): ?>
    <form method="post" action="<?= $e($url('/jd/v/' . $ver['public_id'] . '/ack')) ?>" class="stack-sm" novalidate>
      <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
      <label class="check"><input type="checkbox" name="confirm" value="1"<?= isset($errors['confirm']) ? ' aria-invalid="true" aria-describedby="ja-err"' : '' ?>> <?= $e($t('jd.statement', ['title' => (string) $ver['position_title'], 'n' => (string) $ver['version_no']])) ?></label>
      <?php if (isset($errors['confirm'])): ?><p class="field__error" id="ja-err"><?= $e($errors['confirm']) ?></p><?php endif; ?>
      <?= $ui('button', ['label' => $t('jd.acknowledge'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?>
    </form>
    <p class="text-sm text-muted"><?= $e($t('jd.evidence_note')) ?></p>
  <?php endif; ?>
</section>
<?php endif; ?>
<script src="<?= $e($asset('ui/js/print.js')) ?>" defer></script>
