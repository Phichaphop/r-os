<?php
/**
 * Shown once after sending: the reference and the tracking code (kept nowhere in readable form), as a follow-up ticket
 * the sender saves on their own device (0.44.2 · DEC-113). The ticket carries no subject or details and a neutral title;
 * the picture is drawn in the browser and the PDF is the browser's print — the server stores neither.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var callable $asset @var array<string, mixed> $v
 * @var array{ref: string, code: string, remembered: bool} $res
 * @var array{url: string, qr: string, modules: string, company: string, day: string} $ticket
 */
$day = $date($ticket['day'], false);
?>
<?= $ui('page-header', ['title' => $t('complaints.sent.title'), 'subtitle' => $t('complaints.sent.subtitle', ['ref' => $res['ref']])]) ?>
<div class="stack" data-cp-ticket
  data-title="<?= $e($t('complaints.ticket.title')) ?>" data-company="<?= $e($ticket['company']) ?>"
  data-l-ref="<?= $e($t('complaints.ticket.ref')) ?>" data-ref="<?= $e($res['ref']) ?>"
  data-l-code="<?= $e($t('complaints.ticket.code')) ?>" data-code="<?= $e($res['code']) ?>"
  data-l-day="<?= $e($t('complaints.ticket.day')) ?>" data-day="<?= $e($day) ?>"
  data-how="<?= $e($t('complaints.ticket.how', ['url' => $ticket['url']])) ?>" data-keep="<?= $e($t('complaints.ticket.keep')) ?>"
  data-modules="<?= $e($ticket['modules']) ?>" data-file="<?= $e('blue-ticket-' . strtolower($res['ref']) . '.png') ?>"
  data-msg-copied="<?= $e($t('complaints.ticket.copied')) ?>" data-msg-saved="<?= $e($t('complaints.ticket.saved')) ?>" data-msg-failed="<?= $e($t('complaints.ticket.failed')) ?>">
  <?= $ui('alert', ['tone' => 'warning', 'text' => $t('complaints.sent.warning')]) ?>
  <article class="card cp-ticket" aria-labelledby="cp-ticket-h">
    <header class="cp-ticket__head">
      <h2 id="cp-ticket-h" class="cp-ticket__title"><?= $e($t('complaints.ticket.title')) ?></h2>
      <?php if ($ticket['company'] !== ''): ?><p class="cp-ticket__company"><?= $e($ticket['company']) ?></p><?php endif; ?>
    </header>
    <div class="cp-ticket__body">
      <div class="cp-ticket__qr"><?= $ticket['qr'] ?></div>
      <dl class="cp-ticket__facts">
        <div><dt><?= $e($t('complaints.ticket.ref')) ?></dt><dd><?= $e($res['ref']) ?></dd></div>
        <div><dt><?= $e($t('complaints.ticket.code')) ?></dt><dd class="cp-code" id="cp-code-value"><?= $e($res['code']) ?></dd></div>
        <div><dt><?= $e($t('complaints.ticket.day')) ?></dt><dd><?= $e($day) ?></dd></div>
      </dl>
    </div>
    <p class="text-sm cp-ticket__how"><?= $e($t('complaints.ticket.how', ['url' => $ticket['url']])) ?></p>
    <p class="text-sm text-muted cp-ticket__keep"><?= $e($t('complaints.ticket.keep')) ?></p>
  </article>
  <div class="row cp-ticket__actions print-hide">
    <?= $ui('button', ['label' => $t('complaints.ticket.save_image'), 'type' => 'button', 'variant' => 'primary', 'icon' => 'download', 'attrs' => ['data-cp-ticket-image' => '1']]) ?>
    <?= $ui('button', ['label' => $t('complaints.ticket.save_pdf'), 'type' => 'button', 'variant' => 'secondary', 'icon' => 'printer', 'attrs' => ['data-cp-ticket-print' => '1']]) ?>
    <?= $ui('button', ['label' => $t('complaints.ticket.copy'), 'type' => 'button', 'variant' => 'secondary', 'icon' => 'copy', 'attrs' => ['data-cp-ticket-copy' => '1']]) ?>
  </div>
  <p class="text-sm print-hide" data-cp-ticket-status role="status" aria-live="polite"></p>
  <section class="card stack-sm print-hide" aria-labelledby="cp-next-h">
    <h2 id="cp-next-h" class="card__title"><?= $e($t('complaints.sent.next_title')) ?></h2>
    <label class="field__label" for="cp-code-copy"><?= $e($t('complaints.sent.copy_label')) ?></label>
    <input class="input" id="cp-code-copy" readonly value="<?= $e($res['code']) ?>">
    <?php if ($res['remembered']): ?><p class="text-sm"><?= $e($t('complaints.sent.remembered')) ?></p><?php endif; ?>
    <ul class="stack-sm text-sm">
      <li><?= $e($t('complaints.sent.next1')) ?></li>
      <li><?= $e($t('complaints.sent.next2')) ?></li>
      <li><?= $e($t('complaints.ticket.privacy')) ?></li>
    </ul>
    <div class="row"><a class="btn btn--secondary" href="<?= $e($url('/complaints')) ?>"><?= $e($t('complaints.sent.back')) ?></a></div>
  </section>
</div>
<script src="<?= $e($asset('ui/js/complaint-ticket.js')) ?>" defer></script>
