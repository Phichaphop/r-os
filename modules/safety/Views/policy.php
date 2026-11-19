<?php
/**
 * The company safety policy (0.45.0 · DEC-114): the policy in use, a draft (from the AI template — labelled — or from the
 * policy in use), editing it (safety.manage) and publishing it (safety.approve, not the person who drafted or edited it).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var array<string, mixed> $ov @var bool $canEdit @var bool $canPublish @var int $uid
 * @var array<string, string> $errors @var array<string, mixed> $old @var string $today @var bool $canManage @var string $here
 */
use Blue\Modules\Safety\SafetyPolicy;

$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '"><input type="hidden" name="le" value="' . $e($lePid) . '">';
$pub = $ov['published'];
$draft = $ov['draft'];
$dc = is_array($draft['content'] ?? null) ? $draft['content'] : null;
$val = static function (string $k, mixed $def) use ($old): string {
    if ($old !== [] && array_key_exists($k, $old)) {
        return is_scalar($old[$k]) ? (string) $old[$k] : '';
    }
    return $def === null ? '' : (string) $def;
};
$on = static function (string $k, bool $def) use ($old): bool {
    return $old !== [] && isset($old['_save']) ? !empty($old[$k]) : $def;
};
$lv = static fn (array $c, int $l): array => (array) ($c['levels'][$l] ?? $c['levels'][(string) $l] ?? []);
$ch = static fn (array $c, int $l): array => (array) ($c['chain'][$l] ?? $c['chain'][(string) $l] ?? []);
$sc = static fn (array $c, string $k, int $s): string => (string) ($c[$k][$s] ?? $c[$k][(string) $s] ?? '');
$bandOf = static function (array $c, string $key): array {
    foreach ((array) ($c['bands'] ?? []) as $b) {
        if (($b['key'] ?? '') === $key) {
            return $b;
        }
    }
    return [];
};
$whoLabel = static fn (array $list): string => $list === [] ? '—' : implode(', ', array_map(static fn (string $w): string => $t('safety.policy.who.' . $w), $list));
?>
<?= $ui('page-header', ['title' => $t('safety.policy.title'), 'subtitle' => $t('safety.policy.subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php include __DIR__ . '/_companies.php'; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<p class="text-sm text-muted"><?= $e($t('safety.policy.not_law')) ?></p>

<section class="card stack-sm" aria-labelledby="sp-pub">
  <h2 id="sp-pub" class="card__title"><?= $e($t('safety.policy.in_use')) ?></h2>
  <?php if ($pub === null): ?>
    <p class="text-sm"><?= $e($t('safety.policy.none')) ?></p>
  <?php else: $pc = (array) $pub['content']; ?>
    <p class="text-sm"><?= $e($t('safety.policy.published_line', ['v' => (string) $pub['version'], 'who' => (string) $pub['reviewer'], 'd' => $date((string) $pub['reviewed_on'], false), 'by' => (string) ($pub['published_name'] ?? '—')])) ?></p>
    <div class="table-wrap" role="region" aria-labelledby="sp-pub" tabindex="0"><table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('safety.policy.level')) ?></th><th scope="col"><?= $e($t('safety.policy.meaning')) ?></th><th scope="col"><?= $e($t('safety.policy.alert_now')) ?></th><th scope="col"><?= $e($t('safety.policy.escalate')) ?></th></tr></thead>
      <tbody><?php foreach (SafetyPolicy::LEVELS as $l): $x = $lv($pc, $l); $c = $ch($pc, $l); ?>
        <tr>
          <th scope="row" data-label="<?= $e($t('safety.policy.level')) ?>"><?= $l ?> · <?= $e((string) ($x['name'] ?? '')) ?></th>
          <td data-label="<?= $e($t('safety.policy.meaning')) ?>"><?= $e((string) ($x['meaning'] ?? '')) ?></td>
          <td data-label="<?= $e($t('safety.policy.alert_now')) ?>"><?= $e($t('safety.policy.who.team')) ?><?php foreach (SafetyPolicy::WHO as $w): if (!empty($c[$w])): ?>, <?= $e($t('safety.policy.who.' . $w)) ?><?php endif; endforeach; ?></td>
          <td data-label="<?= $e($t('safety.policy.escalate')) ?>"><?= ($c['after_min'] ?? null) === null ? '—' : $e($t('safety.policy.escalate_line', ['n' => (string) $c['after_min'], 'who' => $whoLabel((array) ($c['then'] ?? []))])) ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
  <?php endif; ?>
</section>

<?php if ($draft === null): ?>
  <?php if ($canEdit): ?>
  <section class="card stack-sm" aria-labelledby="sp-new">
    <h2 id="sp-new" class="card__title"><?= $e($t('safety.policy.new')) ?></h2>
    <p class="text-sm text-muted"><?= $e($t('safety.policy.new_help')) ?></p>
    <div class="row">
      <form method="post" action="<?= $e($url('/safety/policy/draft')) ?>"><?= $csrf ?><input type="hidden" name="from" value="ai"><?= $ui('button', ['label' => $t('safety.policy.from_ai'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'file-text']) ?></form>
      <?php if ($pub !== null): ?><form method="post" action="<?= $e($url('/safety/policy/draft')) ?>"><?= $csrf ?><input type="hidden" name="from" value="current"><?= $ui('button', ['label' => $t('safety.policy.from_current'), 'type' => 'submit', 'variant' => 'secondary']) ?></form><?php endif; ?>
    </div>
  </section>
  <?php endif; ?>
<?php elseif ($dc !== null): ?>
<section class="card stack" aria-labelledby="sp-draft">
  <h2 id="sp-draft" class="card__title"><?= $e($t('safety.policy.draft_title', ['v' => (string) $draft['version']])) ?></h2>
  <?php if ((int) $draft['ai_draft'] === 1): ?><?= $ui('alert', ['tone' => 'warning', 'text' => $t('safety.policy.ai_label')]) ?><?php endif; ?>
  <p class="text-sm text-muted"><?= $e($t('safety.policy.draft_line', ['by' => (string) ($draft['created_name'] ?? '—'), 'edited' => (string) ($draft['updated_name'] ?? '—')])) ?></p>
  <?php if ($canEdit): ?>
  <form method="post" action="<?= $e($url('/safety/policy/save')) ?>" class="stack" novalidate><?= $csrf ?><input type="hidden" name="_save" value="1">
    <fieldset class="stack-sm"><legend class="card__title"><?= $e($t('safety.policy.levels')) ?></legend>
      <p class="text-sm text-muted"><?= $e($t('safety.policy.levels_help')) ?></p>
      <?php foreach (SafetyPolicy::LEVELS as $l): $x = $lv($dc, $l); ?>
      <div class="sp-level stack-sm">
        <h3 class="text-base"><?= $e($t('safety.policy.level_n', ['n' => (string) $l])) ?></h3>
        <?= $ui('field', ['name' => 'name_' . $l, 'id' => 'sp-name-' . $l, 'label' => $t('safety.policy.field.name'), 'required' => true, 'value' => $val('name_' . $l, $x['name'] ?? ''), 'error' => $errors['name_' . $l] ?? null]) ?>
        <?= $ui('field', ['name' => 'meaning_' . $l, 'id' => 'sp-meaning-' . $l, 'type' => 'textarea', 'label' => $t('safety.policy.field.meaning'), 'required' => true, 'value' => $val('meaning_' . $l, $x['meaning'] ?? ''), 'error' => $errors['meaning_' . $l] ?? null]) ?>
        <?= $ui('field', ['name' => 'examples_' . $l, 'id' => 'sp-examples-' . $l, 'type' => 'textarea', 'label' => $t('safety.policy.field.examples'), 'value' => $val('examples_' . $l, $x['examples'] ?? ''), 'error' => $errors['examples_' . $l] ?? null]) ?>
      </div>
      <?php endforeach; ?>
    </fieldset>
    <fieldset class="stack-sm"><legend class="card__title"><?= $e($t('safety.policy.chain')) ?></legend>
      <p class="text-sm text-muted"><?= $e($t('safety.policy.chain_help')) ?></p>
      <?php foreach (SafetyPolicy::LEVELS as $l): $c = $ch($dc, $l); ?>
      <fieldset class="sp-level stack-sm"><legend class="text-base"><?= $e($t('safety.policy.level_n', ['n' => (string) $l])) ?></legend>
        <p class="text-sm"><?= $e($t('safety.policy.alert_now')) ?>: <?= $e($t('safety.policy.who.team')) ?></p>
        <div class="row">
          <?php foreach (SafetyPolicy::WHO as $w): ?>
          <label class="check"><input type="checkbox" name="<?= $e($w . '_' . $l) ?>" value="1"<?= $on($w . '_' . $l, !empty($c[$w])) ? ' checked' : '' ?>> <?= $e($t('safety.policy.who.' . $w)) ?></label>
          <?php endforeach; ?>
        </div>
        <?= $ui('field', ['name' => 'after_' . $l, 'id' => 'sp-after-' . $l, 'type' => 'number', 'label' => $t('safety.policy.field.after'), 'value' => $val('after_' . $l, $c['after_min'] ?? ''), 'help' => $t('safety.policy.after_help'), 'error' => $errors['after_' . $l] ?? null]) ?>
        <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('safety.policy.field.then')) ?></legend>
          <div class="row">
          <?php foreach (SafetyPolicy::WHO as $w): $then = $old !== [] && isset($old['_save']) ? (array) ($old['then_' . $l] ?? []) : (array) ($c['then'] ?? []); ?>
            <label class="check"><input type="checkbox" name="then_<?= $l ?>[]" value="<?= $e($w) ?>"<?= in_array($w, $then, true) ? ' checked' : '' ?>> <?= $e($t('safety.policy.who.' . $w)) ?></label>
          <?php endforeach; ?>
          </div>
          <?php if (isset($errors['then_' . $l])): ?><p class="field__error"><?= $e($errors['then_' . $l]) ?></p><?php endif; ?>
        </fieldset>
      </fieldset>
      <?php endforeach; ?>
    </fieldset>
    <fieldset class="stack-sm"><legend class="card__title"><?= $e($t('safety.policy.scales')) ?></legend>
      <div class="grid grid--2">
        <div class="stack-sm"><h3 class="text-base"><?= $e($t('safety.field.likelihood')) ?></h3>
          <?php foreach (SafetyPolicy::SCALE as $s): ?><?= $ui('field', ['name' => 'likelihood_' . $s, 'id' => 'sp-lik-' . $s, 'label' => (string) $s, 'required' => true, 'value' => $val('likelihood_' . $s, $sc($dc, 'likelihood', $s)), 'error' => $errors['likelihood_' . $s] ?? null]) ?><?php endforeach; ?>
        </div>
        <div class="stack-sm"><h3 class="text-base"><?= $e($t('safety.field.impact')) ?></h3>
          <?php foreach (SafetyPolicy::SCALE as $s): ?><?= $ui('field', ['name' => 'impact_' . $s, 'id' => 'sp-imp-' . $s, 'label' => (string) $s, 'required' => true, 'value' => $val('impact_' . $s, $sc($dc, 'impact', $s)), 'error' => $errors['impact_' . $s] ?? null]) ?><?php endforeach; ?>
        </div>
      </div>
    </fieldset>
    <fieldset class="stack-sm"><legend class="card__title"><?= $e($t('safety.policy.bands')) ?></legend>
      <p class="text-sm text-muted"><?= $e($t('safety.policy.bands_help')) ?></p>
      <?php foreach (SafetyPolicy::BANDS as $b): $bb = $bandOf($dc, $b); ?>
      <div class="grid grid--2">
        <?php if ($b !== 'very_high'): ?>
          <?= $ui('field', ['name' => 'band_max_' . $b, 'id' => 'sp-bmax-' . $b, 'type' => 'number', 'label' => $t('safety.policy.band_max', ['band' => $t('safety.policy.band.' . $b)]), 'required' => true, 'value' => $val('band_max_' . $b, $bb['max'] ?? ''), 'error' => $errors['band_max_' . $b] ?? null]) ?>
        <?php else: ?>
          <p class="text-sm"><strong><?= $e($t('safety.policy.band.very_high')) ?></strong>: <?= $e($t('safety.policy.band_rest')) ?></p>
        <?php endif; ?>
        <?= $ui('field', ['name' => 'band_action_' . $b, 'id' => 'sp-bact-' . $b, 'label' => $t('safety.policy.band_action', ['band' => $t('safety.policy.band.' . $b)]), 'required' => true, 'value' => $val('band_action_' . $b, $bb['action'] ?? ''), 'error' => $errors['band_action_' . $b] ?? null]) ?>
      </div>
      <?php endforeach; ?>
    </fieldset>
    <div class="row"><?= $ui('button', ['label' => $t('safety.policy.save'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
  </form>
  <form method="post" action="<?= $e($url('/safety/policy/discard')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('safety.policy.discard'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'trash']) ?></form>
  <?php endif; ?>

  <?php if ($canPublish): ?>
  <div class="stack-sm sp-publish">
    <h3 class="text-base"><?= $e($t('safety.policy.publish_title')) ?></h3>
    <?php if ((int) $draft['created_by'] === $uid || (int) $draft['updated_by'] === $uid): ?>
      <p class="text-sm"><?= $e($t('safety.policy.error.same_person')) ?></p>
    <?php else: ?>
    <p class="text-sm text-muted"><?= $e($t('safety.policy.publish_help')) ?></p>
    <form method="post" action="<?= $e($url('/safety/policy/publish')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <label class="check"><input type="checkbox" name="reviewed" value="1"<?= isset($errors['reviewed']) ? ' aria-describedby="sp-rev-err"' : '' ?>> <?= $e($t('safety.policy.field.reviewed')) ?></label>
      <?php if (isset($errors['reviewed'])): ?><p class="field__error" id="sp-rev-err"><?= $e($errors['reviewed']) ?></p><?php endif; ?>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'reviewer', 'id' => 'sp-reviewer', 'label' => $t('safety.policy.field.reviewer'), 'required' => true, 'value' => $val('reviewer', ''), 'help' => $t('safety.policy.reviewer_help'), 'error' => $errors['reviewer'] ?? null]) ?>
        <?= $ui('field', ['name' => 'reviewed_on', 'id' => 'sp-reviewed-on', 'type' => 'date', 'label' => $t('safety.policy.field.reviewed_on'), 'required' => true, 'value' => $val('reviewed_on', $today), 'error' => $errors['reviewed_on'] ?? null]) ?>
      </div>
      <div class="row"><?= $ui('button', ['label' => $t('safety.policy.publish'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($ov['history'] !== []): ?>
<section class="card stack-sm" aria-labelledby="sp-hist">
  <h2 id="sp-hist" class="card__title"><?= $e($t('safety.policy.history')) ?></h2>
  <ul class="stack-sm text-sm">
    <?php foreach ($ov['history'] as $h): ?>
    <li><?= $e($t('safety.policy.history_line', ['v' => (string) $h['version'], 'status' => $t('safety.policy.status.' . $h['status']), 'who' => (string) ($h['reviewer'] ?? '—'), 'd' => $h['reviewed_on'] === null ? '—' : $date((string) $h['reviewed_on'], false), 'by' => (string) ($h['published_name'] ?? '—')])) ?></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<p class="text-sm"><a href="<?= $e($url('/safety/forms')) ?>"><?= $e($t('safety.policy.to_forms')) ?></a></p>
