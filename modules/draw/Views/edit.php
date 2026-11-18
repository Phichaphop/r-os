<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $ev @var list<array<string, mixed>> $participants @var int $count @var list<array<string, mixed>> $prizes
 * @var list<array<string, mixed>> $winners @var list<array<string, mixed>> $log @var array<string, string> $errors @var array<string, mixed>|null $report
 * @var string|null $seed @var array<string, mixed>|null $verify @var array<string, string> $eventLocal
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$st = (string) $ev['status'];
$base = '/draw/' . $ev['public_id'];
$seqMode = ($ev['prize_mode'] ?? 'prizes') === 'sequence';
$old = ['name' => $ev['name'], 'description' => $ev['description'], 'mode' => $ev['mode'], 'no_repeat' => (string) $ev['no_repeat'],
    'prize_mode' => (string) ($ev['prize_mode'] ?? 'prizes'), 'seq_count' => $ev['seq_count'] !== null ? (string) $ev['seq_count'] : ''];
$old += $eventLocal; // date/time in the display time zone (from the controller)
$total = array_sum(array_map(static fn (array $z): int => (int) $z['quantity'], $prizes));
$won = array_sum(array_map(static fn (array $z): int => (int) $z['won'], $prizes));
$byPrize = [];
foreach ($winners as $w) {
    $byPrize[(int) $w['prize_id']][] = $w;
}
$status = $st;
?>
<?php
ob_start();
include __DIR__ . '/_status.php';
$badge = (string) ob_get_clean();
$actions = $badge
    . (in_array($st, ['ready', 'drawing'], true) ? $ui('button', ['label' => $t('draw.open_show'), 'href' => $url($base . '/show'), 'variant' => 'primary', 'icon' => 'gift']) : '')
    . (in_array($st, ['confirmed', 'archived'], true) ? $ui('button', ['label' => $t('draw.view_results'), 'href' => $url($base . '/results'), 'variant' => 'secondary', 'icon' => 'eye']) : '');
?>
<?= $ui('page-header', ['title' => (string) $ev['name'], 'subtitle' => $t('draw.edit_subtitle'), 'actions' => $actions]) ?>
<p><a href="<?= $e($url('/draw')) ?>"><?= $ui('icon', ['name' => 'chevron-left', 'size' => 'sm']) ?><?= $e($t('draw.back')) ?></a></p>
<?php $other = array_diff_key($errors, ['name' => 1, 'law_ack' => 1, 'lock' => 1, 'event_date' => 1, 'seq_count' => 1, 'file' => 1]); if ($other !== []): ?><?= $ui('alert', ['tone' => 'danger', 'text' => implode(' · ', array_values($other))]) ?><?php endif; ?>

<?php if ($report !== null): ?>
  <?= $ui('alert', ['tone' => $report['invalid'] === [] && $report['duplicates'] === [] ? 'success' : 'warning', 'title' => $t('draw.import.done', ['added' => (string) $report['added'], 'total' => (string) $report['total']]),
      'text' => ($report['duplicates'] !== [] ? $t('draw.import.dups', ['rows' => implode(', ', array_slice($report['duplicates'], 0, 30)) . (count($report['duplicates']) > 30 ? '…' : '')]) . ' ' : '')
        . ($report['invalid'] !== [] ? $t('draw.import.invalid', ['rows' => implode(', ', array_map(static fn (array $x): string => $x[0] . ' (' . $t($x[1]) . ')', array_slice($report['invalid'], 0, 20)))]) : '')]) ?>
<?php endif; ?>

<?php if ($st === 'draft'): ?>
<?php $ready = $count > 0 && ($seqMode || $prizes !== []); ?>
<ol class="draw-steps" aria-label="<?= $e($t('draw.steps.label')) ?>">
  <li class="is-done"><span class="draw-steps__n">1</span> <?= $e($t('draw.steps.create')) ?></li>
  <li class="<?= $ready ? 'is-done' : 'is-current' ?>"><span class="draw-steps__n">2</span> <?= $e($t($seqMode ? 'draw.steps.names' : 'draw.steps.prizes')) ?></li>
  <li class="<?= $ready ? 'is-current' : '' ?>"><span class="draw-steps__n">3</span> <?= $e($t('draw.steps.start')) ?></li>
