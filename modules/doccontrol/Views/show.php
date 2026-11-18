<?php
/** Read + acknowledge. @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var array<string, mixed> $doc @var array<string, string> $errors */
use Blue\Core\I18n\DateFormat;

$loc = (string) ($v['locale'] ?? 'th');
$ver = $doc['version'];
$ack = $doc['my_ack'];
?>
<?= $ui('page-header', ['title' => (string) $doc['title'], 'subtitle' => $doc['code'] . ' · ' . $t('doccontrol.type.' . $doc['doc_type']), 'crumbs' => [[$t('doccontrol.title'), $url('/policies')], [(string) $doc['code'], null]],
    'actions' => $doc['can_manage'] ? $ui('button', ['label' => $t('doccontrol.manage'), 'href' => $url('/policies/admin/d/' . $doc['public_id']), 'variant' => 'secondary', 'icon' => 'settings']) : '']) ?>
<?php if ($ver === null): ?>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('doccontrol.no_effective')]) ?>
<?php else: ?>
<section class="card stack" aria-labelledby="vd-h">
  <h2 id="vd-h" class="card__title"><?= $e($t('doccontrol.current_version', ['n' => (string) $ver['version_no']])) ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('doccontrol.field.effective_from')) ?></dt><dd><?= $e(DateFormat::day((string) $ver['effective_from'], $loc)) ?></dd></div>
    <div><dt><?= $e($t('doccontrol.field.change_summary')) ?></dt><dd><?= $e((string) $ver['change_summary']) ?></dd></div>
    <div><dt><?= $e($t('doccontrol.field.owner')) ?></dt><dd><?= $e((string) ($doc['owner_name'] ?? '—')) ?></dd></div>
  </dl>
  <div class="row"><a class="btn btn--primary" href="<?= $e($url('/policies/d/' . $doc['public_id'] . '/file')) ?>" target="_blank" rel="noopener"><?= $ui('icon', ['name' => 'file-type-pdf']) ?><span><?= $e($t('doccontrol.open_file')) ?></span><span class="sr-only"> (<?= $e($t('home.new_tab')) ?>)</span></a></div>
  <p class="text-sm text-muted"><?= $e($t('doccontrol.only_current')) ?></p>
</section>
<?php if ($ack !== null): ?>
<section class="card stack-sm" aria-labelledby="ak-h">
  <h2 id="ak-h" class="card__title"><?= $e($t('doccontrol.acknowledgement')) ?></h2>
  <?php if ($ack['status'] === 'acknowledged'): ?>
    <p><?= $ui('badge', ['tone' => 'success', 'icon' => 'circle-check', 'label' => $t('doccontrol.acked_at', ['at' => DateFormat::day(substr((string) $ack['acknowledged_at'], 0, 10), $loc) . ' ' . substr((string) $ack['acknowledged_at'], 11, 5)])]) ?></p>
    <p class="text-sm text-muted"><?= $e((string) $ack['statement']) ?></p>
  <?php elseif ($ack['status'] === 'exception'): ?>
    <p><?= $ui('badge', ['tone' => 'neutral', 'label' => $t('doccontrol.ack_status.exception')]) ?> <?= $e((string) $ack['exception_reason']) ?></p>
  <?php else: ?>
    <?php if ($ack['opened_at'] === null): ?><?= $ui('alert', ['tone' => 'info', 'text' => $t('doccontrol.open_first_help')]) ?><?php endif; ?>
    <form method="post" action="<?= $e($url('/policies/d/' . $doc['public_id'] . '/ack')) ?>" class="stack-sm" novalidate>
      <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
      <label class="check"><input type="checkbox" name="confirm" value="1"<?= isset($errors['confirm']) ? ' aria-invalid="true" aria-describedby="ak-err"' : '' ?>> <?= $e($t('doccontrol.statement', ['code' => (string) $doc['code'], 'n' => (string) $ver['version_no']])) ?></label>
      <?php if (isset($errors['confirm'])): ?><p class="field__error" id="ak-err"><?= $e($errors['confirm']) ?></p><?php endif; ?>
      <?= $ui('button', ['label' => $t('doccontrol.acknowledge'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?>
    </form>
    <p class="text-sm text-muted"><?= $e($t('doccontrol.evidence_note')) ?></p>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php endif; ?>
