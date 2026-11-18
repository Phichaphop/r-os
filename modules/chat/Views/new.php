<?php
/**
 * New chat: pick one colleague (one-to-one) or several plus a name (group). Only colleagues of the same company.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $partial @var array<string, mixed> $v
 * @var bool $canChat @var list<array<string, mixed>> $list @var string $q @var list<array{id: int, name: string, unit: string}> $people
 * @var array<string, string> $errors @var array<string, mixed> $old @var string $current
 */
$picked = array_map('intval', (array) ($old['people'] ?? []));
?>
<?= $ui('page-header', ['title' => $t('chat.new'), 'subtitle' => $t('chat.new_help')]) ?>
<?php if (!$canChat): ?>
  <?= $ui('states', ['kind' => 'forbidden', 'title' => $t('chat.no_employee_title'), 'text' => $t('chat.error.no_employee')]) ?>
<?php else: ?>
<div class="chat">
  <?= $partial('@chat/_list', ['list' => $list, 'current' => $current]) ?>
  <section class="chat__main card stack" aria-labelledby="chat-new-h">
    <h2 id="chat-new-h" class="card__title"><?= $e($t('chat.pick_people')) ?></h2>
    <form method="get" action="<?= $e($url('/chat/new')) ?>" role="search" class="row">
      <label class="sr-only" for="np-q"><?= $e($t('chat.find_people')) ?></label>
      <input class="input" type="search" id="np-q" name="q" value="<?= $e($q) ?>" placeholder="<?= $e($t('chat.find_people')) ?>" maxlength="60">
      <?= $ui('button', ['label' => $t('chat.find'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'search']) ?>
    </form>
    <form method="post" action="<?= $e($url('/chat/new')) ?>" class="stack" novalidate>
      <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
      <fieldset class="stack-sm">
        <legend class="field__label"><?= $e($t('chat.people')) ?></legend>
        <?php if (isset($errors['people'])): ?><p class="field__error" role="alert"><?= $e($errors['people']) ?></p><?php endif; ?>
        <?php if ($people === []): ?><p class="text-sm text-muted"><?= $e($t('chat.no_people')) ?></p><?php endif; ?>
        <ul class="chat__people" role="list">
          <?php foreach ($people as $p): ?>
            <li><label class="check"><input type="checkbox" name="people[]" value="<?= (int) $p['id'] ?>"<?= in_array($p['id'], $picked, true) ? ' checked' : '' ?>>
              <span><?= $e($p['name']) ?><?php if ($p['unit'] !== ''): ?> <span class="text-xs text-muted">· <?= $e($p['unit']) ?></span><?php endif; ?></span></label></li>
          <?php endforeach; ?>
        </ul>
      </fieldset>
      <?= $ui('field', ['name' => 'title', 'label' => $t('chat.group_name'), 'value' => (string) ($old['title'] ?? ''), 'help' => $t('chat.group_name_help'), 'error' => $errors['title'] ?? null]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('chat.start'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'message-circle']) ?></div>
    </form>
  </section>
</div>
<?php endif; ?>