</ol>
<div class="settings-grid">
  <div class="stack">
    <section class="card stack" aria-labelledby="de-p">
      <h2 id="de-p" class="card__title"><?= $ui('icon', ['name' => 'users', 'size' => 'sm']) ?> <?= $e($t('draw.participants')) ?> (<?= number_format($count) ?>)</h2>
      <?php if ($participants !== []): ?>
      <div class="table-wrap draw-names" role="region" aria-labelledby="de-p" tabindex="0">
        <table class="table table--stack">
          <thead><tr><th scope="col">#</th><th scope="col"><?= $e($t('draw.col.code')) ?></th><th scope="col"><?= $e($t('draw.col.name')) ?></th><th scope="col"><?= $e($t('draw.col.group')) ?></th></tr></thead>
          <tbody>
          <?php foreach ($participants as $p): ?>
            <tr><td data-label="#"><?= (int) $p['position'] ?></td><td data-label="<?= $e($t('draw.col.code')) ?>"><?= $e($p['code'] ?? '—') ?></td>
              <td data-label="<?= $e($t('draw.col.name')) ?>"><?= $e($p['name']) ?></td><td data-label="<?= $e($t('draw.col.group')) ?>"><?= $e($p['grp'] ?? '—') ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($count > count($participants)): ?><p class="text-sm text-muted"><?= $e($t('draw.showing_first', ['n' => (string) count($participants), 'total' => (string) $count])) ?></p><?php endif; ?>
      <?php endif; ?>
      <details class="draw-more"<?= $count === 0 || isset($errors['file']) ? ' open' : '' ?>>
        <summary><?= $e($t($count === 0 ? 'draw.add_names' : 'draw.edit_names')) ?></summary>
        <form method="post" action="<?= $e($url($base . '/participants')) ?>" enctype="multipart/form-data" class="stack" novalidate>
          <?= $csrf ?>
          <?= $ui('field', ['name' => 'text', 'type' => 'textarea', 'label' => $t('draw.field.paste'), 'help' => $t('draw.paste_help'), 'placeholder' => $t('draw.names_now_example')]) ?>
          <?= $ui('dropzone', ['name' => 'file', 'label' => $t('draw.field.file'), 'accept' => '.xlsx,.csv', 'help' => $t('draw.file_help'), 'error' => $errors['file'] ?? null]) ?>
          <p class="text-sm"><?= $ui('icon', ['name' => 'download', 'size' => 'sm']) ?> <a href="<?= $e($url('/draw/template')) ?>"><?= $e($t('draw.template.download')) ?></a>
            <span class="text-muted">— <?= $e($t('draw.template.download_help')) ?></span></p>
          <fieldset class="row"><legend class="sr-only"><?= $e($t('draw.field.import_mode')) ?></legend>
            <label class="choice"><input type="radio" name="mode" value="replace" checked> <?= $e($t('draw.import.replace')) ?></label>
            <label class="choice"><input type="radio" name="mode" value="append"> <?= $e($t('draw.import.append')) ?></label>
          </fieldset>
          <div class="row"><?= $ui('button', ['label' => $t('draw.import.submit'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'upload']) ?></div>
        </form>
        <?php if ($participants !== []): ?>
        <form method="post" action="<?= $e($url($base . '/participants/clear')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('draw.clear'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'trash']) ?></form>
        <?php endif; ?>
      </details>
    </section>
    <?php if ($seqMode): ?>
    <section class="card stack-sm" aria-labelledby="de-z">
      <h2 id="de-z" class="card__title"><?= $ui('icon', ['name' => 'list-check', 'size' => 'sm']) ?> <?= $e($t('draw.prize_mode.sequence')) ?></h2>
      <p class="text-sm text-muted"><?= $e($t((int) $ev['no_repeat'] === 1 ? 'draw.seq.explain' : 'draw.seq.explain_keep', ['n' => $ev['seq_count'] !== null ? ((int) $ev['no_repeat'] === 1 ? (string) min((int) $ev['seq_count'], max($count, 1)) : (string) $ev['seq_count']) : $t('draw.seq.everyone')])) ?></p>
    </section>
    <?php else: ?>
    <section class="card stack" aria-labelledby="de-z">
      <h2 id="de-z" class="card__title"><?= $ui('icon', ['name' => 'gift', 'size' => 'sm']) ?> <?= $e($t('draw.prizes')) ?></h2>
      <form method="post" action="<?= $e($url($base . '/prizes')) ?>" class="stack" novalidate>
        <?= $csrf ?>
        <p class="text-sm text-muted"><?= $e($t('draw.prizes_help')) ?></p>
        <?php if (isset($errors['prizes'])): ?><p class="field__error" role="alert"><?= $e($errors['prizes']) ?></p><?php endif; ?>
        <div class="rate-rows">
          <div class="rate-rows__head" aria-hidden="true"><span><?= $e($t('draw.col.prize')) ?></span><span><?= $e($t('draw.col.quantity')) ?></span></div>
          <?php foreach (array_merge($prizes, array_fill(0, 3, ['name' => '', 'quantity' => ''])) as $i => $z): ?>
            <div class="rate-rows__row">
              <input class="input" name="prize_name[]" value="<?= $e($z['name']) ?>" aria-label="<?= $e($t('draw.col.prize') . ' ' . ($i + 1)) ?>">
              <input class="input" name="prize_qty[]" value="<?= $e($z['quantity']) ?>" inputmode="numeric" aria-label="<?= $e($t('draw.col.quantity') . ' ' . ($i + 1)) ?>">
            </div>
          <?php endforeach; ?>
        </div>
        <div class="row"><?= $ui('button', ['label' => $t('draw.save_prizes'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'gift']) ?></div>
      </form>
    </section>
    <?php endif; ?>
  </div>
  <div class="stack">
    <section class="card stack draw-start<?= $ready ? ' is-ready' : '' ?>" aria-labelledby="de-l">
      <h2 id="de-l" class="card__title"><?= $e($t('draw.start_title')) ?></h2>
      <dl class="facts">
        <div><dt><?= $e($t('draw.col.participants')) ?></dt><dd><?= number_format($count) ?></dd></div>
        <div><dt><?= $e($t('draw.field.prize_mode')) ?></dt><dd><?= $e($t('draw.prize_mode.' . ($seqMode ? 'sequence' : 'prizes'))) ?></dd></div>
        <?php if (!$seqMode): ?><div><dt><?= $e($t('draw.total_prizes')) ?></dt><dd><?= number_format($total) ?></dd></div><?php endif; ?>
        <div><dt><?= $e($t('draw.drawn_names')) ?></dt><dd><?= $e($t((int) $ev['no_repeat'] === 1 ? 'draw.drawn_out' : ($seqMode ? 'draw.drawn_stay' : 'draw.drawn_other_prizes'))) ?></dd></div>
      </dl>
      <form method="post" action="<?= $e($url($base . '/lock')) ?>" class="stack-sm" novalidate>
        <?= $csrf ?>
        <label class="check"><input type="checkbox" name="law_ack" value="1"> <?= $e($t('draw.law_ack')) ?></label>
        <?php if (isset($errors['law_ack'])): ?><p class="field__error" role="alert"><?= $e($errors['law_ack']) ?></p><?php endif; ?>
        <?php if (isset($errors['lock'])): ?><p class="field__error" role="alert"><?= $e($errors['lock']) ?></p><?php endif; ?>
        <?= $ui('button', ['label' => $t('draw.lock'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'arrow-right', 'disabled' => !$ready, 'disabledReason' => $t($seqMode ? 'draw.lock_needs_seq' : 'draw.lock_needs')]) ?>
      </form>
      <p class="text-sm text-muted"><?= $ui('icon', ['name' => 'shield-check', 'size' => 'sm']) ?> <?= $e($t('draw.lock_help')) ?></p>
    </section>
    <section class="card stack-sm" aria-labelledby="de-s">
      <details class="draw-more"<?= isset($errors['name']) || isset($errors['event_date']) || isset($errors['seq_count']) ? ' open' : '' ?>>
        <summary><h2 id="de-s" class="card__title d-inline"><?= $e($t('draw.settings')) ?></h2></summary>
        <form method="post" action="<?= $e($url($base)) ?>" class="stack" novalidate>
          <?= $csrf ?>
          <?php include __DIR__ . '/_form.php'; ?>
          <div class="row"><?= $ui('button', ['label' => $t('common.save'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'circle-check']) ?></div>
        </form>
        <form method="post" action="<?= $e($url($base . '/archive')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('draw.archive'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'history']) ?></form>
      </details>
    </section>
  </div>
</div>
<?php else: ?>
<div class="settings-grid">
  <div class="stack">
    <section class="card stack-sm" aria-labelledby="de-r">
      <h2 id="de-r" class="card__title"><?= $e($t('draw.results')) ?> (<?= $won ?>/<?= $total ?>)</h2>
      <?php foreach ($prizes as $z): ?>
        <h3 class="text-sm"><?= $ui('icon', ['name' => $seqMode ? 'list-check' : 'gift', 'size' => 'sm']) ?> <?= $e($z['name']) ?> · <?= (int) $z['won'] ?>/<?= (int) $z['quantity'] ?></h3>
        <?php if (($byPrize[(int) $z['id']] ?? []) === []): ?><p class="text-sm text-muted"><?= $e($t('draw.not_drawn')) ?></p><?php endif; ?>
        <ol class="plain-list draw-winners">
          <?php foreach ($byPrize[(int) $z['id']] ?? [] as $w): ?>
            <li class="<?= $w['status'] === 'void' ? 'is-void' : '' ?>">
              <span><?php if ($w['seq_no'] !== null): ?><span class="draw-seq" aria-label="<?= $e($t('draw.seq.place', ['n' => (string) $w['seq_no']])) ?>"><?= (int) $w['seq_no'] ?></span><?php endif; ?><strong><?= $e($w['name']) ?></strong><?= $w['grp'] ? ' · ' . $e($w['grp']) : '' ?> <span class="text-sm text-muted"><?= $e($t('draw.round_n', ['n' => (string) $w['round_no']])) ?></span>
                <?php if ($w['round_reason']): ?><span class="d-block text-sm text-muted"><?= $e($t('draw.redraw_because', ['reason' => (string) $w['round_reason']])) ?></span><?php endif; ?>
                <?php if ($w['status'] === 'void'): ?><span class="d-block text-sm"><?= $ui('badge', ['tone' => 'neutral', 'icon' => 'x', 'label' => $t('draw.winner.void')]) ?> <?= $e((string) $w['void_reason']) ?></span><?php endif; ?></span>
              <?php if ($st === 'drawing' && $w['status'] === 'active'): ?>
                <details><summary class="text-sm"><?= $e($t('draw.redraw')) ?></summary>
                  <form method="post" action="<?= $e($url($base . '/redraw')) ?>" class="stack-sm" novalidate>
                    <?= $csrf ?><input type="hidden" name="prize" value="<?= (int) $z['id'] ?>"><input type="hidden" name="winner" value="<?= (int) $w['id'] ?>">
                    <input type="hidden" name="token" value="<?= $e(bin2hex(random_bytes(16))) ?>">
                    <?= $ui('field', ['name' => 'reason', 'id' => 'rr-' . (int) $w['id'], 'label' => $t('draw.redraw_reason'), 'required' => true, 'placeholder' => $t('draw.redraw_example')]) ?>
                    <?= $ui('button', ['label' => $t('draw.redraw_submit'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'refresh']) ?>
                  </form></details>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php endforeach; ?>
      <?php if (in_array($st, ['drawing', 'confirmed', 'archived'], true)): ?>
        <div class="row">
          <?= $ui('button', ['label' => $t('draw.export_xlsx'), 'href' => $url($base . '/export'), 'variant' => 'secondary', 'icon' => 'download']) ?>
          <?= $ui('button', ['label' => 'CSV', 'href' => $url($base . '/export?format=csv'), 'variant' => 'ghost', 'icon' => 'download']) ?>
        </div>
      <?php endif; ?>
    </section>
  </div>
  <div class="stack">
    <section class="card stack-sm" aria-labelledby="de-v">
      <h2 id="de-v" class="card__title"><?= $e($t('draw.fairness')) ?></h2>
      <dl class="facts">
        <div><dt><?= $e($t('draw.locked_at')) ?></dt><dd><?= $e($date((string) $ev['locked_at'])) ?></dd></div>
        <div><dt><?= $e($t('draw.col.participants')) ?></dt><dd><?= number_format($count) ?></dd></div>
        <div><dt><?= $e($t('draw.commitment')) ?></dt><dd class="mono break-all text-sm"><?= $e($ev['commitment']) ?></dd></div>
        <div><dt><?= $e($t('draw.snapshot')) ?></dt><dd class="mono break-all text-sm"><?= $e($ev['snapshot_hash']) ?></dd></div>
        <?php if ($seed !== null): ?>
          <div><dt><?= $e($t('draw.seed')) ?></dt><dd class="mono break-all text-sm"><?= $e($seed) ?></dd></div>
          <div><dt><?= $e($t('draw.verify')) ?></dt><dd><?= $verify !== null && $verify['ok'] ? $ui('badge', ['tone' => 'success', 'icon' => 'shield-check', 'label' => $t('draw.verify_ok')]) : $ui('badge', ['tone' => 'danger', 'icon' => 'alert-triangle', 'label' => $t('draw.verify_bad')]) ?></dd></div>
        <?php endif; ?>
      </dl>
      <p class="text-sm text-muted"><?= $e($t($seed === null ? 'draw.commitment_help' : 'draw.seed_help')) ?></p>
      <?php if ($st === 'ready'): ?>
        <form method="post" action="<?= $e($url($base . '/unlock')) ?>" class="stack-sm" novalidate>
          <?= $csrf ?>
          <?= $ui('field', ['name' => 'reason', 'id' => 'ul-reason', 'label' => $t('draw.unlock_reason'), 'required' => true, 'error' => $errors['reason'] ?? null, 'help' => $t('draw.unlock_help')]) ?>
          <?= $ui('button', ['label' => $t('draw.unlock'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'lock-open']) ?>
        </form>
      <?php endif; ?>
    </section>
    <?php if ($st === 'drawing'): ?>
    <section class="card stack-sm" aria-labelledby="de-c">
      <h2 id="de-c" class="card__title"><?= $e($t('draw.confirm_title')) ?></h2>
      <p class="text-sm text-muted"><?= $e($t('draw.confirm_help')) ?></p>
      <form method="post" action="<?= $e($url($base . '/confirm')) ?>" class="stack-sm" novalidate>
        <?= $csrf ?>
        <?= $ui('field', ['name' => 'witness', 'label' => $t('draw.field.witness'), 'help' => $t('draw.witness_help')]) ?>
        <?= $ui('button', ['label' => $t('draw.confirm'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?>
      </form>
    </section>
    <?php endif; ?>
    <?php if ($st === 'confirmed'): ?>
    <section class="card stack-sm" aria-labelledby="de-arc">
      <h2 id="de-arc" class="card__title"><?= $e($t('draw.archive_title')) ?></h2>
      <p class="text-sm text-muted"><?= $e($t('draw.archive_help')) ?></p>
      <form method="post" action="<?= $e($url($base . '/archive')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('draw.archive'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'history']) ?></form>
    </section>
    <?php endif; ?>
    <?php if (in_array($st, ['ready', 'drawing', 'confirmed'], true)): ?>
    <section class="card stack-sm draw-reset" aria-labelledby="de-reset">
      <h2 id="de-reset" class="card__title"><?= $ui('icon', ['name' => 'refresh', 'size' => 'sm']) ?> <?= $e($t('draw.reset_title')) ?></h2>
      <p class="text-sm text-muted"><?= $e($t('draw.reset_help')) ?></p>
      <form method="post" action="<?= $e($url($base . '/reset')) ?>" class="stack-sm" novalidate>
        <?= $csrf ?>
        <?= $ui('field', ['name' => 'reason', 'id' => 'reset-reason', 'label' => $t('draw.reset_reason'), 'required' => true, 'error' => $errors['reset_reason'] ?? null, 'placeholder' => $t('draw.reset_example')]) ?>
        <?= $ui('button', ['label' => $t('draw.reset'), 'type' => 'submit', 'variant' => 'danger', 'icon' => 'refresh']) ?>
      </form>
    </section>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<section class="card stack-sm" aria-labelledby="de-log">
  <details><summary><h2 id="de-log" class="card__title d-inline"><?= $e($t('draw.log')) ?></h2></summary>
    <ol class="plain-list">
      <?php foreach ($log as $l): $d = (array) json_decode((string) ($l['detail_json'] ?? ''), true); ?>
        <li><strong><?= $e($t('draw.event.' . $l['action'])) ?></strong><?= isset($d['round']) ? ' · ' . $e($t('draw.round_n', ['n' => (string) $d['round']])) : '' ?><?= !empty($d['reason']) ? ' · ' . $e((string) $d['reason']) : '' ?>
          <?php if ($l['action'] === 'reset' && !empty($d['old_seed'])): ?><span class="d-block text-sm mono break-all"><?= $e($t('draw.reset_old', ['g' => (string) ($d['generation'] ?? ''), 'rounds' => (string) ($d['rounds'] ?? 0)])) ?> <?= $e($t('draw.commitment')) ?>: <?= $e((string) $d['old_commitment']) ?> · <?= $e($t('draw.seed')) ?>: <?= $e((string) $d['old_seed']) ?></span><?php endif; ?>
          <span class="d-block text-sm text-muted"><?= $e((string) ($l['actor_name'] ?? '—')) ?> · <?= $e($date((string) $l['occurred_at'])) ?></span></li>
      <?php endforeach; ?>
    </ol>
  </details>
</section>
