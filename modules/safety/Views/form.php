<?php
/**
 * One printable internal safety form (A4) — report, near-miss / hazard card, witness statement, risk matrix (0.45.0 ·
 * DEC-114). Level meanings, scales and bands come from the company's published policy, else from the AI template.
 * Paper forms are filled by hand; what is reported is then entered in the system.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $asset @var string $form
 * @var array<string, mixed> $pol @var bool $isTemplate @var string $company @var bool $canManage @var string $here
 */
use Blue\Modules\Safety\SafetyPolicy;

$lv = static fn (int $l): array => (array) ($pol['levels'][$l] ?? $pol['levels'][(string) $l] ?? []);
$sc = static fn (string $k, int $s): string => (string) ($pol[$k][$s] ?? $pol[$k][(string) $s] ?? '');
$bandFor = static function (int $score) use ($pol): string {
    foreach ((array) $pol['bands'] as $b) {
        if ($score <= (int) $b['max']) {
            return (string) $b['key'];
        }
    }
    return 'very_high';
};
$lines = static fn (int $n): string => str_repeat('<span class="sf-line" aria-hidden="true"></span>', $n);
$box = '<span class="sf-box" aria-hidden="true"></span>';
?>
<?= $ui('page-header', ['title' => $t('safety.forms.' . $form), 'subtitle' => $t('safety.forms.print_hint'),
    'actions' => $ui('button', ['label' => $t('safety.forms.print'), 'type' => 'button', 'variant' => 'primary', 'icon' => 'printer', 'attrs' => ['data-print' => '1']])]) ?>
<p class="print-hide"><a href="<?= $e($url('/safety/forms')) ?>"><?= $e($t('safety.forms.back')) ?></a></p>
<?php if ($isTemplate): ?><div class="print-hide"><?= $ui('alert', ['tone' => 'info', 'text' => $t('safety.forms.no_policy')]) ?></div><?php endif; ?>
<article class="paper-doc sf-form" aria-label="<?= $e($t('safety.forms.' . $form)) ?>">
  <header>
    <p class="paper-doc__kicker"><?= $e($t('safety.forms.kicker')) ?><?= $company !== '' ? ' · ' . $e($company) : '' ?></p>
    <h2 class="paper-doc__title"><?= $e($t('safety.forms.' . $form)) ?></h2>
    <p class="paper-doc__meta"><?= $e($t('safety.forms.ai_label_short')) ?><?= $isTemplate ? '' : ' · ' . $e($t('safety.forms.policy_v', ['v' => (string) ($pol['_version'] ?? '')])) ?></p>
  </header>

<?php if ($form === 'report'): ?>
  <section><h3><?= $e($t('safety.forms.s.reporter')) ?></h3>
    <p><?= $e($t('safety.forms.f.name')) ?> <?= $lines(1) ?></p>
    <p><?= $e($t('safety.forms.f.unit')) ?> <?= $lines(1) ?></p>
    <p class="text-sm"><?= $box ?> <?= $e($t('safety.forms.f.anonymous')) ?></p>
  </section>
  <section><h3><?= $e($t('safety.field.kind')) ?></h3>
    <p><?php foreach (\Blue\Modules\Safety\SafetyService::KINDS as $k): ?><span class="sf-choice"><?= $box ?> <?= $e($t('safety.kind.' . $k)) ?></span><?php endforeach; ?></p>
  </section>
  <section><h3><?= $e($t('safety.field.severity')) ?></h3>
    <ul class="sf-levels"><?php foreach (SafetyPolicy::LEVELS as $l): $x = $lv($l); ?>
      <li><?= $box ?> <strong><?= $l ?> · <?= $e((string) ($x['name'] ?? '')) ?></strong> — <?= $e((string) ($x['meaning'] ?? '')) ?></li>
    <?php endforeach; ?></ul>
  </section>
  <section><h3><?= $e($t('safety.forms.s.when_where')) ?></h3>
    <p><?= $e($t('safety.forms.f.date_time')) ?> <?= $lines(1) ?></p>
    <p><?= $e($t('safety.field.site')) ?> / <?= $e($t('safety.field.location')) ?> <?= $lines(1) ?></p>
  </section>
  <section><h3><?= $e($t('safety.field.description')) ?></h3><?= $lines(5) ?></section>
  <section><h3><?= $e($t('safety.field.immediate_action')) ?></h3><?= $lines(3) ?></section>
  <section><h3><?= $e($t('safety.field.people')) ?></h3><?= $lines(3) ?></section>
  <section class="sf-sign"><p><?= $e($t('safety.forms.f.reporter_sign')) ?> <?= $lines(1) ?></p><p><?= $e($t('safety.forms.f.received_by')) ?> <?= $lines(1) ?></p></section>
