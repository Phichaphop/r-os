<?php
/**
 * Public access settings (0.8.1, DEC-68).
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var list<array{code: string, label: string, icon: string, guest: bool, summary: string}> $rows
 * @var array{home: list<string>, tools: list<string>} $form @var bool $homeSet @var array<string, string> $errors
 */
?>
<?= $ui('page-header', ['title' => $t('settings.guest.title'), 'subtitle' => $t('settings.guest.subtitle')]) ?>
<?= $ui('alert', ['tone' => 'warning', 'title' => $t('settings.guest.warn_title'), 'text' => $t('settings.guest.warn_text')]) ?>
<form method="post" action="<?= $e($url('/admin/settings/guest')) ?>" class="card stack" novalidate aria-labelledby="ga-h">
  <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
  <h2 id="ga-h" class="card__title"><?= $e($t('settings.guest.apps')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t($homeSet ? 'settings.guest.apps_help' : 'settings.guest.apps_help_unset')) ?></p>
  <div class="table-wrap" role="region" aria-labelledby="ga-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr>
        <th scope="col"><?= $e($t('settings.guest.col.app')) ?></th>
        <th scope="col"><?= $e($t('settings.guest.col.home')) ?></th>
        <th scope="col"><?= $e($t('settings.guest.col.tool')) ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $row): $c = $row['code']; ?>
        <tr>
          <td data-label="<?= $e($t('settings.guest.col.app')) ?>"><span class="row row--tight"><?= $ui('icon', ['name' => $row['icon'], 'size' => 'sm']) ?><strong id="ga-<?= $e($c) ?>"><?= $e($row['label']) ?></strong></span>
            <?php if ($row['summary'] !== ''): ?><span class="text-sm text-muted d-block"><?= $e($row['summary']) ?></span><?php endif; ?></td>
          <td data-label="<?= $e($t('settings.guest.col.home')) ?>">
            <label class="check"><input type="checkbox" name="home[]" value="<?= $e($c) ?>"<?= in_array($c, $form['home'], true) ? ' checked' : '' ?> aria-describedby="ga-<?= $e($c) ?>"> <?= $e($t('settings.guest.show')) ?></label></td>
          <td data-label="<?= $e($t('settings.guest.col.tool')) ?>">
            <?php if ($row['guest']): ?>
              <label class="check"><input type="checkbox" name="tools[]" value="<?= $e($c) ?>"<?= in_array($c, $form['tools'], true) ? ' checked' : '' ?> aria-describedby="ga-<?= $e($c) ?>"> <?= $e($t('settings.guest.allow')) ?></label>
            <?php else: ?>
              <span class="text-sm text-muted"><?= $e($t('settings.guest.needs_signin')) ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="text-sm text-muted"><?= $ui('icon', ['name' => 'info-circle', 'size' => 'sm']) ?> <?= $e($t('settings.guest.tool_note')) ?></p>
  <div class="row"><?= $ui('button', ['label' => $t('common.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'checks']) ?>
    <a class="btn btn--ghost" href="<?= $e($url('/')) ?>"><?= $e($t('settings.guest.back_home')) ?></a></div>
</form>
