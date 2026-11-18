<?php
/**
 * My shifts (next 5 weeks) + shift swaps. @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, mixed>|null $me @var array<string, array<string, mixed>> $mine @var string $today @var string $to
 * @var list<array<string, mixed>> $swaps @var array<string, string> $colleagues @var bool $canManage @var array<string, string> $errors @var array<string, string> $old
 */
use Blue\Core\I18n\DateFormat;

$loc = (string) ($v['locale'] ?? 'th');
include __DIR__ . '/_helpers.php';
$day = static fn (string $d): string => DateFormat::day($d, $loc);
$myDates = [];
foreach ($mine as $d => $s) {
    if (!$s['off']) {
        $myDates[$d] = $day($d) . ' · ' . $s['code'] . ' ' . $s['start'] . '–' . $s['end'];
    }
}
$myId = $me !== null ? (int) $me['id'] : 0;
?>
<?= $ui('page-header', ['title' => $t('shifts.title'), 'subtitle' => $t('shifts.subtitle'),
    'actions' => $canManage ? $ui('button', ['label' => $t('shifts.nav_admin'), 'href' => $url('/shifts/admin'), 'variant' => 'secondary', 'icon' => 'settings']) : '']) ?>
<?php if ($me === null): ?>
  <?= $ui('alert', ['tone' => 'info', 'title' => $t('shifts.no_employee_title'), 'text' => $t('shifts.no_employee_text')]) ?>
<?php else: ?>
<section class="card stack-sm" aria-labelledby="my-h">
  <h2 id="my-h" class="card__title"><?= $e($t('shifts.my_shifts', ['from' => $day($today), 'to' => $day($to)])) ?></h2>
  <?php if ($mine === []): ?>
    <p class="text-sm text-muted"><?= $e($t('shifts.none_published')) ?></p>
  <?php else: ?>
  <ul class="shift-days" role="list">
    <?php foreach ($mine as $d => $s): ?>
      <li class="shift-day<?= $d === $today ? ' is-today' : '' ?>"><span class="shift-day__date"><span class="text-sm text-muted"><?= $e($wd($d)) ?></span> <?= $e($day($d)) ?></span><?= $shChip($s) ?></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

<section class="card stack" aria-labelledby="sw-h">
  <h2 id="sw-h" class="card__title"><?= $e($t('shifts.swap.title')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('shifts.swap.how')) ?></p>
  <?php if ($myDates === [] || $colleagues === []): ?>
    <p class="text-sm text-muted"><?= $e($t('shifts.swap.nothing')) ?></p>
  <?php else: ?>
  <form method="post" action="<?= $e($url('/shifts/swap')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'my_date', 'type' => 'select', 'label' => $t('shifts.swap.my_date'), 'required' => true, 'value' => $old['my_date'] ?? '', 'options' => ['' => '—'] + $myDates, 'error' => $errors['my_date'] ?? null]) ?>
      <?= $ui('field', ['name' => 'partner', 'type' => 'select', 'label' => $t('shifts.swap.partner'), 'required' => true, 'value' => $old['partner'] ?? '', 'options' => ['' => '—'] + $colleagues, 'error' => $errors['partner'] ?? null]) ?>
      <?= $ui('field', ['name' => 'partner_date', 'type' => 'date', 'label' => $t('shifts.swap.partner_date'), 'value' => $old['partner_date'] ?? '', 'help' => $t('shifts.swap.partner_date_help'), 'error' => $errors['partner_date'] ?? null, 'min' => $today]) ?>
    </div>
    <?= $ui('field', ['name' => 'reason', 'label' => $t('shifts.swap.reason'), 'required' => true, 'value' => $old['reason'] ?? '', 'error' => $errors['reason'] ?? null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('shifts.swap.submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
  </form>
  <?php endif; ?>

  <?php if ($swaps !== []): ?>
  <div class="table-wrap" role="region" aria-labelledby="sw-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('shifts.swap.col.who')) ?></th><th scope="col"><?= $e($t('shifts.swap.col.what')) ?></th><th scope="col"><?= $e($t('shifts.swap.col.status')) ?></th><th scope="col"><span class="sr-only"><?= $e($t('common.actions')) ?></span></th></tr></thead>
      <tbody>
      <?php foreach ($swaps as $s): $iAsked = (int) $s['requester_employee_id'] === $myId; ?>
        <tr>
          <td data-label="<?= $e($t('shifts.swap.col.who')) ?>"><?= $e($iAsked ? $t('shifts.swap.with', ['who' => $s['b_code'] . ' · ' . $s['b_name']]) : $t('shifts.swap.from', ['who' => $s['a_code'] . ' · ' . $s['a_name']])) ?></td>
          <td data-label="<?= $e($t('shifts.swap.col.what')) ?>"><?= $e($day((string) $s['requester_date']) . ' ' . ($s['a_shift'] ?? $t('shifts.off')) . ' ⇄ ' . $day((string) $s['partner_date']) . ' ' . ($s['b_shift'] ?? $t('shifts.off'))) ?>
            <span class="text-sm text-muted d-block"><?= $e((string) $s['reason']) ?></span>
            <?php if ($s['status'] === 'failed' && $s['error'] !== null): ?><span class="text-sm d-block"><?= $e($t('shifts.error.' . preg_replace('/^shifts\.error\./', '', (string) $s['error']))) ?></span><?php endif; ?></td>
          <td data-label="<?= $e($t('shifts.swap.col.status')) ?>"><?= $swapStatus((string) $s['status']) ?></td>
          <td>
            <?php if (!$iAsked && $s['status'] === 'pending_partner'): ?>
              <form method="post" action="<?= $e($url('/shifts/swap/' . $s['public_id'] . '/answer')) ?>" class="row row--tight">
                <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
                <?= $ui('button', ['label' => $t('shifts.swap.accept'), 'type' => 'submit', 'variant' => 'primary', 'size' => 'sm', 'icon' => 'circle-check', 'attrs' => ['name' => 'answer', 'value' => 'accept']]) ?>
                <?= $ui('button', ['label' => $t('shifts.swap.decline'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'x', 'attrs' => ['name' => 'answer', 'value' => 'decline']]) ?>
              </form>
            <?php elseif ($iAsked && in_array($s['status'], ['pending_partner', 'pending'], true)): ?>
              <form method="post" action="<?= $e($url('/shifts/swap/' . $s['public_id'] . '/cancel')) ?>">
                <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
                <?= $ui('button', ['label' => $t('shifts.swap.cancel'), 'type' => 'submit', 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'x']) ?>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>
