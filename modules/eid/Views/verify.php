<?php
/**
 * Verify a scanned card. @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $asset @var array<string, mixed> $v
 * @var array{result: string, card?: array<string, mixed>, face?: array<string, mixed>}|null $result
 */
$loc = (string) ($v['locale'] ?? 'th');
include __DIR__ . '/_helpers.php';
$tone = ['valid' => 'success', 'not_valid' => 'danger', 'expired_qr' => 'warning', 'unknown' => 'danger', 'not_allowed' => 'warning'];
?>
<?= $ui('page-header', ['title' => $t('eid.verify_title'), 'subtitle' => $t('eid.verify_subtitle')]) ?>
<?php if ($result !== null): ?>
  <?= $ui('alert', ['tone' => $tone[$result['result']] ?? 'info', 'title' => $t('eid.verify.' . $result['result']), 'text' => $t('eid.verify_help.' . $result['result']), 'live' => true]) ?>
  <?php if (isset($result['face'])): ?>
  <section class="card stack eid-verify" aria-labelledby="vf-h">
    <h2 id="vf-h" class="card__title"><?= $e((string) $result['face']['name']) ?></h2>
    <?= $cardFace($result['face'], null) ?>
    <dl class="facts">
      <div><dt><?= $e($t('eid.field.code')) ?></dt><dd><?= $e((string) $result['face']['code']) ?></dd></div>
      <div><dt><?= $e($t('eid.field.status')) ?></dt><dd><?= $eidStatus((string) $result['face']['status']) ?></dd></div>
    </dl>
    <p class="text-sm text-muted"><?= $e($t('eid.verify_minimal')) ?></p>
  </section>
  <?php endif; ?>
<?php endif; ?>
<section class="card stack" aria-labelledby="vm-h">
  <h2 id="vm-h" class="card__title"><?= $e($t('eid.verify_manual')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('eid.verify_how')) ?></p>
  <form method="post" action="<?= $e($url('/eid/verify')) ?>" class="row row--tight" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?= $ui('field', ['name' => 'code', 'id' => 'vf-code', 'label' => $t('eid.field.scan'), 'autocomplete' => 'off', 'autofocus' => $result === null]) ?>
    <?= $ui('button', ['label' => $t('eid.verify_btn'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'qrcode']) ?>
  </form>
</section>
