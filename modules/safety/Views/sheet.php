<?php
/**
 * The investigation sheet of one incident (A4) for those who see the whole record (0.45.0 · DEC-114). Medical details are
 * never on it. Internal and confidential.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var callable $asset
 * @var array<string, mixed> $in @var bool $canManage @var string $here
 */
$pol = $in['policy'];
$lvName = '';
if (is_array($pol)) {
    $x = (array) ($pol['levels'][(int) $in['severity']] ?? $pol['levels'][(string) $in['severity']] ?? []);
    $lvName = (string) ($x['name'] ?? '');
}
$para = static fn (?string $s): string => $s === null || trim($s) === '' ? '—' : nl2br(htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
?>
<?= $ui('page-header', ['title' => $t('safety.sheet.title') . ' ' . $in['ref'], 'subtitle' => $t('safety.sheet.subtitle'),
    'actions' => $ui('button', ['label' => $t('safety.forms.print'), 'type' => 'button', 'variant' => 'primary', 'icon' => 'printer', 'attrs' => ['data-print' => '1']])]) ?>
<p class="print-hide"><a href="<?= $e($url('/safety/i/' . $in['public_id'])) ?>"><?= $e($t('safety.sheet.back')) ?></a></p>
<article class="paper-doc sf-form" aria-label="<?= $e($t('safety.sheet.title')) ?>">
  <header>
    <p class="paper-doc__kicker"><?= $e($t('safety.sheet.kicker')) ?></p>
    <h2 class="paper-doc__title"><?= $e($t('safety.sheet.title')) ?> <?= $e((string) $in['ref']) ?></h2>
    <p class="paper-doc__meta"><?= $e($t('safety.kind.' . $in['kind'])) ?> · <?= $e($t('safety.field.severity')) ?> <?= (int) $in['severity'] ?><?= $lvName !== '' ? ' (' . $e($lvName) . ')' : '' ?> · <?= $e($t('safety.status.' . $in['status'])) ?></p>
  </header>
  <section><h3><?= $e($t('safety.forms.s.when_where')) ?></h3>
    <p><?= $e($t('safety.field.occurred_at')) ?>: <?= $e($date((string) $in['occurred_at'])) ?> · <?= $e($t('safety.field.site')) ?>: <?= $e((string) ($in['site'] ?? '—')) ?> · <?= $e($t('safety.field.location')) ?>: <?= $e((string) $in['location']) ?></p>
    <p><?= $e($t('safety.sheet.reporter')) ?>: <?= (int) $in['anonymous'] === 1 ? $e($t('safety.sheet.anonymous')) : $e((string) ($in['reporter'] ?? '—')) ?> · <?= $e($t('safety.field.investigator')) ?>: <?= $e((string) ($in['investigator'] ?? '—')) ?></p>
  </section>
  <section><h3><?= $e($t('safety.field.description')) ?></h3><p><?= $para($in['description']) ?></p></section>
  <section><h3><?= $e($t('safety.field.immediate_action')) ?></h3><p><?= $para($in['immediate_action']) ?></p></section>
  <section><h3><?= $e($t('safety.field.people')) ?></h3><p><?= $para($in['people']) ?></p></section>
  <section><h3><?= $e($t('safety.field.facts')) ?></h3><p><?= $para($in['facts']) ?></p></section>
  <section><h3><?= $e($t('safety.field.root_cause')) ?></h3><p><?= $para($in['root_cause']) ?></p></section>
  <section><h3><?= $e($t('safety.field.temp_control')) ?></h3><p><?= $para($in['temp_control']) ?></p></section>
  <section><h3><?= $e($t('safety.sheet.risk')) ?></h3>
    <?php if ($in['likelihood'] === null || $in['impact'] === null): ?><p>—</p><?php else: ?>
    <p><?= $e($t('safety.risk_score', ['l' => (string) $in['likelihood'], 'i' => (string) $in['impact'], 'score' => (string) ((int) $in['likelihood'] * (int) $in['impact'])])) ?>
      <?php if ($in['band'] !== null): ?> · <strong><?= $e($t('safety.policy.band.' . $in['band']['key'])) ?></strong> — <?= $e((string) $in['band']['action']) ?><?php endif; ?></p>
    <?php endif; ?>
  </section>
  <section><h3><?= $e($t('safety.sheet.witnesses')) ?></h3>
    <?php if ($in['witnesses'] === []): ?><p>—</p><?php else: ?><ul><?php foreach ($in['witnesses'] as $w): ?><li><strong><?= $e((string) $w['name']) ?></strong>: <?= $e((string) $w['statement']) ?></li><?php endforeach; ?></ul><?php endif; ?>
  </section>
  <section><h3><?= $e($t('safety.sheet.actions')) ?></h3>
    <?php if ($in['actions'] === []): ?><p>—</p><?php else: ?>
    <ul><?php foreach ($in['actions'] as $a): ?><li><?= $e($t('safety.action_kind.' . $a['kind'])) ?>: <?= $e((string) $a['description']) ?> — <?= $e((string) $a['owner']) ?> · <?= $e($t('safety.field.due_on')) ?> <?= $e($date((string) $a['due_on'], false)) ?> · <?= $e($t('safety.action_status.' . $a['status'])) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </section>
  <?php if ($in['outcome'] !== null && $in['outcome'] !== ''): ?><section><h3><?= $e($t('safety.sheet.outcome')) ?></h3><p><?= $para($in['outcome']) ?></p></section><?php endif; ?>
  <section class="sf-sign"><p><?= $e($t('safety.sheet.sign_investigator')) ?> <span class="sf-line" aria-hidden="true"></span></p><p><?= $e($t('safety.sheet.sign_reviewer')) ?> <span class="sf-line" aria-hidden="true"></span></p></section>
  <footer class="paper-doc__foot"><?= $e($t('safety.sheet.foot', ['d' => $date($in['today'], false)])) ?></footer>
</article>
<script src="<?= $e($asset('ui/js/print.js')) ?>" defer></script>
