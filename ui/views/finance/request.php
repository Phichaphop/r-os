<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $req @var bool $canManage @var int $le
 */
use Blue\Core\Support\Money;

$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$waiting = in_array($req['status'], ['received', 'needs_attention'], true);
$params = (array) json_decode((string) ($req['error_detail'] ?? '{}'), true);
?>
<?= $ui('page-header', ['title' => $t('finance.request.title'), 'subtitle' => $req['source_module'] . ' · ' . $req['source_document_type'] . ' ' . $req['source_document_id'],
    'crumbs' => [[$t('finance.title'), $url('/finance?le=' . $le)], [$t('finance.requests.title'), $url('/finance/requests?le=' . $le)], [$req['source_document_id'], null]]]) ?>
<?php include __DIR__ . '/_tabs.php'; ?>
<?php if ($req['status'] === 'needs_attention'): ?>
  <?= $ui('alert', ['tone' => 'danger', 'title' => $t('finance.request.waiting'), 'text' => $t((string) $req['error_key'], array_map('strval', array_filter($params, 'is_scalar')))]) ?>
<?php endif; ?>
<div class="grid grid--2">
  <section class="card">
    <dl class="dl">
      <dt><?= $e($t('finance.field.status')) ?></dt><dd><?= $e($t('finance.rstatus.' . $req['status'])) ?></dd>
      <dt><?= $e($t('finance.field.date')) ?></dt><dd><?= $e((string) $req['period_date']) ?></dd>
      <dt><?= $e($t('finance.field.amount')) ?></dt><dd><?= $e(Money::format(Money::fromDb($req['total_amount'])) . ' ' . $req['currency_code']) ?></dd>
      <dt><?= $e($t('finance.field.idempotency')) ?></dt><dd class="mono text-sm"><?= $e($req['idempotency_key']) ?></dd>
      <dt><?= $e($t('finance.field.received')) ?></dt><dd><?= $e($date($req['created_at'])) ?> · <?= (int) $req['attempts'] ?> <?= $e($t('finance.attempts')) ?></dd>
      <?php if ($req['journal'] !== null): ?><dt><?= $e($t('finance.field.journal')) ?></dt><dd><a class="mono" href="<?= $e($url('/finance/journals/' . $req['journal']['public_id'])) ?>"><?= $e($req['journal']['journal_no'] ?? $t('finance.jstatus.' . $req['journal']['status'])) ?></a></dd><?php endif; ?>
      <?php if ($req['status'] === 'rejected'): ?><dt><?= $e($t('iam.field.reason')) ?></dt><dd><?= $e((string) $req['error_detail']) ?></dd><?php endif; ?>
    </dl>
  </section>
  <section class="card">
    <h2 class="card__title"><?= $e($t('finance.journal.next')) ?></h2>
    <?php if ($waiting && $canManage): ?>
      <p class="text-sm text-muted"><?= $e($t('finance.request.retry_help')) ?></p>
      <form method="post" action="<?= $e($url('/finance/requests/' . $req['public_id'] . '/retry')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('finance.request.retry'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'refresh']) ?></form>
      <details class="stack-sm"><summary><?= $e($t('finance.request.reject')) ?></summary>
        <form method="post" action="<?= $e($url('/finance/requests/' . $req['public_id'] . '/reject')) ?>" class="form-grid"><?= $csrf ?>
          <?= $ui('field', ['name' => 'reason', 'label' => $t('iam.field.reason'), 'required' => true, 'full' => true]) ?>
          <div class="row field--full"><?= $ui('button', ['label' => $t('finance.request.reject'), 'type' => 'submit', 'variant' => 'danger', 'icon' => 'circle-x']) ?></div>
        </form>
      </details>
    <?php else: ?><p class="text-muted"><?= $e($t('finance.request.no_action')) ?></p><?php endif; ?>
  </section>
</div>
<section class="card">
  <h2 class="card__title"><?= $e($t('finance.request.lines')) ?></h2>
  <div class="table-wrap" role="region" aria-label="<?= $e($t('finance.request.lines')) ?>" tabindex="0">
    <table class="table table--stack">
      <caption class="sr-only"><?= $e($t('finance.request.lines')) ?></caption>
      <thead><tr><th scope="col"><?= $e($t('finance.field.line_type')) ?></th><th scope="col"><?= $e($t('finance.field.description')) ?></th><th scope="col" class="num"><?= $e($t('finance.field.amount')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($req['lines'] as $l): ?>
        <tr><td data-label="<?= $e($t('finance.field.line_type')) ?>" class="mono"><?= $e((string) $l['line_type']) ?></td><td data-label="<?= $e($t('finance.field.description')) ?>"><?= $e((string) ($l['description'] ?? '')) ?></td>
          <td data-label="<?= $e($t('finance.field.amount')) ?>" class="num"><?= $e(Money::format(Money::fromDb($l['amount']))) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="text-sm text-muted"><?= $e($t('finance.request.rules_hint')) ?> <a href="<?= $e($url('/finance/rules?le=' . $le)) ?>"><?= $e($t('finance.tab.rules')) ?></a></p>
</section>
