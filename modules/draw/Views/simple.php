<?php
/**
 * Lucky draw (0.11.2, DEC-74): the quick-draw screen, but every spin is drawn on the server and saved — then "finish"
 * confirms the results (seed revealed, verifiable) and opens the results page.
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var callable $asset @var array<string, mixed> $v
 * @var array<string, mixed>|null $ev @var bool $canUse @var list<string> $names @var list<array<string, mixed>> $winners @var list<array<string, mixed>> $mine
 */
$pid = $ev !== null ? (string) $ev['public_id'] : '';
$history = []; // newest first, as on the quick draw
foreach ($winners as $w) {
    if ($w['status'] === 'active') {
        $history[(int) $w['seq_no']] = $t('draw.simple.place', ['n' => (string) $w['seq_no']]) . ' · ' . $w['name'] . ' · ' . $w['prize_name'];
    }
}
krsort($history);
$history = array_values($history);
$actions = $ui('button', ['label' => $t('draw.quick.title'), 'href' => $url('/draw/quick'), 'variant' => 'ghost', 'icon' => 'refresh'])
    . ($ev !== null ? $ui('button', ['label' => $t('draw.simple.new'), 'href' => $url('/draw'), 'variant' => 'secondary', 'icon' => 'plus']) : '');
?>
<?= $ui('page-header', ['title' => $t('draw.title'), 'subtitle' => $t('draw.simple.subtitle'), 'actions' => $actions]) ?>
<?php if ($canUse): ?>
<noscript><?= $ui('alert', ['tone' => 'warning', 'text' => $t('draw.simple.needs_js')]) ?></noscript>
<?php $wq = ['saved' => true, 'event' => $pid, 'title' => $ev !== null ? (string) $ev['name'] : '', 'names' => implode("\n", $names), 'count' => '1',
    'take_out' => $ev !== null ? (int) $ev['no_repeat'] === 1 : true, 'errors' => [], 'history' => $history, 'note' => $t('draw.simple.note')];
include __DIR__ . '/_wheel.php'; ?>
<section class="card stack-sm draw-finish" aria-labelledby="ds-fin" data-qd-finish data-base="<?= $e($url('/draw/')) ?>"<?= $pid === '' ? ' hidden' : '' ?>>
  <h2 id="ds-fin" class="card__title"><?= $ui('icon', ['name' => 'circle-check', 'size' => 'sm']) ?> <?= $e($t('draw.finish_title')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('draw.simple.finish_help')) ?></p>
  <form method="post" action="<?= $e($pid !== '' ? $url('/draw/' . $pid . '/confirm') : $url('/draw')) ?>" class="row" novalidate data-qd-finish-form>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <input type="hidden" name="name" value="<?= $e($ev !== null ? (string) $ev['name'] : '') ?>" data-qd-finish-name>
    <?= $ui('field', ['name' => 'witness', 'id' => 'ds-witness', 'label' => $t('draw.field.witness'), 'help' => $t('draw.witness_help')]) ?>
    <?= $ui('button', ['label' => $t('draw.simple.finish'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?>
  </form>
</section>
<section class="card stack-sm" aria-labelledby="dm-h">
  <details class="draw-more">
    <summary><h2 id="dm-h" class="card__title d-inline"><?= $e($t('draw.my_events')) ?> (<?= count($mine) ?>)</h2></summary>
    <?php if ($mine === []): ?>
      <p class="text-sm text-muted"><?= $e($t('draw.no_events')) ?></p>
    <?php else: ?>
    <div class="table-wrap" role="region" aria-labelledby="dm-h" tabindex="0">
      <table class="table table--stack">
        <thead><tr><th scope="col"><?= $e($t('draw.field.name')) ?></th><th scope="col"><?= $e($t('draw.col.status')) ?></th><th scope="col"><?= $e($t('draw.col.participants')) ?></th><th scope="col"><?= $e($t('draw.simple.updated')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($mine as $m): ?>
          <tr>
            <td data-label="<?= $e($t('draw.field.name')) ?>"><a href="<?= $e($url('/draw/' . $m['public_id'])) ?>"><?= $e($m['name']) ?></a></td>
            <td data-label="<?= $e($t('draw.col.status')) ?>"><?php $status = (string) $m['status']; include __DIR__ . '/_status.php'; ?></td>
            <td data-label="<?= $e($t('draw.col.participants')) ?>"><?= number_format((int) $m['participants']) ?></td>
            <td data-label="<?= $e($t('draw.simple.updated')) ?>"><?= $e($date((string) $m['updated_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </details>
  <p class="text-sm text-muted"><?= $ui('icon', ['name' => 'lock', 'size' => 'sm']) ?> <?= $e($t('draw.private_note')) ?> · <?= $e($t('draw.privacy_text')) ?></p>
</section>
<script src="<?= $e($asset('ui/js/race-plan.js')) ?>" defer></script>
<script src="<?= $e($asset('ui/js/draw.js')) ?>" defer></script>
<?php else: ?>
  <?= $ui('states', ['kind' => 'empty', 'icon' => 'gift', 'title' => $t('draw.nothing_title'), 'text' => $t('draw.nothing_text')]) ?>
<?php endif; ?>
