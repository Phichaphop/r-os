<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed>|null $link @var array<string, mixed> $old @var array<string, string> $errors @var list<string> $warnings
 * @var list<string> $roles @var array<int, string> $companies
 */
use Blue\Modules\Links\LinksService;

$pid = $link['public_id'] ?? null;
$action = $url($pid === null ? '/admin/links/new' : '/admin/links/' . $pid);
$selRoles = is_array($old['audience_roles'] ?? null) ? $old['audience_roles'] : array_filter(explode(',', (string) ($old['audience_roles'] ?? '')));
$st = ['draft' => ['neutral', 'pencil'], 'published' => ['success', 'circle-check'], 'disabled' => ['warning', 'eye-off'], 'archived' => ['neutral', 'history']];
?>
<?= $ui('page-header', ['title' => $link === null ? $t('links.new') : (string) $link['name_th'],
    'subtitle' => $link === null ? $t('links.new_help') : $t('links.revision_n', ['n' => (int) $link['revision']]),
    'actions' => $link !== null ? $ui('badge', ['tone' => ($st[$link['status']] ?? ['neutral'])[0], 'icon' => ($st[$link['status']] ?? ['', 'info-circle'])[1], 'label' => $t('links.status.' . $link['status'])]) : '']) ?>
<p><a href="<?= $e($url('/admin/links')) ?>"><?= $ui('icon', ['name' => 'chevron-left', 'size' => 'sm']) ?><?= $e($t('links.back')) ?></a></p>
<?php foreach ($warnings as $w): ?>
  <?= $ui('alert', ['tone' => 'warning', 'text' => $t($w)]) ?>
<?php endforeach; ?>
<?php if ($link !== null && $link['pending'] !== null): ?>
  <?= $ui('alert', ['tone' => 'info', 'title' => $t('links.pending_title'), 'text' => $t('links.pending_text', ['url' => (string) $link['url']])]) ?>
