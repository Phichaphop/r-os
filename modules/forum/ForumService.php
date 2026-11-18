<?php

declare(strict_types=1);

namespace Blue\Modules\Forum;

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
 * M54 discussion board (9D-2 · DEC-98).
 *
 * Employees of a company start threads in the categories the moderators set up (title, text, up to three images / PDFs),
 * reply, like, and @mention colleagues. Posting is rate-limited. The author of a thread and everyone who replied are told
 * about new replies (at most once an hour per thread). Authors may edit or remove their own text; the earlier text is
 * kept as evidence and a removed post leaves a placeholder. Anyone can report a thread or reply once with a reason;
 * moderators (forum.moderate) see the queue, uphold (the item is hidden with the reason, the author told) or dismiss, and
 * may hide / restore, lock / unlock and pin threads — every action with a reason where it takes something away, audited.
 */
final class ForumService
{
    public const MAX_THREADS_PER_HOUR = 5;

    public const MAX_REPLIES_PER_HOUR = 30;

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

    public function hourKey(): string
    {
        return $this->app->clock->now()->setTimezone(new DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok')))->format('YmdH');
    }

    /** @return list<int> */
    public function companies(Authorizer $az, string $perm): array
    {
        $s = $az->scope($perm);
        return array_values(array_filter(array_map('intval', array_column($this->app->db()->all('SELECT id FROM legal_entities ORDER BY code'), 'id')), static fn (int $id): bool => $s->coversLegalEntity($id)));
    }

    public function can(Authorizer $az, string $perm, int $le): bool
    {
        return $az->scope($perm)->coversLegalEntity($le);
    }

    /** The board this person posts on: their employer today (moderators without an employee record: their first company). */
    public function boardOf(Authorizer $az): ?int
    {
        $me = EmployeeService::of($this->app)->forUser($az->userId);
        if ($me !== null && $me['status'] !== 'former' && $me['job'] !== null && $this->can($az, 'forum.post', (int) $me['job']['legal_entity_id'])) {
            return (int) $me['job']['legal_entity_id'];
        }
        return $this->companies($az, 'forum.moderate')[0] ?? null;
    }

    private function isMember(Authorizer $az, int $le): bool
    {
        $me = EmployeeService::of($this->app)->forUser($az->userId);
        return $me !== null && $me['status'] !== 'former' && $me['job'] !== null && (int) $me['job']['legal_entity_id'] === $le && $this->can($az, 'forum.post', $le);
    }

    /** @return list<int> */
    private function moderators(int $le): array
    {
        $out = [];
        foreach ($this->app->db()->all("SELECT DISTINCT a.user_id FROM user_role_assignments a JOIN users u ON u.id = a.user_id JOIN role_permissions p ON p.role_id = a.role_id WHERE a.status = 'active' AND u.status = 'active' AND p.permission_code = 'forum.moderate'") as $r) {
            $az = new Authorizer($this->app->db(), $this->app->clock, (int) $r['user_id']);
            if ($az->scope('forum.moderate')->coversLegalEntity($le)) {
                $out[] = (int) $r['user_id'];
            }
        }
        return $out;
    }

    /** Escaped text with line breaks and clickable https links; @username mentions are left as text. */
    public static function render(string $text): string
    {
        $esc = htmlspecialchars(trim($text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $linked = (string) preg_replace_callback('~https?://[^\s<>"\']+~u', static function (array $m): string {
            $u = rtrim($m[0], '.,;:)');
            return '<a href="' . $u . '" rel="noopener noreferrer" target="_blank">' . $u . '</a>' . substr($m[0], strlen($u));
        }, $esc);
        return implode('', array_map(static fn (string $p): string => '<p>' . nl2br($p, false) . '</p>', preg_split('/\R{2,}/u', $linked) ?: []));
    }

    // ---- categories ----------------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function categories(int $le, bool $activeOnly = false): array
    {
        return $this->app->db()->all('SELECT c.*, (SELECT COUNT(*) FROM fm_threads t WHERE t.category_id = c.id AND t.hidden = 0) AS threads FROM fm_categories c WHERE c.legal_entity_id = ?' . ($activeOnly ? ' AND c.active = 1' : '') . ' ORDER BY c.active DESC, c.sort, c.name', [$le]);
    }

    /** @param array<string, mixed> $in name, sort, active */
    public function saveCategory(Authorizer $az, int $le, array $in, ?string $pid = null): string
    {
        if (!$this->can($az, 'forum.moderate', $le)) {
            throw HttpException::forbidden();
        }
        $db = $this->app->db();
        $name = trim((string) ($in['name'] ?? ''));
        $sort = trim((string) ($in['sort'] ?? '0'));
        $e = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            $e['name'] = 'forum.error.name';
        } elseif ($db->scalar('SELECT id FROM fm_categories WHERE legal_entity_id = ? AND name = ? AND public_id <> ?', [$le, $name, $pid ?? '']) !== null) {
            $e['name'] = 'forum.error.name_taken';
        }
        if (preg_match('/^\d{1,3}$/', $sort === '' ? '0' : $sort) !== 1) {
            $e['sort'] = 'forum.error.sort';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $now = $this->stamp();
        if ($pid === null) {
            $pid = Ulid::generate($this->app->clock);
            $db->run('INSERT INTO fm_categories (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, name, sort) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$pid, $now, $az->userId, $now, $az->userId, $le, $name, (int) $sort]);
        } else {
            if ($db->scalar('SELECT id FROM fm_categories WHERE public_id = ? AND legal_entity_id = ?', [$pid, $le]) === null) {
                throw HttpException::notFound();
            }
            $db->run('UPDATE fm_categories SET name = ?, sort = ?, active = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE public_id = ?', [$name, (int) $sort, !empty($in['active']) ? 1 : 0, $now, $az->userId, $pid]);
        }
        $this->app->audit()->record('forum.category_saved', 'success', 'fm_category', $pid, $le);
        return $pid;
    }

    // ---- reading ---------------------------------------------------------------------------------------------------------------

    /**
     * Threads of the board: pinned first, then by the chosen order. Moderators also see hidden threads (flagged).
     *
     * @param array<string, string> $f category, q, sort (latest|popular|week), mine
     * @return list<array<string, mixed>>
     */
    public function threads(Authorizer $az, int $le, array $f = []): array
    {
        $mod = $this->can($az, 'forum.moderate', $le);
        if (!$mod && !$this->isMember($az, $le)) {
            throw HttpException::forbidden();
        }
        $where = 't.legal_entity_id = ?';
        $params = [$le];
        if (!$mod) {
            $where .= ' AND (t.hidden = 0 OR t.author_id = ?)';
            $params[] = $az->userId;
        }
        if (($f['category'] ?? '') !== '') {
            $where .= ' AND c.public_id = ?';
            $params[] = (string) $f['category'];
        }
        if (($f['mine'] ?? '') === '1') {
            $where .= ' AND t.author_id = ?';
            $params[] = $az->userId;
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes(mb_substr($q, 0, 60), '%_\\') . '%';
            $where .= ' AND (t.title LIKE ? OR t.body LIKE ?)';
            array_push($params, $like, $like);
        }
        $sort = (string) ($f['sort'] ?? '');
        if ($sort === 'week') {
            $where .= ' AND t.created_at >= ?';
            $params[] = $this->ago('-7 days');
        }
        $order = match ($sort) {
            'popular', 'week' => '(likes + t.reply_count) DESC, t.last_post_at DESC',
            default => 't.pinned DESC, t.last_post_at DESC',
        };
        return $this->app->db()->all(
            "SELECT t.public_id, t.title, t.pinned, t.locked, t.hidden, t.reply_count, t.last_post_at, t.created_at, c.name AS category, u.display_name AS author,
                    (SELECT COUNT(*) FROM fm_likes l WHERE l.target = 'thread' AND l.target_id = t.id) AS likes
               FROM fm_threads t JOIN fm_categories c ON c.id = t.category_id JOIN users u ON u.id = t.author_id
              WHERE {$where} ORDER BY {$order} LIMIT 200",
            [...$params]
        );
    }

    /** @return array<string, mixed> */
    private function threadRow(string $pid): array
    {
        return $this->app->db()->one('SELECT * FROM fm_threads WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
    }

    /**
     * A thread with its replies. Hidden content is replaced by a placeholder except for its author and the moderators.
     *
     * @return array<string, mixed>
     */
    public function thread(Authorizer $az, string $pid): array
    {
        $t = $this->threadRow($pid);
        $le = (int) $t['legal_entity_id'];
        $mod = $this->can($az, 'forum.moderate', $le);
        $mine = (int) $t['author_id'] === $az->userId;
        if (!$mod && !$this->isMember($az, $le)) {
            throw HttpException::notFound();
        }
        if ((int) $t['hidden'] === 1 && !$mod && !$mine) {
            throw HttpException::notFound();
        }
        $db = $this->app->db();
        $t['author'] = $db->scalar('SELECT display_name FROM users WHERE id = ?', [$t['author_id']]);
        $t['category'] = $db->one('SELECT name, public_id FROM fm_categories WHERE id = ?', [$t['category_id']]);
        $t['likes'] = (int) $db->scalar("SELECT COUNT(*) FROM fm_likes WHERE target = 'thread' AND target_id = ?", [$t['id']]);
        $t['liked'] = $db->scalar("SELECT 1 FROM fm_likes WHERE target = 'thread' AND target_id = ? AND user_id = ?", [$t['id'], $az->userId]) !== null;
        $t['files'] = $db->all('SELECT id, filename, mime FROM fm_files WHERE thread_id = ? ORDER BY id', [$t['id']]);
        $t['reported'] = $db->scalar("SELECT 1 FROM fm_reports WHERE target = 'thread' AND target_id = ? AND reporter_id = ?", [$t['id'], $az->userId]) !== null;
        $t['edits'] = $mod ? $db->all("SELECT e.old_title, e.old_body, e.edited_at, u.display_name AS editor FROM fm_edits e JOIN users u ON u.id = e.edited_by WHERE e.target = 'thread' AND e.target_id = ? ORDER BY e.id", [$t['id']]) : [];
        $posts = [];
        foreach (
            $db->all(
                "SELECT p.*, u.display_name AS author, (SELECT COUNT(*) FROM fm_likes l WHERE l.target = 'post' AND l.target_id = p.id) AS likes,
                        (SELECT COUNT(*) FROM fm_likes l WHERE l.target = 'post' AND l.target_id = p.id AND l.user_id = ?) AS liked,
                        (SELECT COUNT(*) FROM fm_reports r WHERE r.target = 'post' AND r.target_id = p.id AND r.reporter_id = ?) AS reported,
                        (SELECT COUNT(*) FROM fm_edits e WHERE e.target = 'post' AND e.target_id = p.id) AS edit_count
                   FROM fm_posts p JOIN users u ON u.id = p.author_id WHERE p.thread_id = ? ORDER BY p.id",
                [$az->userId, $az->userId, $t['id']]
            ) as $p
        ) {
            $p['mine'] = (int) $p['author_id'] === $az->userId;
            $p['visible'] = (int) $p['hidden'] === 0 || $mod || $p['mine'];
            if (!$p['visible']) {
                $p['body'] = '';
            }
            $posts[] = $p;
        }
        $t['posts'] = $posts;
        $t['is_mod'] = $mod;
        $t['mine'] = $mine;
        $t['can_reply'] = (int) $t['locked'] === 0 && (int) $t['hidden'] === 0 && $this->isMember($az, $le);
        return $t;
    }

    /** @return array{bytes: string, mime: string, filename: string} */
    public function file(Authorizer $az, string $pid, int $fileId): array
    {
        $t = $this->thread($az, $pid);
        $f = $this->app->db()->one('SELECT * FROM fm_files WHERE id = ? AND thread_id = ?', [$fileId, $t['id']]) ?? throw HttpException::notFound();
        $doc = $this->app->documents()->moduleRead((string) $f['document_pid'], 'forum', $az->userId, 'forum file');
        return ['bytes' => $doc['bytes'], 'mime' => $doc['mime'], 'filename' => (string) $f['filename']];
    }

    // ---- writing ---------------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $in category, title, body
     * @param list<UploadedFile> $files
     * @return string thread public id
     */
    public function create(Authorizer $az, array $in, array $files = []): string
    {
        $le = $this->boardOf($az);
        if ($le === null || !$this->isMember($az, $le)) {
            throw new BusinessException('forum.error.members_only', [], 403);
        }
        $db = $this->app->db();
        $title = trim((string) ($in['title'] ?? ''));
        $body = trim(str_replace("\r\n", "\n", (string) ($in['body'] ?? '')));
        $e = [];
        if (mb_strlen($title) < 5 || mb_strlen($title) > 160) {
            $e['title'] = 'forum.error.title';
        }
        if (mb_strlen($body) < 2 || mb_strlen($body) > 10000) {
            $e['body'] = 'forum.error.body';
        }
        $cat = $db->scalar('SELECT id FROM fm_categories WHERE public_id = ? AND legal_entity_id = ? AND active = 1', [(string) ($in['category'] ?? ''), $le]);
        if ($cat === null) {
            $e['category'] = 'forum.error.choice';
        }
        if (count($files) > 3) {
            $e['files'] = 'forum.error.too_many_files';
        }
        foreach ($files as $f) {
            if (!$f->ok()) {
                $e['files'] = $f->errorKey();
            }
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        if ((int) $db->scalar('SELECT COUNT(*) FROM fm_threads WHERE author_id = ? AND created_at >= ?', [$az->userId, $this->ago('-1 hour')]) >= self::MAX_THREADS_PER_HOUR) {
            throw new BusinessException('forum.error.slow_down', [], 429);
        }
        $stored = [];
        foreach ($files as $f) {
            try {
                $d = $this->app->documents()->storeGenerated($az->userId, 'forum.file', $f->bytes(), $f->safeName(), ['title' => 'Forum file', 'legal_entity_id' => $le], 'upload', true);
            } catch (ValidationException $x) {
                throw new ValidationException(['files' => (string) (array_values($x->errors)[0] ?? 'forum.error.file')]);
            }
            $ext = strtolower(pathinfo($f->safeName(), PATHINFO_EXTENSION));
            $stored[] = [(string) $d['document'], mb_substr($f->safeName(), 0, 200), ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'][$ext] ?? 'application/pdf'];
        }
        $pid = Ulid::generate($this->app->clock);
        $now = $this->stamp();
        $db->transactional(function () use ($db, $pid, $now, $az, $le, $cat, $title, $body, $stored): void {
            $db->run('INSERT INTO fm_threads (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, category_id, author_id, title, body, last_post_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$pid, $now, $az->userId, $now, $az->userId, $le, $cat, $az->userId, $title, $body, $now]);
            $id = (int) $db->pdo()->lastInsertId();
            foreach ($stored as [$doc, $name, $mime]) {
                $db->run('INSERT INTO fm_files (thread_id, document_pid, filename, mime) VALUES (?, ?, ?, ?)', [$id, $doc, $name, $mime]);
            }
        });
        $this->mentions($le, $title . ' ' . $body, $az->userId, $pid, $title);
        return $pid;
    }

    /** Tells colleagues named as @username (same company, not the writer). */
    private function mentions(int $le, string $text, int $writer, string $threadPid, string $title): void
    {
        if (preg_match_all('/(?<![\w.])@([A-Za-z0-9._-]{3,60})/u', $text, $m) === 0) {
            return;
        }
        foreach (array_slice(array_unique($m[1]), 0, 10) as $name) {
            $uid = $this->app->db()->scalar("SELECT id FROM users WHERE username = ? AND status = 'active'", [rtrim($name, '.')]);
            if ($uid === null || (int) $uid === $writer) {
                continue;
            }
            $u = new Authorizer($this->app->db(), $this->app->clock, (int) $uid);
            if ($this->isMember($u, $le)) {
                $this->app->notifier()->notify((int) $uid, 'forum.mention', 'forum.notify.mention', ['title' => mb_substr($title, 0, 80)], '/forum/t/' . $threadPid, 'fm.m:' . $threadPid . ':' . $uid . ':' . $this->hourKey());
            }
        }
    }

    public function reply(Authorizer $az, string $pid, string $body): void
    {
        $t = $this->thread($az, $pid);
        if (!$t['can_reply']) {
            throw new BusinessException((int) $t['locked'] === 1 ? 'forum.error.locked' : 'forum.error.members_only', [], 409);
        }
        $body = trim(str_replace("\r\n", "\n", $body));
        if (mb_strlen($body) < 1 || mb_strlen($body) > 5000) {
            throw new ValidationException(['body' => 'forum.error.reply']);
        }
        $db = $this->app->db();
        if ((int) $db->scalar('SELECT COUNT(*) FROM fm_posts WHERE author_id = ? AND created_at >= ?', [$az->userId, $this->ago('-1 hour')]) >= self::MAX_REPLIES_PER_HOUR) {
            throw new BusinessException('forum.error.slow_down', [], 429);
        }
        $now = $this->stamp();
        $db->transactional(function () use ($db, $t, $az, $body, $now): void {
            $db->run('INSERT INTO fm_posts (public_id, thread_id, author_id, body, created_at) VALUES (?, ?, ?, ?, ?)', [Ulid::generate($this->app->clock), $t['id'], $az->userId, $body, $now]);
            $db->run('UPDATE fm_threads SET reply_count = reply_count + 1, last_post_at = ? WHERE id = ?', [$now, $t['id']]);
        });
        // the thread's author and everyone who replied, at most once an hour per thread
        $people = array_map('intval', array_column($db->all('SELECT DISTINCT author_id FROM fm_posts WHERE thread_id = ? AND hidden = 0', [$t['id']]), 'author_id'));
        $people[] = (int) $t['author_id'];
        foreach (array_unique($people) as $u) {
            if ($u !== $az->userId) {
                $this->app->notifier()->notify($u, 'forum.reply', 'forum.notify.reply', ['title' => mb_substr((string) $t['title'], 0, 80)], '/forum/t/' . $pid, 'fm.r:' . $pid . ':' . $u . ':' . $this->hourKey());
            }
        }
        $this->mentions((int) $t['legal_entity_id'], $body, $az->userId, $pid, (string) $t['title']);
    }

    /** @return array<string, mixed> post row with its thread */
    private function post(string $pid): array
    {
        return $this->app->db()->one('SELECT p.*, t.public_id AS thread_pid, t.legal_entity_id, t.locked AS thread_locked FROM fm_posts p JOIN fm_threads t ON t.id = p.thread_id WHERE p.public_id = ?', [$pid]) ?? throw HttpException::notFound();
    }

    /**
     * The author edits their own thread (title + text) or reply; the earlier text is kept.
     *
     * @param array<string, mixed> $in title, body
     */
    public function edit(Authorizer $az, string $target, string $pid, array $in): string
    {
        $db = $this->app->db();
        $now = $this->stamp();
        $body = trim(str_replace("\r\n", "\n", (string) ($in['body'] ?? '')));
        if ($target === 'thread') {
            $t = $this->threadRow($pid);
            if ((int) $t['author_id'] !== $az->userId || (int) $t['hidden'] === 1 || (int) $t['locked'] === 1) {
                throw new BusinessException('forum.error.cannot_edit', [], 409);
            }
            $title = trim((string) ($in['title'] ?? ''));
            $e = [];
            if (mb_strlen($title) < 5 || mb_strlen($title) > 160) {
                $e['title'] = 'forum.error.title';
            }
            if (mb_strlen($body) < 2 || mb_strlen($body) > 10000) {
                $e['body'] = 'forum.error.body';
            }
            if ($e !== []) {
                throw new ValidationException($e);
            }
            $db->run("INSERT INTO fm_edits (target, target_id, old_title, old_body, edited_by, edited_at) VALUES ('thread', ?, ?, ?, ?, ?)", [$t['id'], $t['title'], $t['body'], $az->userId, $now]);
            $db->run('UPDATE fm_threads SET title = ?, body = ?, edited_at = ?, updated_at = ?, updated_by = ? WHERE id = ?', [$title, $body, $now, $now, $az->userId, $t['id']]);
            return $pid;
        }
        $p = $this->post($pid);
        if ((int) $p['author_id'] !== $az->userId || (int) $p['hidden'] === 1 || (int) $p['thread_locked'] === 1) {
            throw new BusinessException('forum.error.cannot_edit', [], 409);
        }
        if (mb_strlen($body) < 1 || mb_strlen($body) > 5000) {
            throw new ValidationException(['body' => 'forum.error.reply']);
        }
        $db->run("INSERT INTO fm_edits (target, target_id, old_body, edited_by, edited_at) VALUES ('post', ?, ?, ?, ?)", [$p['id'], $p['body'], $az->userId, $now]);
        $db->run('UPDATE fm_posts SET body = ?, edited_at = ? WHERE id = ?', [$body, $now, $p['id']]);
        return (string) $p['thread_pid'];
    }

    /** The author removes their own reply: it is hidden (placeholder), the text stays for moderation evidence. */
    public function remove(Authorizer $az, string $postPid): string
    {
        $p = $this->post($postPid);
        if ((int) $p['author_id'] !== $az->userId || (int) $p['hidden'] === 1) {
            throw new BusinessException('forum.error.cannot_edit', [], 409);
        }
        $this->app->db()->run("UPDATE fm_posts SET hidden = 1, hidden_reason = 'author', hidden_by = ? WHERE id = ?", [$az->userId, $p['id']]);
        return (string) $p['thread_pid'];
    }

    /** Likes (or un-likes) a thread or a reply of a colleague. */
    public function like(Authorizer $az, string $target, string $pid): bool
    {
        $db = $this->app->db();
        if ($target === 'thread') {
            $t = $this->thread($az, $pid);
            $id = (int) $t['id'];
            $author = (int) $t['author_id'];
            $le = (int) $t['legal_entity_id'];
            $hidden = (int) $t['hidden'];
        } else {
            $p = $this->post($pid);
            $this->thread($az, (string) $p['thread_pid']);
            $id = (int) $p['id'];
            $author = (int) $p['author_id'];
            $le = (int) $p['legal_entity_id'];
            $hidden = (int) $p['hidden'];
        }
        if ($author === $az->userId || $hidden === 1 || !$this->isMember($az, $le)) {
            throw new BusinessException('forum.error.cannot_like', [], 409);
        }
        if ($db->run('DELETE FROM fm_likes WHERE target = ? AND target_id = ? AND user_id = ?', [$target, $id, $az->userId])->rowCount() > 0) {
            return false;
        }
        $db->run('INSERT INTO fm_likes (target, target_id, user_id, created_at) VALUES (?, ?, ?, ?)', [$target, $id, $az->userId, $this->stamp()]);
        return true;
    }

    /** Reports a thread or reply to the moderators (once per person per item, with a reason). */
    public function report(Authorizer $az, string $target, string $pid, string $reason): string
    {
        if ($target === 'thread') {
            $t = $this->thread($az, $pid);
            $id = (int) $t['id'];
            $le = (int) $t['legal_entity_id'];
            $threadPid = $pid;
            $author = (int) $t['author_id'];
        } else {
            $p = $this->post($pid);
            $this->thread($az, (string) $p['thread_pid']);
            $id = (int) $p['id'];
            $le = (int) $p['legal_entity_id'];
            $threadPid = (string) $p['thread_pid'];
            $author = (int) $p['author_id'];
        }
        if ($author === $az->userId) {
            throw new BusinessException('forum.error.own_report', [], 409);
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new ValidationException(['reason' => 'forum.error.reason']);
        }
        $n = $this->app->db()->run('INSERT IGNORE INTO fm_reports (public_id, legal_entity_id, target, target_id, reporter_id, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [Ulid::generate($this->app->clock), $le, $target, $id, $az->userId, $reason, $this->stamp()])->rowCount();
        if ($n === 0) {
            throw new BusinessException('forum.error.already_reported', [], 409);
        }
        foreach ($this->moderators($le) as $u) {
            $this->app->notifier()->notify($u, 'forum.report', 'forum.notify.report', [], '/forum/moderate', 'fm.rp:' . $le . ':' . $u . ':' . $this->hourKey());
        }
        return $threadPid;
    }

    // ---- moderation ------------------------------------------------------------------------------------------------------------

    /**
     * hide / unhide (thread or reply) · lock / unlock / pin / unpin (thread). Taking something away needs a reason.
     *
     * @return string the thread public id
     */
    public function moderate(Authorizer $az, string $target, string $pid, string $action, string $reason): string
    {
        $db = $this->app->db();
        if ($target === 'thread') {
            $row = $this->threadRow($pid);
            $threadPid = $pid;
        } else {
            $row = $this->post($pid);
            $threadPid = (string) $row['thread_pid'];
        }
        $le = (int) $row['legal_entity_id'];
        if (!$this->can($az, 'forum.moderate', $le)) {
            throw HttpException::forbidden();
        }
        $reason = trim($reason);
        if (in_array($action, ['hide', 'lock'], true) && (mb_strlen($reason) < 5 || mb_strlen($reason) > 500)) {
            throw new ValidationException(['reason' => 'forum.error.reason']);
        }
        $table = $target === 'thread' ? 'fm_threads' : 'fm_posts';
        $sql = match ($action) {
            'hide' => ['hidden = 1, hidden_reason = ?, hidden_by = ?', [$reason, $az->userId]],
            'unhide' => ['hidden = 0, hidden_reason = NULL, hidden_by = NULL', []],
            'lock' => ['locked = 1', []],
            'unlock' => ['locked = 0', []],
            'pin' => ['pinned = 1', []],
            'unpin' => ['pinned = 0', []],
            default => throw HttpException::notFound(),
        };
        if ($target !== 'thread' && !in_array($action, ['hide', 'unhide'], true)) {
            throw HttpException::notFound();
        }
        $db->run("UPDATE {$table} SET {$sql[0]} WHERE id = ?", [...$sql[1], $row['id']]);
        if ($action === 'hide' && (int) $row['author_id'] !== $az->userId) {
            $this->app->notifier()->notify((int) $row['author_id'], 'forum.moderation', 'forum.notify.hidden', ['reason' => $reason], '/forum/t/' . $threadPid, 'fm.h:' . $target . ':' . $row['id'] . ':' . $this->hourKey());
        }
        $this->app->audit()->record('forum.' . $target . '_' . $action, 'success', 'fm_' . $target, $pid, $le, $reason !== '' ? $reason : null);
        return $threadPid;
    }

    /**
     * Open reports (and recently handled ones) with the reported text.
     *
     * @return array{open: list<array<string, mixed>>, done: list<array<string, mixed>>}
     */
    public function reports(Authorizer $az, int $le): array
    {
        if (!$this->can($az, 'forum.moderate', $le)) {
            throw HttpException::forbidden();
        }
        $rows = $this->app->db()->all(
            "SELECT r.*, u.display_name AS reporter, h.display_name AS handler,
                    COALESCE(t.public_id, pt.public_id) AS thread_pid, COALESCE(t.title, pt.title) AS thread_title,
                    COALESCE(t.body, p.body) AS body, COALESCE(t.hidden, p.hidden) AS hidden, p.public_id AS post_pid,
                    a.display_name AS author
               FROM fm_reports r JOIN users u ON u.id = r.reporter_id LEFT JOIN users h ON h.id = r.handled_by
               LEFT JOIN fm_threads t ON r.target = 'thread' AND t.id = r.target_id
               LEFT JOIN fm_posts p ON r.target = 'post' AND p.id = r.target_id LEFT JOIN fm_threads pt ON pt.id = p.thread_id
               LEFT JOIN users a ON a.id = COALESCE(t.author_id, p.author_id)
              WHERE r.legal_entity_id = ? ORDER BY r.status = 'open' DESC, r.id DESC LIMIT 200",
            [$le]
        );
        return ['open' => array_values(array_filter($rows, static fn (array $r): bool => $r['status'] === 'open')), 'done' => array_values(array_filter($rows, static fn (array $r): bool => $r['status'] !== 'open'))];
    }

    /** Upholds a report (the item is hidden with the note as reason — every open report on it is closed) or dismisses it. */
    public function handle(Authorizer $az, string $reportPid, bool $uphold, string $note): void
    {
        $db = $this->app->db();
        $r = $db->one('SELECT * FROM fm_reports WHERE public_id = ?', [$reportPid]) ?? throw HttpException::notFound();
        if (!$this->can($az, 'forum.moderate', (int) $r['legal_entity_id'])) {
            throw HttpException::forbidden();
        }
        if ($r['status'] !== 'open') {
            throw new BusinessException('forum.error.handled', [], 409);
        }
        $note = trim($note);
        if (mb_strlen($note) < 5 || mb_strlen($note) > 500) {
            throw new ValidationException(['note' => 'forum.error.reason']);
        }
        if ($uphold) {
            $pid = (string) $db->scalar('SELECT public_id FROM ' . ($r['target'] === 'thread' ? 'fm_threads' : 'fm_posts') . ' WHERE id = ?', [$r['target_id']]);
            $this->moderate($az, (string) $r['target'], $pid, 'hide', $note);
            $db->run("UPDATE fm_reports SET status = 'upheld', handled_by = ?, handled_at = ?, note = ? WHERE target = ? AND target_id = ? AND status = 'open'", [$az->userId, $this->stamp(), $note, $r['target'], $r['target_id']]);
        } else {
            $db->run("UPDATE fm_reports SET status = 'dismissed', handled_by = ?, handled_at = ?, note = ? WHERE id = ?", [$az->userId, $this->stamp(), $note, $r['id']]);
        }
        $this->app->audit()->record($uphold ? 'forum.report_upheld' : 'forum.report_dismissed', 'success', 'fm_report', $reportPid, (int) $r['legal_entity_id'], $note);
    }
}
