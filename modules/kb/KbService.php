<?php

declare(strict_types=1);

namespace Blue\Modules\Kb;

use DateTimeImmutable;
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
 * M55 Q&A / knowledge base (9D-1 · DEC-97).
 *
 * Authors (kb.author) write question-and-answer articles in categories (Thai required, English optional), with tags, the
 * source they rely on, an owner and a review date. Every change is a revision: draft → review → published (the previous
 * published revision is superseded, still kept) or returned; a reviewer (kb.review) who did not write the revision
 * approves it. Readers only ever see the published revision. An article can be withdrawn with a reason, optionally telling
 * everyone who read it. Past its review date the owner is reminded and readers see that it is under review. Readers search
 * (words, the company's synonym groups), browse by category, see the most read, mark an answer helpful or not, and ask a
 * new question — it is answered by publishing an article (the asker is told when it is published) or declined with a reply.
 * Before reporting a fault, the help desk form suggests matching answers (through the /kb/suggest page, no code link).
 */
final class KbService
{
    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    public function today(): string
    {
        return $this->app->clock->now()->setTimezone(new DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok')))->format('Y-m-d');
    }

    private function stamp(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
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

    private function require(Authorizer $az, string $perm, int $le): void
    {
        if (!$this->can($az, $perm, $le)) {
            throw HttpException::forbidden();
        }
    }

    /** The company whose knowledge base this person reads: their employer today, else the first company they write for. */
    public function readerCompany(Authorizer $az): ?int
    {
        $me = EmployeeService::of($this->app)->forUser($az->userId);
        if ($me !== null && $me['status'] !== 'former' && $me['job'] !== null && $this->can($az, 'kb.read', (int) $me['job']['legal_entity_id'])) {
            return (int) $me['job']['legal_entity_id'];
        }
        return $this->companies($az, 'kb.author')[0] ?? null;
    }

    /** @return list<int> */
    private function staff(int $le, string $perm): array
    {
        $out = [];
        foreach ($this->app->db()->all("SELECT DISTINCT a.user_id FROM user_role_assignments a JOIN users u ON u.id = a.user_id JOIN role_permissions p ON p.role_id = a.role_id WHERE a.status = 'active' AND u.status = 'active' AND p.permission_code = ?", [$perm]) as $r) {
            $az = new Authorizer($this->app->db(), $this->app->clock, (int) $r['user_id']);
            if ($az->scope($perm)->coversLegalEntity($le)) {
                $out[] = (int) $r['user_id'];
            }
        }
        return $out;
    }

    /**
     * Safe HTML of an answer: text is escaped first; blank lines make paragraphs, lines starting "- " make a list, and
     * http(s) addresses become links.
     */
    public static function render(string $text): string
    {
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $link = static fn (string $s): string => (string) preg_replace_callback('~https?://[^\s<>"\']+~u', static function (array $m): string {
            $u = rtrim($m[0], '.,;:)');
            $rest = substr($m[0], strlen($u));
            return '<a href="' . $u . '" rel="noopener noreferrer" target="_blank">' . $u . '</a>' . $rest;
        }, $s);
        $out = '';
        foreach (preg_split('/\R{2,}/u', trim($text)) ?: [] as $block) {
            $lines = preg_split('/\R/u', $block) ?: [];
            $isList = $lines !== [] && count(array_filter($lines, static fn (string $l): bool => str_starts_with(ltrim($l), '- '))) === count($lines);
            if ($isList) {
                $out .= '<ul>' . implode('', array_map(static fn (string $l): string => '<li>' . $link($esc(substr(ltrim($l), 2))) . '</li>', $lines)) . '</ul>';
            } else {
                $out .= '<p>' . implode('<br>', array_map(static fn (string $l): string => $link($esc($l)), $lines)) . '</p>';
            }
        }
        return $out;
    }

    // ---- categories and synonyms ----------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function categories(int $le, bool $activeOnly = false): array
    {
        return $this->app->db()->all(
            'SELECT c.*, (SELECT COUNT(*) FROM kb_articles a WHERE a.category_id = c.id AND a.status = \'published\') AS published FROM kb_categories c WHERE c.legal_entity_id = ?' . ($activeOnly ? ' AND c.active = 1' : '') . ' ORDER BY c.active DESC, c.sort, c.name_th',
            [$le]
        );
    }

    /** @param array<string, mixed> $in name_th, name_en, sort, active */
    public function saveCategory(Authorizer $az, int $le, array $in, ?string $pid = null): string
    {
        $this->require($az, 'kb.review', $le);
        $db = $this->app->db();
        $th = trim((string) ($in['name_th'] ?? ''));
        $en = trim((string) ($in['name_en'] ?? ''));
        $sort = trim((string) ($in['sort'] ?? '0'));
        $e = [];
        if (mb_strlen($th) < 2 || mb_strlen($th) > 120) {
            $e['name_th'] = 'kb.error.name';
        } elseif ($db->scalar('SELECT id FROM kb_categories WHERE legal_entity_id = ? AND name_th = ? AND public_id <> ?', [$le, $th, $pid ?? '']) !== null) {
            $e['name_th'] = 'kb.error.name_taken';
        }
        if (mb_strlen($en) > 120) {
            $e['name_en'] = 'kb.error.name';
        }
        if (preg_match('/^\d{1,3}$/', $sort === '' ? '0' : $sort) !== 1) {
            $e['sort'] = 'kb.error.sort';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $now = $this->stamp();
        if ($pid === null) {
            $pid = Ulid::generate($this->app->clock);
            $db->run('INSERT INTO kb_categories (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, name_th, name_en, sort) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$pid, $now, $az->userId, $now, $az->userId, $le, $th, $en ?: null, (int) $sort]);
        } else {
            if ($db->scalar('SELECT id FROM kb_categories WHERE public_id = ? AND legal_entity_id = ?', [$pid, $le]) === null) {
                throw HttpException::notFound();
            }
            $db->run('UPDATE kb_categories SET name_th = ?, name_en = ?, sort = ?, active = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE public_id = ?', [$th, $en ?: null, (int) $sort, !empty($in['active']) ? 1 : 0, $now, $az->userId, $pid]);
        }
        $this->app->audit()->record('kb.category_saved', 'success', 'kb_category', $pid, $le);
        return $pid;
    }

    public function synonyms(int $le): string
    {
        return (string) ($this->app->db()->scalar('SELECT groups_text FROM kb_synonyms WHERE legal_entity_id = ?', [$le]) ?? '');
    }

    public function saveSynonyms(Authorizer $az, int $le, string $text): void
    {
        $this->require($az, 'kb.review', $le);
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $l) {
            $words = array_values(array_unique(array_filter(array_map(static fn (string $w): string => mb_strtolower(trim($w)), explode(',', $l)), static fn (string $w): bool => $w !== '')));
            if (count($words) >= 2) {
                $lines[] = implode(', ', $words);
            }
        }
        $clean = implode("\n", $lines);
        if (mb_strlen($clean) > 5000) {
            throw new ValidationException(['synonyms' => 'kb.error.too_long']);
        }
        $this->app->db()->run('INSERT INTO kb_synonyms (legal_entity_id, groups_text, updated_at, updated_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE groups_text = VALUES(groups_text), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)', [$le, $clean, $this->stamp(), $az->userId]);
        $this->app->audit()->record('kb.synonyms_saved', 'success', 'legal_entity', $le, $le);
    }

    /** @return list<string> the words of a query plus their synonyms (lower case) */
    public function expand(int $le, string $q): array
    {
        $words = array_values(array_filter(array_map(static fn (string $w): string => mb_strtolower($w), preg_split('/[\s,]+/u', trim($q)) ?: []), static fn (string $w): bool => mb_strlen($w) >= 2));
        $words = array_slice($words, 0, 8);
        $out = $words;
        foreach (preg_split('/\R/u', $this->synonyms($le)) ?: [] as $line) {
            $group = array_map('trim', explode(',', $line));
            foreach ($words as $w) {
                if (in_array($w, $group, true)) {
                    $out = array_merge($out, $group);
                }
            }
        }
        return array_values(array_unique(array_filter($out, static fn (string $w): bool => $w !== '')));
    }

    // ---- reading -----------------------------------------------------------------------------------------------------------------

    /**
     * Published articles of the company: search (words + synonyms, best match first) or a category, else all by title.
     *
     * @return list<array<string, mixed>>
     */
    public function search(int $le, string $q = '', ?string $category = null, int $limit = 100): array
    {
        $db = $this->app->db();
        $sql = "SELECT a.public_id, a.views, a.review_due, r.question_th, r.question_en, r.answer_th, r.tags, c.name_th AS category_th, c.name_en AS category_en, c.public_id AS category_pid
                  FROM kb_articles a JOIN kb_revisions r ON r.id = a.published_rev_id JOIN kb_categories c ON c.id = a.category_id
                 WHERE a.legal_entity_id = ? AND a.status = 'published'";
        $params = [$le];
        if ($category !== null && $category !== '') {
            $sql .= ' AND c.public_id = ?';
            $params[] = $category;
        }
        $words = $this->expand($le, $q);
        if ($q !== '' && $words === []) {
            return [];
        }
        if ($words !== []) {
            $or = [];
            foreach ($words as $w) {
                $like = '%' . addcslashes($w, '%_\\') . '%';
                $or[] = '(r.question_th LIKE ? OR r.question_en LIKE ? OR r.tags LIKE ? OR r.answer_th LIKE ? OR r.answer_en LIKE ?)';
                array_push($params, $like, $like, $like, $like, $like);
            }
            $sql .= ' AND (' . implode(' OR ', $or) . ')';
        }
        $rows = $db->all($sql . ' ORDER BY a.views DESC, r.question_th LIMIT 500', $params);
        if ($words !== []) {
            foreach ($rows as &$r) {
                $score = 0;
                foreach ($words as $w) {
                    $score += (mb_stripos((string) $r['question_th'] . ' ' . ($r['question_en'] ?? ''), $w) !== false ? 3 : 0) + (mb_stripos((string) ($r['tags'] ?? ''), $w) !== false ? 2 : 0) + (mb_stripos((string) $r['answer_th'], $w) !== false ? 1 : 0);
                }
                $r['score'] = $score;
            }
            unset($r);
            usort($rows, static fn (array $a, array $b): int => [$b['score'], $b['views']] <=> [$a['score'], $a['views']]);
        }
        return array_slice($rows, 0, $limit);
    }

    /** @return list<array<string, mixed>> */
    public function popular(int $le, int $n = 5): array
    {
        return array_slice($this->search($le), 0, $n);
    }

    /** @return array<string, mixed> */
    private function articleRow(string $pid): array
    {
        return $this->app->db()->one('SELECT * FROM kb_articles WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
    }

    /**
     * An article for a reader: the published revision only (authors of the company also see archived ones). Counts one view
     * per person per day and remembers who read it.
     *
     * @return array<string, mixed>
     */
    public function read(Authorizer $az, string $pid): array
    {
        $a = $this->articleRow($pid);
        $le = (int) $a['legal_entity_id'];
        $author = $this->can($az, 'kb.author', $le);
        if (!$this->can($az, 'kb.read', $le) || $a['published_rev_id'] === null || ($a['status'] !== 'published' && !$author) || ($this->readerCompany($az) !== $le && !$author)) {
            throw HttpException::notFound();
        }
        $db = $this->app->db();
        $today = $this->today();
        $seen = $db->scalar('SELECT last_viewed FROM kb_viewers WHERE article_id = ? AND user_id = ?', [$a['id'], $az->userId]);
        if ($seen !== $today && $a['status'] === 'published') {
            $db->run('INSERT INTO kb_viewers (article_id, user_id, last_viewed) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE last_viewed = VALUES(last_viewed)', [$a['id'], $az->userId, $today]);
            $db->run('UPDATE kb_articles SET views = views + 1 WHERE id = ?', [$a['id']]);
            $a['views'] = (int) $a['views'] + 1;
        }
        $a['rev'] = $db->one('SELECT * FROM kb_revisions WHERE id = ?', [$a['published_rev_id']]);
        $a['category'] = $db->one('SELECT name_th, name_en, public_id FROM kb_categories WHERE id = ?', [$a['category_id']]);
        $a['files'] = $db->all('SELECT id, filename FROM kb_files WHERE article_id = ? ORDER BY id', [$a['id']]);
        $a['owner_name'] = $db->scalar('SELECT display_name FROM users WHERE id = ?', [$a['owner_id']]);
        $a['mine'] = $db->one('SELECT helpful, comment FROM kb_feedback WHERE article_id = ? AND user_id = ?', [$a['id'], $az->userId]);
        $a['helpful_yes'] = (int) $db->scalar('SELECT COUNT(*) FROM kb_feedback WHERE article_id = ? AND helpful = 1', [$a['id']]);
        $a['helpful_no'] = (int) $db->scalar('SELECT COUNT(*) FROM kb_feedback WHERE article_id = ? AND helpful = 0', [$a['id']]);
        $a['review_overdue'] = $a['status'] === 'published' && $a['review_due'] !== null && (string) $a['review_due'] < $today;
        $a['can_author'] = $author;
        return $a;
    }

    /** "Was this helpful?" — one answer per person, changeable; a "no" can say what is missing. */
    public function feedback(Authorizer $az, string $pid, bool $helpful, string $comment): void
    {
        $a = $this->read($az, $pid);
        $comment = trim($comment);
        if (mb_strlen($comment) > 500) {
            throw new ValidationException(['comment' => 'kb.error.too_long']);
        }
        $this->app->db()->run(
            'INSERT INTO kb_feedback (article_id, user_id, helpful, comment, updated_at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE helpful = VALUES(helpful), comment = VALUES(comment), updated_at = VALUES(updated_at)',
            [$a['id'], $az->userId, $helpful ? 1 : 0, $comment !== '' && !$helpful ? $comment : null, $this->stamp()]
        );
    }

    /** @return array{bytes: string, mime: string, filename: string} */
    public function file(Authorizer $az, string $pid, int $fileId): array
    {
        $a = $this->articleRow($pid);
        $le = (int) $a['legal_entity_id'];
        if (!$this->can($az, 'kb.author', $le)) {
            $this->read($az, $pid);
        }
        $f = $this->app->db()->one('SELECT * FROM kb_files WHERE id = ? AND article_id = ?', [$fileId, $a['id']]) ?? throw HttpException::notFound();
        $doc = $this->app->documents()->moduleRead((string) $f['document_pid'], 'kb', $az->userId, 'knowledge base file');
        return ['bytes' => $doc['bytes'], 'mime' => $doc['mime'], 'filename' => (string) $f['filename']];
    }

    // ---- questions from employees ----------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $in text, category */
    public function ask(Authorizer $az, array $in): string
    {
        $le = $this->readerCompany($az);
        if ($le === null) {
            throw HttpException::forbidden();
        }
        $this->require($az, 'kb.read', $le);
        $db = $this->app->db();
        $text = trim((string) ($in['text'] ?? ''));
        $e = [];
        if (mb_strlen($text) < 10 || mb_strlen($text) > 1000) {
            $e['text'] = 'kb.error.question_text';
        }
        $cat = null;
        if (($in['category'] ?? '') !== '') {
            $cat = $db->scalar('SELECT id FROM kb_categories WHERE public_id = ? AND legal_entity_id = ? AND active = 1', [(string) $in['category'], $le]);
            if ($cat === null) {
                $e['category'] = 'kb.error.choice';
            }
        }
        if ((int) $db->scalar("SELECT COUNT(*) FROM kb_questions WHERE asker_id = ? AND status = 'open'", [$az->userId]) >= 10) {
            $e['text'] = 'kb.error.too_many_open';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $pid = Ulid::generate($this->app->clock);
        $now = $this->stamp();
        $db->run('INSERT INTO kb_questions (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, asker_id, category_id, text) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$pid, $now, $az->userId, $now, $az->userId, $le, $az->userId, $cat, $text]);
        foreach ($this->staff($le, 'kb.author') as $u) {
            $this->app->notifier()->notify($u, 'kb.question', 'kb.notify.question', [], '/kb/manage?view=questions', 'kb.q:' . $pid . ':' . $u);
        }
        return $pid;
    }

    /** @return list<array<string, mixed>> */
    public function myQuestions(Authorizer $az): array
    {
        return $this->app->db()->all('SELECT q.public_id, q.text, q.status, q.reply, q.created_at, a.public_id AS article_pid, a.status AS article_status FROM kb_questions q LEFT JOIN kb_articles a ON a.id = q.article_id WHERE q.asker_id = ? ORDER BY q.id DESC LIMIT 50', [$az->userId]);
    }

    /** @return list<array<string, mixed>> */
    public function questions(Authorizer $az, int $le, bool $openOnly = true): array
    {
        $this->require($az, 'kb.author', $le);
        return $this->app->db()->all(
            'SELECT q.*, u.display_name AS asker_name, c.name_th AS category_th, a.public_id AS article_pid FROM kb_questions q JOIN users u ON u.id = q.asker_id LEFT JOIN kb_categories c ON c.id = q.category_id LEFT JOIN kb_articles a ON a.id = q.article_id
              WHERE q.legal_entity_id = ?' . ($openOnly ? " AND q.status = 'open'" : '') . ' ORDER BY q.id DESC LIMIT 200',
            [$le]
        );
    }

    /** @return array<string, mixed> */
    public function question(Authorizer $az, string $pid): array
    {
        $q = $this->app->db()->one('SELECT * FROM kb_questions WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $this->require($az, 'kb.author', (int) $q['legal_entity_id']);
        return $q;
    }

    public function decline(Authorizer $az, string $pid, string $reply): void
    {
        $q = $this->question($az, $pid);
        if ($q['status'] !== 'open') {
            throw new BusinessException('kb.error.not_open', [], 409);
        }
        $reply = trim($reply);
        if (mb_strlen($reply) < 5 || mb_strlen($reply) > 1000) {
            throw new ValidationException(['reply' => 'kb.error.reply']);
        }
        $this->app->db()->run("UPDATE kb_questions SET status = 'declined', reply = ?, handler_id = ?, updated_at = ?, updated_by = ? WHERE id = ?", [$reply, $az->userId, $this->stamp(), $az->userId, $q['id']]);
        $this->app->notifier()->notify((int) $q['asker_id'], 'kb.question', 'kb.notify.declined', [], '/kb#my-questions', 'kb.qd:' . $pid);
    }

    // ---- authoring ----------------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $in category, question_th, answer_th, question_en, answer_en, tags, source, change_reason
     * @return array<string, mixed>
     */
    private function validate(int $le, array $in): array
    {
        $db = $this->app->db();
        $e = [];
        $v = [];
        foreach (['question_th' => 300, 'question_en' => 300, 'tags' => 300, 'source' => 500, 'change_reason' => 500] as $k => $max) {
            $v[$k] = trim((string) ($in[$k] ?? ''));
            if (mb_strlen($v[$k]) > $max) {
                $e[$k] = 'kb.error.too_long';
            }
        }
        foreach (['answer_th', 'answer_en'] as $k) {
            $v[$k] = trim(str_replace("\r\n", "\n", (string) ($in[$k] ?? '')));
            if (mb_strlen($v[$k]) > 20000) {
                $e[$k] = 'kb.error.too_long';
            }
        }
        if (mb_strlen($v['question_th']) < 5) {
            $e['question_th'] = 'kb.error.question';
        }
        if (mb_strlen($v['answer_th']) < 10) {
            $e['answer_th'] = 'kb.error.answer';
        }
        if (($v['question_en'] === '') !== ($v['answer_en'] === '')) {
            $e['question_en'] = 'kb.error.english_pair';
        }
        $cat = $db->scalar('SELECT id FROM kb_categories WHERE public_id = ? AND legal_entity_id = ? AND active = 1', [(string) ($in['category'] ?? ''), $le]);
        if ($cat === null) {
            $e['category'] = 'kb.error.choice';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $v['category_id'] = (int) $cat;
        $v['tags'] = implode(', ', array_values(array_unique(array_filter(array_map(static fn (string $t): string => mb_strtolower(trim($t)), explode(',', $v['tags'])), static fn (string $t): bool => $t !== ''))));
        return $v;
    }

    /**
     * New article (first revision as a draft); a question being answered can be linked to it.
     *
     * @param array<string, mixed> $in
     */
    public function create(Authorizer $az, int $le, array $in, ?string $questionPid = null): string
    {
        $this->require($az, 'kb.author', $le);
        $v = $this->validate($le, $in);
        $db = $this->app->db();
        $pid = Ulid::generate($this->app->clock);
        $now = $this->stamp();
        $db->transactional(function () use ($db, $pid, $now, $az, $le, $v, $questionPid): void {
            $db->run('INSERT INTO kb_articles (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, category_id, owner_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$pid, $now, $az->userId, $now, $az->userId, $le, $v['category_id'], $az->userId]);
            $aid = (int) $db->pdo()->lastInsertId();
            $this->insertRevision($aid, 1, $v, $az->userId);
            if ($questionPid !== null) {
                $q = $this->question($az, $questionPid);
                if ($q['status'] !== 'open' || (int) $q['legal_entity_id'] !== $le) {
                    throw new BusinessException('kb.error.not_open', [], 409);
                }
                $db->run('UPDATE kb_questions SET article_id = ?, handler_id = ?, updated_at = ? WHERE id = ?', [$aid, $az->userId, $now, $q['id']]);
            }
        });
        $this->app->audit()->record('kb.article_created', 'success', 'kb_article', $pid, $le);
        return $pid;
    }

    /** @param array<string, mixed> $v */
    private function insertRevision(int $articleId, int $version, array $v, int $author): void
    {
        $this->app->db()->run(
            'INSERT INTO kb_revisions (article_id, version, question_th, answer_th, question_en, answer_en, tags, source, change_reason, author_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$articleId, $version, $v['question_th'], $v['answer_th'], $v['question_en'] ?: null, $v['answer_en'] ?: null, $v['tags'] ?: null, $v['source'] ?: null, $v['change_reason'] ?: null, $author, $this->stamp()]
        );
    }

    /**
     * The article for its authors and reviewers: every revision, the working one, files, feedback, linked questions.
     *
     * @return array<string, mixed>
     */
    public function manage(Authorizer $az, string $pid): array
    {
        $a = $this->articleRow($pid);
        $le = (int) $a['legal_entity_id'];
        $this->require($az, 'kb.author', $le);
        $db = $this->app->db();
        $a['revisions'] = $db->all('SELECT r.*, u.display_name AS author_name, v.display_name AS reviewer_name FROM kb_revisions r JOIN users u ON u.id = r.author_id LEFT JOIN users v ON v.id = r.reviewer_id WHERE r.article_id = ? ORDER BY r.version DESC', [$a['id']]);
        $a['working'] = null;
        foreach ($a['revisions'] as $r) {
            if (in_array($r['status'], ['draft', 'review', 'returned'], true)) {
                $a['working'] = $r;
                break;
            }
        }
        $a['published'] = null;
        foreach ($a['revisions'] as $r) {
            if ((int) $r['id'] === (int) $a['published_rev_id']) {
                $a['published'] = $r;
            }
        }
        $a['category'] = $db->one('SELECT name_th, public_id FROM kb_categories WHERE id = ?', [$a['category_id']]);
        $a['owner_name'] = $db->scalar('SELECT display_name FROM users WHERE id = ?', [$a['owner_id']]);
        $a['files'] = $db->all('SELECT id, filename, added_at FROM kb_files WHERE article_id = ? ORDER BY id', [$a['id']]);
        $a['feedback'] = $db->all('SELECT helpful, comment, updated_at FROM kb_feedback WHERE article_id = ? ORDER BY updated_at DESC LIMIT 50', [$a['id']]);
        $a['viewers'] = (int) $db->scalar('SELECT COUNT(*) FROM kb_viewers WHERE article_id = ?', [$a['id']]);
        $a['linked_questions'] = $db->all("SELECT q.text, q.status, u.display_name AS asker_name FROM kb_questions q JOIN users u ON u.id = q.asker_id WHERE q.article_id = ?", [$a['id']]);
        $a['can_review'] = $this->can($az, 'kb.review', $le);
        return $a;
    }

    /**
     * Edits the working revision (draft / returned) or starts a new one from the published text.
     *
     * @param array<string, mixed> $in
     */
    public function edit(Authorizer $az, string $pid, array $in): void
    {
        $a = $this->manage($az, $pid);
        if ($a['status'] === 'archived') {
            throw new BusinessException('kb.error.archived', [], 409);
        }
        $v = $this->validate((int) $a['legal_entity_id'], $in);
        $db = $this->app->db();
        $w = $a['working'];
        if ($w !== null && $w['status'] === 'review') {
            throw new BusinessException('kb.error.in_review', [], 409);
        }
        if ($a['published'] !== null && $w === null && mb_strlen($v['change_reason']) < 5) {
            throw new ValidationException(['change_reason' => 'kb.error.change_reason']);
        }
        $db->transactional(function () use ($db, $a, $w, $v, $az): void {
            if ($w === null) {
                $this->insertRevision((int) $a['id'], (int) $a['revisions'][0]['version'] + 1, $v, $az->userId);
            } else {
                $db->run("UPDATE kb_revisions SET question_th = ?, answer_th = ?, question_en = ?, answer_en = ?, tags = ?, source = ?, change_reason = ?, status = 'draft', author_id = ? WHERE id = ?", [$v['question_th'], $v['answer_th'], $v['question_en'] ?: null, $v['answer_en'] ?: null, $v['tags'] ?: null, $v['source'] ?: null, $v['change_reason'] ?: null, $az->userId, $w['id']]);
            }
            $db->run('UPDATE kb_articles SET category_id = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [$v['category_id'], $this->stamp(), $az->userId, $a['id']]);
        });
    }

    /** Sends the working revision for review with the date the content must next be checked. */
    public function submit(Authorizer $az, string $pid, string $reviewDue): void
    {
        $a = $this->manage($az, $pid);
        $w = $a['working'];
        if ($w === null || !in_array($w['status'], ['draft', 'returned'], true)) {
            throw new BusinessException('kb.error.nothing_to_send', [], 409);
        }
        $due = trim($reviewDue);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) !== 1 || DateTimeImmutable::createFromFormat('!Y-m-d', $due) === false || $due <= $this->today() || (DateTimeImmutable::createFromFormat('!Y-m-d', $this->today()) ?: throw new \RuntimeException('date'))->modify('+3 years')->format('Y-m-d') < $due) {
            throw new ValidationException(['review_due' => 'kb.error.review_due']);
        }
        $this->app->db()->run("UPDATE kb_revisions SET status = 'review', review_due = ?, submitted_at = ? WHERE id = ?", [$due, $this->stamp(), $w['id']]);
        foreach ($this->staff((int) $a['legal_entity_id'], 'kb.review') as $u) {
            if ($u !== (int) $w['author_id']) {
                $this->app->notifier()->notify($u, 'kb.review', 'kb.notify.review', ['q' => mb_substr((string) $w['question_th'], 0, 80)], '/kb/manage/a/' . $pid, 'kb.rv:' . $w['id'] . ':' . $u);
            }
        }
        $this->app->audit()->record('kb.article_submitted', 'success', 'kb_article', $pid, (int) $a['legal_entity_id']);
    }

    /** A reviewer who did not write it publishes the revision (the previous one is superseded) or returns it with a note. */
    public function decide(Authorizer $az, string $pid, bool $approve, string $note): void
    {
        $a = $this->manage($az, $pid);
        $le = (int) $a['legal_entity_id'];
        $this->require($az, 'kb.review', $le);
        $w = $a['working'];
        if ($w === null || $w['status'] !== 'review') {
            throw new BusinessException('kb.error.nothing_to_review', [], 409);
        }
        if ((int) $w['author_id'] === $az->userId) {
            throw new BusinessException('kb.error.own_revision', [], 409);
        }
        $note = trim($note);
        if (!$approve && mb_strlen($note) < 5) {
            throw new ValidationException(['note' => 'kb.error.reason']);
        }
        $db = $this->app->db();
        $now = $this->stamp();
        $db->transactional(function () use ($db, $a, $w, $approve, $note, $az, $now): void {
            if ($approve) {
                $db->run("UPDATE kb_revisions SET status = 'superseded' WHERE article_id = ? AND status = 'published'", [$a['id']]);
                $db->run("UPDATE kb_revisions SET status = 'published', reviewer_id = ?, decided_at = ?, review_note = ? WHERE id = ?", [$az->userId, $now, $note ?: null, $w['id']]);
                $db->run("UPDATE kb_articles SET status = 'published', published_rev_id = ?, review_due = ?, updated_at = ?, updated_by = ? WHERE id = ?", [$w['id'], $w['review_due'], $now, $az->userId, $a['id']]);
            } else {
                $db->run("UPDATE kb_revisions SET status = 'returned', reviewer_id = ?, decided_at = ?, review_note = ? WHERE id = ?", [$az->userId, $now, $note, $w['id']]);
            }
        });
        $this->app->notifier()->notify((int) $w['author_id'], 'kb.review', $approve ? 'kb.notify.published' : 'kb.notify.returned', ['q' => mb_substr((string) $w['question_th'], 0, 80)], '/kb/manage/a/' . $pid, 'kb.dc:' . $w['id']);
        if ($approve) {
            foreach ($db->all("SELECT id, public_id, asker_id FROM kb_questions WHERE article_id = ? AND status = 'open'", [$a['id']]) as $q) {
                $db->run("UPDATE kb_questions SET status = 'answered', updated_at = ? WHERE id = ?", [$now, $q['id']]);
                $this->app->notifier()->notify((int) $q['asker_id'], 'kb.question', 'kb.notify.answered', [], '/kb/a/' . $pid, 'kb.qa:' . $q['public_id']);
            }
        }
        $this->app->audit()->record($approve ? 'kb.article_published' : 'kb.article_returned', 'success', 'kb_article', $pid, $le, $note ?: null);
    }

    /** Withdraws an article (reason); optionally tells everyone who read it. Its revisions are kept. */
    public function withdraw(Authorizer $az, string $pid, string $reason, bool $tellReaders): int
    {
        $a = $this->manage($az, $pid);
        $le = (int) $a['legal_entity_id'];
        $this->require($az, 'kb.review', $le);
        if ($a['status'] === 'archived') {
            throw new BusinessException('kb.error.archived', [], 409);
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new ValidationException(['reason' => 'kb.error.reason']);
        }
        $db = $this->app->db();
        $now = $this->stamp();
        $db->run("UPDATE kb_articles SET status = 'archived', archived_reason = ?, updated_at = ?, updated_by = ? WHERE id = ?", [$reason, $now, $az->userId, $a['id']]);
        $db->run("UPDATE kb_revisions SET status = 'withdrawn' WHERE article_id = ? AND status IN ('published','draft','review','returned')", [$a['id']]);
        $told = 0;
        if ($tellReaders && $a['published'] !== null) {
            foreach ($db->all('SELECT user_id FROM kb_viewers WHERE article_id = ?', [$a['id']]) as $v) {
                $this->app->notifier()->notify((int) $v['user_id'], 'kb.withdrawn', 'kb.notify.withdrawn', ['q' => mb_substr((string) $a['published']['question_th'], 0, 80), 'reason' => $reason], '/kb', 'kb.wd:' . $pid . ':' . $v['user_id']);
                $told++;
            }
        }
        $this->app->audit()->record('kb.article_withdrawn', 'success', 'kb_article', $pid, $le, $reason, ['told' => $told]);
        return $told;
    }

    public function changeOwner(Authorizer $az, string $pid, string $who): void
    {
        $a = $this->manage($az, $pid);
        $le = (int) $a['legal_entity_id'];
        $this->require($az, 'kb.review', $le);
        $id = $this->app->db()->scalar("SELECT u.id FROM users u LEFT JOIN employees e ON e.id = u.employee_id WHERE u.status = 'active' AND (u.username = ? OR e.employee_code = ?) LIMIT 1", [trim($who), trim($who)]);
        if ($id === null || !(new Authorizer($this->app->db(), $this->app->clock, (int) $id))->scope('kb.author')->coversLegalEntity($le)) {
            throw new ValidationException(['owner' => 'kb.error.owner']);
        }
        $this->app->db()->run('UPDATE kb_articles SET owner_id = ?, updated_at = ?, updated_by = ? WHERE id = ?', [$id, $this->stamp(), $az->userId, $a['id']]);
        $this->app->audit()->record('kb.owner_changed', 'success', 'kb_article', $pid, $le);
    }

    public function attach(Authorizer $az, string $pid, ?UploadedFile $f): void
    {
        $a = $this->manage($az, $pid);
        if ($f === null || !$f->ok()) {
            throw new ValidationException(['file' => $f?->errorKey() ?? 'kb.error.file']);
        }
        if (count($a['files']) >= 10) {
            throw new ValidationException(['file' => 'kb.error.too_many_files']);
        }
        try {
            $stored = $this->app->documents()->storeGenerated($az->userId, 'kb.file', $f->bytes(), $f->safeName(), ['title' => 'Knowledge base file', 'legal_entity_id' => (int) $a['legal_entity_id']], 'upload', true);
        } catch (ValidationException $x) {
            throw new ValidationException(['file' => (string) (array_values($x->errors)[0] ?? 'kb.error.file')]);
        }
        $this->app->db()->run('INSERT INTO kb_files (article_id, document_pid, filename, added_by, added_at) VALUES (?, ?, ?, ?, ?)', [$a['id'], $stored['document'], mb_substr($f->safeName(), 0, 200), $az->userId, $this->stamp()]);
    }

    /**
     * Articles for authors: filter by state.
     *
     * @param array<string, string> $f status (draft|review|published|due|archived), q
     * @return list<array<string, mixed>>
     */
    public function adminList(Authorizer $az, int $le, array $f = []): array
    {
        $this->require($az, 'kb.author', $le);
        $rows = $this->app->db()->all(
            "SELECT a.public_id, a.status, a.review_due, a.views, u.display_name AS owner_name, c.name_th AS category_th,
                    COALESCE(p.question_th, w.question_th) AS question_th, w.status AS working_status,
                    (SELECT COUNT(*) FROM kb_feedback f WHERE f.article_id = a.id AND f.helpful = 0) AS not_helpful
               FROM kb_articles a JOIN users u ON u.id = a.owner_id JOIN kb_categories c ON c.id = a.category_id
               LEFT JOIN kb_revisions p ON p.id = a.published_rev_id
               LEFT JOIN kb_revisions w ON w.id = (SELECT MAX(x.id) FROM kb_revisions x WHERE x.article_id = a.id AND x.status IN ('draft','review','returned'))
              WHERE a.legal_entity_id = ? ORDER BY a.updated_at DESC LIMIT 500",
            [$le]
        );
        $today = $this->today();
        $st = (string) ($f['status'] ?? '');
        $q = mb_strtolower(trim((string) ($f['q'] ?? '')));
        return array_values(array_filter($rows, static function (array $r) use ($st, $today, $q): bool {
            if ($q !== '' && mb_stripos((string) $r['question_th'], $q) === false) {
                return false;
            }
            return match ($st) {
                'draft' => in_array($r['working_status'], ['draft', 'returned'], true),
                'review' => $r['working_status'] === 'review',
                'published' => $r['status'] === 'published',
                'due' => $r['status'] === 'published' && $r['review_due'] !== null && (string) $r['review_due'] < $today,
                'archived' => $r['status'] === 'archived',
                default => true,
            };
        }));
    }

    /** Hourly: published articles past their review date → the owner is reminded once per review date. */
    public function tick(): int
    {
        $n = 0;
        foreach ($this->app->db()->all("SELECT a.public_id, a.owner_id, a.review_due, r.question_th FROM kb_articles a JOIN kb_revisions r ON r.id = a.published_rev_id WHERE a.status = 'published' AND a.review_due < ? LIMIT 500", [$this->today()]) as $a) {
            $this->app->notifier()->notify((int) $a['owner_id'], 'kb.review', 'kb.notify.review_due', ['q' => mb_substr((string) $a['question_th'], 0, 80)], '/kb/manage/a/' . $a['public_id'], 'kb.due:' . $a['public_id'] . ':' . $a['review_due']);
            $n++;
        }
        return $n;
    }

    public static function tickJob(App $app): string
    {
        return 'kb: ' . self::of($app)->tick();
    }
}