<?php endif; ?>
<div class="settings-grid">
<form method="post" action="<?= $e($action) ?>" class="card stack" novalidate aria-labelledby="link-form-h">
  <h2 id="link-form-h" class="card__title"><?= $e($t('links.details')) ?></h2>
  <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
  <?= $ui('field', ['name' => 'name_th', 'label' => $t('links.field.name_th'), 'required' => true, 'value' => (string) ($old['name_th'] ?? ''), 'error' => $errors['name_th'] ?? null]) ?>
  <?= $ui('field', ['name' => 'name_en', 'label' => $t('links.field.name_en'), 'value' => (string) ($old['name_en'] ?? '')]) ?>
  <?= $ui('field', ['name' => 'url', 'label' => $t('links.field.url'), 'required' => true, 'value' => (string) ($old['url'] ?? ''), 'placeholder' => 'https://', 'error' => $errors['url'] ?? null, 'help' => $t('links.url_help')]) ?>
  <?= $ui('field', ['name' => 'description', 'label' => $t('links.field.description'), 'value' => (string) ($old['description'] ?? '')]) ?>
  <div class="grid grid--2">
    <?= $ui('field', ['name' => 'category', 'label' => $t('links.field.category'), 'type' => 'select', 'required' => true, 'value' => (string) ($old['category'] ?? 'other'), 'error' => $errors['category'] ?? null,
        'options' => array_combine(LinksService::CATEGORIES, array_map(static fn ($c) => $t('links.cat.' . $c), LinksService::CATEGORIES))]) ?>
    <?= $ui('field', ['name' => 'icon', 'label' => $t('links.field.icon'), 'type' => 'select', 'value' => (string) ($old['icon'] ?? 'external-link'),
        'options' => array_combine(LinksService::ICONS, LinksService::ICONS)]) ?>
  </div>
  <div class="grid grid--2">
    <?= $ui('field', ['name' => 'legal_entity_id', 'label' => $t('links.field.company'), 'type' => 'select', 'value' => (string) ($old['legal_entity_id'] ?? ''), 'error' => $errors['legal_entity_id'] ?? null,
        'options' => ['' => $t('links.all_companies')] + $companies]) ?>
    <?= $ui('field', ['name' => 'sort_order', 'label' => $t('links.field.sort_order'), 'type' => 'number', 'value' => (string) ($old['sort_order'] ?? '100'), 'min' => '0', 'max' => '9999']) ?>
  </div>
  <fieldset class="stack-sm">
    <legend class="field__label"><?= $e($t('links.field.audience')) ?></legend>
    <p class="text-sm text-muted"><?= $e($t('links.audience_help')) ?></p>
    <div class="checks checks--inline">
      <?php foreach ($roles as $role): ?>
        <label class="check"><input type="checkbox" name="audience_roles[]" value="<?= $e($role) ?>"<?= in_array($role, $selRoles, true) ? ' checked' : '' ?>> <?= $e($t('role.' . $role)) ?></label>
      <?php endforeach; ?>
    </div>
  </fieldset>
  <label class="check"><input type="checkbox" name="open_new_tab" value="1"<?= (string) ($old['open_new_tab'] ?? '1') === '1' ? ' checked' : '' ?>> <?= $e($t('links.field.open_new_tab')) ?></label>
  <div class="row">
    <?= $ui('button', ['label' => $link !== null && $link['status'] !== 'draft' ? $t('links.save_pending') : $t('common.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'checks']) ?>
  </div>
</form>

<?php if ($link !== null): ?>
<div class="stack">
  <?php if ($link['status'] !== 'archived'): ?>
  <form method="post" action="<?= $e($url('/admin/links/' . $pid . '/publish')) ?>" class="card stack" id="review" aria-labelledby="review-h">
    <h2 id="review-h" class="card__title"><?= $e($t('links.review')) ?></h2>
    <p class="text-sm text-muted"><?= $e($t('links.review_help')) ?></p>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?= $ui('field', ['name' => 'purpose', 'label' => $t('links.field.purpose'), 'required' => true, 'value' => (string) ($link['purpose'] ?? '')]) ?>
    <?= $ui('field', ['name' => 'risk_note', 'label' => $t('links.field.risk_note'), 'type' => 'textarea', 'value' => (string) ($link['risk_note'] ?? '')]) ?>
    <label class="check"><input type="checkbox" name="confirm" value="1" required> <?= $e($t('links.confirm')) ?></label>
    <div class="row">
      <?= $ui('button', ['label' => $link['pending'] !== null ? $t('links.publish_revision') : $t('links.publish'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check',
          'disabled' => $link['status'] === 'published' && $link['pending'] === null, 'disabledReason' => $t('links.nothing_to_publish')]) ?>
    </div>
  </form>
  <?php if ($link['pending'] !== null): ?>
  <form method="post" action="<?= $e($url('/admin/links/' . $pid . '/discard')) ?>" class="row">
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?= $ui('button', ['label' => $t('links.discard'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'x']) ?>
  </form>
  <?php endif; ?>
  <form method="post" action="<?= $e($url('/admin/links/' . $pid . '/status')) ?>" class="card stack-sm" aria-labelledby="status-h">
    <h2 id="status-h" class="card__title"><?= $e($t('links.change_status')) ?></h2>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?= $ui('field', ['name' => 'reason', 'label' => $t('links.field.reason'), 'required' => true]) ?>
    <div class="row">
      <?php if ($link['status'] === 'published'): ?><?= $ui('button', ['label' => $t('links.disable'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'eye-off', 'attrs' => ['name' => 'to', 'value' => 'disabled']]) ?><?php endif; ?>
      <?php if ($link['status'] === 'disabled'): ?><?= $ui('button', ['label' => $t('links.enable'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'eye', 'attrs' => ['name' => 'to', 'value' => 'published']]) ?><?php endif; ?>
      <?= $ui('button', ['label' => $t('links.archive'), 'type' => 'submit', 'variant' => 'danger', 'icon' => 'history', 'attrs' => ['name' => 'to', 'value' => 'archived']]) ?>
    </div>
  </form>
  <?php endif; ?>
  <section class="card stack-sm" aria-labelledby="rev-h">
    <h2 id="rev-h" class="card__title"><?= $e($t('links.history')) ?></h2>
    <?php if ($link['revisions'] === []): ?><p class="text-sm text-muted"><?= $e($t('links.history_none')) ?></p><?php endif; ?>
    <ol class="timeline">
      <?php foreach ($link['revisions'] as $rv): $snap = (array) json_decode((string) $rv['snapshot_json'], true); ?>
        <li class="timeline__item"><span class="timeline__dot" aria-hidden="true"></span>
          <div><strong><?= $e($t('links.action.' . $rv['action'])) ?></strong> · <?= $e($t('links.revision_n', ['n' => (int) $rv['revision']])) ?>
            <div class="text-sm text-muted"><?= $e($date((string) $rv['changed_at'])) ?> · <?= $e((string) ($rv['display_name'] ?? '—')) ?></div>
            <div class="text-sm mono break-all"><?= $e((string) ($snap['url'] ?? '')) ?></div>
            <?php if ($rv['reason'] !== null): ?><div class="text-sm"><?= $e((string) $rv['reason']) ?></div><?php endif; ?></div></li>
      <?php endforeach; ?>
    </ol>
  </section>
</div>
<?php endif; ?>
</div>
