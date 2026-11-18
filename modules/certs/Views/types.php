<?php
/**
 * Certificate types of a company: template per language (core templates), signer, copies.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $rows @var array<string, string> $templates
 * @var array<string, mixed>|null $edit @var array<string, string> $errors @var array<string, mixed> $old @var list<string> $auto
 */
$o = static fn (string $k, string $d = ''): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$val = static fn (string $k, string $d = ''): string => $old !== [] ? $o($k, $d) : (string) ($edit[$k] ?? $d);
?>
<?= $ui('page-header', ['title' => $t('certs.types_title'), 'subtitle' => $t('certs.types_subtitle'), 'crumbs' => [[$t('certs.admin_title'), $url('/certs/admin?le=' . rawurlencode($lePid))], [$t('certs.types_title'), null]]]) ?>
<section class="card stack-sm" aria-labelledby="ct-l">
  <h2 id="ct-l" class="card__title"><?= $e($t('certs.types')) ?></h2>
  <?php if ($rows === []): ?><p class="text-sm text-muted"><?= $e($t('certs.none')) ?></p><?php else: ?>
  <ul class="plain-list stack-sm">
    <?php foreach ($rows as $x): ?>
      <li><a href="<?= $e($url('/certs/admin/types/' . $x['public_id'])) ?>"><?= $e($x['code'] . ' · ' . $x['name_th']) ?></a>
        <?= $x['status'] === 'archived' ? $ui('badge', ['tone' => 'neutral', 'label' => $t('certs.archived')]) : '' ?>
        <span class="d-block text-sm text-muted"><?= $e(implode(' · ', array_filter([(string) ($x['template_th_name'] ?? ''), (string) ($x['template_en_name'] ?? '')]))) ?></span></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<section class="card stack" aria-labelledby="ct-f">
  <h2 id="ct-f" class="card__title"><?= $e($edit !== null ? $t('certs.type_edit', ['code' => (string) $edit['code']]) : $t('certs.type_new')) ?></h2>
  <?php if ($templates === []): ?><?= $ui('alert', ['tone' => 'warning', 'text' => $t('certs.no_templates')]) ?><?php endif; ?>
  <form method="post" action="<?= $e($url($edit !== null ? '/certs/admin/types/' . $edit['public_id'] : '/certs/admin/types')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <input type="hidden" name="le" value="<?= $e($lePid) ?>">
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'code', 'label' => $t('certs.field.code'), 'required' => true, 'value' => $val('code'), 'placeholder' => 'EMPLOYMENT', 'error' => $errors['code'] ?? null]) ?>
      <?= $ui('field', ['name' => 'name_th', 'label' => $t('certs.field.name_th'), 'required' => true, 'value' => $val('name_th'), 'error' => $errors['name_th'] ?? null]) ?>
      <?= $ui('field', ['name' => 'name_en', 'label' => $t('certs.field.name_en'), 'value' => $val('name_en')]) ?>
      <?= $ui('field', ['name' => 'template_th', 'type' => 'select', 'label' => $t('certs.field.template_th'), 'value' => $val('template_th'), 'options' => ['' => '—'] + $templates, 'error' => $errors['template_th'] ?? null]) ?>
      <?= $ui('field', ['name' => 'template_en', 'type' => 'select', 'label' => $t('certs.field.template_en'), 'value' => $val('template_en'), 'options' => ['' => '—'] + $templates, 'error' => $errors['template_en'] ?? null]) ?>
      <?= $ui('field', ['name' => 'max_copies', 'type' => 'number', 'label' => $t('certs.field.max_copies'), 'value' => $val('max_copies', '3'), 'min' => '1', 'max' => '10', 'error' => $errors['max_copies'] ?? null]) ?>
      <?= $ui('field', ['name' => 'signer_name', 'label' => $t('certs.field.signer_name'), 'value' => $val('signer_name')]) ?>
      <?= $ui('field', ['name' => 'signer_title', 'label' => $t('certs.field.signer_title'), 'value' => $val('signer_title')]) ?>
      <?php if ($edit !== null): ?>
        <?= $ui('field', ['name' => 'status', 'type' => 'select', 'label' => $t('certs.field.status'), 'value' => $val('status', 'active'), 'options' => ['active' => $t('certs.active'), 'archived' => $t('certs.archived')]]) ?>
      <?php endif; ?>
    </div>
    <?= $ui('field', ['name' => 'description', 'label' => $t('certs.field.description'), 'value' => $val('description')]) ?>
    <p class="text-sm text-muted"><?= $e($t('certs.auto_help')) ?> <code><?= $e(implode(', ', $auto)) ?></code></p>
    <div class="row"><?= $ui('button', ['label' => $t('certs.save'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
  </form>
</section>
