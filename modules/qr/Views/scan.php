<?php
/**
 * 0.41.1 — QR reader. Everything happens in the browser (jsQR, Apache-2.0, ui/js/vendor/jsqr): the picture or the
 * camera image is never sent to the server — there is no <form> and the script makes no
 * requests. Camera works on HTTPS (or localhost) and only on this page (Permissions-Policy camera=(self)).
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $asset @var array<string, mixed> $v
 */
?>
<?= $ui('page-header', ['title' => $t('qr.scan.title'), 'subtitle' => $t('qr.scan.subtitle'),
    'actions' => $ui('button', ['label' => $t('qr.scan.to_create'), 'href' => $url('/qr'), 'variant' => 'secondary', 'icon' => 'qrcode'])]) ?>
<div class="settings-grid qr-scan" data-qr-scan
  data-msg-none="<?= $e($t('qr.scan.none')) ?>" data-msg-not-image="<?= $e($t('qr.scan.not_image')) ?>"
  data-msg-bad-image="<?= $e($t('qr.scan.bad_image')) ?>" data-msg-reading="<?= $e($t('qr.scan.reading')) ?>"
  data-msg-no-camera="<?= $e($t('qr.scan.no_camera')) ?>" data-msg-camera-denied="<?= $e($t('qr.scan.camera_denied')) ?>"
  data-msg-camera-https="<?= $e($t('qr.scan.camera_https')) ?>" data-msg-looking="<?= $e($t('qr.scan.looking')) ?>"
  data-msg-done="<?= $e($t('qr.scan.done')) ?>" data-msg-copied="<?= $e($t('qr.scan.copied')) ?>" data-msg-copy-failed="<?= $e($t('qr.scan.copy_failed')) ?>"
  data-msg-copy="<?= $e($t('qr.scan.copy')) ?>" data-msg-open="<?= $e($t('qr.scan.open')) ?>"
  data-msg-link-warn="<?= $e($t('qr.scan.link_warn')) ?>" data-msg-unsafe-link="<?= $e($t('qr.scan.unsafe_link')) ?>"
  data-msg-show="<?= $e($t('qr.scan.show_password')) ?>" data-msg-hide="<?= $e($t('qr.scan.hide_password')) ?>"
  data-type-url="<?= $e($t('qr.content_type.url')) ?>" data-type-text="<?= $e($t('qr.content_type.text')) ?>" data-type-tel="<?= $e($t('qr.content_type.tel')) ?>"
  data-type-email="<?= $e($t('qr.content_type.email')) ?>" data-type-wifi="<?= $e($t('qr.content_type.wifi')) ?>" data-type-vcard="<?= $e($t('qr.content_type.vcard')) ?>"
  data-type-sms="<?= $e($t('qr.scan.type_sms')) ?>" data-type-geo="<?= $e($t('qr.scan.type_geo')) ?>"
  data-f-network="<?= $e($t('qr.scan.f_network')) ?>" data-f-security="<?= $e($t('qr.scan.f_security')) ?>" data-f-password="<?= $e($t('qr.scan.f_password')) ?>"
  data-f-hidden="<?= $e($t('qr.scan.f_hidden')) ?>" data-f-name="<?= $e($t('qr.scan.f_name')) ?>" data-f-org="<?= $e($t('qr.scan.f_org')) ?>"
  data-f-title="<?= $e($t('qr.scan.f_title')) ?>" data-f-phone="<?= $e($t('qr.scan.f_phone')) ?>" data-f-email="<?= $e($t('qr.scan.f_email')) ?>"
  data-f-web="<?= $e($t('qr.scan.f_web')) ?>" data-f-address="<?= $e($t('qr.scan.f_address')) ?>" data-f-subject="<?= $e($t('qr.scan.f_subject')) ?>"
  data-f-body="<?= $e($t('qr.scan.f_body')) ?>" data-f-to="<?= $e($t('qr.scan.f_to')) ?>" data-f-place="<?= $e($t('qr.scan.f_place')) ?>"
  data-f-raw="<?= $e($t('qr.scan.f_raw')) ?>" data-f-yes="<?= $e($t('qr.scan.f_yes')) ?>">
  <section class="card stack" aria-labelledby="qr-scan-in-h">
    <h2 id="qr-scan-in-h" class="card__title"><?= $e($t('qr.scan.input_title')) ?></h2>
    <noscript><?= $ui('alert', ['tone' => 'warning', 'text' => $t('qr.scan.needs_script')]) ?></noscript>
    <?= $ui('dropzone', ['name' => 'qr_image', 'id' => 'qr-scan-file', 'label' => $t('qr.scan.image'), 'accept' => 'image/*,.png,.jpg,.jpeg,.webp,.gif,.bmp',
        'hint' => $t('qr.scan.drop'), 'help' => $t('qr.scan.image_help'), 'data' => ['qr-scan-file' => '1']]) ?>
    <div class="row qr-scan__camera-row">
      <?= $ui('button', ['label' => $t('qr.scan.camera_start'), 'type' => 'button', 'variant' => 'primary', 'icon' => 'camera', 'attrs' => ['data-qr-scan-start' => '1']]) ?>
      <?= $ui('button', ['label' => $t('qr.scan.camera_stop'), 'type' => 'button', 'variant' => 'secondary', 'icon' => 'x', 'attrs' => ['data-qr-scan-stop' => '1', 'hidden' => 'hidden']]) ?>
    </div>
    <div class="qr-scan__video" data-qr-scan-video-box hidden>
      <video class="qr-scan__video-el" data-qr-scan-video muted playsinline aria-label="<?= $e($t('qr.scan.camera_view')) ?>"></video>
      <span class="qr-scan__frame" aria-hidden="true"></span>
    </div>
    <p class="text-sm text-muted"><?= $e($t('qr.scan.privacy')) ?></p>
  </section>

  <section class="card stack" aria-labelledby="qr-scan-out-h">
    <h2 id="qr-scan-out-h" class="card__title"><?= $e($t('qr.scan.result_title')) ?></h2>
    <p class="text-sm" data-qr-scan-status role="status" aria-live="polite"><?= $e($t('qr.scan.waiting')) ?></p>
    <div class="stack" data-qr-scan-result hidden></div>
    <img class="qr-scan__thumb" data-qr-scan-thumb alt="<?= $e($t('qr.scan.thumb_alt')) ?>" hidden>
  </section>
</div>
<script src="<?= $e($asset('ui/js/vendor/jsqr/jsQR.js')) ?>" defer></script>
<script src="<?= $e($asset('ui/js/qr-scan.js')) ?>" defer></script>
