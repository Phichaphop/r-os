<?php

declare(strict_types=1);

namespace Blue\Modules\Library;

use DateTimeImmutable;
use DateTimeZone;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\FileType;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Pdf\PdfError;
use Blue\Core\Pdf\TextExtractor;
use Blue\Core\Support\Ulid;
use Blue\Modules\Employees\Audience;
use Blue\Modules\Employees\EmployeeService;

/**
 * M03 online library (9D-3 · DEC-99).
 *
 * Librarians (library.manage) add items of the company: a digital file (PDF read in the browser; Word/Excel/PowerPoint/
 * pictures downloaded) or a physical book with copies. Each item has category, tags, language, the rights holder and the
 * licence it is used under, an audience and an optional "read online only" flag; draft → published → archived (withdrawn,
 * with a reason). A new file makes a new version — the old one is kept for librarians. Every PDF page's text is indexed
 * (core/Pdf/TextExtractor — no OCR, so scanned pages have none) so readers can search inside documents and jump to the
 * page. Readers keep their own last page and bookmarks; the library only counts views (once a person a day) and
 * downloads (audited). Physical books: reserve → the first free copy is held for pickup (or the person waits in the queue)
 * → lent at the desk with a due date → returned (the copy goes to the next person waiting) / lost. Reminders a day before
 * the due date and once when overdue. There are no fines and no default loan period: the librarian sets both periods
 * on each book.
 */
final class LibraryService
{
    public const MAX_TOKENS = 5;
    private const KINDS = ['digital', 'physical'];
    private const LANGS = ['th', 'en', 'other'];

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    private function tz(): DateTimeZone
    {
        return new DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'));
    }

    public function today(): string
    {
        return $this->app->clock->now()->setTimezone($this->tz())->format('Y-m-d');
    }

