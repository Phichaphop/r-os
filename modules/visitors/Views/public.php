<?php
/**
 * The visitor's own page (no sign-in): the visit, a QR to show at the gate, and the company's privacy notice for visitors.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed>|null $visit @var string|null $qr
 */
$vv = $visit;
?>
<div class="stack visit-public">
<?php if ($vv === null): ?>
  <section class="stack-sm"><h1 class="card__title"><?= $e($t('visitors.public.none')) ?></h1><p><?= $e($t('visitors.public.none_text')) ?></p></section>
<?php else: ?>
  <section class="stack-sm" aria-labelledby="vp-h">
    <h1 id="vp-h"><?= $e($t('visitors.public.title')) ?></h1>
    <p><?= $e($t('visitors.public.hello', ['name' => (string) $vv['visitor_name'], 'company' => (string) $vv['company']])) ?></p>
    <dl class="facts">
      <div><dt><?= $e($t('visitors.field.ref')) ?></dt><dd><strong><?= $e((string) $vv['ref']) ?></strong></dd></div>
      <div><dt><?= $e($t('visitors.field.window')) ?></dt><dd><?= $e($date((string) $vv['expected_from'])) ?> – <?= $e($date((string) $vv['expected_to'])) ?></dd></div>
      <div><dt><?= $e($t('visitors.field.host')) ?></dt><dd><?= $e((string) $vv['host_name']) ?></dd></div>
      <?php if ($vv['site_code'] !== null): ?><div><dt><?= $e($t('visitors.field.site')) ?></dt><dd><?= $e((string) $vv['site_code']) ?></dd></div><?php endif; ?>
    </dl>
    <div class="visit-qr"><?= $qr ?></div>
    <p class="text-sm"><?= $e($t('visitors.public.show_qr')) ?></p>
    <?php if ($vv['notice_url'] !== null): ?>
      <p><a href="<?= $e($url((string) $vv['notice_url'])) ?>"><?= $e($t('visitors.public.notice')) ?></a></p>
    <?php endif; ?>
  </section>
<?php endif; ?>
</div>
