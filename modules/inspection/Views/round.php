<?php
/**
 * One inspection round: the checklist to answer (pass / fail / not applicable or a number, note, photo), or the answers
 * given; the corrective actions (helpdesk tickets or own actions); review, close and the history.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $rd @var array<string, string> $errors @var array<string, mixed> $old @var string $form @var string $today @var int $meId
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$base = '/inspection/r/' . $rd['public_id'];
$row = $rd;
$fill = $form === 'submit' && $old !== [];
$val = static function (string $k, string $d) use ($fill, $old): string {
    return $fill ? (is_scalar($old[$k] ?? null) ? (string) $old[$k] : '') : $d;
};
$num = static fn (?float $x): string => $x === null ? '' : rtrim(rtrim(sprintf('%.2F', $x), '0'), '.');
$rangeText = static function (array $item) use ($t, $num): string {
    if ($item['min'] !== null && $item['max'] !== null) {
        return $t('inspection.range_between', ['min' => $num($item['min']), 'max' => $num($item['max'])]);
    }
    return $item['min'] !== null ? $t('inspection.range_min', ['min' => $num($item['min'])]) : $t('inspection.range_max', ['max' => $num($item['max'])]);
};
$err = static fn (string $f, string $k): ?string => $form === $f ? ($errors[$k] ?? null) : null;
?>
<?= $ui('page-header', ['title' => $rd['code'] . ' · ' . $rd['name'], 'subtitle' => $t('inspection.round_of', ['d' => $date((string) $rd['due_date'], false)]),
    'crumbs' => [[$t('inspection.title'), $url($rd['manage'] && !$rd['mine'] ? '/inspection/board' : '/inspection')], [(string) $rd['due_date'], null]]]) ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<section class="card stack-sm" aria-labelledby="ir-d">
  <h2 id="ir-d" class="card__title"><?= $e($t('inspection.round')) ?> <?php include __DIR__ . '/_status.php'; ?><?= (int) $rd['late'] === 1 ? ' ' . $ui('badge', ['tone' => 'warning', 'label' => $t('inspection.late')]) : '' ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('inspection.field.location')) ?></dt><dd><?= $e(trim(($rd['site_code'] ?? '') . ' ' . ($rd['location'] ?? '—'))) ?></dd></div>
    <div><dt><?= $e($t('inspection.field.inspector')) ?></dt><dd><?= $e((string) $rd['inspector_name']) ?></dd></div>
    <div><dt><?= $e($t('inspection.field.checklist')) ?></dt><dd><?= $e($rd['checklist_name'] . ' · ' . $t('inspection.version_n', ['n' => (string) $rd['version_no']])) ?></dd></div>
    <?php if ((int) $rd['revision'] > 1): ?><div><dt><?= $e($t('inspection.revision')) ?></dt><dd><?= (int) $rd['revision'] ?></dd></div><?php endif; ?>
    <?php if ($rd['submitted_at'] !== null): ?><div><dt><?= $e($t('inspection.col.submitted')) ?></dt><dd><?= $e($date(substr((string) $rd['submitted_at'], 0, 10), false) . ' ' . substr((string) $rd['submitted_at'], 11, 5)) ?></dd></div><?php endif; ?>
  </dl>
</section>

<?php if ($rd['early']): ?><?= $ui('alert', ['tone' => 'info', 'title' => $t('inspection.not_yet', ['d' => $date((string) $rd['due_date'], false)])]) ?><?php endif; ?>

<?php if ($rd['can_fill']): ?>
<form method="post" action="<?= $e($url($base . '/submit')) ?>" enctype="multipart/form-data" class="stack" novalidate><?= $csrf ?>
  <p class="text-sm text-muted"><?= $e($t('inspection.fill_help')) ?></p>
  <?php foreach ($rd['items'] as $i => $item): $n = $i + 1; $res = $rd['results'][$n] ?? null; $cur = $val('result_' . $n, (string) ($res['result'] ?? '')); ?>
  <fieldset class="in-item stack-sm<?= $cur === 'fail' ? ' in-item--fail' : '' ?>">
    <legend class="field__label"><?= $n ?>. <?= $e((string) $item['text']) ?> <span class="field__req"><?= $e($t($item['required'] ? 'form.required_tag' : 'form.optional_tag')) ?></span></legend>
    <?php if ($item['kind'] === 'number'): ?>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'value_' . $n, 'id' => 'f-value-' . $n, 'label' => $t('inspection.field.value'), 'inputmode' => 'decimal', 'value' => $val('value_' . $n, $res !== null && $res['value'] !== null ? $num((float) $res['value']) : ''),
            'help' => $rangeText($item), 'error' => $err('submit', 'value_' . $n)]) ?>
        <label class="check"><input type="checkbox" name="na_<?= $n ?>" value="1"<?= $val('na_' . $n, $res !== null && $res['result'] === 'na' ? '1' : '') !== '' ? ' checked' : '' ?>> <?= $e($t('inspection.result.na')) ?></label>
      </div>
    <?php else: ?>
      <div class="in-choice" role="radiogroup" aria-label="<?= $e($t('inspection.result_for', ['n' => (string) $n])) ?>">
        <?php foreach (['pass', 'fail', 'na'] as $opt): ?><label class="check"><input type="radio" name="result_<?= $n ?>" value="<?= $opt ?>"<?= $cur === $opt ? ' checked' : '' ?>> <?= $e($t('inspection.result.' . $opt)) ?></label><?php endforeach; ?>
      </div>
      <?php if ($err('submit', 'result_' . $n) !== null): ?><p class="field__error"><?= $e((string) $err('submit', 'result_' . $n)) ?></p><?php endif; ?>
    <?php endif; ?>
    <?= $ui('field', ['name' => 'note_' . $n, 'id' => 'f-note-' . $n, 'label' => $t('inspection.field.note'), 'value' => $val('note_' . $n, (string) ($res['note'] ?? '')), 'help' => $t('inspection.note_help'), 'error' => $err('submit', 'note_' . $n)]) ?>
    <div class="field">
      <label class="field__label" for="f-photo-<?= $n ?>"><?= $e($t('inspection.field.photo')) ?> <span class="field__req"><?= $e($t('form.optional_tag')) ?></span></label>
      <input class="input input--file" type="file" id="f-photo-<?= $n ?>" name="photo_<?= $n ?>" accept="image/*,.pdf" capture="environment"<?= $err('submit', 'photo_' . $n) !== null ? ' aria-invalid="true"' : '' ?>>
      <?php if ($res !== null && $res['document_pid'] !== null): ?><div class="field__help"><a href="<?= $e($url($base . '/photo/' . $res['id'])) ?>"><?= $e($t('inspection.photo_saved', ['name' => (string) $res['filename']])) ?></a></div><?php endif; ?>
      <?php if ($err('submit', 'photo_' . $n) !== null): ?><div class="field__error"><?= $e((string) $err('submit', 'photo_' . $n)) ?></div><?php endif; ?>
    </div>
  </fieldset>
  <?php endforeach; ?>
  <div class="row">
    <?= $ui('button', ['label' => $t('inspection.submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?>
    <?= $ui('button', ['label' => $t('inspection.save_draft'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'pencil', 'attrs' => ['formaction' => $url($base . '/save')]]) ?>
  </div>
</form>
<?php elseif ($rd['results'] !== []): ?>
<section class="card card--flush" aria-labelledby="ir-res">
  <div class="card__head"><h2 id="ir-res" class="card__title"><?= $e($t('inspection.results')) ?></h2></div>
  <div class="table-wrap" role="region" aria-labelledby="ir-res" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col">#</th><th scope="col"><?= $e($t('inspection.col.item')) ?></th><th scope="col"><?= $e($t('inspection.col.result')) ?></th><th scope="col"><?= $e($t('inspection.field.note')) ?></th><th scope="col"><?= $e($t('inspection.field.photo')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rd['items'] as $i => $item): $n = $i + 1; $res = $rd['results'][$n] ?? null; ?>
        <tr><td data-label="#"><?= $n ?></td>
          <th scope="row" data-label="<?= $e($t('inspection.col.item')) ?>"><?= $e((string) $item['text']) ?></th>
          <td data-label="<?= $e($t('inspection.col.result')) ?>"><?php if ($res !== null): ?><?= $ui('badge', ['tone' => $res['result'] === 'pass' ? 'success' : ($res['result'] === 'fail' ? 'danger' : 'neutral'), 'label' => $t('inspection.result.' . $res['result'])]) ?><?= $res['value'] !== null ? ' ' . $e($num((float) $res['value'])) : '' ?><?php else: ?>—<?php endif; ?></td>
          <td data-label="<?= $e($t('inspection.field.note')) ?>"><?= $e((string) ($res['note'] ?? '')) ?></td>
          <td data-label="<?= $e($t('inspection.field.photo')) ?>"><?= $res !== null && $res['document_pid'] !== null ? '<a href="' . $e($url($base . '/photo/' . $res['id'])) . '">' . $e($t('inspection.view_photo')) . '</a>' : '' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<?php if ($rd['earlier'] !== []): ?>
<details class="card"><summary><?= $e($t('inspection.earlier')) ?></summary>
  <ul class="plain-list stack-sm"><?php foreach ($rd['earlier'] as $x): ?><li><?= $e($t('inspection.revision') . ' ' . $x['revision'] . ' · #' . $x['item_no'] . ' · ' . $t('inspection.result.' . $x['result']) . ($x['value'] !== null ? ' ' . $num((float) $x['value']) : '') . ($x['note'] !== null ? ' — ' . $x['note'] : '')) ?></li><?php endforeach; ?></ul>
</details>
<?php endif; ?>

<?php if ($rd['actions'] !== []): ?>
<section class="card stack-sm" aria-labelledby="ir-a">
  <h2 id="ir-a" class="card__title"><?= $e($t('inspection.actions')) ?></h2>
  <ul class="plain-list stack">
  <?php foreach ($rd['actions'] as $a): $mineA = $a['assignee_id'] !== null && (int) $a['assignee_id'] === $meId; ?>
    <li class="in-item stack-sm">
      <p>#<?= (int) $a['item_no'] ?> · <?= $e((string) $a['description']) ?> · <?= $ui('badge', ['tone' => $a['status'] === 'done' ? 'success' : 'warning', 'label' => $t('inspection.action_status.' . $a['status'])]) ?></p>
      <?php if ($a['ticket_pid'] !== null): ?>
        <p class="text-sm"><?= $e($t('inspection.ticket_link')) ?> <a href="<?= $e($url('/helpdesk/t/' . $a['ticket_pid'])) ?>"><?= $e((string) $a['ticket_ref']) ?></a> · <?= $e($t('helpdesk.status.' . $a['ticket_status'])) ?></p>
      <?php else: ?>
        <p class="text-sm"><?= $e($a['assignee_name'] !== null ? $t('inspection.action_who', ['who' => (string) $a['assignee_name'], 'd' => $date((string) $a['due_date'], false)]) : $t('inspection.action_unassigned')) ?><?= $a['done_note'] !== null ? ' · ' . $e((string) $a['done_note']) : '' ?></p>
        <?php if ($a['status'] === 'open' && $rd['manage']): $fa = $form === 'action-assign' && ($old['action'] ?? '') === $a['public_id']; ?>
        <form method="post" action="<?= $e($url('/inspection/a/' . $a['public_id'] . '/assign')) ?>" class="stack-sm" novalidate><?= $csrf ?>
          <div class="grid grid--2">
            <?= $ui('field', ['name' => 'assignee', 'id' => 'f-as-' . $a['public_id'], 'label' => $t('inspection.field.assignee'), 'required' => true, 'value' => $fa ? (string) ($old['assignee'] ?? '') : '', 'help' => $t('inspection.person_help'), 'error' => $fa ? ($errors['assignee'] ?? null) : null]) ?>
            <?= $ui('field', ['name' => 'due_date', 'id' => 'f-ad-' . $a['public_id'], 'type' => 'date', 'label' => $t('inspection.field.action_due'), 'required' => true, 'value' => $fa ? (string) ($old['due_date'] ?? '') : (string) ($a['due_date'] ?? ''), 'min' => $today, 'error' => $fa ? ($errors['due_date'] ?? null) : null]) ?>
          </div>
          <div class="row"><?= $ui('button', ['label' => $t('inspection.assign_action'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'user']) ?></div>
        </form>
        <?php endif; ?>
        <?php if ($a['status'] === 'open' && ($rd['manage'] || $mineA)): $fd = $form === 'action-done' && ($old['action'] ?? '') === $a['public_id']; ?>
        <form method="post" action="<?= $e($url('/inspection/a/' . $a['public_id'] . '/done')) ?>" class="stack-sm" novalidate><?= $csrf ?>
          <?= $ui('field', ['name' => 'done_note', 'id' => 'f-dn-' . $a['public_id'], 'label' => $t('inspection.field.done_note'), 'required' => true, 'value' => $fd ? (string) ($old['done_note'] ?? '') : '', 'error' => $fd ? ($errors['done_note'] ?? null) : null]) ?>
          <div class="row"><?= $ui('button', ['label' => $t('inspection.mark_done'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'circle-check']) ?></div>
        </form>
        <?php endif; ?>
      <?php endif; ?>
    </li>
  <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if ($rd['can_review']): ?>
<section class="card stack-sm" aria-labelledby="ir-rv">
  <h2 id="ir-rv" class="card__title"><?= $e($t('inspection.review')) ?></h2>
  <form method="post" action="<?= $e($url($base . '/review')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <fieldset class="in-choice"><legend class="field__label"><?= $e($t('inspection.field.decision')) ?></legend>
      <label class="check"><input type="radio" name="decision" value="accept" checked> <?= $e($t('inspection.accept')) ?></label>
      <label class="check"><input type="radio" name="decision" value="return"<?= ($form === 'review' && ($old['decision'] ?? '') === 'return') ? ' checked' : '' ?>> <?= $e($t('inspection.return')) ?></label>
    </fieldset>
    <?= $ui('field', ['name' => 'review_note', 'label' => $t('inspection.field.review_note'), 'value' => $form === 'review' ? (string) ($old['review_note'] ?? '') : '', 'help' => $t('inspection.review_help'), 'error' => $err('review', 'review_note')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('inspection.save_review'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'checks']) ?></div>
  </form>
</section>
<?php endif; ?>
<?php if ($rd['status'] === 'reviewed' && $rd['manage']): ?>
<form method="post" action="<?= $e($url($base . '/close')) ?>" class="card row"><?= $csrf ?>
  <p><?= $e($rd['can_close'] ? $t('inspection.close_ready') : $t('inspection.close_wait', ['n' => (string) $rd['open_actions']])) ?></p>
  <?= $ui('button', ['label' => $t('inspection.close'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'lock', 'disabled' => !$rd['can_close']]) ?>
</form>
<?php endif; ?>

<section class="card stack-sm" aria-labelledby="ir-h">
  <h2 id="ir-h" class="card__title"><?= $e($t('inspection.history')) ?></h2>
  <?php if ($rd['events'] === []): ?><p class="text-sm text-muted">—</p><?php endif; ?>
  <ul class="plain-list stack-sm"><?php foreach ($rd['events'] as $ev): ?><li><?= $e($t('inspection.event.' . $ev['event'])) ?> · <?= $e((string) ($ev['actor'] ?? $t('inspection.system'))) ?> <span class="text-sm text-muted"><?= $e($date((string) $ev['created_at'])) ?></span><?= $ev['note'] !== null ? ' — ' . $e((string) $ev['note']) : '' ?></li><?php endforeach; ?></ul>
</section>
