<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var string $class @var list<array<string, mixed>> $history @var array<string, mixed>|null $current @var bool $canManage
 * @var array<string, string> $errors @var array<string, string> $old @var int $userId @var string $tab
 */
use Blue\Core\Records\DocumentCatalog;

$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
?>
<?= $ui('page-header', ['title' => $t('records.class.' . $class), 'subtitle' => $class,
    'crumbs' => [[$t('records.title'), $url('/admin/records')], [$t('records.class.' . $class), null]]]) ?>
<?php include __DIR__ . '/_tabs.php'; ?>
<?php if (isset($errors['form'])): ?><?= $ui('alert', ['tone' => 'danger', 'text' => $errors['form'], 'live' => true]) ?><?php endif; ?>
<div class="grid grid--2">
  <section class="card" aria-labelledby="cur-h">
    <h2 id="cur-h" class="card__title"><?= $e($t('records.field.in_force')) ?></h2>
    <?php if ($current === null): ?><p><?= $e($t('records.no_rule_help')) ?></p><?php else: ?>
      <p class="kpi"><?= $e($t('records.months_n', ['n' => (int) $current['duration_months']])) ?></p>
      <dl class="dl">
        <dt><?= $e($t('records.field.trigger')) ?></dt><dd><?= $e($t('records.trigger.' . $current['trigger_event'])) ?></dd>
        <dt><?= $e($t('records.field.action')) ?></dt><dd><?= $e($t('records.action.' . $current['disposition_action'])) ?></dd>
        <dt><?= $e($t('records.field.source')) ?></dt><dd><?= $e($current['source_reference'] ?? '—') ?></dd>
        <dt><?= $e($t('records.field.effective_from')) ?></dt><dd><?= $e($current['effective_from']) ?></dd>
      </dl>
    <?php endif; ?>
  </section>
  <?php if ($canManage): ?>
  <section class="card" aria-labelledby="prop-h">
    <h2 id="prop-h" class="card__title"><?= $e($t('records.propose.title')) ?></h2>
    <p class="text-sm text-muted"><?= $e($t('records.propose.help')) ?></p>
    <form method="post" action="<?= $e($url('/admin/records/rules/' . $class)) ?>" class="form-grid" novalidate><?= $csrf ?>
      <?= $ui('field', ['name' => 'months', 'label' => $t('records.field.months'), 'required' => true, 'inputmode' => 'numeric', 'value' => $old['months'], 'error' => $errors['months'] ?? null]) ?>
      <?= $ui('field', ['name' => 'trigger', 'label' => $t('records.field.trigger'), 'type' => 'select', 'value' => $old['trigger'], 'error' => $errors['trigger'] ?? null,
          'options' => array_combine(DocumentCatalog::TRIGGERS, array_map(static fn ($x) => $t('records.trigger.' . $x), DocumentCatalog::TRIGGERS))]) ?>
      <?= $ui('field', ['name' => 'action', 'label' => $t('records.field.action'), 'type' => 'select', 'value' => $old['action'],
          'options' => array_combine(['review', 'destroy', 'anonymize', 'archive'], array_map(static fn ($x) => $t('records.action.' . $x), ['review', 'destroy', 'anonymize', 'archive']))]) ?>
      <?= $ui('field', ['name' => 'effective_from', 'label' => $t('records.field.effective_from'), 'type' => 'date', 'required' => true, 'value' => $old['effective_from'], 'error' => $errors['effective_from'] ?? null]) ?>
      <?= $ui('field', ['name' => 'source', 'label' => $t('records.field.source'), 'full' => true, 'value' => $old['source'], 'error' => $errors['source'] ?? null, 'help' => $t('records.help.source')]) ?>
      <?= $ui('field', ['name' => 'note', 'label' => $t('records.field.note'), 'type' => 'textarea', 'full' => true, 'value' => $old['note']]) ?>
      <?= $ui('field', ['name' => 'reason', 'label' => $t('iam.field.reason'), 'required' => true, 'full' => true, 'value' => $old['reason'], 'error' => $errors['reason'] ?? null]) ?>
      <label class="choice field--full"><input type="checkbox" name="submit_now" value="1"> <?= $e($t('records.propose.submit_now')) ?></label>
      <div class="row field--full"><?= $ui('button', ['label' => $t('records.propose.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'checks']) ?></div>
    </form>
  </section>
  <?php endif; ?>
</div>
<section class="card" aria-labelledby="hist-h">
  <h2 id="hist-h" class="card__title"><?= $e($t('records.history')) ?></h2>
  <div class="table-wrap" role="region" aria-label="<?= $e($t('records.history')) ?>" tabindex="0">
    <table class="table table--stack">
      <caption class="sr-only"><?= $e($t('records.history')) ?></caption>
      <thead><tr><th scope="col">#</th><th scope="col"><?= $e($t('records.field.rule')) ?></th><th scope="col"><?= $e($t('records.field.status')) ?></th>
        <th scope="col"><?= $e($t('records.field.people')) ?></th><th scope="col"><?= $e($t('records.field.actions')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($history as $h): ?>
        <tr id="v<?= (int) $h['version_no'] ?>">
          <td data-label="#" class="num">v<?= (int) $h['version_no'] ?></td>
          <td data-label="<?= $e($t('records.field.rule')) ?>"><?= $e($t('records.rule_summary', ['months' => (int) $h['duration_months'], 'trigger' => $t('records.trigger.' . $h['trigger_event']), 'action' => $t('records.action.' . $h['disposition_action'])])) ?>
            <?php if ($h['legal_basis_note'] !== null): ?><div class="text-sm text-muted"><?= $e($h['legal_basis_note']) ?></div><?php endif; ?>
            <?php if ($h['source_reference'] !== null): ?><div class="text-sm"><?= $e($t('records.field.source')) ?>: <?= $e($h['source_reference']) ?></div><?php endif; ?></td>
          <td data-label="<?= $e($t('records.field.status')) ?>"><?= (int) $h['is_ai_draft'] === 1 ? $ui('badge', ['tone' => 'warning', 'icon' => 'robot-off', 'label' => $t('records.ai_draft')]) . ' ' : '' ?><?= $ui('badge', ['status' => in_array($h['status'], ['draft', 'approved', 'rejected', 'returned', 'cancelled'], true) ? $h['status'] : '', 'tone' => 'info', 'label' => $t('records.rule_status.' . $h['status'])]) ?></td>
          <td data-label="<?= $e($t('records.field.people')) ?>" class="text-sm"><?= $e($h['proposed_name'] ?? 'AI (DEC-48)') ?><?= $h['decided_name'] !== null ? ' → ' . $e($h['decided_name']) : '' ?></td>
          <td data-label="<?= $e($t('records.field.actions')) ?>"><?php if ($canManage && $h['status'] === 'draft' && (int) $h['is_ai_draft'] === 0 && (int) $h['proposed_by'] === $userId): ?>
            <form method="post" action="<?= $e($url('/admin/records/rule/' . $h['public_id'] . '/submit')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('records.submit'), 'type' => 'submit', 'size' => 'sm', 'icon' => 'send']) ?></form>
          <?php elseif ((int) $h['is_ai_draft'] === 1 && $h['status'] === 'draft'): ?><span class="text-sm text-muted"><?= $e($t('records.adopt_hint')) ?></span><?php else: ?>—<?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
