<?php

declare(strict_types=1);

namespace Blue\Modules\Chat;

use DateTimeZone;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Support\Ulid;
use Blue\Modules\Employees\EmployeeService;

/**
 * M17 internal chat (R5 · 0.41.0 · DEC-107). Answers of the owner (Q-01 – Q-06):
 *  - the page asks the server for new messages every chat.poll_seconds (5–10) only while a conversation is open and
 *    visible — no background polling anywhere else (Q-01);
 *  - one-to-one conversations and groups people make themselves, with colleagues of the same company (Q-02);
 *  - pictures or PDF, at most three per message, stored encrypted through the document service (Q-03);
 *  - kept chat.retention_days (default 3 years, administrator can change), then text and files are removed (Q-04);
 *  - NOBODY reads conversations — there is no screen for it. A member may report one message; the moderators
 *    (chat.moderate) see that message only (its text when reported, earlier versions and files), nothing around it (Q-05);
 *  - the sender may edit or delete within chat.edit_minutes (default 15); the earlier text is kept (Q-06) and seen only
 *    through a report.
 * Sending is idempotent per client id (a retried send is stored once) and rate-limited. New-message notices never contain
 * the text and come at most once an hour per conversation.
 */
final class ChatService
{
    public const MAX_FILES = 3;

    public const MAX_LENGTH = 4000;

    public const MAX_PER_MINUTE = 30;

    public const MAX_GROUP = 100;

    public const PAGE = 50;