<?php elseif ($form === 'near-miss'): ?>
  <section><p class="text-sm"><?= $box ?> <?= $e($t('safety.kind.near_miss')) ?> <span class="sf-choice"><?= $box ?> <?= $e($t('safety.kind.hazard')) ?></span></p></section>
  <section><h3><?= $e($t('safety.forms.f.what_seen')) ?></h3><?= $lines(4) ?></section>
  <section><h3><?= $e($t('safety.forms.s.when_where')) ?></h3><?= $lines(2) ?></section>
  <section><h3><?= $e($t('safety.forms.f.could_happen')) ?></h3><?= $lines(3) ?></section>
  <section><h3><?= $e($t('safety.forms.f.suggestion')) ?></h3><?= $lines(3) ?></section>
  <section><h3><?= $e($t('safety.field.severity')) ?></h3>
    <p><?php foreach (SafetyPolicy::LEVELS as $l): $x = $lv($l); ?><span class="sf-choice"><?= $box ?> <?= $l ?> · <?= $e((string) ($x['name'] ?? '')) ?></span><?php endforeach; ?></p>
  </section>
  <section class="sf-sign"><p><?= $e($t('safety.forms.f.name_optional')) ?> <?= $lines(1) ?></p></section>
<?php elseif ($form === 'witness'): ?>
  <section><h3><?= $e($t('safety.forms.s.case')) ?></h3>
    <p><?= $e($t('safety.forms.f.ref')) ?> <?= $lines(1) ?></p>
    <p><?= $e($t('safety.forms.f.statement_at')) ?> <?= $lines(1) ?></p>
  </section>
  <section><h3><?= $e($t('safety.field.witness')) ?></h3>
    <p><?= $e($t('safety.forms.f.name')) ?> <?= $lines(1) ?></p>
    <p><?= $e($t('safety.forms.f.unit')) ?> <?= $lines(1) ?></p>
    <p><?= $e($t('safety.forms.f.relation')) ?> <?= $lines(1) ?></p>
  </section>
  <section><h3><?= $e($t('safety.field.statement')) ?></h3><?= $lines(10) ?></section>
  <section><p class="text-sm"><?= $e($t('safety.forms.f.witness_note')) ?></p></section>
  <section class="sf-sign"><p><?= $e($t('safety.forms.f.witness_sign')) ?> <?= $lines(1) ?></p><p><?= $e($t('safety.forms.f.interviewer_sign')) ?> <?= $lines(1) ?></p></section>
<?php else: ?>
  <section><h3><?= $e($t('safety.forms.s.matrix')) ?></h3>
    <div class="table-wrap" role="region" aria-label="<?= $e($t('safety.forms.s.matrix')) ?>" tabindex="0">
    <table class="sf-matrix">
      <caption class="sr-only"><?= $e($t('safety.forms.matrix_caption')) ?></caption>
      <thead><tr><th scope="col"><?= $e($t('safety.forms.lik_imp')) ?></th><?php foreach (SafetyPolicy::SCALE as $i): ?><th scope="col"><?= $i ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach (array_reverse(SafetyPolicy::SCALE) as $l): ?>
        <tr><th scope="row"><?= $l ?></th>
        <?php foreach (SafetyPolicy::SCALE as $i): $s = $l * $i; $b = $bandFor($s); ?>
          <td class="sf-band sf-band--<?= $e($b) ?>"><?= $s ?><span class="sf-band__label"> <?= $e($t('safety.policy.band.' . $b)) ?></span></td>
        <?php endforeach; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </section>
  <div class="grid grid--2">
    <section><h3><?= $e($t('safety.field.likelihood')) ?></h3><ol class="sf-scale"><?php foreach (SafetyPolicy::SCALE as $s): ?><li value="<?= $s ?>"><?= $e($sc('likelihood', $s)) ?></li><?php endforeach; ?></ol></section>
    <section><h3><?= $e($t('safety.field.impact')) ?></h3><ol class="sf-scale"><?php foreach (SafetyPolicy::SCALE as $s): ?><li value="<?= $s ?>"><?= $e($sc('impact', $s)) ?></li><?php endforeach; ?></ol></section>
  </div>
  <section><h3><?= $e($t('safety.forms.s.bands')) ?></h3>
    <ul class="sf-levels"><?php $prev = 0; foreach ((array) $pol['bands'] as $b): ?>
      <li><strong><?= $e($t('safety.policy.band.' . $b['key'])) ?> (<?= $prev + 1 ?>–<?= (int) $b['max'] ?>)</strong> — <?= $e((string) $b['action']) ?></li>
    <?php $prev = (int) $b['max']; endforeach; ?></ul>
  </section>
<?php endif; ?>
  <footer class="paper-doc__foot"><?= $e($t('safety.forms.foot')) ?></footer>
</article>
<script src="<?= $e($asset('ui/js/print.js')) ?>" defer></script>
