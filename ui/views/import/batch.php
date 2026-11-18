<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array{batch: array<string, mixed>, rows: list<array<string, mixed>>, definition: \Blue\Core\Import\ImportDefinition, total: int} $p
 * @var string $filter @var int $page @var int $per @var string $back
 */
$b = $p['batch'];
$cols = $p['definition']->columns();
$masked = array_flip($p['definition']->sensitiveColumns());
$pending = $b['status'] === 'validated';
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$step = $pending ? 2 : 3;
?>
<?= $ui('page-header', ['title' => $t($p['definition']->titleKey()), 'subtitle' => (string) $b['filename'],
    'actions' => $ui('button', ['label' => $t('common.back'), 'href' => $url($back), 'variant' => 'ghost', 'icon' => 'arrow-back-up'])]) ?>
<ol class="stepper" aria-label="<?= $e($t('import.steps')) ?>">
  <?php foreach (['upload', 'check', 'confirm'] as $i => $s): $n = $i + 1; ?>
  <li class="stepper__step<?= $n < $step ? ' stepper__step--done' : ($n === $step ? ' stepper__step--current' : '') ?>"><span class="stepper__num"><?= $n ?></span><?= $e($t('import.step.' . $s)) ?></li>
  <?php endforeach; ?>
</ol>
<section class="card">
  <div class="grid grid--4">
    <div><div class="stat__label"><?= $e($t('import.field.total')) ?></div><div class="stat__value"><?= (int) $b['total_rows'] ?></div></div>
    <div><div class="stat__label"><?= $e($t('import.field.ok')) ?></div><div class="stat__value"><?= (int) $b['ok_rows'] ?></div></div>
    <div><div class="stat__label"><?= $e($t('import.field.errors')) ?></div><div class="stat__value<?= (int) $b['error_rows'] > 0 ? ' text-danger' : '' ?>"><?= (int) $b['error_rows'] ?></div></div>
    <div><div class="stat__label"><?= $e($t('import.field.status')) ?></div><div class="stat__value stat__value--sm"><?= $e($t('import.status.' . $b['status'])) ?></div></div>
  </div>
  <?php if ($b['status'] === 'applied'): ?><?= $ui('alert', ['tone' => 'success', 'text' => $t('import.applied_note', ['n' => (int) $b['applied_rows'], 'at' => $date($b['applied_at'])])]) ?><?php endif; ?>
  <?php if ($pending): ?>
    <p class="text-sm text-muted"><?= $e($t('import.expires', ['at' => $date($b['expires_at'])])) ?></p>
    <div class="row">
      <form method="post" action="<?= $e($url('/import/batch/' . $b['public_id'] . '/confirm')) ?>" class="row"><?= $csrf ?>
        <?php if ((int) $b['error_rows'] > 0): ?><label class="choice"><input type="checkbox" name="skip_errors" value="1" required> <?= $e($t('import.skip_errors', ['n' => (int) $b['error_rows']])) ?></label><?php endif; ?>
        <?= $ui('button', ['label' => $t('import.confirm', ['n' => (int) $b['ok_rows']]), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'checks', 'disabled' => (int) $b['ok_rows'] === 0]) ?>
      </form>
      <form method="post" action="<?= $e($url('/import/batch/' . $b['public_id'] . '/cancel')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('import.cancel'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'x']) ?></form>
      <?php if ((int) $b['error_rows'] > 0): ?><?= $ui('button', ['label' => $t('import.error_report'), 'href' => $url('/import/batch/' . $b['public_id'] . '/errors'), 'variant' => 'secondary', 'icon' => 'download']) ?><?php endif; ?>
    </div>
  <?php endif; ?>
</section>
<form method="get" action="<?= $e($url('/import/batch/' . $b['public_id'])) ?>" class="filter-bar">
  <?= $ui('field', ['name' => 'show', 'label' => $t('import.show'), 'type' => 'select', 'value' => $filter, 'options' => ['' => $t('common.all'), 'ok' => $t('import.row.ok'), 'error' => $t('import.row.error'), 'applied' => $t('import.row.applied')]]) ?>
  <?= $ui('button', ['label' => $t('common.filter'), 'type' => 'submit', 'icon' => 'filter']) ?>
</form>
<div class="table-wrap" role="region" aria-label="<?= $e($t('import.preview')) ?>" tabindex="0">
  <table class="table table--stack">
    <caption class="sr-only"><?= $e($t('import.preview')) ?></caption>
    <thead><tr><th scope="col"><?= $e($t('import.field.row')) ?></th><th scope="col"><?= $e($t('import.field.result')) ?></th>
      <?php foreach ($cols as $c): ?><th scope="col"><?= $e($t($c['label'])) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($p['rows'] as $row): ?>
      <tr>
        <td data-label="<?= $e($t('import.field.row')) ?>" class="num"><?= (int) $row['row_no'] ?></td>
        <td data-label="<?= $e($t('import.field.result')) ?>"><?php if ($row['status'] === 'error'): ?><?= $ui('badge', ['tone' => 'danger', 'icon' => 'alert-circle', 'label' => $t('import.row.error')]) ?>
          <?php else: ?><?= $ui('badge', ['tone' => $row['status'] === 'applied' ? 'success' : 'info', 'icon' => $row['status'] === 'applied' ? 'circle-check' : 'checks', 'label' => $t('import.row.' . $row['status']) . ' · ' . $t('import.action.' . $row['action'])]) ?><?php endif; ?></td>
        <?php foreach ($cols as $c): $err = $row['errors'][$c['key']] ?? null; ?>
          <td data-label="<?= $e($t($c['label'])) ?>"><?php $cell = (string) ($row['payload']['raw'][$c['key']] ?? ''); ?><?= $e(isset($masked[$c['key']]) && mb_strlen($cell) > 4 ? '••••' . mb_substr($cell, -4) : $cell) ?><?php if ($err !== null): ?><div class="field__error"><?= $ui('icon', ['name' => 'alert-circle', 'size' => 'sm']) ?><?= $e($t((string) $err)) ?></div><?php endif; ?></td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    <?php if ($p['rows'] === []): ?><tr><td colspan="<?= count($cols) + 2 ?>"><?= $ui('states', ['title' => $t('import.no_rows')]) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?= $ui('pagination', ['path' => '/import/batch/' . $b['public_id'], 'query' => ['show' => $filter], 'page' => $page, 'per' => $per, 'total' => $p['total']]) ?>