    public const FILE_TYPES = ['png' => 'image/png', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    private function stamp(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    private function ago(string $modify): string
    {
        return $this->app->clock->now()->modify($modify)->format('Y-m-d H:i:s.u');
    }

    private function hourKey(): string
    {
        return $this->app->clock->now()->setTimezone(new DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok')))->format('YmdH');
    }

    /** @return array{retention_days: int, edit_minutes: int, max_mb: int, poll_seconds: int} */
    public function settings(): array
    {
        $get = function (string $key, int $default, int $min, int $max): int {
            $v = $this->app->registry()->resolveOrNull($key)?->value;
            return is_int($v) ? max($min, min($max, $v)) : $default;
        };
        return ['retention_days' => $get('chat.retention_days', 1095, 30, 3650), 'edit_minutes' => $get('chat.edit_minutes', 15, 1, 1440),
            'max_mb' => $get('chat.max_mb', 10, 1, 20), 'poll_seconds' => $get('chat.poll_seconds', 6, 5, 10)];
    }

    /** The company this person chats in: their employer today (null = cannot chat: no employee record or former). */
    public function companyOf(Authorizer $az): ?int
    {
        if (!$az->can('chat.use')) {
            return null;
        }
        $me = EmployeeService::of($this->app)->forUser($az->userId);
        if ($me === null || $me['status'] === 'former' || $me['job'] === null) {
            return null;
        }
        $le = (int) $me['job']['legal_entity_id'];
        return $az->scope('chat.use')->coversLegalEntity($le) ? $le : null;
    }

    private function mustChat(Authorizer $az): int
    {
        return $this->companyOf($az) ?? throw new BusinessException('chat.error.no_employee', [], 403);
    }

    /**
     * Colleagues one can chat with: active accounts linked to someone employed by the same company today.
     * @return list<array{id: int, name: string, unit: string}>
     */
    public function colleagues(Authorizer $az, string $q = '', int $limit = 20): array
    {
        $le = $this->mustChat($az);
        $today = $this->app->clock->now()->format('Y-m-d');
        $where = '';
        $params = [$le, $today, $today, $az->userId];
        $q = trim($q);
        if ($q !== '') {
            $like = '%' . addcslashes(mb_substr($q, 0, 60), '%_\\') . '%';
            $where = ' AND (u.display_name LIKE ? OR u.username LIKE ? OR h.position_title LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $rows = $this->app->db()->all(
            "SELECT DISTINCT u.id, u.display_name AS name, IFNULL(h.position_title, '') AS unit FROM users u
               JOIN employees e ON e.id = u.employee_id AND e.status <> 'former'
               JOIN employment_history h ON h.employee_id = e.id AND h.legal_entity_id = ? AND h.effective_from <= ? AND (h.effective_to IS NULL OR h.effective_to >= ?)
              WHERE u.status = 'active' AND u.id <> ?{$where} ORDER BY u.display_name LIMIT " . max(1, min(200, $limit)),
            $params
        );
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'unit' => (string) $r['unit']], $rows);
    }

    private function isColleague(int $le, int $userId): bool
    {
        $today = $this->app->clock->now()->format('Y-m-d');
        return $this->app->db()->scalar(
            "SELECT u.id FROM users u JOIN employees e ON e.id = u.employee_id AND e.status <> 'former'
               JOIN employment_history h ON h.employee_id = e.id AND h.legal_entity_id = ? AND h.effective_from <= ? AND (h.effective_to IS NULL OR h.effective_to >= ?)
              WHERE u.id = ? AND u.status = 'active' LIMIT 1",
            [$le, $today, $today, $userId]
        ) !== null;
    }

    // ---- conversations --------------------------------------------------------------------------------------------------

    /**
     * My conversations, newest first, with the unread count and the other person's name for one-to-one chats.
     * @return list<array<string, mixed>>
     */
    public function conversations(Authorizer $az): array
    {
        $this->mustChat($az);
        return $this->app->db()->all(
            "SELECT c.public_id, c.kind, c.title, c.last_message_at, m.last_read_id,
                    (SELECT COUNT(*) FROM ch_messages x WHERE x.conversation_id = c.id AND x.id > GREATEST(m.last_read_id, m.visible_from_id) AND x.sender_id <> m.user_id AND x.kind = 'text') AS unread,
                    (SELECT u.display_name FROM ch_members o JOIN users u ON u.id = o.user_id WHERE o.conversation_id = c.id AND o.user_id <> m.user_id LIMIT 1) AS other_name,
                    (SELECT COUNT(*) FROM ch_members o WHERE o.conversation_id = c.id AND o.left_at IS NULL) AS members
               FROM ch_members m JOIN ch_conversations c ON c.id = m.conversation_id
              WHERE m.user_id = ? AND m.left_at IS NULL
              ORDER BY IFNULL(c.last_message_at, c.created_at) DESC LIMIT 200",
            [$az->userId]
        );
    }

    /** One-to-one with a colleague: the existing conversation or a new one. @return string public id */
    public function direct(Authorizer $az, int $otherId): string
    {
        $le = $this->mustChat($az);
        if ($otherId === $az->userId || !$this->isColleague($le, $otherId)) {
            throw new ValidationException(['people' => 'chat.error.person']);
        }
        $key = min($az->userId, $otherId) . ':' . max($az->userId, $otherId);
        $db = $this->app->db();
        $pid = $db->scalar('SELECT public_id FROM ch_conversations WHERE direct_key = ?', [$key]);
        if ($pid !== null) {
            $id = (int) $db->scalar('SELECT id FROM ch_conversations WHERE public_id = ?', [$pid]);
            $db->run('UPDATE ch_members SET left_at = NULL WHERE conversation_id = ? AND user_id = ?', [$id, $az->userId]); // reopened from the list
            return (string) $pid;
        }
        $pid = Ulid::generate($this->app->clock);
        $now = $this->stamp();
        $db->transactional(function () use ($db, $pid, $now, $az, $otherId, $le, $key): void {
            $db->run(
                "INSERT INTO ch_conversations (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, kind, direct_key) VALUES (?, ?, ?, ?, ?, ?, 'direct', ?)",
                [$pid, $now, $az->userId, $now, $az->userId, $le, $key]
            );
            $id = (int) $db->pdo()->lastInsertId();
            foreach ([$az->userId, $otherId] as $u) {
                $db->run("INSERT INTO ch_members (conversation_id, user_id, role, joined_at) VALUES (?, ?, 'member', ?)", [$id, $u, $now]);
            }
        });
        $this->app->audit()->record('chat.conversation_started', 'success', 'ch_conversation', $pid, $le, null, ['kind' => 'direct']);
        return $pid;
    }

    /** @param list<int> $people @return string public id */
    public function group(Authorizer $az, string $title, array $people): string
    {
        $le = $this->mustChat($az);
        $title = trim($title);
        $people = array_values(array_unique(array_filter(array_map('intval', $people), static fn (int $u): bool => $u !== $az->userId)));
        $e = [];
        if (mb_strlen($title) < 2 || mb_strlen($title) > 80) {
            $e['title'] = 'chat.error.title';
        }
        if (count($people) < 2) {
            $e['people'] = 'chat.error.group_size';
        } elseif (count($people) + 1 > self::MAX_GROUP) {
            $e['people'] = 'chat.error.group_max';
        }
        foreach ($people as $u) {
            if (!$this->isColleague($le, $u)) {
                $e['people'] = 'chat.error.person';
            }
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $db = $this->app->db();
        $pid = Ulid::generate($this->app->clock);
        $now = $this->stamp();
        $db->transactional(function () use ($db, $pid, $now, $az, $le, $title, $people): void {
            $db->run(
                "INSERT INTO ch_conversations (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, kind, title) VALUES (?, ?, ?, ?, ?, ?, 'group', ?)",
                [$pid, $now, $az->userId, $now, $az->userId, $le, $title]
            );
            $id = (int) $db->pdo()->lastInsertId();
            $db->run("INSERT INTO ch_members (conversation_id, user_id, role, joined_at) VALUES (?, ?, 'owner', ?)", [$id, $az->userId, $now]);
            foreach ($people as $u) {
                $db->run("INSERT INTO ch_members (conversation_id, user_id, role, joined_at) VALUES (?, ?, 'member', ?)", [$id, $u, $now]);
            }
            $this->system($id, $az->userId, 'chat.sys.created', ['title' => $title]);
        });
        $this->app->audit()->record('chat.conversation_started', 'success', 'ch_conversation', $pid, $le, null, ['kind' => 'group', 'members' => count($people) + 1]);
        return $pid;
    }

    /**
     * The conversation if I am a current member (otherwise 404 — not even its existence is shown).
     * @return array<string, mixed> conversation + me (member row)
     */
    public function conversation(Authorizer $az, string $pid): array
    {
        $this->mustChat($az);
        $c = $this->app->db()->one('SELECT * FROM ch_conversations WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $me = $this->app->db()->one('SELECT * FROM ch_members WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL', [$c['id'], $az->userId]) ?? throw HttpException::notFound();
        $c['me'] = $me;
        $c['members_list'] = $this->app->db()->all(
            'SELECT u.id, u.display_name AS name, m.role, m.last_read_id FROM ch_members m JOIN users u ON u.id = m.user_id WHERE m.conversation_id = ? AND m.left_at IS NULL ORDER BY m.role, u.display_name',
            [$c['id']]
        );
        if ($c['kind'] === 'direct') {
            foreach ($c['members_list'] as $m) {
                if ((int) $m['id'] !== $az->userId) {
                    $c['title'] = $m['name'];
                }
            }
        }
        return $c;
    }

    /**
     * Messages I may see (from when I joined), newest page; older pages with $before.
     * @param array<string, mixed> $c
     * @return list<array<string, mixed>>
     */
    public function messages(Authorizer $az, array $c, ?int $after = null, ?int $before = null, int $limit = self::PAGE): array
    {
        $where = 'm.conversation_id = ? AND m.id > ?';
        $params = [$c['id'], (int) $c['me']['visible_from_id']];
        if ($after !== null) {
            $where .= ' AND m.id > ?';
            $params[] = $after;
        }
        if ($before !== null) {
            $where .= ' AND m.id < ?';
            $params[] = $before;
        }
        $rows = $this->app->db()->all(
            "SELECT m.id, m.public_id, m.sender_id, m.kind, m.body, m.created_at, m.updated_at, m.edited_at, m.deleted_at, m.hidden_at, m.purged_at, u.display_name AS sender
               FROM ch_messages m JOIN users u ON u.id = m.sender_id WHERE {$where} ORDER BY m.id " . ($after !== null ? 'ASC' : 'DESC') . ' LIMIT ' . max(1, min(200, $limit)),
            $params
        );
        if ($after === null) {
            $rows = array_reverse($rows);
        }
        return $this->withFiles($az, $rows);
    }

    /**
     * Messages changed (edited / deleted / hidden) since a moment, among those I may see.
     * @param array<string, mixed> $c
     * @return list<array<string, mixed>>
     */
    public function changed(Authorizer $az, array $c, string $since, int $upTo): array
    {
        $rows = $this->app->db()->all(
            "SELECT m.id, m.public_id, m.sender_id, m.kind, m.body, m.created_at, m.updated_at, m.edited_at, m.deleted_at, m.hidden_at, m.purged_at, u.display_name AS sender
               FROM ch_messages m JOIN users u ON u.id = m.sender_id
              WHERE m.conversation_id = ? AND m.id > ? AND m.id <= ? AND m.updated_at > ? AND m.updated_at > m.created_at ORDER BY m.id LIMIT 200",
            [$c['id'], (int) $c['me']['visible_from_id'], $upTo, $since]
        );
        return $this->withFiles($az, $rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function withFiles(Authorizer $az, array $rows): array
    {
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $files = [];
        if ($ids !== []) {
            foreach ($this->app->db()->all('SELECT id, message_id, filename, mime, size, purged_at FROM ch_files WHERE message_id IN (' . implode(',', $ids) . ') ORDER BY id') as $f) {
                $files[(int) $f['message_id']][] = $f;
            }
        }
        $edit = $this->settings()['edit_minutes'];
        $limit = $this->ago('-' . $edit . ' minutes');
        foreach ($rows as &$r) {
            $gone = $r['deleted_at'] !== null || $r['hidden_at'] !== null || $r['purged_at'] !== null;
            $r['files'] = $gone ? [] : array_values(array_filter($files[(int) $r['id']] ?? [], static fn (array $f): bool => $f['purged_at'] === null));
            $r['mine'] = (int) $r['sender_id'] === $az->userId;
            $r['can_change'] = $r['mine'] && !$gone && $r['kind'] === 'text' && (string) $r['created_at'] >= $limit;
            if ($gone) {
                $r['body'] = null; // members never see removed text — the earlier text stays only for a report (Q-05/Q-06)
            }
        }
        return $rows;
    }

    /**
     * Marks everything up to $id as read by me.
     * @param array<string, mixed> $c
     */
    public function markRead(Authorizer $az, array $c, int $id): void
    {
        $this->app->db()->run('UPDATE ch_members SET last_read_id = GREATEST(last_read_id, ?) WHERE conversation_id = ? AND user_id = ?', [$id, $c['id'], $az->userId]);
    }

    /**
     * Sends a message (text and/or up to three files). A send repeated with the same client id returns the first one.
     * @param list<UploadedFile> $files
     * @return array{id: int, public_id: string, duplicate: bool}
     */
    public function send(Authorizer $az, string $convPid, string $body, array $files, string $clientId): array
    {
        $c = $this->conversation($az, $convPid);
        $db = $this->app->db();
        $clientId = preg_match('/^[a-f0-9]{32}$/', $clientId) === 1 ? $clientId : bin2hex(random_bytes(16));
        $dup = $db->one('SELECT id, public_id FROM ch_messages WHERE sender_id = ? AND client_id = ?', [$az->userId, $clientId]);
        if ($dup !== null) {
            return ['id' => (int) $dup['id'], 'public_id' => (string) $dup['public_id'], 'duplicate' => true];
        }
        $body = trim(str_replace("\r\n", "\n", $body));
        $files = array_values(array_filter($files, static fn (UploadedFile $f): bool => $f->error !== UPLOAD_ERR_NO_FILE));
        $e = [];
        if ($body === '' && $files === []) {
            $e['body'] = 'chat.error.empty';
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            $e['body'] = 'chat.error.too_long';
        }
        if (count($files) > self::MAX_FILES) {
            $e['files'] = 'chat.error.too_many_files';
        }
        $maxBytes = $this->settings()['max_mb'] * 1024 * 1024;
        foreach ($files as $f) {
            if (!$f->ok()) {
                $e['files'] = $f->errorKey();
            } elseif ($f->size > $maxBytes) {
                $e['files'] = 'chat.error.file_size';
            }
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        if ((int) $db->scalar("SELECT COUNT(*) FROM ch_messages WHERE sender_id = ? AND kind = 'text' AND created_at >= ?", [$az->userId, $this->ago('-1 minute')]) >= self::MAX_PER_MINUTE) {
            throw new BusinessException('chat.error.slow_down', [], 429);
        }
        $stored = [];
        foreach ($files as $f) {
            try {
                $d = $this->app->documents()->storeGenerated($az->userId, 'chat.file', $f->bytes(), $f->safeName(), ['title' => 'Chat file', 'legal_entity_id' => (int) $c['legal_entity_id'],
                    'related_type' => 'ch_conversation', 'related_id' => $convPid], 'upload', true);
            } catch (ValidationException $x) {
                throw new ValidationException(['files' => (string) (array_values($x->errors)[0] ?? 'chat.error.file')]);
            }
            $ext = strtolower(pathinfo($f->safeName(), PATHINFO_EXTENSION));
            $ext = $ext === 'jpg' ? 'jpeg' : $ext;
            $stored[] = [(string) $d['document'], mb_substr($f->safeName(), 0, 200), self::FILE_TYPES[$ext] ?? 'application/pdf', strlen($f->bytes())];
        }
        $pid = Ulid::generate($this->app->clock);
        $now = $this->stamp();
        $id = $db->transactional(function () use ($db, $c, $az, $pid, $clientId, $body, $now, $stored): int {
            $db->run(
                "INSERT INTO ch_messages (public_id, conversation_id, sender_id, client_id, kind, body, created_at, updated_at) VALUES (?, ?, ?, ?, 'text', ?, ?, ?)",
                [$pid, $c['id'], $az->userId, $clientId, $body === '' ? null : $body, $now, $now]
            );
            $id = (int) $db->pdo()->lastInsertId();
            foreach ($stored as [$doc, $name, $mime, $size]) {
                $db->run('INSERT INTO ch_files (message_id, document_pid, filename, mime, size) VALUES (?, ?, ?, ?, ?)', [$id, $doc, $name, $mime, $size]);
            }
            $db->run('UPDATE ch_conversations SET last_message_id = ?, last_message_at = ? WHERE id = ?', [$id, $now, $c['id']]);
            $db->run('UPDATE ch_members SET last_read_id = ? WHERE conversation_id = ? AND user_id = ?', [$id, $c['id'], $az->userId]);
            return $id;
        });
        // notices: no text, at most once an hour per conversation per person, only to members who have not read it
        $who = $c['kind'] === 'direct' ? (string) $db->scalar('SELECT display_name FROM users WHERE id = ?', [$az->userId]) : (string) $c['title'];
        foreach ($c['members_list'] as $m) {
            if ((int) $m['id'] !== $az->userId) {
                $this->app->notifier()->notify(
                    (int) $m['id'],
                    'chat.message',
                    $c['kind'] === 'direct' ? 'chat.notify.direct' : 'chat.notify.group',
                    ['who' => mb_substr($who, 0, 80)],
                    '/chat/c/' . $convPid,
                    'ch:' . $convPid . ':' . $m['id'] . ':' . $this->hourKey()
                );
            }
        }
        return ['id' => $id, 'public_id' => $pid, 'duplicate' => false];
    }

    /** Edit (new text) or delete my message within chat.edit_minutes; the earlier text is kept. */
    public function change(Authorizer $az, string $convPid, string $msgPid, string $action, string $body = ''): void
    {
        $c = $this->conversation($az, $convPid);
        $db = $this->app->db();
        $m = $db->one('SELECT * FROM ch_messages WHERE public_id = ? AND conversation_id = ? AND id > ?', [$msgPid, $c['id'], $c['me']['visible_from_id']]) ?? throw HttpException::notFound();
        if ((int) $m['sender_id'] !== $az->userId || $m['kind'] !== 'text') {
            throw HttpException::forbidden();
        }
        if ($m['deleted_at'] !== null || $m['hidden_at'] !== null || $m['purged_at'] !== null) {
            throw new BusinessException('chat.error.gone', [], 409);
        }
        if ((string) $m['created_at'] < $this->ago('-' . $this->settings()['edit_minutes'] . ' minutes')) {
            throw new BusinessException('chat.error.too_late', ['minutes' => (string) $this->settings()['edit_minutes']], 409);
        }
        $now = $this->stamp();
        if ($action === 'edit') {
            $body = trim(str_replace("\r\n", "\n", $body));
            if ($body === '' || mb_strlen($body) > self::MAX_LENGTH) {
                throw new ValidationException(['body' => $body === '' ? 'chat.error.empty' : 'chat.error.too_long']);
            }
            if ($body === (string) $m['body']) {
                return;
            }
            $db->transactional(function () use ($db, $m, $body, $now): void {
                $db->run("INSERT INTO ch_message_edits (message_id, action, old_body, created_at) VALUES (?, 'edit', ?, ?)", [$m['id'], $m['body'], $now]);
                $db->run('UPDATE ch_messages SET body = ?, edited_at = ?, updated_at = ? WHERE id = ?', [$body, $now, $now, $m['id']]);
            });
        } else {
            $db->transactional(function () use ($db, $m, $now): void {
                $db->run("INSERT INTO ch_message_edits (message_id, action, old_body, created_at) VALUES (?, 'delete', ?, ?)", [$m['id'], $m['body'], $now]);
                $db->run('UPDATE ch_messages SET body = NULL, deleted_at = ?, updated_at = ? WHERE id = ?', [$now, $now, $m['id']]);
            });
        }
        $this->app->audit()->record('chat.message_' . ($action === 'edit' ? 'edited' : 'deleted'), 'success', 'ch_message', $msgPid, (int) $c['legal_entity_id']);
    }

    /**
     * A member's file (only of a message still visible to members).
     * @return array{bytes: string, mime: string, filename: string}
     */
    public function file(Authorizer $az, string $convPid, int $fileId): array
    {
        $c = $this->conversation($az, $convPid);
        $f = $this->app->db()->one(
            'SELECT f.* FROM ch_files f JOIN ch_messages m ON m.id = f.message_id WHERE f.id = ? AND m.conversation_id = ? AND m.id > ? AND m.deleted_at IS NULL AND m.hidden_at IS NULL AND m.purged_at IS NULL AND f.purged_at IS NULL',
            [$fileId, $c['id'], $c['me']['visible_from_id']]
        ) ?? throw HttpException::notFound();
        $doc = $this->app->documents()->moduleRead((string) $f['document_pid'], 'chat', $az->userId, 'chat file', false);
        return ['bytes' => $doc['bytes'], 'mime' => (string) $f['mime'], 'filename' => (string) $f['filename']];
    }

    /**
     * Search my own conversations (from when I joined; removed messages are not found).
     * @return list<array<string, mixed>>
     */
    public function search(Authorizer $az, string $q): array
    {
        $this->mustChat($az);
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $like = '%' . addcslashes(mb_substr($q, 0, 60), '%_\\') . '%';
        return $this->app->db()->all(
            "SELECT m.public_id, m.body, m.created_at, u.display_name AS sender, c.public_id AS conversation, c.kind, c.title,
                    (SELECT ou.display_name FROM ch_members o JOIN users ou ON ou.id = o.user_id WHERE o.conversation_id = c.id AND o.user_id <> me.user_id LIMIT 1) AS other_name
               FROM ch_members me JOIN ch_conversations c ON c.id = me.conversation_id JOIN ch_messages m ON m.conversation_id = c.id AND m.id > me.visible_from_id
               JOIN users u ON u.id = m.sender_id
              WHERE me.user_id = ? AND me.left_at IS NULL AND m.kind = 'text' AND m.deleted_at IS NULL AND m.hidden_at IS NULL AND m.purged_at IS NULL AND m.body LIKE ?
              ORDER BY m.id DESC LIMIT 50",
            [$az->userId, $like]
        );
    }

    // ---- groups ---------------------------------------------------------------------------------------------------------

    /**
     * Group owner: add / remove members, rename; anyone: leave. Added members see messages from now on.
     * @param array<string, mixed> $in
     */
    public function manage(Authorizer $az, string $convPid, string $action, array $in): void
    {
        $c = $this->conversation($az, $convPid);
        if ($c['kind'] !== 'group') {
            throw HttpException::notFound();
        }
        $db = $this->app->db();
        $owner = $c['me']['role'] === 'owner';
        $now = $this->stamp();
        $le = (int) $c['legal_entity_id'];
        $name = static fn (int $u): string => (string) $db->scalar('SELECT display_name FROM users WHERE id = ?', [$u]);
        switch ($action) {
            case 'leave':
                $db->transactional(function () use ($db, $c, $az, $now, $owner, $name): void {
                    $db->run('UPDATE ch_members SET left_at = ? WHERE conversation_id = ? AND user_id = ?', [$now, $c['id'], $az->userId]);
                    if ($owner) { // the longest-standing member takes over
                        $next = $db->scalar('SELECT user_id FROM ch_members WHERE conversation_id = ? AND left_at IS NULL ORDER BY joined_at, user_id LIMIT 1', [$c['id']]);
                        if ($next !== null) {
                            $db->run("UPDATE ch_members SET role = 'owner' WHERE conversation_id = ? AND user_id = ?", [$c['id'], $next]);
                        }
                    }
                    $this->system((int) $c['id'], $az->userId, 'chat.sys.left', ['name' => $name($az->userId)]);
                });
                break;
            case 'add':
                if (!$owner) {
                    throw HttpException::forbidden();
                }
                $u = (int) ($in['user'] ?? 0);
                if (!$this->isColleague($le, $u)) {
                    throw new ValidationException(['people' => 'chat.error.person']);
                }
                if ((int) $db->scalar('SELECT COUNT(*) FROM ch_members WHERE conversation_id = ? AND left_at IS NULL', [$c['id']]) >= self::MAX_GROUP) {
                    throw new ValidationException(['people' => 'chat.error.group_max']);
                }
                $db->transactional(function () use ($db, $c, $u, $now, $az, $name): void {
                    $from = (int) $db->scalar('SELECT IFNULL(MAX(id), 0) FROM ch_messages WHERE conversation_id = ?', [$c['id']]);
                    $db->run(
                        "INSERT INTO ch_members (conversation_id, user_id, role, joined_at, visible_from_id, last_read_id) VALUES (?, ?, 'member', ?, ?, ?)
                         ON DUPLICATE KEY UPDATE left_at = NULL, role = 'member', joined_at = VALUES(joined_at), visible_from_id = VALUES(visible_from_id), last_read_id = VALUES(last_read_id)",
                        [$c['id'], $u, $now, $from, $from]
                    );
                    $this->system((int) $c['id'], $az->userId, 'chat.sys.added', ['name' => $name($u)]);
                });
                break;
            case 'remove':
                $u = (int) ($in['user'] ?? 0);
                if (!$owner || $u === $az->userId) {
                    throw HttpException::forbidden();
                }
                $db->transactional(function () use ($db, $c, $u, $now, $az, $name): void {
                    if ($db->run('UPDATE ch_members SET left_at = ? WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL', [$now, $c['id'], $u])->rowCount() === 1) {
                        $this->system((int) $c['id'], $az->userId, 'chat.sys.removed', ['name' => $name($u)]);
                    }
                });
                break;
            case 'rename':
                if (!$owner) {
                    throw HttpException::forbidden();
                }
                $title = trim((string) ($in['title'] ?? ''));
                if (mb_strlen($title) < 2 || mb_strlen($title) > 80) {
                    throw new ValidationException(['title' => 'chat.error.title']);
                }
                $db->transactional(function () use ($db, $c, $title, $now, $az): void {
                    $db->run('UPDATE ch_conversations SET title = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [$title, $now, $az->userId, $c['id']]);
                    $this->system((int) $c['id'], $az->userId, 'chat.sys.renamed', ['title' => $title]);
                });
                break;
            default:
                throw HttpException::notFound();
        }
        $this->app->audit()->record('chat.group_' . $action, 'success', 'ch_conversation', $convPid, $le);
    }

    /**
     * A system line in the conversation ("X added Y") — key + params as JSON, translated when shown.
     * @param array<string, string> $params
     */
    private function system(int $convId, int $actor, string $key, array $params): void
    {
        $now = $this->stamp();
        $this->app->db()->run(
            "INSERT INTO ch_messages (public_id, conversation_id, sender_id, kind, body, created_at, updated_at) VALUES (?, ?, ?, 'system', ?, ?, ?)",
            [Ulid::generate($this->app->clock), $convId, $actor, (string) json_encode(['k' => $key, 'p' => $params], JSON_UNESCAPED_UNICODE), $now, $now]
        );
        $id = (int) $this->app->db()->pdo()->lastInsertId();
        $this->app->db()->run('UPDATE ch_conversations SET last_message_id = ?, last_message_at = ? WHERE id = ?', [$id, $now, $convId]);
    }

    // ---- reports (the ONLY way anyone but the members sees a message) ---------------------------------------------------------

    public function report(Authorizer $az, string $convPid, string $msgPid, string $reason): void
    {
        $c = $this->conversation($az, $convPid);
        $db = $this->app->db();
        $m = $db->one('SELECT * FROM ch_messages WHERE public_id = ? AND conversation_id = ? AND id > ?', [$msgPid, $c['id'], $c['me']['visible_from_id']]) ?? throw HttpException::notFound();
        $reason = trim($reason);
        if ((int) $m['sender_id'] === $az->userId || $m['kind'] !== 'text' || $m['deleted_at'] !== null || $m['hidden_at'] !== null || $m['purged_at'] !== null) {
            throw new BusinessException('chat.error.cannot_report', [], 409);
        }
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new ValidationException(['reason' => 'chat.error.reason']);
        }
        if ($db->scalar('SELECT id FROM ch_reports WHERE message_id = ? AND reporter_id = ?', [$m['id'], $az->userId]) !== null) {
            throw new BusinessException('chat.error.reported_already', [], 409);
        }
        $pid = Ulid::generate($this->app->clock);
        $now = $this->stamp();
        $le = (int) $c['legal_entity_id'];
        $db->run(
            'INSERT INTO ch_reports (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, message_id, reporter_id, reason, snapshot_body) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$pid, $now, $az->userId, $now, $az->userId, $le, $m['id'], $az->userId, $reason, $m['body']]
        );
        $this->app->audit()->record('chat.reported', 'success', 'ch_report', $pid, $le); // no text in the audit
        foreach ($this->moderators($le) as $u) {
            $this->app->notifier()->notify($u, 'chat.report', 'chat.notify.report', [], '/chat/moderate', 'ch.rp:' . $le . ':' . $u . ':' . $this->hourKey());
        }
    }

    /** @return list<int> */
    private function moderators(int $le): array
    {
        $out = [];
        foreach ($this->app->db()->all("SELECT DISTINCT a.user_id FROM user_role_assignments a JOIN users u ON u.id = a.user_id JOIN role_permissions p ON p.role_id = a.role_id WHERE a.status = 'active' AND u.status = 'active' AND p.permission_code = 'chat.moderate'") as $r) {
            $az = new Authorizer($this->app->db(), $this->app->clock, (int) $r['user_id']);
            if ($az->scope('chat.moderate')->coversLegalEntity($le)) {
                $out[] = (int) $r['user_id'];
            }
        }
        return $out;
    }

    /**
     * Report queue: each item shows the reported message only — its text when reported, the earlier versions and its files —
     * never the conversation around it or who else is in it.
     * @return list<array<string, mixed>>
     */
    public function reports(Authorizer $az, string $status = 'open'): array
    {
        $s = $az->scope('chat.moderate');
        if ($s->isEmpty()) {
            throw HttpException::forbidden();
        }
        $rows = $this->app->db()->all(
            "SELECT r.*, m.public_id AS message_pid, m.created_at AS sent_at, m.edited_at, m.deleted_at, m.hidden_at, m.hidden_reason, m.id AS mid,
                    su.display_name AS sender, ru.display_name AS reporter
               FROM ch_reports r JOIN ch_messages m ON m.id = r.message_id JOIN users su ON su.id = m.sender_id JOIN users ru ON ru.id = r.reporter_id
              WHERE r.status " . ($status === 'open' ? "= 'open'" : "<> 'open'") . ' ORDER BY r.id DESC LIMIT 200'
        );
        $out = [];
        foreach ($rows as $r) {
            if (!$s->coversLegalEntity((int) $r['legal_entity_id'])) {
                continue;
            }
            $r['edits'] = $this->app->db()->all('SELECT action, old_body, created_at FROM ch_message_edits WHERE message_id = ? ORDER BY id', [$r['mid']]);
            $r['files'] = $this->app->db()->all('SELECT id, filename, mime, size, purged_at FROM ch_files WHERE message_id = ? ORDER BY id', [$r['mid']]);
            $out[] = $r;
        }
        return $out;
    }

    /** @return array<string, mixed> a report the moderator may handle */
    private function reportRow(Authorizer $az, string $pid): array
    {
        $r = $this->app->db()->one('SELECT * FROM ch_reports WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        if (!$az->scope('chat.moderate')->coversLegalEntity((int) $r['legal_entity_id'])) {
            throw HttpException::notFound();
        }
        return $r;
    }

    /** Uphold (the message is hidden from the members with the reason, the sender told) or dismiss; every report of that message closes. */
    public function handle(Authorizer $az, string $reportPid, bool $uphold, string $note): void
    {
        $r = $this->reportRow($az, $reportPid);
        if ($r['status'] !== 'open') {
            throw new BusinessException('chat.error.handled', [], 409);
        }
        $note = trim($note);
        if ($uphold && (mb_strlen($note) < 5 || mb_strlen($note) > 500)) {
            throw new ValidationException(['note' => 'chat.error.reason']);
        }
        $db = $this->app->db();
        $now = $this->stamp();
        $m = $db->one('SELECT m.*, c.public_id AS conv FROM ch_messages m JOIN ch_conversations c ON c.id = m.conversation_id WHERE m.id = ?', [$r['message_id']]) ?? throw HttpException::notFound();
        $db->transactional(function () use ($db, $r, $uphold, $note, $now, $az, $m): void {
            $db->run(
                "UPDATE ch_reports SET status = ?, handled_by = ?, handled_at = ?, note = ?, updated_at = ?, updated_by = ? WHERE message_id = ? AND status = 'open'",
                [$uphold ? 'upheld' : 'dismissed', $az->userId, $now, $note === '' ? null : $note, $now, $az->userId, $r['message_id']]
            );
            if ($uphold && $m['hidden_at'] === null) {
                $db->run('UPDATE ch_messages SET hidden_at = ?, hidden_reason = ?, updated_at = ? WHERE id = ?', [$now, $note, $now, $m['id']]);
            }
        });
        $this->app->audit()->record('chat.report_' . ($uphold ? 'upheld' : 'dismissed'), 'success', 'ch_report', $reportPid, (int) $r['legal_entity_id']);
        if ($uphold) {
            $this->app->notifier()->notify((int) $m['sender_id'], 'chat.moderation', 'chat.notify.hidden', ['reason' => mb_substr($note, 0, 200)], '/chat/c/' . $m['conv'], 'ch.h:' . $m['id']);
        }
    }

    /**
     * A file of a REPORTED message, for the moderator handling it (audited).
     * @return array{bytes: string, mime: string, filename: string}
     */
    public function reportFile(Authorizer $az, string $reportPid, int $fileId): array
    {
        $r = $this->reportRow($az, $reportPid);
        $f = $this->app->db()->one('SELECT * FROM ch_files WHERE id = ? AND message_id = ? AND purged_at IS NULL', [$fileId, $r['message_id']]) ?? throw HttpException::notFound();
        $doc = $this->app->documents()->moduleRead((string) $f['document_pid'], 'chat', $az->userId, 'chat report review');
        $this->app->audit()->record('chat.report_file_viewed', 'success', 'ch_report', $reportPid, (int) $r['legal_entity_id']);
        return ['bytes' => $doc['bytes'], 'mime' => (string) $f['mime'], 'filename' => (string) $f['filename']];
    }

    // ---- retention (Q-04) ---------------------------------------------------------------------------------------------------

    /**
     * Removes the text, the earlier versions and the files of messages older than chat.retention_days. A message with an
     * open report waits until the report is handled. @return int messages removed
     */
    public function retention(): int
    {
        $db = $this->app->db();
        $cut = $this->ago('-' . $this->settings()['retention_days'] . ' days');
        $now = $this->stamp();
        $n = 0;
        foreach ($db->all("SELECT m.id FROM ch_messages m WHERE m.created_at < ? AND m.purged_at IS NULL AND NOT EXISTS (SELECT 1 FROM ch_reports r WHERE r.message_id = m.id AND r.status = 'open') LIMIT 500", [$cut]) as $m) {
            foreach ($db->all('SELECT id, document_pid FROM ch_files WHERE message_id = ? AND purged_at IS NULL', [$m['id']]) as $f) {
                $docId = $db->scalar('SELECT id FROM documents WHERE public_id = ?', [$f['document_pid']]);
                if ($docId !== null) {
                    $this->app->documents()->purge((int) $docId, 0);
                }
                $db->run('UPDATE ch_files SET purged_at = ? WHERE id = ?', [$now, $f['id']]);
            }
            $db->run('DELETE FROM ch_message_edits WHERE message_id = ?', [$m['id']]);
            $db->run("UPDATE ch_messages SET body = NULL, purged_at = ?, updated_at = ? WHERE id = ?", [$now, $now, $m['id']]);
            $db->run('UPDATE ch_reports SET snapshot_body = NULL WHERE message_id = ?', [$m['id']]);
            $n++;
        }
        if ($n > 0) {
            $this->app->audit()->record('chat.retention', 'success', 'ch_message', null, null, null, ['messages' => $n, 'days' => $this->settings()['retention_days']]);
        }
        return $n;
    }

    public static function retentionJob(App $app): void
    {
        self::of($app)->retention();
    }

    /** Escaped text with line breaks and clickable https links (no HTML from people). */
    public static function render(string $text): string
    {
        $esc = htmlspecialchars(trim($text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $linked = (string) preg_replace_callback('~https://[^\s<>"\']+~u', static function (array $m): string {
            $u = rtrim($m[0], '.,;:)');
            return '<a href="' . $u . '" rel="noopener noreferrer" target="_blank">' . $u . '</a>' . substr($m[0], strlen($u));
        }, $esc);
        return nl2br($linked, false);
    }

    /** Translated system line. */
    public static function systemText(string $json, callable $t): string
    {
        $d = json_decode($json, true);
        return is_array($d) && is_string($d['k'] ?? null) && str_starts_with($d['k'], 'chat.sys.') ? (string) $t($d['k'], array_map('strval', (array) ($d['p'] ?? []))) : '';
    }
}
