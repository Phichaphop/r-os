<?php
/**
 * A conversation: messages (newest at the bottom), the composer, group members. ui/js/chat.js asks the server for news
 * every chat.poll_seconds while this page is open and visible (Q-01) and sends without reloading; without script the
 * forms still work.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var callable $partial @var callable $asset @var array<string, mixed> $v
 * @var array<string, mixed> $c @var list<array<string, mixed>> $msgs @var int $last @var int|null $before @var array<string, string> $errors
 * @var array<string, mixed> $old @var list<array<string, mixed>> $list @var string $current @var string $edit @var string $reportId @var string $now
 * @var array<string, int> $settings @var list<array{id: int, name: string, unit: string}> $colleagues
 */
use Blue\Modules\Chat\ChatService;

$base = '/chat/c/' . $c['public_id'];
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$reads = [];
foreach ($c['members_list'] as $mm) {
    $reads[(int) $mm['id']] = (int) $mm['last_read_id'];
}
$owner = $c['me']['role'] === 'owner';
$memberIds = array_map(static fn (array $mm): int => (int) $mm['id'], $c['members_list']);
?>
<p class="chat__back"><a href="<?= $e($url('/chat')) ?>"><?= $ui('icon', ['name' => 'chevron-left', 'size' => 'sm']) ?><?= $e($t('chat.conversations')) ?></a></p>
<div class="chat chat--open">
  <?= $partial('@chat/_list', ['list' => $list, 'current' => $current]) ?>
  <section class="chat__main card" aria-labelledby="chat-h" data-chat
    data-poll="<?= $e($url($base . '/poll')) ?>" data-send="<?= $e($url($base . '/send')) ?>" data-seconds="<?= (int) $settings['poll_seconds'] ?>"
    data-last="<?= (int) $last ?>" data-since="<?= $e($now) ?>" data-csrf="<?= $e($v['csrf'] ?? '') ?>" data-kind="<?= $e((string) $c['kind']) ?>"
    data-msg-read="<?= $e($t('chat.read')) ?>" data-msg-read-n="<?= $e($t('chat.read_n')) ?>" data-msg-sent="<?= $e($t('chat.sent')) ?>"
    data-msg-sending="<?= $e($t('chat.sending')) ?>" data-msg-failed="<?= $e($t('chat.failed')) ?>" data-msg-new="<?= $e($t('chat.new_messages')) ?>">
    <header class="chat__head">
      <span class="chat__avatar" aria-hidden="true"><?= $ui('icon', ['name' => $c['kind'] === 'direct' ? 'user' : 'users']) ?></span>
      <div class="chat__head-body">
        <h1 id="chat-h" class="chat__title"><?= $e((string) $c['title']) ?></h1>
        <p class="text-xs text-muted"><?= $c['kind'] === 'group' ? $e($t('chat.members_n', ['n' => (string) count($c['members_list'])])) . ' · ' : '' ?><?= $e($t('chat.private_note')) ?></p>
      </div>
      <?php if ($c['kind'] === 'group'): ?>
      <details class="chat__group">
        <summary class="btn btn--ghost btn--sm"><?= $ui('icon', ['name' => 'users', 'size' => 'sm']) ?> <?= $e($t('chat.group_settings')) ?></summary>
        <div class="chat__group-body stack-sm">
          <ul class="plain-list stack-sm">
            <?php foreach ($c['members_list'] as $mm): ?>
              <li class="row"><span><?= $e((string) $mm['name']) ?><?= $mm['role'] === 'owner' ? ' · ' . $e($t('chat.owner')) : '' ?></span>
                <?php if ($owner && (int) $mm['id'] !== (int) $c['me']['user_id']): ?>
                  <form method="post" action="<?= $e($url($base . '/members/remove')) ?>"><?= $csrf ?><input type="hidden" name="user" value="<?= (int) $mm['id'] ?>">
                    <button type="submit" class="btn btn--ghost btn--sm" aria-label="<?= $e($t('chat.remove_who', ['name' => (string) $mm['name']])) ?>"><?= $ui('icon', ['name' => 'x', 'size' => 'sm']) ?></button></form>
                <?php endif; ?></li>
            <?php endforeach; ?>
          </ul>
          <?php if ($owner): ?>
            <form method="post" action="<?= $e($url($base . '/members/add')) ?>" class="row">
              <?= $csrf ?>
              <label class="sr-only" for="add-user"><?= $e($t('chat.add_member')) ?></label>
              <select class="input" id="add-user" name="user">
                <?php foreach ($colleagues as $p): if (in_array($p['id'], $memberIds, true)) { continue; } ?><option value="<?= (int) $p['id'] ?>"><?= $e($p['name']) ?></option><?php endforeach; ?>
              </select>
              <?= $ui('button', ['label' => $t('chat.add_member'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'plus']) ?>
            </form>
            <p class="text-xs text-muted"><?= $e($t('chat.add_help')) ?></p>
            <form method="post" action="<?= $e($url($base . '/members/rename')) ?>" class="row">
              <?= $csrf ?>
              <label class="sr-only" for="rn-title"><?= $e($t('chat.group_name')) ?></label>
              <input class="input" id="rn-title" name="title" value="<?= $e((string) $c['title']) ?>" maxlength="80">
              <?= $ui('button', ['label' => $t('chat.rename'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm']) ?>
            </form>
          <?php endif; ?>
          <form method="post" action="<?= $e($url($base . '/members/leave')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('chat.leave'), 'type' => 'submit', 'variant' => 'danger', 'size' => 'sm', 'icon' => 'x']) ?></form>
        </div>
      </details>
      <?php endif; ?>
    </header>
    <div class="chat__scroll" data-chat-scroll>
      <?php if ($msgs !== [] && count($msgs) >= ChatService::PAGE): ?>
        <p class="text-center"><a class="btn btn--ghost btn--sm" href="<?= $e($url($base . '?before=' . (int) $msgs[0]['id'])) ?>"><?= $e($t('chat.older')) ?></a></p>
      <?php endif; ?>
      <?php if ($before !== null): ?><p class="text-center"><a href="<?= $e($url($base)) ?>"><?= $e($t('chat.latest')) ?></a></p><?php endif; ?>
      <div role="log" aria-live="polite" aria-labelledby="chat-h">
        <ol class="chat__messages" data-chat-list>
          <?php foreach ($msgs as $m): ?><?= $partial('@chat/_message', ['m' => $m, 'c' => $c, 'reads' => $reads, 'edit' => $edit, 'reportId' => $reportId]) ?><?php endforeach; ?>
        </ol>
      </div>
      <?php if ($msgs === []): ?><p class="text-sm text-muted text-center" data-chat-empty><?= $e($t('chat.empty')) ?></p><?php endif; ?>
    </div>
    <form method="post" action="<?= $e($url($base . '/send')) ?>" enctype="multipart/form-data" class="chat__composer" novalidate data-chat-form>
      <?= $csrf ?>
      <input type="hidden" name="client_id" value="<?= $e(bin2hex(random_bytes(16))) ?>" data-chat-client>
      <label class="sr-only" for="chat-body"><?= $e($t('chat.message')) ?></label>
      <textarea class="input chat__input" id="chat-body" name="body" rows="2" maxlength="<?= ChatService::MAX_LENGTH ?>" placeholder="<?= $e($t('chat.type_here')) ?>"
        aria-describedby="chat-body-help<?= isset($errors['body']) || isset($errors['files']) ? ' chat-err' : '' ?>"><?= $e((string) ($old['body'] ?? '')) ?></textarea>
      <div class="chat__tools">
        <label class="btn btn--ghost btn--sm chat__attach"><?= $ui('icon', ['name' => 'paperclip', 'size' => 'sm']) ?> <span><?= $e($t('chat.attach')) ?></span>
          <input class="sr-only" type="file" name="files[]" multiple accept=".png,.jpg,.jpeg,.webp,.pdf,image/png,image/jpeg,image/webp,application/pdf" data-chat-files></label>
        <span class="text-xs text-muted" data-chat-picked></span>
        <?= $ui('button', ['label' => $t('chat.send'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?>
      </div>
      <p id="chat-body-help" class="text-xs text-muted"><?= $e($t('chat.composer_help', ['mb' => (string) $settings['max_mb'], 'minutes' => (string) $settings['edit_minutes']])) ?></p>
      <?php if (isset($errors['body']) || isset($errors['files'])): ?><p id="chat-err" class="field__error" role="alert"><?= $e((string) ($errors['body'] ?? $errors['files'])) ?></p><?php endif; ?>
      <p class="field__error" role="alert" data-chat-error hidden></p>
    </form>
  </section>
</div>
<script src="<?= $e($asset('ui/js/chat.js')) ?>" defer></script>
