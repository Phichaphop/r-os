<?php
/**
 * Send an anonymous complaint, follow one with its code, and my (optional) list.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var callable $partial @var array<string, mixed> $v
 * @var int|null $le @var array<string, mixed>|null $settings @var string|null $notice @var bool $alternate @var string $company
 * @var list<array<string, mixed>> $rows @var array<string, string> $errors @var array<string, mixed> $old @var string|null $alert
 */
$o = static fn (string $k, string $d = ''): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$cats = [];
foreach (\Blue\Modules\Complaints\ComplaintService::CATEGORIES as $c) {
    $cats[$c] = $t('complaints.category.' . $c);
}
?>
<?= $ui('page-header', ['title' => $t('complaints.mine.title'), 'subtitle' => $t('complaints.mine.subtitle')]) ?>
<?php if ($alert !== null): ?><?= $ui('alert', ['tone' => 'danger', 'text' => $alert]) ?><?php endif; ?>
<?php if ($le === null): ?>
  <?= $ui('alert', ['tone' => 'warning', 'text' => $t('complaints.error.not_employee')]) ?>
<?php elseif ($settings === null || !$settings['open']): ?>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('complaints.mine.closed', ['company' => $company])]) ?>
<?php else: ?>
<section class="card stack" aria-labelledby="cp-new">
  <h2 id="cp-new" class="card__title"><?= $e($t('complaints.new.title')) ?></h2>
  <ul class="stack-sm text-sm cp-promise">
    <li><?= $e($t('complaints.promise.1')) ?></li>
    <li><?= $e($t('complaints.promise.2')) ?></li>
    <li><?= $e($t('complaints.promise.3')) ?></li>
    <li><?= $e($t('complaints.promise.4')) ?></li>
  </ul>
  <?php if ($settings['policy_text'] !== ''): ?>
  <details class="cp-policy"><summary><?= $e($t('complaints.policy_title', ['company' => $company])) ?></summary><p class="cp-text"><?= nl2br($e((string) $settings['policy_text'])) ?></p></details>
  <?php endif; ?>
  <?= $ui('alert', ['tone' => 'warning', 'text' => $t('complaints.urgent_note')]) ?>
  <form method="post" action="<?= $e($url('/complaints')) ?>" enctype="multipart/form-data" class="stack" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'category', 'id' => 'cp-cat', 'type' => 'select', 'label' => $t('complaints.field.category'), 'required' => true, 'value' => $o('category'),
        'options' => ['' => $t('complaints.choose')] + $cats, 'error' => $errors['category'] ?? null]) ?>
    <?= $ui('field', ['name' => 'title', 'id' => 'cp-title', 'label' => $t('complaints.field.title'), 'required' => true, 'value' => $o('title'), 'autocomplete' => 'off', 'error' => $errors['title'] ?? null]) ?>
    <?= $ui('field', ['name' => 'body', 'id' => 'cp-body', 'type' => 'textarea', 'label' => $t('complaints.field.body'), 'required' => true, 'value' => $o('body'), 'help' => $t('complaints.body_help'), 'error' => $errors['body'] ?? null]) ?>
    <?php if ($alternate): ?>
    <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('complaints.field.route')) ?></legend>
      <label class="check"><input type="radio" name="route" value="normal"<?= $o('route', 'normal') !== 'alternate' ? ' checked' : '' ?>> <?= $e($t('complaints.route.normal')) ?></label>
      <label class="check"><input type="radio" name="route" value="alternate"<?= $o('route') === 'alternate' ? ' checked' : '' ?>> <?= $e($t('complaints.route.alternate')) ?></label>
      <p class="field__help"><?= $e($t('complaints.route_help')) ?></p>
      <?php if (isset($errors['route'])): ?><p class="field__error"><?= $e($errors['route']) ?></p><?php endif; ?>
    </fieldset>
    <?php endif; ?>
    <?= $ui('dropzone', ['name' => 'files[]', 'id' => 'cp-files', 'label' => $t('complaints.field.files'), 'multiple' => true, 'accept' => '.png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp', 'help' => $t('complaints.files_help'), 'error' => $errors['files'] ?? null]) ?>
    <div class="stack-sm">
      <label class="check"><input type="checkbox" name="ack" value="1" aria-describedby="cp-ack-help<?= isset($errors['ack']) ? ' cp-ack-err' : '' ?>"<?= $o('ack') !== '' ? ' checked' : '' ?>> <?= $e($t('complaints.field.ack')) ?></label>
      <p class="field__help" id="cp-ack-help"><?php if ($notice !== null): ?><a href="<?= $e($url($notice)) ?>" target="_blank" rel="noopener"><?= $e($t('complaints.notice_link')) ?></a><?php endif; ?></p>
      <?php if (isset($errors['ack'])): ?><p class="field__error" id="cp-ack-err"><?= $e($errors['ack']) ?></p><?php endif; ?>
    </div>
    <div class="stack-sm">
      <label class="check"><input type="checkbox" name="remember" value="1" aria-describedby="cp-rem-help"<?= $o('remember') !== '' ? ' checked' : '' ?>> <?= $e($t('complaints.field.remember')) ?></label>
      <p class="field__help" id="cp-rem-help"><?= $e($t('complaints.remember_help')) ?></p>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('complaints.new.submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
  </form>
</section>
<?php endif; ?>

<section class="card stack-sm" aria-labelledby="cp-tr">
  <h2 id="cp-tr" class="card__title"><?= $e($t('complaints.track.title')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('complaints.track.help')) ?> <a href="<?= $e($url('/speak-up/track')) ?>"><?= $e($t('complaints.track.public_link')) ?></a></p>
  <form method="post" action="<?= $e($url('/complaints/track')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'code', 'id' => 'cp-code', 'label' => $t('complaints.field.code'), 'required' => true, 'autocomplete' => 'off', 'help' => $t('complaints.code_help')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('complaints.track.open'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
  </form>
</section>

<section class="card stack-sm" aria-labelledby="cp-my">
  <h2 id="cp-my" class="card__title"><?= $e($t('complaints.mine.list')) ?></h2>
  <?php if ($rows === []): ?><p class="text-sm text-muted"><?= $e($t('complaints.mine.none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="cp-my" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('complaints.field.ref')) ?></th><th scope="col"><?= $e($t('complaints.field.category')) ?></th><th scope="col"><?= $e($t('complaints.field.status')) ?></th><th scope="col"><?= $e($t('complaints.field.replies')) ?></th><th scope="col"><span class="sr-only"><?= $e($t('complaints.actions')) ?></span></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <th scope="row" data-label="<?= $e($t('complaints.field.ref')) ?>"><?= $e((string) $r['ref']) ?></th>
          <td data-label="<?= $e($t('complaints.field.category')) ?>"><?= $e($t('complaints.category.' . $r['category'])) ?></td>
          <td data-label="<?= $e($t('complaints.field.status')) ?>"><?= $partial('@complaints/_status', ['s' => (string) $r['status']]) ?></td>
          <td data-label="<?= $e($t('complaints.field.replies')) ?>"><?= (int) $r['replies'] ?></td>
          <td class="row">
            <form method="post" action="<?= $e($url('/complaints/track')) ?>"><?= $csrf ?><input type="hidden" name="code" value="<?= $e((string) $r['code']) ?>"><?= $ui('button', ['label' => $t('complaints.track.open'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm']) ?></form>
            <form method="post" action="<?= $e($url('/complaints/forget')) ?>"><?= $csrf ?><input type="hidden" name="ref" value="<?= $e((string) $r['ref']) ?>"><?= $ui('button', ['label' => $t('complaints.forget'), 'type' => 'submit', 'variant' => 'ghost', 'size' => 'sm']) ?></form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
