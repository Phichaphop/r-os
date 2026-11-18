<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var list<array<string, mixed>> $calendars @var bool $canCreate @var array<string, string> $scopes @var array<string, string> $bases
 * @var array<string, string> $errors @var array<string, mixed> $old
 */
?>
<?= $ui('page-header', ['title' => $t('calendar.manage_title'), 'subtitle' => $t('calendar.subtitle'),
    'actions' => $ui('button', ['label' => $t('calendar.mine_link'), 'href' => $url('/calendars'), 'variant' => 'secondary', 'icon' => 'calendar-event'])]) ?>
<div class="table-wrap" role="region" aria-label="<?= $e($t('calendar.title')) ?>" tabindex="0">
  <table class="table table--stack">
    <caption class="sr-only"><?= $e($t('calendar.title')) ?></caption>
    <thead><tr><th scope="col"><?= $e($t('calendar.field.name')) ?></th><th scope="col"><?= $e($t('calendar.field.type')) ?></th>
      <th scope="col"><?= $e($t('calendar.field.scope')) ?></th><th scope="col"><?= $e($t('calendar.field.pattern')) ?></th><th scope="col"><?= $e($t('calendar.field.holidays')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($calendars as $c): ?>
      <tr>
        <td data-label="<?= $e($t('calendar.field.name')) ?>"><a href="<?= $e($url('/admin/calendars/' . $c['public_id'])) ?>"><?= $e($c['name']) ?></a>
          <div class="text-sm text-muted mono"><?= $e($c['code']) ?><?= $c['base_code'] !== null ? ' ← ' . $e($c['base_code']) : '' ?></div></td>
        <td data-label="<?= $e($t('calendar.field.type')) ?>"><?= $e($t('calendar.type.' . $c['calendar_type'])) ?></td>
        <td data-label="<?= $e($t('calendar.field.scope')) ?>"><?= $e($t('config.scope.' . $c['scope_level'])) ?> #<?= (int) $c['scope_id'] ?></td>
        <td data-label="<?= $e($t('calendar.field.pattern')) ?>"><?php for ($i = 0; $i < 7; $i++): ?><span class="wd-chip<?= $c['weekly_pattern'][$i] === '1' ? ' wd-chip--on' : '' ?>" title="<?= $e($t('calendar.weekday.' . $i)) ?>"><?= $e($t('calendar.weekday_short.' . $i)) ?></span><?php endfor; ?>
          <span class="sr-only"><?= $e(implode(', ', array_map(static fn ($i) => $t('calendar.weekday.' . $i), array_keys(array_filter(str_split((string) $c['weekly_pattern']), static fn ($x) => $x === '1'))))) ?></span></td>
        <td data-label="<?= $e($t('calendar.field.holidays')) ?>" class="num"><?= (int) $c['holidays'] ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($calendars === []): ?><tr><td colspan="5"><?= $ui('states', ['icon' => 'calendar-event', 'title' => $t('calendar.empty'), 'text' => $t('calendar.empty_text')]) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php if ($canCreate): ?>
<section class="card" aria-labelledby="new-h">
  <h2 id="new-h" class="card__title"><?= $e($t('calendar.new')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('calendar.new_help')) ?></p>
  <?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors'), 'live' => true]) ?><?php endif; ?>
  <form method="post" action="<?= $e($url('/admin/calendars')) ?>" class="form-grid" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?= $ui('field', ['name' => 'code', 'label' => $t('calendar.field.code'), 'required' => true, 'value' => (string) $old['code'], 'error' => $errors['code'] ?? null, 'help' => $t('calendar.help.code')]) ?>
    <?= $ui('field', ['name' => 'name', 'label' => $t('calendar.field.name'), 'required' => true, 'value' => (string) $old['name'], 'error' => $errors['name'] ?? null]) ?>
    <?= $ui('field', ['name' => 'type', 'label' => $t('calendar.field.type'), 'type' => 'select', 'value' => (string) $old['type'], 'options' => ['work' => $t('calendar.type.work'), 'service' => $t('calendar.type.service')], 'error' => $errors['type'] ?? null]) ?>
    <?= $ui('field', ['name' => 'scope', 'label' => $t('calendar.field.scope'), 'type' => 'select', 'required' => true, 'value' => (string) $old['scope'], 'options' => ['' => '—'] + $scopes, 'error' => $errors['scope'] ?? null]) ?>
    <?= $ui('field', ['name' => 'base', 'label' => $t('calendar.field.base'), 'type' => 'select', 'full' => true, 'value' => (string) $old['base'], 'options' => ['' => '—'] + $bases, 'error' => $errors['base'] ?? null, 'help' => $t('calendar.help.base')]) ?>
    <?php $pattern = (string) $old['pattern']; $legend = $t('calendar.field.pattern'); include __DIR__ . '/_pattern.php'; ?>
    <div class="row field--full"><?= $ui('button', ['label' => $t('calendar.create'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></div>
  </form>
</section>
<?php endif; ?>