    private function addDays(string $date, int $days): string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $this->tz());
        return $d === false ? $date : $d->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    private function stamp(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    public function emp(): EmployeeService
    {
        return EmployeeService::of($this->app);
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

    private function requireManage(Authorizer $az, int $le): void
    {
        if (!$this->can($az, 'library.manage', $le)) {
            throw HttpException::forbidden();
        }
    }

    /** @return array<string, mixed>|null my employee with today's job */
    public function me(Authorizer $az): ?array
    {
        $me = $this->emp()->forUser($az->userId);
        return $me === null || $me['status'] === 'former' || $me['job'] === null ? null : $me;
    }

    /** The library this person reads: their employer's, else the first company they look after. */
    public function readerCompany(Authorizer $az): ?int
    {
        $me = $this->me($az);
        if ($me !== null && $this->can($az, 'library.read', (int) $me['job']['legal_entity_id'])) {
            return (int) $me['job']['legal_entity_id'];
        }
        return $this->companies($az, 'library.manage')[0] ?? null;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed>|null $me
     */
    private function readable(Authorizer $az, array $item, ?array $me = null): bool
    {
        $le = (int) $item['legal_entity_id'];
        if ($this->can($az, 'library.manage', $le)) {
            return true;
        }
        if ($item['status'] !== 'published') {
            return false;
        }
        $me ??= $this->me($az);
        return $me !== null && (int) $me['job']['legal_entity_id'] === $le && $this->can($az, 'library.read', $le)
            && Audience::matches($this->app, $le, Audience::decode((string) $item['audience_json']), $me['job']);
    }

    /** @return array<string, mixed> */
    private function row(string $pid): array
    {
        return $this->app->db()->one('SELECT * FROM lb_items WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
    }

    /** @return array<string, mixed> item the person may read (404 otherwise — a withdrawn item is gone for readers) */
    private function readableRow(Authorizer $az, string $pid): array
    {
        $item = $this->row($pid);
        if (!$this->readable($az, $item)) {
            throw HttpException::notFound();
        }
        return $item;
    }

    /** @return array<string, mixed>|null */
    private function currentFile(int $itemId): ?array
    {
        return $this->app->db()->one('SELECT * FROM lb_files WHERE item_id = ? AND is_current = 1', [$itemId]);
    }

    public static function like(string $s): string
    {
        return '%' . addcslashes($s, '%_\\') . '%';
    }

    /** Text squeezed for matching: lower case, no whitespace (Thai PDFs often break words with spaces). */
    public static function squeeze(string $s): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', '', $s));
    }

    // ---- categories ------------------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function categories(int $le, bool $activeOnly = false): array
    {
        return $this->app->db()->all(
            "SELECT c.*, (SELECT COUNT(*) FROM lb_items i WHERE i.category_id = c.id AND i.status = 'published') AS items FROM lb_categories c WHERE c.legal_entity_id = ?" . ($activeOnly ? ' AND c.active = 1' : '') . ' ORDER BY c.active DESC, c.sort, c.name',
            [$le]
        );
    }

    /** @param array<string, mixed> $in name, sort, active */
    public function saveCategory(Authorizer $az, int $le, array $in, ?string $pid = null): string
    {
        $this->requireManage($az, $le);
        $db = $this->app->db();
        $name = trim((string) ($in['name'] ?? ''));
        $sort = trim((string) ($in['sort'] ?? '0'));
        $e = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $e['name'] = 'library.error.name';
        } elseif ($db->scalar('SELECT id FROM lb_categories WHERE legal_entity_id = ? AND name = ? AND public_id <> ?', [$le, $name, $pid ?? '']) !== null) {
            $e['name'] = 'library.error.duplicate_name';
        }
        if (preg_match('/^-?\d{1,4}$/', $sort) !== 1) {
            $e['sort'] = 'library.error.number';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $now = $this->stamp();
        if ($pid === null) {
            $pid = Ulid::generate($this->app->clock);
            $db->run('INSERT INTO lb_categories (public_id, legal_entity_id, name, sort, active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?)', [$pid, $le, $name, (int) $sort, $now, $now]);
        } else {
            $n = $db->run('UPDATE lb_categories SET name = ?, sort = ?, active = ?, updated_at = ? WHERE public_id = ? AND legal_entity_id = ?', [$name, (int) $sort, !empty($in['active']) ? 1 : 0, $now, $pid, $le])->rowCount();
            if ($n === 0 && $db->scalar('SELECT id FROM lb_categories WHERE public_id = ? AND legal_entity_id = ?', [$pid, $le]) === null) {
                throw HttpException::notFound();
            }
        }
        $this->app->audit()->record('library.category_saved', 'success', 'lb_category', $pid, $le);
        return $pid;
    }

    // ---- reading ---------------------------------------------------------------------------------------------------------------

    /**
     * Published items this person may read, filtered and sorted. A search word must appear in the title, author,
     * publisher, tags or description — or the whole search in the text of a PDF page (then the pages are listed).
     *
     * @param array<string, string> $f q, category, kind, lang, sort (new|popular|title)
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function search(Authorizer $az, int $le, array $f, int $page = 1, int $per = 20): array
    {
        $db = $this->app->db();
        $where = ["i.legal_entity_id = ?", "i.status = 'published'"];
        $args = [$le];
        if (($f['category'] ?? '') !== '') {
            $where[] = 'c.public_id = ?';
            $args[] = $f['category'];
        }
        if (in_array($f['kind'] ?? '', self::KINDS, true)) {
            $where[] = 'i.kind = ?';
            $args[] = $f['kind'];
        }
        if (in_array($f['lang'] ?? '', self::LANGS, true)) {
            $where[] = 'i.language = ?';
            $args[] = $f['lang'];
        }
        $q = trim(mb_substr((string) ($f['q'] ?? ''), 0, 80));
        $contentIds = [];
        if ($q !== '') {
            $tokens = array_slice(array_values(array_filter(preg_split('/\s+/u', $q) ?: [], static fn (string $x): bool => $x !== '')), 0, self::MAX_TOKENS);
            $meta = [];
            foreach ($tokens as $tk) {
                $meta[] = "CONCAT_WS(' ', i.title, i.author, i.publisher, i.tags, i.description) LIKE ?";
                $args[] = self::like($tk);
            }
            $sq = self::squeeze($q);
            foreach ($db->all("SELECT DISTINCT t.item_id FROM lb_text t JOIN lb_items i ON i.id = t.item_id WHERE i.legal_entity_id = ? AND i.status = 'published' AND t.squeezed LIKE ? LIMIT 500", [$le, self::like($sq)]) as $r) {
                $contentIds[(int) $r['item_id']] = true;
            }
            $where[] = '((' . implode(' AND ', $meta) . ')' . ($contentIds !== [] ? ' OR i.id IN (' . implode(',', array_keys($contentIds)) . ')' : '') . ')';
        }
        $order = match ($f['sort'] ?? '') {
            'popular' => 'i.views + i.downloads DESC, i.published_at DESC',
            'title' => 'i.title, i.id',
            default => 'i.published_at DESC, i.id DESC',
        };
        $rows = $db->all('SELECT i.*, c.name AS category FROM lb_items i LEFT JOIN lb_categories c ON c.id = i.category_id WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $order . ' LIMIT 1000', $args);
        $me = $this->me($az);
        $rows = array_values(array_filter($rows, fn (array $i): bool => $this->readable($az, $i, $me)));
        $total = count($rows);
        $rows = array_slice($rows, max(0, ($page - 1) * $per), $per);
        foreach ($rows as &$r) {
            $r['hits'] = [];
            if ($q !== '' && isset($contentIds[(int) $r['id']])) {
                $r['hits'] = $this->hits((int) $r['id'], $q);
            }
            $r['copies'] = $r['kind'] === 'physical' ? $this->copyCounts((int) $r['id']) : null;
        }
        unset($r);
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Up to three pages whose text contains the search, with a short piece of text around it.
     *
     * @return list<array{page: int, snippet: string}>
     */
    public function hits(int $itemId, string $q): array
    {
        $out = [];
        $sq = self::squeeze($q);
        foreach ($this->app->db()->all('SELECT page, body FROM lb_text WHERE item_id = ? AND squeezed LIKE ? ORDER BY page LIMIT 3', [$itemId, self::like($sq)]) as $r) {
            $out[] = ['page' => (int) $r['page'], 'snippet' => self::snippet((string) $r['body'], $q)];
        }
        return $out;
    }

    public static function snippet(string $body, string $q): string
    {
        $flat = (string) preg_replace('/\s+/u', ' ', $body);
        $first = (string) (preg_split('/\s+/u', trim($q))[0] ?? '');
        $pos = $first === '' ? false : mb_stripos($flat, $first);
        if ($pos === false) {
            // the words were split in the PDF: find the first letters of the search instead
            $pos = mb_stripos($flat, mb_substr(self::squeeze($q), 0, 2));
        }
        $start = max(0, (int) $pos - 60);
        $s = mb_substr($flat, $start, 180);
        return ($start > 0 ? '… ' : '') . trim($s) . (mb_strlen($flat) > $start + 180 ? ' …' : '');
    }

    /** @return array{total: int, available: int} */
    private function copyCounts(int $itemId): array
    {
        $r = $this->app->db()->one("SELECT SUM(status <> 'withdrawn' AND status <> 'lost') AS total, SUM(status = 'available') AS available FROM lb_copies WHERE item_id = ?", [$itemId]);
        return ['total' => (int) ($r['total'] ?? 0), 'available' => (int) ($r['available'] ?? 0)];
    }

    /**
     * Items I opened lately ("continue reading"), newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(Authorizer $az, int $limit = 5): array
    {
        $rows = $this->app->db()->all('SELECT i.*, r.last_page, r.pages AS read_pages, r.last_opened_at FROM lb_reads r JOIN lb_items i ON i.id = r.item_id WHERE r.user_id = ? ORDER BY r.last_opened_at DESC LIMIT 20', [$az->userId]);
        $me = $this->me($az);
        $out = [];
        foreach ($rows as $r) {
            $r['gone'] = !$this->readable($az, $r, $me);
            $out[] = $r;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** @return array<string, mixed> one item for a reader */
    public function item(Authorizer $az, string $pid, string $q = ''): array
    {
        $item = $this->readableRow($az, $pid);
        $db = $this->app->db();
        $id = (int) $item['id'];
        $item['category'] = $item['category_id'] === null ? null : $db->scalar('SELECT name FROM lb_categories WHERE id = ?', [$item['category_id']]);
        $item['file'] = $this->currentFile($id);
        $item['read'] = $db->one('SELECT * FROM lb_reads WHERE user_id = ? AND item_id = ?', [$az->userId, $id]);
        $item['bookmarks'] = $db->all('SELECT * FROM lb_bookmarks WHERE user_id = ? AND item_id = ? ORDER BY page', [$az->userId, $id]);
        $item['hits'] = $q !== '' ? $this->hits($id, $q) : [];
        $item['can_manage'] = $this->can($az, 'library.manage', (int) $item['legal_entity_id']);
        $item['can_download'] = $item['file'] !== null && ((int) $item['read_only'] === 0 || $item['can_manage']);
        $item['can_read_online'] = $item['file'] !== null && $item['file']['file_type'] === 'pdf';
        $item['audience_text'] = Audience::describe($this->app, Audience::decode((string) $item['audience_json']));
        $item['today'] = $this->today();
        if ($item['kind'] === 'physical') {
            $item['copies'] = $this->copyCounts($id);
            $me = $this->me($az);
            $item['my_loan'] = $me === null ? null : $db->one("SELECT * FROM lb_loans WHERE item_id = ? AND employee_id = ? AND status IN ('waiting','ready','loaned') ORDER BY id DESC LIMIT 1", [$id, $me['id']]);
            $item['queue_pos'] = null;
            if ($item['my_loan'] !== null && $item['my_loan']['status'] === 'waiting') {
                $item['queue_pos'] = 1 + (int) $db->scalar("SELECT COUNT(*) FROM lb_loans WHERE item_id = ? AND status = 'waiting' AND id < ?", [$id, $item['my_loan']['id']]);
            }
            $item['waiting'] = (int) $db->scalar("SELECT COUNT(*) FROM lb_loans WHERE item_id = ? AND status = 'waiting'", [$id]);
            $item['can_reserve'] = $me !== null && $item['status'] === 'published' && $item['my_loan'] === null && $item['copies']['total'] > 0 && (int) $me['job']['legal_entity_id'] === (int) $item['legal_entity_id'];
        }
        return $item;
    }

    /**
     * The current file for reading online (a view is counted once a person a day) or for download (counted and audited;
     * not for "read online only" items unless you manage the library).
     *
     * @return array{bytes: string, mime: string, filename: string}
     */
    public function file(Authorizer $az, string $pid, bool $download): array
    {
        $item = $this->readableRow($az, $pid);
        $f = $this->currentFile((int) $item['id']) ?? throw HttpException::notFound();
        $manage = $this->can($az, 'library.manage', (int) $item['legal_entity_id']);
        if ($download && (int) $item['read_only'] === 1 && !$manage) {
            throw new BusinessException('library.error.read_only', [], 403);
        }
        if (!$download && $f['file_type'] !== 'pdf') {
            throw HttpException::notFound();
        }
        $doc = $this->app->documents()->moduleRead((string) $f['document_pid'], 'library', $az->userId, $download ? 'library download' : 'library reading', false);
        if ($item['status'] === 'published') {
            $download ? $this->countDownload($az, $item) : $this->countView($az, $item, $f);
        }
        return ['bytes' => $doc['bytes'], 'mime' => (string) $f['mime'], 'filename' => (string) $f['filename']];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $f
     */
    private function countView(Authorizer $az, array $item, array $f): void
    {
        $db = $this->app->db();
        $now = $this->stamp();
        $today = $this->today();
        $last = $db->scalar('SELECT last_opened_at FROM lb_reads WHERE user_id = ? AND item_id = ?', [$az->userId, $item['id']]);
        $lastAt = $last === null ? false : DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', (string) $last, new DateTimeZone('UTC'));
        $lastDay = $lastAt === false ? null : $lastAt->setTimezone($this->tz())->format('Y-m-d');
        $db->run(
            'INSERT INTO lb_reads (user_id, item_id, pages, last_opened_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE last_opened_at = VALUES(last_opened_at), pages = VALUES(pages)',
            [$az->userId, $item['id'], $f['pages'], $now]
        );
        if ($lastDay !== $today) {
            $db->run('UPDATE lb_items SET views = views + 1 WHERE id = ?', [$item['id']]);
            $db->run('INSERT INTO lb_daily (item_id, day, views) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE views = views + 1', [$item['id'], $today]);
        }
    }

    /** @param array<string, mixed> $item */
    private function countDownload(Authorizer $az, array $item): void
    {
        $db = $this->app->db();
        $db->run('UPDATE lb_items SET downloads = downloads + 1 WHERE id = ?', [$item['id']]);
        $db->run('INSERT INTO lb_daily (item_id, day, downloads) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE downloads = downloads + 1', [$item['id'], $this->today()]);
        $this->app->audit()->record('library.downloaded', 'success', 'lb_item', (string) $item['public_id'], (int) $item['legal_entity_id']);
    }

    /** The page I am on (from the reader), so "continue reading" opens there. */
    public function progress(Authorizer $az, string $pid, int $page): void
    {
        $item = $this->readableRow($az, $pid);
        $f = $this->currentFile((int) $item['id']);
        $max = (int) ($f['pages'] ?? 0);
        if ($page < 1 || ($max > 0 && $page > $max)) {
            throw new ValidationException(['page' => 'library.error.page']);
        }
        $this->app->db()->run(
            'INSERT INTO lb_reads (user_id, item_id, last_page, pages, last_opened_at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE last_page = VALUES(last_page), pages = VALUES(pages)',
            [$az->userId, $item['id'], $page, $f['pages'] ?? null, $this->stamp()]
        );
    }

    /** Add (or re-note) a bookmark on a page. */
    public function bookmark(Authorizer $az, string $pid, int $page, string $note): void
    {
        $item = $this->readableRow($az, $pid);
        $f = $this->currentFile((int) $item['id']);
        $max = (int) ($f['pages'] ?? 0);
        $note = trim($note);
        $e = [];
        if ($page < 1 || ($max > 0 && $page > $max)) {
            $e['page'] = 'library.error.page';
        }
        if (mb_strlen($note) > 200) {
            $e['note'] = 'library.error.too_long';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $db = $this->app->db();
        if ((int) $db->scalar('SELECT COUNT(*) FROM lb_bookmarks WHERE user_id = ? AND item_id = ?', [$az->userId, $item['id']]) >= 100) {
            throw new BusinessException('library.error.too_many_bookmarks', [], 409);
        }
        $db->run('INSERT INTO lb_bookmarks (user_id, item_id, page, note, created_at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE note = VALUES(note)', [$az->userId, $item['id'], $page, $note === '' ? null : $note, $this->stamp()]);
    }

    public function unbookmark(Authorizer $az, string $pid, int $bookmarkId): void
    {
        $item = $this->row($pid);
        $this->app->db()->run('DELETE FROM lb_bookmarks WHERE id = ? AND user_id = ? AND item_id = ?', [$bookmarkId, $az->userId, $item['id']]);
    }

    /** Text of one page from the index (for screen readers and copying — the reader draws pages as pictures). */
    public function pageText(Authorizer $az, string $pid, int $page): string
    {
        $item = $this->readableRow($az, $pid);
        return (string) ($this->app->db()->scalar('SELECT body FROM lb_text WHERE item_id = ? AND page = ?', [$item['id'], $page]) ?? '');
    }

    // ---- managing items -------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $f q, status, kind
     * @return list<array<string, mixed>>
     */
    public function manageList(Authorizer $az, int $le, array $f): array
    {
        $this->requireManage($az, $le);
        $where = ['i.legal_entity_id = ?'];
        $args = [$le];
        if (in_array($f['status'] ?? '', ['draft', 'published', 'archived'], true)) {
            $where[] = 'i.status = ?';
            $args[] = $f['status'];
        }
        if (in_array($f['kind'] ?? '', self::KINDS, true)) {
            $where[] = 'i.kind = ?';
            $args[] = $f['kind'];
        }
        if (($f['q'] ?? '') !== '') {
            $where[] = "CONCAT_WS(' ', i.title, i.author, i.tags) LIKE ?";
            $args[] = self::like((string) $f['q']);
        }
        return $this->app->db()->all(
            "SELECT i.*, c.name AS category, f.file_type, f.text_status, f.pages,
                    (SELECT COUNT(*) FROM lb_copies k WHERE k.item_id = i.id AND k.status NOT IN ('withdrawn','lost')) AS copies,
                    (SELECT COUNT(*) FROM lb_loans l WHERE l.item_id = i.id AND l.status IN ('waiting','ready','loaned')) AS active_loans
             FROM lb_items i LEFT JOIN lb_categories c ON c.id = i.category_id LEFT JOIN lb_files f ON f.item_id = i.id AND f.is_current = 1
             WHERE " . implode(' AND ', $where) . ' ORDER BY i.updated_at DESC LIMIT 300',
            $args
        );
    }

    /**
     * Create (draft) or edit an item. The kind cannot change once there is a file or a copy.
     *
     * @param array<string, mixed> $in
     */
    public function save(Authorizer $az, int $le, array $in, ?string $pid = null): string
    {
        $this->requireManage($az, $le);
        $db = $this->app->db();
        $old = $pid === null ? null : $this->row($pid);
        if ($old !== null && (int) $old['legal_entity_id'] !== $le) {
            throw HttpException::notFound();
        }
        $v = [];
        foreach (['title' => 200, 'author' => 200, 'publisher' => 200, 'tags' => 300, 'rights_holder' => 200, 'license' => 300] as $k => $max) {
            $v[$k] = trim((string) ($in[$k] ?? ''));
        }
        $desc = trim(str_replace("\r\n", "\n", (string) ($in['description'] ?? '')));
        $kind = (string) ($in['kind'] ?? ($old['kind'] ?? ''));
        $lang = (string) ($in['language'] ?? '');
        $year = trim((string) ($in['pub_year'] ?? ''));
        $loan = trim((string) ($in['loan_days'] ?? ''));
        $pick = trim((string) ($in['pickup_days'] ?? ''));
        $e = [];
        if (mb_strlen($v['title']) < 2 || mb_strlen($v['title']) > 200) {
            $e['title'] = 'library.error.title';
        }
        foreach (['author' => 200, 'publisher' => 200, 'tags' => 300, 'rights_holder' => 200, 'license' => 300] as $k => $max) {
            if (mb_strlen($v[$k]) > $max) {
                $e[$k] = 'library.error.too_long';
            }
        }
        if (mb_strlen($desc) > 5000) {
            $e['description'] = 'library.error.too_long';
        }
        if (!in_array($kind, self::KINDS, true)) {
            $e['kind'] = 'library.error.choice';
        } elseif ($old !== null && $kind !== $old['kind'] && ($this->currentFile((int) $old['id']) !== null || $db->scalar('SELECT id FROM lb_copies WHERE item_id = ? LIMIT 1', [$old['id']]) !== null)) {
            $e['kind'] = 'library.error.kind_locked';
        }
        if (!in_array($lang, self::LANGS, true)) {
            $e['language'] = 'library.error.choice';
        }
        $thisYear = (int) $this->app->clock->now()->setTimezone($this->tz())->format('Y');
        if ($year !== '' && (preg_match('/^\d{4}$/', $year) !== 1 || (int) $year < 1400 || (int) $year > $thisYear + 1)) {
            $e['pub_year'] = 'library.error.year';
        }
        $cat = null;
        if ((string) ($in['category'] ?? '') !== '') {
            $cat = $db->scalar('SELECT id FROM lb_categories WHERE public_id = ? AND legal_entity_id = ?', [(string) $in['category'], $le]);
            if ($cat === null) {
                $e['category'] = 'library.error.choice';
            }
        }
        if ($kind === 'physical') {
            if (preg_match('/^\d{1,3}$/', $loan) !== 1 || (int) $loan < 1 || (int) $loan > 180) {
                $e['loan_days'] = 'library.error.loan_days';
            }
            if (preg_match('/^\d{1,2}$/', $pick) !== 1 || (int) $pick < 1 || (int) $pick > 30) {
                $e['pickup_days'] = 'library.error.pickup_days';
            }
        }
        $readOnly = !empty($in['read_only']) && $kind === 'digital' ? 1 : 0;
        if ($readOnly === 1 && $old !== null && ($f = $this->currentFile((int) $old['id'])) !== null && $f['file_type'] !== 'pdf') {
            $e['read_only'] = 'library.error.read_only_pdf';
        }
        try {
            $aud = Audience::fromInput($this->app, $le, $in);
        } catch (ValidationException $x) {
            $e += $x->errors;
            $aud = Audience::empty();
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $now = $this->stamp();
        $vals = [$cat, $kind, $v['title'], $v['author'] ?: null, $v['publisher'] ?: null, $year === '' ? null : (int) $year, $lang, $desc === '' ? null : $desc, $v['tags'] ?: null,
            $v['rights_holder'] ?: null, $v['license'] ?: null, Audience::encode($aud), $readOnly, $kind === 'physical' ? (int) $loan : null, $kind === 'physical' ? (int) $pick : null];
        if ($old === null) {
            $pid = Ulid::generate($this->app->clock);
            $db->run(
                'INSERT INTO lb_items (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, category_id, kind, title, author, publisher, pub_year, language, description, tags,
                    rights_holder, license, audience_json, read_only, loan_days, pickup_days) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                array_merge([$pid, $now, $az->userId, $now, $az->userId, $le], $vals)
            );
            $this->app->audit()->record('library.created', 'success', 'lb_item', $pid, $le);
            return $pid;
        }
        if ($old['status'] === 'published' && ($v['rights_holder'] === '' || $v['license'] === '')) {
            throw new ValidationException(array_filter(['rights_holder' => $v['rights_holder'] === '' ? 'library.error.required' : null, 'license' => $v['license'] === '' ? 'library.error.required' : null]));
        }
        $db->run(
            'UPDATE lb_items SET category_id = ?, kind = ?, title = ?, author = ?, publisher = ?, pub_year = ?, language = ?, description = ?, tags = ?, rights_holder = ?, license = ?, audience_json = ?,
                read_only = ?, loan_days = ?, pickup_days = ?, updated_at = ?, updated_by = ? WHERE id = ?',
            array_merge($vals, [$now, $az->userId, $old['id']])
        );
        $this->app->audit()->record('library.updated', 'success', 'lb_item', (string) $pid, $le);
        return (string) $pid;
    }

    /**
     * Upload the item's file (a new version when there is one). The file is checked first: real type, not broken, not
     * password protected, no active content, not already in this company's library; PDF text is indexed per page.
     *
     * @return array{text_status: string, pages: ?int}
     */
    public function upload(Authorizer $az, string $pid, ?UploadedFile $file, string $note): array
    {
        $item = $this->row($pid);
        $le = (int) $item['legal_entity_id'];
        $this->requireManage($az, $le);
        if ($item['kind'] !== 'digital') {
            throw new BusinessException('library.error.not_digital', [], 409);
        }
        if ($item['status'] === 'archived') {
            throw new BusinessException('library.error.archived', [], 409);
        }
        if ($file === null || !$file->ok()) {
            throw new ValidationException(['file' => $file === null ? 'library.error.file_required' : $file->errorKey()]);
        }
        $note = trim($note);
        if (mb_strlen($note) > 300) {
            throw new ValidationException(['note' => 'library.error.too_long']);
        }
        $db = $this->app->db();
        $bytes = $file->bytes();
        $sniff = FileType::sniff($bytes, $file->safeName());
        if ($sniff['type'] === null || !in_array($sniff['type'], ['pdf', 'docx', 'xlsx', 'pptx', 'png', 'jpeg'], true)) {
            throw new ValidationException(['file' => $sniff['problem'] ?? 'file.error.type_not_allowed']);
        }
        if ((int) $item['read_only'] === 1 && $sniff['type'] !== 'pdf') {
            throw new ValidationException(['file' => 'library.error.read_only_pdf']);
        }
        $sha = hash('sha256', $bytes);
        $dup = $db->one('SELECT i.title, i.public_id FROM lb_files f JOIN lb_items i ON i.id = f.item_id WHERE f.sha256 = ? AND f.is_current = 1 AND i.legal_entity_id = ? AND i.id <> ? LIMIT 1', [$sha, $le, $item['id']]);
        if ($dup !== null) {
            throw new ValidationException(['file' => 'library.error.duplicate_file']);
        }
        $pages = [];
        $status = 'not_pdf';
        if ($sniff['type'] === 'pdf' && $sniff['problem'] === null) {
            try {
                $pages = TextExtractor::pages($bytes);
            } catch (PdfError $x) {
                throw new ValidationException(['file' => $x->messageKey === 'pdf.error.password' ? 'file.error.pdf_encrypted' : 'library.error.broken_file']);
            }
            $withText = count(array_filter($pages, static fn (string $p): bool => mb_strlen($p) >= 3));
            $status = $withText === 0 ? 'no_text' : ($withText < count($pages) ? 'partial' : 'indexed');
        }
        try {
            $stored = $this->app->documents()->storeGenerated($az->userId, 'library.file', $bytes, $file->safeName(), ['title' => mb_substr((string) $item['title'], 0, 180), 'legal_entity_id' => $le], 'upload', true);
        } catch (ValidationException $x) {
            throw new ValidationException(['file' => (string) (array_values($x->errors)[0] ?? 'library.error.broken_file')]);
        }
        $pageCount = $sniff['type'] === 'pdf' ? count($pages) : null;
        $now = $this->stamp();
        $db->transactional(function () use ($db, $item, $stored, $file, $sniff, $bytes, $sha, $pageCount, $status, $note, $az, $now, $pages): void {
            $no = 1 + (int) $db->scalar('SELECT COALESCE(MAX(version_no), 0) FROM lb_files WHERE item_id = ?', [$item['id']]);
            $db->run('UPDATE lb_files SET is_current = 0 WHERE item_id = ?', [$item['id']]);
            $db->run(
                'INSERT INTO lb_files (item_id, version_no, document_pid, filename, file_type, mime, size_bytes, sha256, pages, text_status, is_current, note, uploaded_by, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)',
                [$item['id'], $no, $stored['document'], mb_substr($file->safeName(), 0, 200), $sniff['type'], $sniff['mime'], strlen($bytes), $sha, $pageCount, $status, $note === '' ? null : $note, $az->userId, $now]
            );
            $db->run('DELETE FROM lb_text WHERE item_id = ?', [$item['id']]);
            foreach ($pages as $i => $text) {
                if (mb_strlen($text) >= 3) {
                    $db->run('INSERT INTO lb_text (item_id, page, body, squeezed) VALUES (?, ?, ?, ?)', [$item['id'], $i + 1, $text, self::squeeze($text)]);
                }
            }
            $db->run('UPDATE lb_items SET updated_at = ?, updated_by = ? WHERE id = ?', [$now, $az->userId, $item['id']]);
        });
        $this->app->audit()->record('library.file_uploaded', 'success', 'lb_item', $pid, $le, null, ['type' => $sniff['type'], 'pages' => $pageCount, 'text' => $status]);
        return ['text_status' => $status, 'pages' => $pageCount];
    }

    /** Publish: needs rights holder + licence, and a file (digital) or a copy (physical). */
    public function publish(Authorizer $az, string $pid): void
    {
        $item = $this->row($pid);
        $le = (int) $item['legal_entity_id'];
        $this->requireManage($az, $le);
        if ($item['status'] !== 'draft') {
            throw new BusinessException('library.error.not_draft', [], 409);
        }
        $e = [];
        if ((string) $item['rights_holder'] === '' || (string) $item['license'] === '') {
            $e['license'] = 'library.error.license_needed';
        }
        if ($item['kind'] === 'digital' && $this->currentFile((int) $item['id']) === null) {
            $e['file'] = 'library.error.file_needed';
        }
        if ($item['kind'] === 'physical' && $this->copyCounts((int) $item['id'])['total'] === 0) {
            $e['copies'] = 'library.error.copy_needed';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $now = $this->stamp();
        $this->app->db()->run("UPDATE lb_items SET status = 'published', archive_reason = NULL, published_at = COALESCE(published_at, ?), published_by = ?, updated_at = ?, updated_by = ? WHERE id = ?", [$now, $az->userId, $now, $az->userId, $item['id']]);
        $this->app->audit()->record('library.published', 'success', 'lb_item', $pid, $le);
    }

    /** Withdraw (archive) with a reason: readers lose it at once; people waiting or holding a reservation are told. */
    public function archive(Authorizer $az, string $pid, string $reason): void
    {
        $item = $this->row($pid);
        $le = (int) $item['legal_entity_id'];
        $this->requireManage($az, $le);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new ValidationException(['reason' => 'library.error.reason']);
        }
        if ($item['status'] !== 'published') {
            throw new BusinessException('library.error.not_published', [], 409);
        }
        $db = $this->app->db();
        $now = $this->stamp();
        $told = [];
        $db->transactional(function () use ($db, $item, $reason, $az, $now, &$told): void {
            $db->run("UPDATE lb_items SET status = 'archived', archive_reason = ?, updated_at = ?, updated_by = ? WHERE id = ?", [$reason, $now, $az->userId, $item['id']]);
            foreach ($db->all("SELECT * FROM lb_loans WHERE item_id = ? AND status IN ('waiting','ready') FOR UPDATE", [$item['id']]) as $l) {
                $db->run("UPDATE lb_loans SET status = 'cancelled', closed_at = ?, handled_by = ?, reason = ? WHERE id = ?", [$now, $az->userId, $reason, $l['id']]);
                if ($l['copy_id'] !== null) {
                    $db->run("UPDATE lb_copies SET status = 'available', updated_at = ? WHERE id = ? AND status = 'reserved'", [$now, $l['copy_id']]);
                }
                if ($l['user_id'] !== null) {
                    $told[] = (int) $l['user_id'];
                }
            }
        });
        foreach ($told as $u) {
            $this->app->notifier()->notify($u, 'library.loan', 'library.notify.withdrawn', ['title' => (string) $item['title'], 'reason' => $reason], '/library', 'lb.w:' . $pid . ':' . $u);
        }
        $this->app->audit()->record('library.archived', 'success', 'lb_item', $pid, $le, $reason);
    }

    /** Back to draft (to fix and publish again). */
    public function restore(Authorizer $az, string $pid): void
    {
        $item = $this->row($pid);
        $le = (int) $item['legal_entity_id'];
        $this->requireManage($az, $le);
        if ($item['status'] !== 'archived') {
            throw new BusinessException('library.error.not_archived', [], 409);
        }
        $this->app->db()->run("UPDATE lb_items SET status = 'draft', updated_at = ?, updated_by = ? WHERE id = ?", [$this->stamp(), $az->userId, $item['id']]);
        $this->app->audit()->record('library.restored', 'success', 'lb_item', $pid, $le);
    }

    /** @return array<string, mixed> item for the librarian: versions, copies, loans and queue, counts for 30 days */
    public function manage(Authorizer $az, string $pid): array
    {
        $item = $this->row($pid);
        $this->requireManage($az, (int) $item['legal_entity_id']);
        $db = $this->app->db();
        $id = (int) $item['id'];
        $item['files'] = $db->all('SELECT f.*, u.username AS uploader FROM lb_files f LEFT JOIN users u ON u.id = f.uploaded_by WHERE f.item_id = ? ORDER BY f.version_no DESC', [$id]);
        $item['copy_list'] = $db->all('SELECT * FROM lb_copies WHERE item_id = ? ORDER BY code', [$id]);
        $item['loans'] = $db->all(
            "SELECT l.*, p.employee_code, p.name_th, k.code AS copy_code FROM lb_loans l JOIN employees p ON p.id = l.employee_id LEFT JOIN lb_copies k ON k.id = l.copy_id
             WHERE l.item_id = ? AND l.status IN ('waiting','ready','loaned') ORDER BY FIELD(l.status, 'loaned', 'ready', 'waiting'), l.id",
            [$id]
        );
        $since = $this->addDays($this->today(), -29);
        $item['last30'] = $db->one('SELECT COALESCE(SUM(views), 0) AS views, COALESCE(SUM(downloads), 0) AS downloads FROM lb_daily WHERE item_id = ? AND day >= ?', [$id, $since]) ?? ['views' => 0, 'downloads' => 0];
        $item['copies'] = $this->copyCounts($id);
        $aud = Audience::decode((string) $item['audience_json']);
        $item['audience_text'] = Audience::describe($this->app, $aud);
        $item['audience_in'] = Audience::toInput($this->app, $aud);
        $item['category_pid'] = $item['category_id'] === null ? '' : (string) $db->scalar('SELECT public_id FROM lb_categories WHERE id = ?', [$item['category_id']]);
        $item['today'] = $this->today();
        return $item;
    }

    /**
     * A librarian downloads an older version.
     *
     * @return array{bytes: string, mime: string, filename: string}
     */
    public function version(Authorizer $az, string $pid, int $versionNo): array
    {
        $item = $this->row($pid);
        $this->requireManage($az, (int) $item['legal_entity_id']);
        $f = $this->app->db()->one('SELECT * FROM lb_files WHERE item_id = ? AND version_no = ?', [$item['id'], $versionNo]) ?? throw HttpException::notFound();
        $doc = $this->app->documents()->moduleRead((string) $f['document_pid'], 'library', $az->userId, 'library version');
        return ['bytes' => $doc['bytes'], 'mime' => (string) $f['mime'], 'filename' => (string) $f['filename']];
    }

    // ---- copies and loans ------------------------------------------------------------------------------------------------------

    public function addCopy(Authorizer $az, string $pid, string $code, string $location): void
    {
        $item = $this->row($pid);
        $le = (int) $item['legal_entity_id'];
        $this->requireManage($az, $le);
        if ($item['kind'] !== 'physical') {
            throw new BusinessException('library.error.not_physical', [], 409);
        }
        $db = $this->app->db();
        $code = trim($code);
        $location = trim($location);
        $e = [];
        if (preg_match('/^[\p{L}\p{N}._\/-]{1,40}$/u', $code) !== 1) {
            $e['code'] = 'library.error.copy_code';
        } elseif ($db->scalar('SELECT id FROM lb_copies WHERE item_id = ? AND code = ?', [$item['id'], $code]) !== null) {
            $e['code'] = 'library.error.duplicate_copy';
        }
        if (mb_strlen($location) > 120) {
            $e['location'] = 'library.error.too_long';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $now = $this->stamp();
        $copyPid = Ulid::generate($this->app->clock);
        $db->transactional(function () use ($db, $item, $copyPid, $code, $location, $now): void {
            $db->one('SELECT id FROM lb_items WHERE id = ? FOR UPDATE', [$item['id']]);
            $db->run('INSERT INTO lb_copies (public_id, item_id, code, location, status, created_at, updated_at) VALUES (?, ?, ?, ?, \'available\', ?, ?)', [$copyPid, $item['id'], $code, $location === '' ? null : $location, $now, $now]);
            $this->assignNext($item, (int) $db->pdo()->lastInsertId());
        });
        $this->app->audit()->record('library.copy_added', 'success', 'lb_item', $pid, $le, null, ['copy' => $code]);
    }

    /** Take a copy out of use (only when it is on the shelf). */
    public function withdrawCopy(Authorizer $az, string $copyPid, string $reason): void
    {
        $db = $this->app->db();
        $c = $db->one('SELECT k.*, i.legal_entity_id, i.public_id AS item_pid FROM lb_copies k JOIN lb_items i ON i.id = k.item_id WHERE k.public_id = ?', [$copyPid]) ?? throw HttpException::notFound();
        $this->requireManage($az, (int) $c['legal_entity_id']);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 300) {
            throw new ValidationException(['reason' => 'library.error.reason']);
        }
        if ($c['status'] !== 'available') {
            throw new BusinessException('library.error.copy_in_use', [], 409);
        }
        $db->run("UPDATE lb_copies SET status = 'withdrawn', note = ?, updated_at = ? WHERE id = ? AND status = 'available'", [$reason, $this->stamp(), $c['id']]);
        $this->app->audit()->record('library.copy_withdrawn', 'success', 'lb_item', (string) $c['item_pid'], (int) $c['legal_entity_id'], $reason, ['copy' => (string) $c['code']]);
    }

    /**
     * Reserve a book: the first copy on the shelf is held for me until the pickup date; when none is free I wait in the
     * queue. The item row is locked, so two people asking for the last copy at once get one copy and one place in the queue.
     *
     * @return string ready|waiting
     */
    public function reserve(Authorizer $az, string $pid): string
    {
        $item = $this->readableRow($az, $pid);
        $me = $this->me($az);
        if ($item['kind'] !== 'physical' || $item['status'] !== 'published') {
            throw new BusinessException('library.error.not_physical', [], 409);
        }
        if ($me === null || (int) $me['job']['legal_entity_id'] !== (int) $item['legal_entity_id']) {
            throw new BusinessException('library.error.employees_only', [], 403);
        }
        $db = $this->app->db();
        $now = $this->stamp();
        $lp = Ulid::generate($this->app->clock);
        $state = $db->transactional(function () use ($db, $item, $me, $az, $now, $lp): string {
            $db->one('SELECT id FROM lb_items WHERE id = ? FOR UPDATE', [$item['id']]);
            if ($db->scalar("SELECT id FROM lb_loans WHERE item_id = ? AND employee_id = ? AND status IN ('waiting','ready','loaned')", [$item['id'], $me['id']]) !== null) {
                throw new BusinessException('library.error.already', [], 409);
            }
            if ((int) $db->scalar("SELECT COUNT(*) FROM lb_copies WHERE item_id = ? AND status NOT IN ('withdrawn','lost')", [$item['id']]) === 0) {
                throw new BusinessException('library.error.no_copies', [], 409);
            }
            $copy = $db->scalar("SELECT id FROM lb_copies WHERE item_id = ? AND status = 'available' ORDER BY code LIMIT 1", [$item['id']]);
            if ($copy === null) {
                $db->run("INSERT INTO lb_loans (public_id, item_id, employee_id, user_id, status, queued_at) VALUES (?, ?, ?, ?, 'waiting', ?)", [$lp, $item['id'], $me['id'], $az->userId, $now]);
                return 'waiting';
            }
            $db->run("UPDATE lb_copies SET status = 'reserved', updated_at = ? WHERE id = ?", [$now, $copy]);
            $db->run(
                "INSERT INTO lb_loans (public_id, item_id, copy_id, employee_id, user_id, status, queued_at, ready_at, ready_until) VALUES (?, ?, ?, ?, ?, 'ready', ?, ?, ?)",
                [$lp, $item['id'], $copy, $me['id'], $az->userId, $now, $now, $this->addDays($this->today(), (int) $item['pickup_days'])]
            );
            return 'ready';
        });
        $this->app->audit()->record('library.reserved', 'success', 'lb_loan', $lp, (int) $item['legal_entity_id'], null, ['state' => $state]);
        return $state;
    }

    /** I no longer want it (waiting or held for me). */
    public function cancelMine(Authorizer $az, string $loanPid): void
    {
        $db = $this->app->db();
        $l = $db->one('SELECT l.*, i.legal_entity_id FROM lb_loans l JOIN lb_items i ON i.id = l.item_id WHERE l.public_id = ?', [$loanPid]) ?? throw HttpException::notFound();
        $me = $this->me($az);
        if ($me === null || (int) $l['employee_id'] !== (int) $me['id']) {
            throw HttpException::notFound();
        }
        $this->close($l, 'cancelled', $az->userId, null);
    }

    /**
     * Close a waiting / held reservation (cancelled or expired) and pass its copy on.
     *
     * @param array<string, mixed> $l
     */
    private function close(array $l, string $to, ?int $by, ?string $reason): void
    {
        $db = $this->app->db();
        $now = $this->stamp();
        $item = $db->one('SELECT * FROM lb_items WHERE id = ?', [$l['item_id']]) ?? throw HttpException::notFound();
        $db->transactional(function () use ($db, $l, $to, $by, $reason, $now, $item): void {
            $db->one('SELECT id FROM lb_items WHERE id = ? FOR UPDATE', [$l['item_id']]);
            $n = $db->run("UPDATE lb_loans SET status = ?, closed_at = ?, handled_by = ?, reason = ? WHERE id = ? AND status IN ('waiting','ready')", [$to, $now, $by, $reason, $l['id']])->rowCount();
            if ($n === 0) {
                throw new BusinessException('library.error.not_open', [], 409);
            }
            if ($l['status'] === 'ready' && $l['copy_id'] !== null) {
                $this->assignNext($item, (int) $l['copy_id']);
            }
        });
        $this->app->audit()->record('library.reservation_' . $to, 'success', 'lb_loan', (string) $l['public_id'], (int) $item['legal_entity_id']);
    }

    /**
     * A copy came free (returned, added, a hold ended): hold it for the first person waiting and tell them, or put it on
     * the shelf. Call inside a transaction that locked the item row.
     *
     * @param array<string, mixed> $item
     */
    private function assignNext(array $item, int $copyId): void
    {
        $db = $this->app->db();
        $now = $this->stamp();
        $next = $item['status'] === 'published' ? $db->one("SELECT * FROM lb_loans WHERE item_id = ? AND status = 'waiting' ORDER BY id LIMIT 1", [$item['id']]) : null;
        if ($next === null) {
            $db->run("UPDATE lb_copies SET status = 'available', updated_at = ? WHERE id = ? AND status IN ('available','reserved','loaned')", [$now, $copyId]);
            return;
        }
        $until = $this->addDays($this->today(), (int) $item['pickup_days']);
        $db->run("UPDATE lb_copies SET status = 'reserved', updated_at = ? WHERE id = ?", [$now, $copyId]);
        $db->run("UPDATE lb_loans SET status = 'ready', copy_id = ?, ready_at = ?, ready_until = ? WHERE id = ?", [$copyId, $now, $until, $next['id']]);
        if ($next['user_id'] !== null) {
            $this->app->notifier()->notify((int) $next['user_id'], 'library.loan', 'library.notify.ready', ['title' => (string) $item['title'], 'until' => $until], '/library/i/' . $item['public_id'], 'lb.rd:' . $next['public_id']);
        }
    }

    /** @return array<string, mixed>|null employee found by code (or "CODE — name" from the list) */
    private function employeeByCode(string $input): ?array
    {
        $code = trim((string) preg_replace('/\s+—.*$/u', '', trim($input)));
        if ($code === '') {
            return null;
        }
        $id = $this->app->db()->scalar('SELECT id FROM employees WHERE employee_code = ?', [mb_substr($code, 0, 30)]);
        return $id === null ? null : $this->emp()->byId((int) $id);
    }

    /**
     * Lend at the desk: to the person a copy is held for (any copy code they bring is checked), or to someone at the desk
     * when a copy is on the shelf. Due date = today + the book's loan period.
     *
     * @return array{name: string, due: string, copy: string}
     */
    public function lend(Authorizer $az, string $pid, string $who, string $copyCode): array
    {
        $item = $this->row($pid);
        $le = (int) $item['legal_entity_id'];
        $this->requireManage($az, $le);
        if ($item['kind'] !== 'physical' || $item['status'] !== 'published') {
            throw new BusinessException('library.error.not_published', [], 409);
        }
        $emp = $this->employeeByCode($who);
        if ($emp === null) {
            throw new ValidationException(['who' => 'library.error.unknown_employee']);
        }
        if ($emp['status'] === 'former' || $emp['job'] === null || (int) $emp['job']['legal_entity_id'] !== $le) {
            throw new ValidationException(['who' => 'library.error.other_company']);
        }
        if (!Audience::matches($this->app, $le, Audience::decode((string) $item['audience_json']), $emp['job'])) {
            throw new ValidationException(['who' => 'library.error.not_audience']);
        }
        $db = $this->app->db();
        $now = $this->stamp();
        $due = $this->addDays($this->today(), (int) $item['loan_days']);
        $copyCode = trim($copyCode);
        $userId = $db->scalar("SELECT id FROM users WHERE employee_id = ? AND status = 'active' LIMIT 1", [$emp['id']]);
        $out = $db->transactional(function () use ($db, $item, $emp, $now, $due, $copyCode, $az, $userId): array {
            $db->one('SELECT id FROM lb_items WHERE id = ? FOR UPDATE', [$item['id']]);
            $open = $db->one("SELECT * FROM lb_loans WHERE item_id = ? AND employee_id = ? AND status IN ('waiting','ready','loaned')", [$item['id'], $emp['id']]);
            if ($open !== null && $open['status'] === 'loaned') {
                throw new ValidationException(['who' => 'library.error.already_loaned']);
            }
            $copy = null;
            if ($copyCode !== '') {
                $copy = $db->one('SELECT * FROM lb_copies WHERE item_id = ? AND code = ?', [$item['id'], $copyCode]);
                if ($copy === null) {
                    throw new ValidationException(['copy' => 'library.error.unknown_copy']);
                }
            }
            if ($open !== null && $open['status'] === 'ready') {
                // the copy held for this person, or another one from the shelf (then the held copy goes back)
                if ($copy !== null && (int) $copy['id'] !== (int) $open['copy_id']) {
                    if ($copy['status'] !== 'available') {
                        throw new ValidationException(['copy' => 'library.error.copy_busy']);
                    }
                    $this->assignNext($item, (int) $open['copy_id']); // the held copy was not taken: pass it on
                } else {
                    $copy = $db->one('SELECT * FROM lb_copies WHERE id = ?', [$open['copy_id']]);
                }
                $loanId = (int) $open['id'];
                $pid = (string) $open['public_id'];
            } else {
                if ($copy === null) {
                    $copy = $db->one("SELECT * FROM lb_copies WHERE item_id = ? AND status = 'available' ORDER BY code LIMIT 1", [$item['id']]);
                }
                if ($copy === null || $copy['status'] !== 'available') {
                    throw new ValidationException([$copyCode === '' ? 'who' : 'copy' => 'library.error.no_free_copy']);
                }
                if ($open !== null) { // was waiting in the queue: served now
                    $loanId = (int) $open['id'];
                    $pid = (string) $open['public_id'];
                } else {
                    $pid = Ulid::generate($this->app->clock);
                    $db->run("INSERT INTO lb_loans (public_id, item_id, employee_id, user_id, status, queued_at) VALUES (?, ?, ?, ?, 'loaned', ?)", [$pid, $item['id'], $emp['id'], $userId, $now]);
                    $loanId = (int) $db->pdo()->lastInsertId();
                }
            }
            $db->run("UPDATE lb_copies SET status = 'loaned', updated_at = ? WHERE id = ?", [$now, $copy['id']]);
            $db->run("UPDATE lb_loans SET status = 'loaned', copy_id = ?, loaned_at = ?, due_on = ?, handled_by = ?, user_id = COALESCE(user_id, ?) WHERE id = ?", [$copy['id'], $now, $due, $az->userId, $userId, $loanId]);
            return ['loan' => $pid, 'copy' => (string) $copy['code']];
        });
        $this->app->audit()->record('library.lent', 'success', 'lb_loan', $out['loan'], $le, null, ['copy' => $out['copy'], 'due' => $due]);
        return ['name' => $this->emp()->displayName($emp), 'due' => $due, 'copy' => $out['copy']];
    }

    /**
     * Return a copy at the desk (by its code). The copy goes to the next person waiting, or back to the shelf.
     *
     * @return array{name: string, late_days: int, next: bool}
     */
    public function giveBack(Authorizer $az, string $pid, string $copyCode): array
    {
        $item = $this->row($pid);
        $le = (int) $item['legal_entity_id'];
        $this->requireManage($az, $le);
        $db = $this->app->db();
        $copy = $db->one('SELECT * FROM lb_copies WHERE item_id = ? AND code = ?', [$item['id'], trim($copyCode)]);
        if ($copy === null) {
            throw new ValidationException(['copy' => 'library.error.unknown_copy']);
        }
        $loan = $db->one("SELECT * FROM lb_loans WHERE copy_id = ? AND status = 'loaned'", [$copy['id']]);
        if ($loan === null) {
            throw new ValidationException(['copy' => 'library.error.not_loaned']);
        }
        $now = $this->stamp();
        $today = $this->today();
        $next = false;
        $db->transactional(function () use ($db, $item, $copy, $loan, $now, $az, &$next): void {
            $db->one('SELECT id FROM lb_items WHERE id = ? FOR UPDATE', [$item['id']]);
            $n = $db->run("UPDATE lb_loans SET status = 'returned', closed_at = ?, handled_by = ? WHERE id = ? AND status = 'loaned'", [$now, $az->userId, $loan['id']])->rowCount();
            if ($n === 0) {
                throw new ValidationException(['copy' => 'library.error.not_loaned']);
            }
            $this->assignNext($item, (int) $copy['id']);
            $next = $db->scalar('SELECT status FROM lb_copies WHERE id = ?', [$copy['id']]) === 'reserved';
        });
        $late = 0;
        $dueAt = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $loan['due_on'], new DateTimeZone('UTC'));
        $todayAt = DateTimeImmutable::createFromFormat('!Y-m-d', $today, new DateTimeZone('UTC'));
        if ($dueAt !== false && $todayAt !== false && $dueAt < $todayAt) {
            $late = (int) $dueAt->diff($todayAt)->days;
        }
        $this->app->audit()->record('library.returned', 'success', 'lb_loan', (string) $loan['public_id'], $le, null, ['copy' => (string) $copy['code'], 'late_days' => $late]);
        $emp = $this->emp()->byId((int) $loan['employee_id']);
        return ['name' => $emp === null ? '' : $this->emp()->displayName($emp), 'late_days' => $late, 'next' => $next];
    }

    /** A lent copy is lost (reason required): the loan closes and the copy is out of use. No fine is worked out. */
    public function markLost(Authorizer $az, string $loanPid, string $reason): void
    {
        $db = $this->app->db();
        $l = $db->one('SELECT l.*, i.legal_entity_id FROM lb_loans l JOIN lb_items i ON i.id = l.item_id WHERE l.public_id = ?', [$loanPid]) ?? throw HttpException::notFound();
        $this->requireManage($az, (int) $l['legal_entity_id']);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new ValidationException(['reason' => 'library.error.reason']);
        }
        if ($l['status'] !== 'loaned') {
            throw new BusinessException('library.error.not_loaned', [], 409);
        }
        $now = $this->stamp();
        $db->transactional(function () use ($db, $l, $now, $az, $reason): void {
            $db->run("UPDATE lb_loans SET status = 'lost', closed_at = ?, handled_by = ?, reason = ? WHERE id = ? AND status = 'loaned'", [$now, $az->userId, $reason, $l['id']]);
            $db->run("UPDATE lb_copies SET status = 'lost', note = ?, updated_at = ? WHERE id = ?", [$reason, $now, $l['copy_id']]);
        });
        $this->app->audit()->record('library.lost', 'success', 'lb_loan', $loanPid, (int) $l['legal_entity_id'], $reason);
    }

    /** The librarian ends a reservation (e.g. the person left). */
    public function cancelReservation(Authorizer $az, string $loanPid, string $reason): void
    {
        $db = $this->app->db();
        $l = $db->one('SELECT l.*, i.legal_entity_id, i.title FROM lb_loans l JOIN lb_items i ON i.id = l.item_id WHERE l.public_id = ?', [$loanPid]) ?? throw HttpException::notFound();
        $this->requireManage($az, (int) $l['legal_entity_id']);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new ValidationException(['reason' => 'library.error.reason']);
        }
        $this->close($l, 'cancelled', $az->userId, $reason);
        if ($l['user_id'] !== null) {
            $this->app->notifier()->notify((int) $l['user_id'], 'library.loan', 'library.notify.cancelled', ['title' => (string) $l['title'], 'reason' => $reason], '/library', 'lb.c:' . $loanPid);
        }
    }

    /**
     * My reservations and loans (open ones first, then the last 10 closed).
     *
     * @return list<array<string, mixed>>
     */
    public function myLoans(Authorizer $az): array
    {
        $me = $this->me($az);
        if ($me === null) {
            return [];
        }
        $db = $this->app->db();
        $open = $db->all(
            "SELECT l.*, i.title, i.public_id AS item_pid, k.code AS copy_code FROM lb_loans l JOIN lb_items i ON i.id = l.item_id LEFT JOIN lb_copies k ON k.id = l.copy_id
             WHERE l.employee_id = ? AND l.status IN ('waiting','ready','loaned') ORDER BY FIELD(l.status, 'ready', 'loaned', 'waiting'), l.id",
            [$me['id']]
        );
        $closed = $db->all(
            "SELECT l.*, i.title, i.public_id AS item_pid, k.code AS copy_code FROM lb_loans l JOIN lb_items i ON i.id = l.item_id LEFT JOIN lb_copies k ON k.id = l.copy_id
             WHERE l.employee_id = ? AND l.status NOT IN ('waiting','ready','loaned') ORDER BY l.closed_at DESC LIMIT 10",
            [$me['id']]
        );
        return array_merge($open, $closed);
    }

    /**
     * The desk list of a company: held copies, loans (overdue first) and the queue sizes.
     *
     * @return list<array<string, mixed>>
     */
    public function desk(Authorizer $az, int $le): array
    {
        $this->requireManage($az, $le);
        return $this->app->db()->all(
            "SELECT l.*, i.title, i.public_id AS item_pid, p.employee_code, p.name_th, k.code AS copy_code FROM lb_loans l JOIN lb_items i ON i.id = l.item_id
             JOIN employees p ON p.id = l.employee_id LEFT JOIN lb_copies k ON k.id = l.copy_id
             WHERE i.legal_entity_id = ? AND l.status IN ('ready','loaned') ORDER BY l.status = 'loaned' DESC, l.due_on, l.ready_until LIMIT 500",
            [$le]
        );
    }

    /** Hourly: held copies past the pickup date expire (next person), a reminder the day before the due date, one notice when overdue. */
    public function tick(): int
    {
        $db = $this->app->db();
        $today = $this->today();
        $tomorrow = $this->addDays($today, 1);
        $n = 0;
        foreach ($db->all("SELECT l.*, i.title FROM lb_loans l JOIN lb_items i ON i.id = l.item_id WHERE l.status = 'ready' AND l.ready_until < ? LIMIT 200", [$today]) as $l) {
            try {
                $this->close($l, 'expired', null, null);
            } catch (BusinessException) {
                continue;
            }
            if ($l['user_id'] !== null) {
                $this->app->notifier()->notify((int) $l['user_id'], 'library.loan', 'library.notify.expired', ['title' => (string) $l['title']], '/library', 'lb.x:' . $l['public_id']);
            }
            $n++;
        }
        foreach ($db->all("SELECT l.*, i.title, i.public_id AS item_pid FROM lb_loans l JOIN lb_items i ON i.id = l.item_id WHERE l.status = 'loaned' AND l.reminded = 0 AND l.due_on = ? LIMIT 500", [$tomorrow]) as $l) {
            $db->run('UPDATE lb_loans SET reminded = 1 WHERE id = ?', [$l['id']]);
            if ($l['user_id'] !== null) {
                $this->app->notifier()->notify((int) $l['user_id'], 'library.loan', 'library.notify.due', ['title' => (string) $l['title'], 'due' => (string) $l['due_on']], '/library/i/' . $l['item_pid'], 'lb.d:' . $l['public_id']);
            }
            $n++;
        }
        foreach ($db->all("SELECT l.*, i.title, i.public_id AS item_pid FROM lb_loans l JOIN lb_items i ON i.id = l.item_id WHERE l.status = 'loaned' AND l.overdue_notified = 0 AND l.due_on < ? LIMIT 500", [$today]) as $l) {
            $db->run('UPDATE lb_loans SET overdue_notified = 1 WHERE id = ?', [$l['id']]);
            if ($l['user_id'] !== null) {
                $this->app->notifier()->notify((int) $l['user_id'], 'library.loan', 'library.notify.overdue', ['title' => (string) $l['title'], 'due' => (string) $l['due_on']], '/library/i/' . $l['item_pid'], 'lb.o:' . $l['public_id']);
            }
            $n++;
        }
        return $n;
    }

    public static function tickJob(App $app): string
    {
        return 'library: ' . self::of($app)->tick();
    }
}
