<?php
/**
 * My card + wallet. @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $asset @var array<string, mixed> $v
 * @var array<string, mixed>|null $me @var array<string, mixed>|null $card @var string|null $status @var array<string, mixed>|null $wallet
 * @var list<array<string, mixed>> $entries @var array{topup: int, spend: int, refund: int}|null $month @var string $ym @var array<string, mixed>|null $rules
 */
use Blue\Core\I18n\DateFormat;

$loc = (string) ($v['locale'] ?? 'th');
include __DIR__ . '/_helpers.php';
$cur = (string) ($wallet['currency_code'] ?? $rules['currency'] ?? '');
?>
<?= $ui('page-header', ['title' => $t('eid.title'), 'subtitle' => $t('eid.subtitle')]) ?>
<?php if ($me === null): ?>
  <?= $ui('alert', ['tone' => 'info', 'title' => $t('eid.no_employee_title'), 'text' => $t('eid.no_employee_text')]) ?>
<?php else: ?>
<div class="grid grid--2">
<section class="card stack eid-home" aria-labelledby="cd-h">
  <h2 id="cd-h" class="card__title"><?= $ui('icon', ['name' => 'id-badge-2']) ?> <?= $e($t('eid.my_card')) ?></h2>
  <?php if ($card === null): ?>
    <p class="text-sm text-muted"><?= $e($t('eid.no_card')) ?></p>
  <?php else: ?>
    <dl class="facts">
      <div><dt><?= $e($t('eid.field.status')) ?></dt><dd><?= $eidStatus($status) ?></dd></div>
      <div><dt><?= $e($t('eid.field.card_no')) ?></dt><dd><?= $e((string) $card['card_no']) ?></dd></div>
      <div><dt><?= $e($t('eid.field.expires')) ?></dt><dd><?= $e($card['expires_on'] !== null ? DateFormat::day((string) $card['expires_on'], $loc) : $t('eid.no_expiry')) ?></dd></div>
    </dl>
    <?php if (in_array($status, ['active', 'suspended', 'lost', 'expired'], true)): ?>
    <div class="row"><?= $ui('button', ['label' => $t('eid.show_card'), 'href' => $url('/eid/card'), 'variant' => 'primary', 'size' => 'lg', 'icon' => 'id-badge-2']) ?></div>
    <?php endif; ?>
    <?php if ($status === 'active'): ?>
    <details>
      <summary><?= $e($t('eid.report_lost')) ?></summary>
      <form method="post" action="<?= $e($url('/eid/lost')) ?>" class="stack-sm">
        <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
        <p class="text-sm text-muted"><?= $e($t('eid.report_lost_help')) ?></p>
        <?= $ui('field', ['name' => 'reason', 'id' => 'lost-reason', 'label' => $t('eid.field.reason'), 'value' => '', 'placeholder' => $t('eid.lost_example')]) ?>
        <?= $ui('button', ['label' => $t('eid.report_lost_btn'), 'type' => 'submit', 'variant' => 'danger', 'icon' => 'lock']) ?>
      </form>
    </details>
    <?php endif; ?>
  <?php endif; ?>
</section>

<section class="card stack" aria-labelledby="wl-h">
  <h2 id="wl-h" class="card__title"><?= $ui('icon', ['name' => 'wallet']) ?> <?= $e($t('eid.wallet')) ?></h2>
  <?php if ($wallet === null): ?>
    <p class="text-sm text-muted"><?= $e($t('eid.wallet_none')) ?></p>
  <?php else: ?>
    <p class="eid-balance"><span class="eid-balance__label"><?= $e($t('eid.balance')) ?></span> <span class="eid-balance__value"><?= $e($money((string) $wallet['balance'], $cur)) ?></span></p>
    <?php if ($month !== null): ?>
    <dl class="facts">
      <div><dt><?= $e($t('eid.month_topup', ['month' => $ym])) ?></dt><dd><?= $e($money($month['topup'], $cur)) ?></dd></div>
      <div><dt><?= $e($t('eid.month_spend', ['month' => $ym])) ?></dt><dd><?= $e($money($month['spend'], $cur)) ?></dd></div>
    </dl>
    <?php endif; ?>
  <?php endif; ?>
  <p class="text-sm text-muted"><?= $ui('icon', ['name' => 'info-circle', 'size' => 'sm']) ?> <?= $e($t('eid.wallet_closed_note')) ?><?= $rules !== null ? ' — ' . $e((string) $rules['purpose']) : '' ?></p>
</section>
</div>

<?php if ($entries !== []): ?>
<section class="card stack-sm" aria-labelledby="en-h">
  <h2 id="en-h" class="card__title"><?= $e($t('eid.history')) ?></h2>
  <div class="table-wrap" role="region" aria-labelledby="en-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('eid.field.when')) ?></th><th scope="col"><?= $e($t('eid.field.type')) ?></th><th scope="col"><?= $e($t('eid.field.amount')) ?></th><th scope="col"><?= $e($t('eid.field.balance_after')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($entries as $x): ?>
        <tr><td data-label="<?= $e($t('eid.field.when')) ?>"><?= $e(DateFormat::day(substr((string) $x['created_at'], 0, 10), $loc) . ' ' . substr((string) $x['created_at'], 11, 5)) ?></td>
          <td data-label="<?= $e($t('eid.field.type')) ?>"><?= $e($t('eid.entry.' . $x['entry_type'])) ?></td>
          <td data-label="<?= $e($t('eid.field.amount')) ?>" class="num"><?= $e($money((string) $x['amount'], $cur)) ?></td>
          <td data-label="<?= $e($t('eid.field.balance_after')) ?>" class="num"><?= $e($money((string) $x['balance_after'], $cur)) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>
<?php endif; ?>
