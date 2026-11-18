<?php
/** Public page of a managed QR that no longer works (revoked / expired / unknown) — no detail about the code. @var callable $e @var callable $t @var callable $ui @var string $state */
?>
<div class="stack">
  <?= $ui('states', ['kind' => 'empty', 'icon' => 'qrcode', 'title' => $t('qr.gone.title'), 'text' => $t('qr.gone.' . $state)]) ?>
</div>
