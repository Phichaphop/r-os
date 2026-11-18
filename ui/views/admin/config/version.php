<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $ver @var array{current: ?\Blue\Core\Registry\ResolvedConfig, retroactive: bool, inheriting: int, warnings: list<string>} $impact
 * @var string $scopeLabel @var bool $isProposer @var bool $canApprove @var \DateTimeZone $tz
 */
use Blue\Core\Registry\ConfigValue;

$value = json_decode((string) $ver['value_json'], true);
$cur = $impact['current'];
$st = (string) $ver['display_status'];
?>
<?= $ui('page-header', ['title' => (string) $ver['config_key'], 'subtitle' => $t('config.version.subtitle'),
    'actions' => $ui('button', ['label' => $t('common.back'), 'href' => $url('/admin/config/key/' . $ver['config_key']), 'variant' => 'ghost', 'icon' => 'arrow-back-up'])]) ?>
<div class="grid grid--2">
  <section class="card" aria-labelledby="vh">
    <div class="card__head"><h2 id="vh" class="card__title"><?= $e($t('config.version.title')) ?></h2><?php include __DIR__ . '/_status.php'; ?></div>
    <dl class="dl">
      <dt><?= $e($t('config.field.value')) ?></dt><dd class="mono"><?= $e(ConfigValue::display($value)) ?> <?= $e((string) $ver['unit']) ?></dd>
      <dt><?= $e($t('config.field.scope')) ?></dt><dd><?= $e($scopeLabel) ?></dd>
      <dt><?= $e($t('config.field.effective')) ?></dt><dd><?= $e($date($ver['effective_from'])) ?> → <?= $ver['effective_to'] !== null ? $e($date($ver['effective_to'])) : $e($t('config.open_ended')) ?></dd>
      <dt><?= $e($t('iam.field.reason')) ?></dt><dd><?= $e((string) $ver['reason']) ?></dd>
      <?php if ($ver['source_reference'] !== null): ?><dt><?= $e($t('config.field.source_reference')) ?></dt><dd><?= $e((string) $ver['source_reference']) ?></dd><?php endif; ?>
      <dt><?= $e($t('config.field.proposed_by')) ?></dt><dd><?= $e((string) $ver['proposer_name']) ?> · <?= $e($date($ver['created_at'])) ?></dd>
      <?php if ($ver['decider_name'] !== null): ?><dt><?= $e($t('config.field.decided_by')) ?></dt><dd><?= $e((string) $ver['decider_name']) ?> · <?= $e($date($ver['decided_at'])) ?></dd><?php endif; ?>
      <?php if ($ver['decision_comment'] !== null): ?><dt><?= $e($t('workflow.field.comment')) ?></dt><dd><?= $e((string) $ver['decision_comment']) ?></dd><?php endif; ?>
    </dl>
    <?php if ($ver['workflow_public_id'] !== null): ?><p><a href="<?= $e($url('/requests/' . $ver['workflow_public_id'])) ?>"><?= $e($t('config.version.workflow')) ?></a></p><?php endif; ?>
  </section>

  <section class="card" aria-labelledby="ih">
    <h2 id="ih" class="card__title"><?= $e($t('config.impact.title')) ?></h2>
    <dl class="dl">
      <dt><?= $e($t('config.impact.before')) ?></dt>
      <dd><?= $cur === null ? $e($t('config.no_value')) : '<span class="mono">' . $e(ConfigValue::display($cur->value)) . '</span> (' . $e($t('config.scope.' . $cur->sourceScope)) . ')' ?></dd>
      <dt><?= $e($t('config.impact.after')) ?></dt><dd class="mono"><?= $e(ConfigValue::display($value)) ?></dd>
      <dt><?= $e($t('config.impact.inheriting')) ?></dt><dd><?= $e($t('config.impact.inheriting_n', ['n' => $impact['inheriting']])) ?></dd>
    </dl>
    <?php foreach ($impact['warnings'] as $w): ?><?= $ui('alert', ['tone' => 'warning', 'text' => $t($w)]) ?><?php endforeach; ?>
    <p class="text-sm text-muted"><?= $e($t('config.impact.note')) ?></p>
  </section>
</div>

<?php if ($isProposer && $ver['status'] === 'draft'): ?>
<section class="card row">
  <form method="post" action="<?= $e($url('/admin/config/version/' . $ver['public_id'] . '/submit')) ?>"><input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?= $ui('button', ['label' => $t('config.version.submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></form>
  <form method="post" action="<?= $e($url('/admin/config/version/' . $ver['public_id'] . '/cancel')) ?>" class="row"><input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <label class="sr-only" for="c-reason"><?= $e($t('iam.field.reason')) ?></label><input class="input" id="c-reason" name="reason" required placeholder="<?= $e($t('iam.field.reason')) ?>">
    <?= $ui('button', ['label' => $t('config.version.discard'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'trash']) ?></form>
</section>
<?php elseif ($canApprove && $st === 'scheduled'): ?>
<section class="card">
  <form method="post" action="<?= $e($url('/admin/config/version/' . $ver['public_id'] . '/cancel')) ?>" class="row"><input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <label class="sr-only" for="c-reason2"><?= $e($t('iam.field.reason')) ?></label><input class="input" id="c-reason2" name="reason" required placeholder="<?= $e($t('iam.field.reason')) ?>">
    <?= $ui('button', ['label' => $t('config.version.cancel_scheduled'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'x']) ?></form>
</section>
<?php endif; ?>
