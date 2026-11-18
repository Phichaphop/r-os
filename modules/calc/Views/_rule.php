<?php
/** Which approved table the result uses (7B item 1 — source of the rates). @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed>|null $rule @var bool $canRates */
?>
<section class="card stack-sm" aria-labelledby="rule-h">
  <h2 id="rule-h" class="card__title"><?= $e($t('calc.rule_title')) ?></h2>
  <?php if (($rule['state'] ?? '') === 'ok'): ?>
    <dl class="facts">
      <div><dt><?= $e($t('calc.rule.effective')) ?></dt><dd><?= $e($date((string) $rule['effective_from'], false)) ?></dd></div>
      <div><dt><?= $e($t('calc.rule.source')) ?></dt><dd class="break-all"><?= $e((string) ($rule['source'] ?? '—')) ?></dd></div>
      <div><dt><?= $e($t('calc.rule.approved')) ?></dt><dd><?= $e($date($rule['decided_at'] ?? null, false)) ?> · <span class="mono text-sm"><?= $e((string) $rule['version']) ?></span></dd></div>
    </dl>
  <?php elseif (($rule['state'] ?? '') === 'invalid'): ?>
    <?= $ui('alert', ['tone' => 'danger', 'text' => $t('calc.rule_invalid')]) ?>
  <?php else: ?>
    <?= $ui('alert', ['tone' => 'warning', 'title' => $t('calc.no_rule_title'), 'text' => $t($canRates ? 'calc.no_rule_admin' : 'calc.no_rule_user')]) ?>
    <?php if ($canRates): ?><p><?= $ui('button', ['label' => $t('calc.rates.title'), 'href' => $url('/calc/rates'), 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'settings']) ?></p><?php endif; ?>
  <?php endif; ?>
</section>
