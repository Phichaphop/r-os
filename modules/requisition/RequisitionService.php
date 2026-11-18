<?php

declare(strict_types=1);

namespace Blue\Modules\Requisition;

use DateTimeImmutable;
use DateTimeZone;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Files\Xlsx;
use Blue\Core\Support\Ulid;
use Blue\Modules\Employees\EmployeeService;

/**
 * M01 office supplies (9B-2 · DEC-93).
 *
 * Shop-like catalogue per site (branch): the user puts items in a cart (a draft request) and sends it. Sending checks
 * the company's open days/hours, the request limit per week/month, the department quota (over quota: warn, block or
 * special approval — the company's rule) and the available stock (on hand − reserved), then reserves the quantities
 * atomically and starts the approval (line manager; special: then a special approver). An approved request gets a
 * pick-up code (QR); the issuer scans it, may lower quantities (with a note) and issues: the stock ledger is debited and
 * what was reserved but not issued is released in the same transaction. Rejected/returned/cancelled/expired requests
 * release their reservation. Stock never goes negative; a request is never issued twice. The ledger is never deleted.
 *
 * Statuses: draft → pending → approved → partial | fulfilled → closed (the requester confirms receipt);
 * rejected · cancelled · expired (not picked up within the company's days). A returned request goes back to draft.
 */
final class RequisitionService
{
    /** statuses that hold a reservation */
    public const HOLDING = ['pending', 'approved'];

    /** statuses that count against quotas and request limits */
    public const COUNTED = ['pending', 'approved', 'partial', 'fulfilled', 'closed'];

    public const MAX_LINES = 40;

    public const MAX_QTY = 9999;

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    // ---- time and scope ------------------------------------------------------------------------------------------------------

    public function tz(): DateTimeZone
    {
        return new DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'));
    }

    public function nowLocal(): DateTimeImmutable
    {
        $n = $this->app->clock->now()->setTimezone($this->tz());
        return DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $n->format('Y-m-d H:i:s')) ?: $n;
    }

    private function now(): string
    {
        return $this->nowLocal()->format('Y-m-d H:i:s');
    }

    public function today(): string
    {
        return $this->nowLocal()->format('Y-m-d');
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

    private function require(Authorizer $az, string $perm, int $le): void
    {
        if (!$az->scope($perm)->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
    }

    /** @return list<array<string, mixed>> active sites of the company */
    public function sites(int $le): array
    {
        return $this->app->db()->all("SELECT id, public_id, code FROM sites WHERE legal_entity_id = ? AND status = 'active' ORDER BY code", [$le]);
    }

    private function siteId(int $le, string $pid): int
    {
        $id = $this->app->db()->scalar("SELECT id FROM sites WHERE public_id = ? AND legal_entity_id = ? AND status = 'active'", [$pid, $le]);
        if ($id === null) {
            throw new ValidationException(['site' => 'requisition.error.site']);
        }
        return (int) $id;
    }

    /** @return array{employee: ?int, department: ?int, site: ?int} the user's current job */
    public function job(int $userId): array
    {
        $emp = $this->app->db()->scalar('SELECT employee_id FROM users WHERE id = ?', [$userId]);
        if ($emp === null) {
            return ['employee' => null, 'department' => null, 'site' => null];
        }
        $j = EmployeeService::of($this->app)->employment((int) $emp, $this->today());
        return ['employee' => (int) $emp, 'department' => $j !== null && $j['department_id'] !== null ? (int) $j['department_id'] : null, 'site' => $j !== null && $j['site_id'] !== null ? (int) $j['site_id'] : null];
    }

    /** @return array{0: string, 1: string} first day and last day of the week (Mon–Sun) or month containing $date */
    private function period(string $per, string $date): array
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date) ?: $this->nowLocal();
        if ($per === 'week') {
            $start = $d->modify('-' . ((int) $d->format('N') - 1) . ' days');
            return [$start->format('Y-m-d'), $start->modify('+6 days')->format('Y-m-d')];
        }
        return [$d->format('Y-m-01'), $d->format('Y-m-t')];
    }

    // ---- categories and items -------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function categories(int $le, bool $activeOnly = false): array
    {
        return $this->app->db()->all(
            'SELECT c.*, (SELECT COUNT(*) FROM rq_items i WHERE i.category_id = c.id AND i.active = 1) AS items FROM rq_categories c WHERE c.legal_entity_id = ?' . ($activeOnly ? ' AND c.active = 1' : '') . ' ORDER BY c.active DESC, c.name',
            [$le]
        );
    }

    /** @param array<string, mixed> $in */
    public function saveCategory(Authorizer $az, int $le, array $in, ?string $pid = null): string
    {
        $this->require($az, 'requisition.manage', $le);
        $db = $this->app->db();
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw new ValidationException(['cat_name' => 'requisition.error.name']);
        }
        if ($db->scalar('SELECT id FROM rq_categories WHERE legal_entity_id = ? AND name = ? AND public_id <> ?', [$le, $name, $pid ?? '']) !== null) {
            throw new ValidationException(['cat_name' => 'requisition.error.name_taken']);
        }
        $now = $this->stamp();
        if ($pid === null) {
            $pid = Ulid::generate($this->app->clock);
            $db->run('INSERT INTO rq_categories (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, name) VALUES (?, ?, ?, ?, ?, ?, ?)', [$pid, $now, $az->userId, $now, $az->userId, $le, $name]);
            $this->app->audit()->record('requisition.category_added', 'success', 'rq_category', $pid, $le, null, ['name' => $name]);
            return $pid;
        }
        $c = $db->one('SELECT * FROM rq_categories WHERE public_id = ? AND legal_entity_id = ?', [$pid, $le]) ?? throw HttpException::notFound();
        $db->run('UPDATE rq_categories SET name = ?, active = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [$name, !empty($in['active']) ? 1 : 0, $now, $az->userId, $c['id']]);
        $this->app->audit()->record('requisition.category_changed', 'success', 'rq_category', $pid, $le, null, ['name' => $name]);
        return $pid;
    }

    /** @return array<string, mixed> */
    public function item(string $pid): array
    {
        return $this->app->db()->one('SELECT i.*, c.name AS category_name FROM rq_items i JOIN rq_categories c ON c.id = i.category_id WHERE i.public_id = ?', [$pid]) ?? throw HttpException::notFound();
    }

    /**
     * Items of a company with the stock of one site (and my favourites / my department's remaining quota).
     *
     * @param array<string, string> $f q, category, fav
     * @return list<array<string, mixed>>
     */
    public function items(int $le, ?int $siteId, array $f = [], ?int $userId = null, bool $activeOnly = true): array
    {
        $where = ['i.legal_entity_id = ?'];
        $p = [(int) $siteId, (int) $userId, $le];
        if ($activeOnly) {
            $where[] = 'i.active = 1 AND c.active = 1';
        }
        if (($f['q'] ?? '') !== '') {
            $where[] = '(i.name LIKE ? OR i.code LIKE ?)';
            $like = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
            array_push($p, $like, $like);
        }
        if (($f['category'] ?? '') !== '') {
            $where[] = 'c.public_id = ?';
            $p[] = (string) $f['category'];
        }
        if (!empty($f['fav'])) {
            $where[] = 'fv.item_id IS NOT NULL';
        }
        $rows = $this->app->db()->all(
            'SELECT i.*, c.name AS category_name, c.public_id AS category_pid, COALESCE(s.on_hand, 0) AS on_hand, COALESCE(s.reserved, 0) AS reserved, fv.item_id IS NOT NULL AS fav
               FROM rq_items i JOIN rq_categories c ON c.id = i.category_id LEFT JOIN rq_stock s ON s.item_id = i.id AND s.site_id = ?
               LEFT JOIN rq_favorites fv ON fv.item_id = i.id AND fv.user_id = ?
              WHERE ' . implode(' AND ', $where) . ' ORDER BY c.name, i.name LIMIT 500',
            $p
        );
        foreach ($rows as &$r) {
            $r['available'] = max(0, (int) $r['on_hand'] - (int) $r['reserved']);
            $r['low'] = $r['reorder_level'] !== null && $r['available'] <= (int) $r['reorder_level'];
        }
        return $rows;
    }

    /**
     * Adds or changes an item (code unique in the company; picture: png/jpeg/webp through the document service).
     *
     * @param array<string, mixed> $in
     */
    public function saveItem(Authorizer $az, int $le, array $in, ?UploadedFile $photo = null, ?string $pid = null): string
    {
        $this->require($az, 'requisition.manage', $le);
        $db = $this->app->db();
        $e = [];
        $code = strtoupper(trim((string) ($in['code'] ?? '')));
        if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{0,39}$/', $code)) {
            $e['code'] = 'requisition.error.code';
        } elseif ($db->scalar('SELECT id FROM rq_items WHERE legal_entity_id = ? AND code = ? AND public_id <> ?', [$le, $code, $pid ?? '']) !== null) {
            $e['code'] = 'requisition.error.code_taken';
        }
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
            $e['name'] = 'requisition.error.name';
        }
        $unit = trim((string) ($in['unit'] ?? ''));
        if ($unit === '' || mb_strlen($unit) > 30) {
            $e['unit'] = 'requisition.error.unit';
        }
        $cat = $db->scalar('SELECT id FROM rq_categories WHERE public_id = ? AND legal_entity_id = ?', [(string) ($in['category'] ?? ''), $le]);
        if ($cat === null) {
            $e['category'] = 'requisition.error.category';
        }
        $desc = trim((string) ($in['description'] ?? ''));
        if (mb_strlen($desc) > 500) {
            $e['description'] = 'requisition.error.text';
        }
        $reorder = trim((string) ($in['reorder_level'] ?? ''));
        if ($reorder !== '' && (!ctype_digit($reorder) || (int) $reorder > 1000000)) {
            $e['reorder_level'] = 'requisition.error.qty';
        }
        if ($photo !== null && !$photo->ok()) {
            $e['photo'] = $photo->errorKey();
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $photoPid = null;
        if ($photo !== null) {
            try {
                $photoPid = (string) $this->app->documents()->storeGenerated($az->userId, 'requisition.photo', $photo->bytes(), $photo->safeName(), ['title' => 'Supply item ' . $code, 'legal_entity_id' => $le], 'upload', true)['document'];
            } catch (ValidationException $x) {
                throw new ValidationException(['photo' => (string) (array_values($x->errors)[0] ?? 'requisition.error.photo')]);
            }
        }
        $now = $this->stamp();
        $vals = [(int) $cat, $code, $name, $unit, $desc === '' ? null : $desc, $reorder === '' ? null : (int) $reorder];
        if ($pid === null) {
            $pid = Ulid::generate($this->app->clock);
            $db->run(
                'INSERT INTO rq_items (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, category_id, code, name, unit, description, reorder_level, photo_pid) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $now, $az->userId, $now, $az->userId, $le, ...$vals, $photoPid]
            );
            $this->app->audit()->record('requisition.item_added', 'success', 'rq_item', $pid, $le, null, ['code' => $code]);
            return $pid;
        }
        $it = $this->item($pid);
        if ((int) $it['legal_entity_id'] !== $le) {
            throw HttpException::notFound();
        }
        $db->run(
            'UPDATE rq_items SET category_id = ?, code = ?, name = ?, unit = ?, description = ?, reorder_level = ?, photo_pid = COALESCE(?, photo_pid), active = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
            [...$vals, $photoPid, !empty($in['active']) ? 1 : 0, $now, $az->userId, $it['id']]
        );
        $this->app->audit()->record('requisition.item_changed', 'success', 'rq_item', $pid, $le, null, ['code' => $code, 'photo' => $photoPid !== null]);
        return $pid;
    }

    /** @return array{bytes: string, mime: string} the picture of an item (anyone who may use supplies in that company) */
    public function photo(Authorizer $az, string $pid): array
    {
        $it = $this->item($pid);
        if ($it['photo_pid'] === null || !$az->scope('requisition.use')->coversLegalEntity((int) $it['legal_entity_id'])) {
            throw HttpException::notFound();
        }
        $doc = $this->app->documents()->moduleRead((string) $it['photo_pid'], 'requisition', $az->userId, 'supply item picture', false);
        return ['bytes' => $doc['bytes'], 'mime' => $doc['mime']];
    }

    public function toggleFavorite(Authorizer $az, string $itemPid): bool
    {
        $it = $this->item($itemPid);
        $this->require($az, 'requisition.use', (int) $it['legal_entity_id']);
        $db = $this->app->db();
        if ($db->scalar('SELECT 1 FROM rq_favorites WHERE user_id = ? AND item_id = ?', [$az->userId, $it['id']]) !== null) {
            $db->run('DELETE FROM rq_favorites WHERE user_id = ? AND item_id = ?', [$az->userId, $it['id']]);
            return false;
        }
        $db->run('INSERT INTO rq_favorites (user_id, item_id, created_at) VALUES (?, ?, ?)', [$az->userId, $it['id'], $this->stamp()]);
        return true;
    }

    // ---- stock -----------------------------------------------------------------------------------------------------------------

    /**
     * Moves stock of one item at one site under a row lock and writes the ledger: "receive" adds, "count" sets the counted
     * quantity (the ledger keeps the difference), "issue" takes away. On hand never goes below zero.
     */
    private function move(int $le, int $itemId, int $siteId, string $kind, int $qty, ?int $requestId, ?string $note, ?int $actor): int
    {
        $db = $this->app->db();
        return $db->transactional(function () use ($db, $le, $itemId, $siteId, $kind, $qty, $requestId, $note, $actor): int {
            $db->run('INSERT IGNORE INTO rq_stock (item_id, site_id, on_hand, reserved, updated_at) VALUES (?, ?, 0, 0, ?)', [$itemId, $siteId, $this->stamp()]);
            $s = $db->one('SELECT * FROM rq_stock WHERE item_id = ? AND site_id = ? FOR UPDATE', [$itemId, $siteId]);
            $before = (int) ($s['on_hand'] ?? 0);
            $after = match ($kind) {
                'receive' => $before + $qty,
                'count' => $qty,
                default => $before - $qty,
            };
            if ($after < 0) {
                throw new BusinessException('requisition.error.negative', [], 409);
            }
            $delta = $after - $before;
            $db->run('UPDATE rq_stock SET on_hand = ?, low_notified = IF(? > 0, 0, low_notified), updated_at = ? WHERE id = ?', [$after, $delta, $this->stamp(), $s['id']]);
            if ($delta !== 0 || $kind !== 'count') {
                $db->run(
                    'INSERT INTO rq_ledger (legal_entity_id, item_id, site_id, kind, qty, balance_after, request_id, note, actor_id, at_local, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$le, $itemId, $siteId, $kind, $delta, $after, $requestId, $note === null || $note === '' ? null : mb_substr($note, 0, 300), $actor, $this->now(), $this->stamp()]
                );
            }
            return $delta;
        });
    }

    /**
     * Receives or counts stock of an item at a site (stock take needs a note — the difference goes to the ledger).
     */
    public function adjust(Authorizer $az, int $le, string $sitePid, string $itemRef, string $kind, string $qty, string $note): int
    {
        $this->require($az, 'requisition.manage', $le);
        $e = [];
        $site = $this->siteId($le, $sitePid);
        $item = $this->app->db()->one('SELECT id, code FROM rq_items WHERE legal_entity_id = ? AND (public_id = ? OR code = ?)', [$le, $itemRef, strtoupper($itemRef)]);
        if ($item === null) {
            $e['item'] = 'requisition.error.item';
        }
        if (!ctype_digit($qty) || (int) $qty > 1000000 || ($kind === 'receive' && (int) $qty < 1)) {
            $e['qty'] = 'requisition.error.qty';
        }
        if ($kind === 'count' && mb_strlen(trim($note)) < 3) {
            $e['note'] = 'requisition.error.count_note';
        }
        if ($e !== [] || $item === null) {
            throw new ValidationException($e);
        }
        $delta = $this->move($le, (int) $item['id'], $site, $kind === 'count' ? 'count' : 'receive', (int) $qty, null, trim($note), $az->userId);
        $this->app->audit()->record('requisition.stock_' . ($kind === 'count' ? 'counted' : 'received'), 'success', 'rq_item', (string) $item['code'], $le, null, ['site' => $site, 'delta' => $delta]);
        $this->lowStock($le, (int) $item['id'], $site);
        return $delta;
    }

    /**
     * Excel import of received or counted quantities: columns code | quantity | note (first row = header).
     *
     * @return array{done: int, errors: list<string>}
     */
    public function import(Authorizer $az, int $le, string $sitePid, string $kind, ?UploadedFile $file): array
    {
        $this->require($az, 'requisition.manage', $le);
        $this->siteId($le, $sitePid);
        if ($file === null || !$file->ok()) {
            throw new ValidationException(['file' => $file?->errorKey() ?? 'requisition.error.file']);
        }
        try {
            $rows = Xlsx::read($file->bytes(), null, 2001);
        } catch (\Throwable) {
            throw new ValidationException(['file' => 'requisition.error.file']);
        }
        array_shift($rows);
        if ($rows === [] || count($rows) > 2000) {
            throw new ValidationException(['file' => 'requisition.error.file_rows']);
        }
        $t = $this->app->translator();
        $errors = [];
        $done = 0;
        // check every row first: nothing is applied when a row is wrong
        foreach ($rows as $i => $row) {
            $code = strtoupper(trim((string) ($row[0] ?? '')));
            $qty = trim((string) ($row[1] ?? ''));
            if ($code === '' && $qty === '') {
                continue;
            }
            if ($this->app->db()->scalar('SELECT id FROM rq_items WHERE legal_entity_id = ? AND code = ?', [$le, $code]) === null) {
                $errors[] = $t->get('requisition.import.bad_code', ['row' => (string) ($i + 2), 'code' => $code]);
            } elseif (!ctype_digit($qty) || (int) $qty > 1000000 || ($kind === 'receive' && (int) $qty < 1)) {
                $errors[] = $t->get('requisition.import.bad_qty', ['row' => (string) ($i + 2), 'code' => $code]);
            }
        }
        if ($errors !== []) {
            return ['done' => 0, 'errors' => array_slice($errors, 0, 50)];
        }
        $this->app->db()->transactional(function () use ($az, $le, $sitePid, $kind, $rows, &$done): void {
            foreach ($rows as $row) {
                $code = strtoupper(trim((string) ($row[0] ?? '')));
                if ($code === '') {
                    continue;
                }
                $note = trim((string) ($row[2] ?? ''));
                $this->adjust($az, $le, $sitePid, $code, $kind, trim((string) ($row[1] ?? '')), $note !== '' ? $note : ($kind === 'count' ? 'Excel import' : ''));
                $done++;
            }
        });
        return ['done' => $done, 'errors' => []];
    }

    /** @return list<array<string, mixed>> the latest ledger lines of a company (optionally one site / one month) */
    public function ledger(int $le, ?int $siteId, ?string $month = null, int $limit = 200): array
    {
        $where = ['l.legal_entity_id = ?'];
        $p = [$le];
        if ($siteId !== null) {
            $where[] = 'l.site_id = ?';
            $p[] = $siteId;
        }
        if ($month !== null) {
            $where[] = 'l.at_local >= ? AND l.at_local < ?';
            array_push($p, $month . '-01 00:00:00', (DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01') ?: $this->nowLocal())->modify('+1 month')->format('Y-m-d') . ' 00:00:00');
        }
        return $this->app->db()->all(
            'SELECT l.*, i.code, i.name, i.unit, s.code AS site_code, r.ref, r.public_id AS request_pid, COALESCE(u.display_name, u.username) AS actor_name
               FROM rq_ledger l JOIN rq_items i ON i.id = l.item_id JOIN sites s ON s.id = l.site_id LEFT JOIN rq_requests r ON r.id = l.request_id LEFT JOIN users u ON u.id = l.actor_id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY l.id DESC LIMIT ' . max(1, min(5000, $limit)),
            $p
        );
    }

    /** tells the supply admins once when what is available of an item at a site falls to its reorder level */
    private function lowStock(int $le, int $itemId, int $siteId): void
    {
        $db = $this->app->db();
        $s = $db->one(
            'SELECT s.id, s.on_hand, s.reserved, s.low_notified, i.reorder_level, i.code, i.name, st.code AS site_code FROM rq_stock s JOIN rq_items i ON i.id = s.item_id JOIN sites st ON st.id = s.site_id WHERE s.item_id = ? AND s.site_id = ?',
            [$itemId, $siteId]
        );
        if ($s === null || $s['reorder_level'] === null) {
            return;
        }
        $avail = (int) $s['on_hand'] - (int) $s['reserved'];
        if ($avail > (int) $s['reorder_level']) {
            if ((int) $s['low_notified'] === 1) {
                $db->run('UPDATE rq_stock SET low_notified = 0 WHERE id = ?', [$s['id']]);
            }
            return;
        }
        if ((int) $s['low_notified'] === 1) {
            return;
        }
        $db->run('UPDATE rq_stock SET low_notified = 1 WHERE id = ?', [$s['id']]);
        foreach ($this->staff($le, 'requisition.manage') as $u) {
            $this->app->notifier()->notify($u, 'requisition.low', 'requisition.notify.low', ['code' => (string) $s['code'], 'name' => (string) $s['name'], 'site' => (string) $s['site_code'], 'qty' => (string) max(0, $avail)], '/requisition/stock', 'rq.low:' . $s['id'] . ':' . $this->today() . ':' . $u);
        }
    }

    /** @return list<int> active users holding a permission for the company */
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

    // ---- policy and quotas ------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> the company's rules (all blank until the supply admin sets them — no defaults) */
    public function policy(int $le): array
    {
        return $this->app->db()->one('SELECT * FROM rq_policies WHERE legal_entity_id = ?', [$le])
            ?? ['legal_entity_id' => $le, 'over_quota' => null, 'max_requests' => null, 'max_per' => null, 'open_days' => null, 'open_from' => null, 'open_to' => null, 'expire_days' => null];
    }

    /** @param array<string, mixed> $in */
    public function savePolicy(Authorizer $az, int $le, array $in): void
    {
        $this->require($az, 'requisition.manage', $le);
        $e = [];
        $over = (string) ($in['over_quota'] ?? '');
        if (!in_array($over, ['', 'warn', 'block', 'special'], true)) {
            $e['over_quota'] = 'requisition.error.choice';
        }
        $max = trim((string) ($in['max_requests'] ?? ''));
        $per = (string) ($in['max_per'] ?? '');
        if ($max !== '' && (!ctype_digit($max) || (int) $max < 1 || (int) $max > 100)) {
            $e['max_requests'] = 'requisition.error.max_requests';
        } elseif ($max !== '' && !in_array($per, ['week', 'month'], true)) {
            $e['max_per'] = 'requisition.error.choice';
        }
        $days = array_values(array_unique(array_filter(array_map('intval', (array) ($in['open_days'] ?? [])), static fn (int $d): bool => $d >= 1 && $d <= 7)));
        sort($days);
        $from = trim((string) ($in['open_from'] ?? ''));
        $to = trim((string) ($in['open_to'] ?? ''));
        $okTime = static fn (string $x): bool => $x === '' || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $x) === 1;
        if (!$okTime($from) || !$okTime($to) || (($from === '') !== ($to === '')) || ($from !== '' && $from >= $to)) {
            $e['open_from'] = 'requisition.error.hours';
        }
        $exp = trim((string) ($in['expire_days'] ?? ''));
        if ($exp !== '' && (!ctype_digit($exp) || (int) $exp < 1 || (int) $exp > 365)) {
            $e['expire_days'] = 'requisition.error.days';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $vals = [$over === '' ? null : $over, $max === '' ? null : (int) $max, $max === '' ? null : $per, $days === [] ? null : implode(',', $days), $from === '' ? null : $from . ':00', $to === '' ? null : $to . ':00', $exp === '' ? null : (int) $exp];
        $this->app->db()->run(
            'INSERT INTO rq_policies (legal_entity_id, over_quota, max_requests, max_per, open_days, open_from, open_to, expire_days, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE over_quota = VALUES(over_quota), max_requests = VALUES(max_requests), max_per = VALUES(max_per), open_days = VALUES(open_days), open_from = VALUES(open_from),
                open_to = VALUES(open_to), expire_days = VALUES(expire_days), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)',
            [$le, ...$vals, $this->stamp(), $az->userId]
        );
        $this->app->audit()->record('requisition.policy_changed', 'success', 'rq_policy', (string) $le, $le, null, ['over_quota' => $vals[0], 'max' => $vals[1], 'per' => $vals[2], 'days' => $vals[3], 'from' => $vals[4], 'to' => $vals[5], 'expire' => $vals[6]]);
    }

    /** @return list<array<string, mixed>> */
    public function quotas(int $le): array
    {
        return $this->app->db()->all(
            'SELECT q.*, d.code AS dept_code, i.code AS item_code, i.name AS item_name, i.unit FROM rq_quotas q JOIN departments d ON d.id = q.department_id JOIN rq_items i ON i.id = q.item_id
              WHERE q.legal_entity_id = ? ORDER BY d.code, i.code, q.valid_from DESC',
            [$le]
        );
    }

    /** @param array<string, mixed> $in department, item, qty, period, valid_from, valid_to */
    public function saveQuota(Authorizer $az, int $le, array $in): string
    {
        $this->require($az, 'requisition.manage', $le);
        $db = $this->app->db();
        $e = [];
        $dept = $db->scalar("SELECT id FROM departments WHERE public_id = ? AND legal_entity_id = ? AND status = 'active'", [(string) ($in['department'] ?? ''), $le]);
        if ($dept === null) {
            $e['department'] = 'requisition.error.department';
        }
        $item = $db->scalar('SELECT id FROM rq_items WHERE public_id = ? AND legal_entity_id = ?', [(string) ($in['item'] ?? ''), $le]);
        if ($item === null) {
            $e['quota_item'] = 'requisition.error.item';
        }
        $qty = trim((string) ($in['qty'] ?? ''));
        if (!ctype_digit($qty) || (int) $qty < 1 || (int) $qty > 1000000) {
            $e['quota_qty'] = 'requisition.error.qty';
        }
        $period = (string) ($in['period'] ?? '');
        if (!in_array($period, ['week', 'month'], true)) {
            $e['period'] = 'requisition.error.choice';
        }
        $from = (string) ($in['valid_from'] ?? '');
        $to = trim((string) ($in['valid_to'] ?? ''));
        $okDate = static fn (string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && DateTimeImmutable::createFromFormat('!Y-m-d', $d) !== false;
        if (!$okDate($from) || ($to !== '' && (!$okDate($to) || $to < $from))) {
            $e['valid_from'] = 'requisition.error.dates';
        }
        if (
            $e === [] && $db->scalar(
                'SELECT id FROM rq_quotas WHERE department_id = ? AND item_id = ? AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)',
                [$dept, $item, $to === '' ? '9999-12-31' : $to, $from]
            ) !== null
        ) {
            $e['valid_from'] = 'requisition.error.quota_overlap';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $pid = Ulid::generate($this->app->clock);
        $now = $this->stamp();
        $db->run(
            'INSERT INTO rq_quotas (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, department_id, item_id, qty, period, valid_from, valid_to) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$pid, $now, $az->userId, $now, $az->userId, $le, $dept, $item, (int) $qty, $period, $from, $to === '' ? null : $to]
        );
        $this->app->audit()->record('requisition.quota_added', 'success', 'rq_quota', $pid, $le, null, ['department' => (int) $dept, 'item' => (int) $item, 'qty' => (int) $qty, 'period' => $period, 'from' => $from, 'to' => $to]);
        return $pid;
    }

    /** Ends a quota yesterday (kept for history), or deletes one that has not started yet. */
    public function endQuota(Authorizer $az, string $pid): void
    {
        $db = $this->app->db();
        $q = $db->one('SELECT * FROM rq_quotas WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $this->require($az, 'requisition.manage', (int) $q['legal_entity_id']);
        $today = $this->today();
        if ((string) $q['valid_from'] >= $today) {
            $db->run('DELETE FROM rq_quotas WHERE id = ?', [$q['id']]);
        } else {
            $yesterday = $this->nowLocal()->modify('-1 day')->format('Y-m-d');
            $db->run('UPDATE rq_quotas SET valid_to = ?, updated_at = ?, updated_by = ? WHERE id = ? AND (valid_to IS NULL OR valid_to > ?)', [$yesterday, $this->stamp(), $az->userId, $q['id'], $yesterday]);
        }
        $this->app->audit()->record('requisition.quota_ended', 'success', 'rq_quota', $pid, (int) $q['legal_entity_id']);
    }

    /** @return ?array<string, mixed> the department's quota of an item on a date */
    private function quotaOf(int $dept, int $itemId, string $date): ?array
    {
        return $this->app->db()->one(
            'SELECT * FROM rq_quotas WHERE department_id = ? AND item_id = ? AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?) ORDER BY valid_from DESC LIMIT 1',
            [$dept, $itemId, $date, $date]
        );
    }

    /** what a department has used of an item in a period: requested while pending/approved, issued afterwards */
    private function used(int $dept, int $itemId, string $from, string $to, int $exceptRequest = 0): int
    {
        return (int) $this->app->db()->scalar(
            "SELECT COALESCE(SUM(CASE WHEN r.status IN ('pending','approved') THEN l.qty ELSE COALESCE(l.qty_issued, 0) END), 0)
               FROM rq_lines l JOIN rq_requests r ON r.id = l.request_id
              WHERE r.department_id = ? AND l.item_id = ? AND r.status IN ('pending','approved','partial','fulfilled','closed') AND r.submitted_at >= ? AND r.submitted_at < ? AND r.id <> ?",
            [$dept, $itemId, $from . ' 00:00:00', (DateTimeImmutable::createFromFormat('!Y-m-d', $to) ?: $this->nowLocal())->modify('+1 day')->format('Y-m-d') . ' 00:00:00', $exceptRequest]
        );
    }

    /**
     * Remaining quota of my department for items (null = no quota).
     *
     * @param list<int> $itemIds
     * @return array<int, array{quota: int, used: int, left: int, period: string}>
     */
    public function quotaLeft(?int $dept, array $itemIds): array
    {
        $out = [];
        if ($dept === null) {
            return $out;
        }
        $today = $this->today();
        foreach (array_unique($itemIds) as $id) {
            $q = $this->quotaOf($dept, $id, $today);
            if ($q === null) {
                continue;
            }
            [$a, $b] = $this->period((string) $q['period'], $today);
            $used = $this->used($dept, $id, $a, $b);
            $out[$id] = ['quota' => (int) $q['qty'], 'used' => $used, 'left' => max(0, (int) $q['qty'] - $used), 'period' => (string) $q['period']];
        }
        return $out;
    }

    /** @return ?array{key: string, params: array<string, string>} why sending is closed now (open days/hours of the company) */
    public function closedReason(int $le): ?array
    {
        $p = $this->policy($le);
        $now = $this->nowLocal();
        $t = $this->app->translator();
        if ($p['open_days'] !== null && !in_array((int) $now->format('N'), array_map('intval', explode(',', (string) $p['open_days'])), true)) {
            return ['key' => 'requisition.closed_day', 'params' => ['days' => implode(', ', array_map(static fn (string $d): string => $t->get('requisition.dow.' . $d), explode(',', (string) $p['open_days'])))]];
        }
        if ($p['open_from'] !== null && ($now->format('H:i:s') < (string) $p['open_from'] || $now->format('H:i:s') >= (string) $p['open_to'])) {
            return ['key' => 'requisition.closed_hours', 'params' => ['from' => substr((string) $p['open_from'], 0, 5), 'to' => substr((string) $p['open_to'], 0, 5)]];
        }
        return null;
    }

    /** @return ?array{used: int, max: int, per: string} the request limit when it is reached */
    public function limitReached(int $le, int $userId, int $exceptRequest = 0): ?array
    {
        $p = $this->policy($le);
        if ($p['max_requests'] === null) {
            return null;
        }
        [$a, $b] = $this->period((string) $p['max_per'], $this->today());
        $used = (int) $this->app->db()->scalar(
            "SELECT COUNT(*) FROM rq_requests WHERE legal_entity_id = ? AND requester_id = ? AND status IN ('pending','approved','partial','fulfilled','closed','rejected','expired')
                AND submitted_at >= ? AND submitted_at <= ? AND id <> ?",
            [$le, $userId, $a . ' 00:00:00', $b . ' 23:59:59', $exceptRequest]
        );
        return $used >= (int) $p['max_requests'] ? ['used' => $used, 'max' => (int) $p['max_requests'], 'per' => (string) $p['max_per']] : null;
    }

    // ---- cart (a draft request) ---------------------------------------------------------------------------------------------

    /** @return ?array<string, mixed> my draft in a company with its lines */
    public function cart(Authorizer $az, int $le): ?array
    {
        $d = $this->app->db()->one("SELECT * FROM rq_requests WHERE legal_entity_id = ? AND requester_id = ? AND status = 'draft' ORDER BY id DESC LIMIT 1", [$le, $az->userId]);
        if ($d === null) {
            return null;
        }
        $d['lines'] = $this->lines((int) $d['id'], (int) $d['site_id']);
        return $d;
    }

    /** @return list<array<string, mixed>> */
    private function lines(int $requestId, int $siteId): array
    {
        $rows = $this->app->db()->all(
            'SELECT l.*, i.public_id AS item_pid, i.code, i.name, i.unit, i.active, i.photo_pid, COALESCE(s.on_hand, 0) AS on_hand, COALESCE(s.reserved, 0) AS reserved
               FROM rq_lines l JOIN rq_items i ON i.id = l.item_id LEFT JOIN rq_stock s ON s.item_id = l.item_id AND s.site_id = ? WHERE l.request_id = ? ORDER BY i.code',
            [$siteId, $requestId]
        );
        foreach ($rows as &$r) {
            $r['available'] = max(0, (int) $r['on_hand'] - (int) $r['reserved']);
        }
        return $rows;
    }

    /** @return array<string, mixed> */
    private function draftFor(Authorizer $az, int $le, ?int $siteId = null): array
    {
        $db = $this->app->db();
        $d = $db->one("SELECT * FROM rq_requests WHERE legal_entity_id = ? AND requester_id = ? AND status = 'draft' ORDER BY id DESC LIMIT 1", [$le, $az->userId]);
        if ($d !== null) {
            return $d;
        }
        $job = $this->job($az->userId);
        $site = $siteId ?? ($job['site'] !== null && $db->scalar("SELECT id FROM sites WHERE id = ? AND legal_entity_id = ? AND status = 'active'", [$job['site'], $le]) !== null ? $job['site'] : null);
        $site ??= (int) ($this->sites($le)[0]['id'] ?? 0);
        if ($site === 0) {
            throw new BusinessException('requisition.error.no_site', [], 409);
        }
        $pid = Ulid::generate($this->app->clock);
        $now = $this->stamp();
        $db->run('INSERT INTO rq_requests (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, site_id, requester_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$pid, $now, $az->userId, $now, $az->userId, $le, $site, $az->userId]);
        return $db->one('SELECT * FROM rq_requests WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
    }

    /** Puts an item in my cart (adds to the quantity already there). */
    public function addToCart(Authorizer $az, int $le, string $itemPid, string $qty, ?string $sitePid = null): void
    {
        $this->require($az, 'requisition.use', $le);
        $it = $this->item($itemPid);
        if ((int) $it['legal_entity_id'] !== $le || (int) $it['active'] !== 1) {
            throw new ValidationException(['qty' => 'requisition.error.item']);
        }
        if (!ctype_digit($qty) || (int) $qty < 1 || (int) $qty > self::MAX_QTY) {
            throw new ValidationException(['qty' => 'requisition.error.qty']);
        }
        $d = $this->draftFor($az, $le, $sitePid !== null && $sitePid !== '' ? $this->siteId($le, $sitePid) : null);
        $db = $this->app->db();
        if ((int) $db->scalar('SELECT COUNT(*) FROM rq_lines WHERE request_id = ?', [$d['id']]) >= self::MAX_LINES && $db->scalar('SELECT id FROM rq_lines WHERE request_id = ? AND item_id = ?', [$d['id'], $it['id']]) === null) {
            throw new ValidationException(['qty' => 'requisition.error.too_many_lines']);
        }
        $db->run('INSERT INTO rq_lines (request_id, item_id, qty) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE qty = LEAST(qty + VALUES(qty), ?)', [$d['id'], $it['id'], (int) $qty, self::MAX_QTY]);
        $db->run('UPDATE rq_requests SET updated_at = ?, row_version = row_version + 1 WHERE id = ?', [$this->stamp(), $d['id']]);
    }

    /**
     * Changes my cart: quantities (0 removes the line), site and note.
     *
     * @param array<string, mixed> $in qty[item_pid], site, note
     */
    public function updateCart(Authorizer $az, string $pid, array $in): void
    {
        $db = $this->app->db();
        $d = $this->own($az, $pid, ['draft']);
        $e = [];
        $site = (int) $d['site_id'];
        if (($in['site'] ?? '') !== '') {
            try {
                $site = $this->siteId((int) $d['legal_entity_id'], (string) $in['site']);
            } catch (ValidationException $x) {
                $e += $x->errors;
            }
        }
        $note = trim((string) ($in['note'] ?? ''));
        if (mb_strlen($note) > 500) {
            $e['note'] = 'requisition.error.text';
        }
        $qtys = [];
        foreach ((array) ($in['qty'] ?? []) as $itemPid => $q) {
            $q = trim((string) $q);
            if (!ctype_digit($q) || (int) $q > self::MAX_QTY) {
                $e['qty_' . $itemPid] = 'requisition.error.qty';
                continue;
            }
            $qtys[(string) $itemPid] = (int) $q;
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $db->transactional(function () use ($db, $d, $site, $note, $qtys): void {
            foreach ($qtys as $itemPid => $q) {
                $item = $db->scalar('SELECT id FROM rq_items WHERE public_id = ?', [$itemPid]);
                if ($item === null) {
                    continue;
                }
                if ($q === 0) {
                    $db->run('DELETE FROM rq_lines WHERE request_id = ? AND item_id = ?', [$d['id'], $item]);
                } else {
                    $db->run('UPDATE rq_lines SET qty = ? WHERE request_id = ? AND item_id = ?', [$q, $d['id'], $item]);
                }
            }
            $db->run('UPDATE rq_requests SET site_id = ?, note = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?', [$site, $note === '' ? null : $note, $this->stamp(), $d['id']]);
        });
    }

    /**
     * @param list<string> $statuses
     * @return array<string, mixed>
     */
    private function own(Authorizer $az, string $pid, array $statuses): array
    {
        $d = $this->app->db()->one('SELECT * FROM rq_requests WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        if ((int) $d['requester_id'] !== $az->userId) {
            throw HttpException::notFound();
        }
        if (!in_array($d['status'], $statuses, true)) {
            throw new BusinessException('requisition.error.cannot_change', [], 409);
        }
        return $d;
    }

    private function nextRef(int $le): string
    {
        $prefix = 'RQ' . $this->nowLocal()->format('ym') . '-';
        $last = (string) ($this->app->db()->scalar('SELECT MAX(ref) FROM rq_requests WHERE legal_entity_id = ? AND ref LIKE ?', [$le, $prefix . '%']) ?? '');
        return $prefix . str_pad((string) ((int) substr($last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Sends my cart: open days/hours → request limit → stock and quota (under row locks) → reserve → approval.
     *
     * @return array{over_quota: bool, special: bool}
     */
    public function submit(Authorizer $az, string $pid): array
    {
        $db = $this->app->db();
        $d = $this->own($az, $pid, ['draft']);
        $le = (int) $d['legal_entity_id'];
        $this->require($az, 'requisition.use', $le);
        $closed = $this->closedReason($le);
        if ($closed !== null) {
            throw new BusinessException($closed['key'], $closed['params'], 409);
        }
        $limit = $this->limitReached($le, $az->userId, (int) $d['id']);
        if ($limit !== null) {
            throw new BusinessException('requisition.error.limit', ['max' => (string) $limit['max'], 'per' => $this->app->translator()->get('requisition.per.' . $limit['per'])], 409);
        }
        $job = $this->job($az->userId);
        $policy = $this->policy($le);
        $rule = (string) ($policy['over_quota'] ?? 'warn') ?: 'warn';
        $result = $db->transactional(function () use ($db, $d, $le, $job, $rule): array {
            $lines = $db->all('SELECT l.*, i.code, i.name, i.active FROM rq_lines l JOIN rq_items i ON i.id = l.item_id WHERE l.request_id = ? ORDER BY l.item_id', [$d['id']]);
            if ($lines === []) {
                throw new BusinessException('requisition.error.empty', [], 409);
            }
            $short = [];
            $over = [];
            $today = $this->today();
            foreach ($lines as $l) {
                if ((int) $l['active'] !== 1) {
                    throw new BusinessException('requisition.error.inactive_item', ['code' => (string) $l['code']], 409);
                }
                $db->run('INSERT IGNORE INTO rq_stock (item_id, site_id, on_hand, reserved, updated_at) VALUES (?, ?, 0, 0, ?)', [$l['item_id'], $d['site_id'], $this->stamp()]);
                $s = $db->one('SELECT * FROM rq_stock WHERE item_id = ? AND site_id = ? FOR UPDATE', [$l['item_id'], $d['site_id']]);
                if ((int) $s['on_hand'] - (int) $s['reserved'] < (int) $l['qty']) {
                    $short[] = $l['code'] . ' (' . max(0, (int) $s['on_hand'] - (int) $s['reserved']) . ')';
                    continue;
                }
                $overQty = 0;
                if ($job['department'] !== null) {
                    $q = $this->quotaOf($job['department'], (int) $l['item_id'], $today);
                    if ($q !== null) {
                        $db->one('SELECT id FROM rq_quotas WHERE id = ? FOR UPDATE', [$q['id']]); // one sender of a department at a time per quota
                        [$a, $b] = $this->period((string) $q['period'], $today);
                        $overQty = max(0, $this->used($job['department'], (int) $l['item_id'], $a, $b, (int) $d['id']) + (int) $l['qty'] - (int) $q['qty']);
                    }
                }
                if ($overQty > 0) {
                    $over[] = $l['code'] . ' +' . $overQty;
                }
                $db->run('UPDATE rq_lines SET over_qty = ? WHERE id = ?', [$overQty, $l['id']]);
                $db->run('UPDATE rq_stock SET reserved = reserved + ?, updated_at = ? WHERE id = ?', [$l['qty'], $this->stamp(), $s['id']]);
            }
            if ($short !== []) {
                throw new BusinessException('requisition.error.short', ['items' => implode(', ', $short)], 409);
            }
            if ($over !== [] && $rule === 'block') {
                throw new BusinessException('requisition.error.over_quota', ['items' => implode(', ', $over)], 409);
            }
            $special = $over !== [] && $rule === 'special';
            $ref = $d['ref'] ?? $this->nextRef($le);
            $db->run(
                "UPDATE rq_requests SET status = 'pending', ref = ?, department_id = ?, over_quota = ?, route = ?, submitted_at = ?, reason = NULL, updated_at = ?, row_version = row_version + 1 WHERE id = ? AND status = 'draft'",
                [$ref, $job['department'], $over !== [] ? 1 : 0, $special ? 'requisition.special' : 'requisition.request', $this->now(), $this->stamp(), $d['id']]
            );
            return ['ref' => $ref, 'over_quota' => $over !== [], 'special' => $special, 'over' => $over, 'lines' => count($lines)];
        });
        $wf = $this->app->workflow();
        $title = $this->app->translator()->get('requisition.wf_title', ['ref' => (string) $result['ref'], 'lines' => (string) $result['lines']]);
        $route = $result['special'] ? 'requisition.special' : 'requisition.request';
        $prev = $d['workflow_id'] !== null ? (string) $db->scalar('SELECT status FROM workflow_instances WHERE public_id = ?', [$d['workflow_id']]) : '';
        if ($prev === 'returned' && (string) $d['route'] === $route) { // a returned request goes back to the same approval
            $wf->resubmit((string) $d['workflow_id'], $az->userId);
        } else {
            $inst = $wf->start($route, $pid, $title, $le, $az->userId, (int) $d['site_id'], $job['department']);
            $db->run('UPDATE rq_requests SET workflow_id = ? WHERE id = ?', [$inst, $d['id']]);
        }
        $this->event((int) $d['id'], 'submitted', $az->userId, $result['over'] !== [] ? implode(', ', $result['over']) : null);
        $this->app->audit()->record('requisition.submitted', 'success', 'rq_request', $pid, $le, null, ['lines' => $result['lines'], 'over_quota' => $result['over_quota'], 'route' => $route]);
        foreach ($db->all('SELECT item_id FROM rq_lines WHERE request_id = ?', [$d['id']]) as $l) {
            $this->lowStock($le, (int) $l['item_id'], (int) $d['site_id']);
        }
        return ['over_quota' => $result['over_quota'], 'special' => $result['special']];
    }

    /** @param array<string, mixed> $r releases what a request still holds (lines × qty) — inside the caller's transaction */
    private function release(array $r): void
    {
        $db = $this->app->db();
        foreach ($db->all('SELECT item_id, qty FROM rq_lines WHERE request_id = ?', [$r['id']]) as $l) {
            $db->run('UPDATE rq_stock SET reserved = GREATEST(reserved - ?, 0), updated_at = ? WHERE item_id = ? AND site_id = ?', [$l['qty'], $this->stamp(), $l['item_id'], $r['site_id']]);
        }
    }

    /** @return list<int> line manager(s) of the requester */
    public function managerUserIds(string $pid): array
    {
        $emp = $this->app->db()->scalar('SELECT u.employee_id FROM rq_requests r JOIN users u ON u.id = r.requester_id WHERE r.public_id = ?', [$pid]);
        return $emp === null ? [] : EmployeeService::of($this->app)->managerUserIds((int) $emp, $this->today());
    }

    public function onApproved(string $pid, ?int $approverId = null): void
    {
        $db = $this->app->db();
        $r = $db->one('SELECT * FROM rq_requests WHERE public_id = ?', [$pid]);
        if ($r === null || $r['status'] !== 'pending') {
            return;
        }
        $code = strtoupper(substr(bin2hex(random_bytes(8)), 0, 16));
        $db->run("UPDATE rq_requests SET status = 'approved', approved_at = ?, pickup_code = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ? AND status = 'pending'", [$this->now(), $code, $this->stamp(), $r['id']]);
        $this->event((int) $r['id'], 'approved', $approverId, null);
        $this->app->notifier()->notify((int) $r['requester_id'], 'requisition.approved', 'requisition.notify.approved', ['ref' => (string) $r['ref']], '/requisition/r/' . $pid, 'rq.ok:' . $pid);
        foreach ($this->staff((int) $r['legal_entity_id'], 'requisition.issue') as $u) {
            $this->app->notifier()->notify($u, 'requisition.issue', 'requisition.notify.to_issue', ['ref' => (string) $r['ref']], '/requisition/issue?code=' . $code, 'rq.is:' . $pid . ':' . $u);
        }
    }

    /** @param 'rejected'|'returned'|'cancelled' $outcome */
    public function onClosed(string $pid, string $outcome): void
    {
        $db = $this->app->db();
        $db->transactional(function () use ($db, $pid, $outcome): void {
            $r = $db->one('SELECT * FROM rq_requests WHERE public_id = ? FOR UPDATE', [$pid]);
            if ($r === null || $r['status'] !== 'pending') {
                return;
            }
            $to = ['rejected' => 'rejected', 'returned' => 'draft', 'cancelled' => 'cancelled'][$outcome];
            $this->release($r);
            $db->run('UPDATE rq_requests SET status = ?, closed_at = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?', [$to, $to === 'draft' ? null : $this->now(), $this->stamp(), $r['id']]);
            $this->event((int) $r['id'], $outcome, $outcome === 'cancelled' ? (int) $r['requester_id'] : null, null);
        });
    }

    public function onResubmitted(string $pid): void
    {
        // submit() reserved again and moved the request back to "pending"
    }

    // ---- one request ------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> a request the person may open: requester, approvers, issuers and supply admins of the company */
    public function request(Authorizer $az, string $pid): array
    {
        $db = $this->app->db();
        $r = $db->one(
            'SELECT r.*, s.code AS site_code, d.code AS dept_code, COALESCE(u.display_name, u.username) AS requester_name, COALESCE(iu.display_name, iu.username) AS issuer_name
               FROM rq_requests r JOIN sites s ON s.id = r.site_id JOIN users u ON u.id = r.requester_id LEFT JOIN departments d ON d.id = r.department_id LEFT JOIN users iu ON iu.id = r.issued_by
              WHERE r.public_id = ?',
            [$pid]
        ) ?? throw HttpException::notFound();
        $le = (int) $r['legal_entity_id'];
        $r['mine'] = (int) $r['requester_id'] === $az->userId;
        $r['can_issue'] = $az->scope('requisition.issue')->coversLegalEntity($le);
        $r['can_manage'] = $az->scope('requisition.manage')->coversLegalEntity($le);
        $approver = $r['workflow_id'] !== null && $db->scalar(
            'SELECT 1 FROM workflow_tasks t JOIN workflow_instances i ON i.id = t.instance_id WHERE i.public_id = ? AND t.assignee_id = ? LIMIT 1',
            [$r['workflow_id'], $az->userId]
        ) !== null;
        if (!$r['mine'] && !$r['can_issue'] && !$r['can_manage'] && !$approver) {
            throw HttpException::notFound();
        }
        if ($r['status'] === 'draft' && !$r['mine']) {
            throw HttpException::notFound();
        }
        $r['lines'] = $this->lines((int) $r['id'], (int) $r['site_id']);
        $r['events'] = $db->all('SELECT e.*, COALESCE(u.display_name, u.username) AS actor_name FROM rq_events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.request_id = ? ORDER BY e.id', [$r['id']]);
        $r['ledger'] = $db->all('SELECT l.*, i.code FROM rq_ledger l JOIN rq_items i ON i.id = l.item_id WHERE l.request_id = ? ORDER BY l.id', [$r['id']]);
        return $r;
    }

    /** I withdraw my request before it is issued (pending: the approval is cancelled too); the reservation is released. */
    public function cancel(Authorizer $az, string $pid, string $reason): void
    {
        $r = $this->own($az, $pid, ['pending', 'approved']);
        if ($r['status'] === 'pending' && $r['workflow_id'] !== null) {
            $this->app->workflow()->cancel((string) $r['workflow_id'], $az->userId, $reason === '' ? '-' : $reason); // → onClosed('cancelled')
        } else {
            $db = $this->app->db();
            $db->transactional(function () use ($db, $r, $az, $reason): void {
                $x = $db->one('SELECT * FROM rq_requests WHERE id = ? FOR UPDATE', [$r['id']]);
                if ($x === null || $x['status'] !== 'approved') {
                    throw new BusinessException('requisition.error.cannot_change', [], 409);
                }
                $this->release($x);
                $db->run("UPDATE rq_requests SET status = 'cancelled', pickup_code = NULL, closed_at = ?, reason = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$this->now(), $reason === '' ? null : mb_substr($reason, 0, 500), $this->stamp(), $x['id']]);
                $this->event((int) $x['id'], 'cancelled', $az->userId, $reason);
            });
        }
        $this->app->audit()->record('requisition.cancelled', 'success', 'rq_request', $pid, (int) $r['legal_entity_id']);
    }

    /** I confirm I received what was issued → closed. */
    public function confirmReceipt(Authorizer $az, string $pid): void
    {
        $r = $this->own($az, $pid, ['partial', 'fulfilled']);
        $this->app->db()->run("UPDATE rq_requests SET status = 'closed', closed_at = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ? AND status IN ('partial','fulfilled')", [$this->now(), $this->stamp(), $r['id']]);
        $this->event((int) $r['id'], 'received', $az->userId, null);
    }

    /** "Request again": puts the items of an earlier request of mine in my cart (active items only). */
    public function repeat(Authorizer $az, string $pid): int
    {
        $r = $this->own($az, $pid, ['pending', 'approved', 'partial', 'fulfilled', 'closed', 'rejected', 'cancelled', 'expired']);
        $n = 0;
        foreach ($this->app->db()->all('SELECT i.public_id, l.qty FROM rq_lines l JOIN rq_items i ON i.id = l.item_id WHERE l.request_id = ? AND i.active = 1', [$r['id']]) as $l) {
            $this->addToCart($az, (int) $r['legal_entity_id'], (string) $l['public_id'], (string) $l['qty']);
            $n++;
        }
        if ($n === 0) {
            throw new BusinessException('requisition.error.nothing_to_repeat', [], 409);
        }
        return $n;
    }

    // ---- issue desk --------------------------------------------------------------------------------------------------------

    /** Finds a request by its pick-up code or its number — typed, scanned from the barcode, or the whole link from the QR. */
    public function find(Authorizer $az, string $input): ?string
    {
        $in = trim($input);
        if (preg_match('/[?&]code=([^&#\s]+)/', $in, $m) === 1) {
            $in = rawurldecode($m[1]); // a scanned QR carries the whole desk link — with the pick-up code or the request number (0.38.0)
        }
        $in = strtoupper($in);
        if ($in === '') {
            return null;
        }
        $r = $this->app->db()->one('SELECT public_id, legal_entity_id FROM rq_requests WHERE pickup_code = ? OR (ref = ? AND status <> \'draft\') ORDER BY id DESC LIMIT 1', [$in, $in]);
        if ($r === null || !$az->scope('requisition.issue')->coversLegalEntity((int) $r['legal_entity_id'])) {
            return null;
        }
        return (string) $r['public_id'];
    }

    /** @return list<array<string, mixed>> approved requests waiting at the issue desk */
    public function waiting(Authorizer $az): array
    {
        $les = $this->companies($az, 'requisition.issue');
        if ($les === []) {
            return [];
        }
        return $this->app->db()->all(
            "SELECT r.public_id, r.ref, r.approved_at, s.code AS site_code, COALESCE(u.display_name, u.username) AS requester_name, (SELECT COUNT(*) FROM rq_lines l WHERE l.request_id = r.id) AS line_count
               FROM rq_requests r JOIN sites s ON s.id = r.site_id JOIN users u ON u.id = r.requester_id
              WHERE r.status = 'approved' AND r.legal_entity_id IN (" . implode(',', $les) . ') ORDER BY r.approved_at LIMIT 200'
        );
    }

    /**
     * Issues an approved request: quantities may be lowered (a note is then required), never above what was approved or
     * what is on hand. Debits the ledger and releases the whole reservation in one transaction; issued once only.
     *
     * @param array<string, mixed> $qty item_pid => quantity issued
     */
    public function issue(Authorizer $az, string $pid, array $qty, string $note): string
    {
        $db = $this->app->db();
        $head = $db->one('SELECT * FROM rq_requests WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $le = (int) $head['legal_entity_id'];
        $this->require($az, 'requisition.issue', $le);
        if ((int) $head['requester_id'] === $az->userId) {
            throw new BusinessException('requisition.error.own_issue', [], 409); // maker ≠ checker
        }
        $note = trim($note);
        $status = $db->transactional(function () use ($db, $head, $le, $qty, $note, $az): string {
            $r = $db->one('SELECT * FROM rq_requests WHERE id = ? FOR UPDATE', [$head['id']]);
            if ($r === null || $r['status'] !== 'approved') {
                throw new BusinessException('requisition.error.already_issued', [], 409);
            }
            $lines = $db->all('SELECT l.*, i.public_id AS item_pid, i.code FROM rq_lines l JOIN rq_items i ON i.id = l.item_id WHERE l.request_id = ?', [$r['id']]);
            $e = [];
            $plan = [];
            $less = false;
            foreach ($lines as $l) {
                $v = trim((string) ($qty[$l['item_pid']] ?? $l['qty']));
                if (!ctype_digit($v) || (int) $v > (int) $l['qty']) {
                    $e['qty_' . $l['item_pid']] = 'requisition.error.issue_qty';
                    continue;
                }
                $plan[] = [$l, (int) $v];
                $less = $less || (int) $v < (int) $l['qty'];
            }
            if ($less && mb_strlen($note) < 3) {
                $e['issue_note'] = 'requisition.error.issue_note';
            }
            if ($e !== []) {
                throw new ValidationException($e);
            }
            if (array_sum(array_map(static fn (array $p): int => $p[1], $plan)) === 0) {
                throw new ValidationException(['issue_note' => 'requisition.error.issue_nothing']);
            }
            $this->release($r);
            foreach ($plan as [$l, $v]) {
                if ($v > 0) {
                    $this->move($le, (int) $l['item_id'], (int) $r['site_id'], 'issue', $v, (int) $r['id'], (string) $r['ref'], $az->userId);
                }
                $db->run('UPDATE rq_lines SET qty_issued = ? WHERE id = ?', [$v, $l['id']]);
            }
            $status = $less ? 'partial' : 'fulfilled';
            $db->run(
                'UPDATE rq_requests SET status = ?, issued_at = ?, issued_by = ?, issue_note = ?, pickup_code = NULL, updated_at = ?, row_version = row_version + 1 WHERE id = ?',
                [$status, $this->now(), $az->userId, $note === '' ? null : mb_substr($note, 0, 500), $this->stamp(), $r['id']]
            );
            $this->event((int) $r['id'], $status === 'partial' ? 'issued_partial' : 'issued', $az->userId, $note === '' ? null : $note);
            return $status;
        });
        $this->app->notifier()->notify((int) $head['requester_id'], 'requisition.issued', 'requisition.notify.issued', ['ref' => (string) $head['ref']], '/requisition/r/' . $pid, 'rq.iss:' . $pid);
        $this->app->audit()->record('requisition.issued', 'success', 'rq_request', $pid, $le, null, ['status' => $status]);
        foreach ($db->all('SELECT item_id FROM rq_lines WHERE request_id = ?', [$head['id']]) as $l) {
            $this->lowStock($le, (int) $l['item_id'], (int) $head['site_id']);
        }
        return $status;
    }

    /** The issuer refuses an approved request at the desk (reason required) — the reservation is released. */
    public function refuse(Authorizer $az, string $pid, string $reason): void
    {
        $db = $this->app->db();
        $head = $db->one('SELECT * FROM rq_requests WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $this->require($az, 'requisition.issue', (int) $head['legal_entity_id']);
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new ValidationException(['refuse_reason' => 'requisition.error.reason']);
        }
        $db->transactional(function () use ($db, $head, $az, $reason): void {
            $r = $db->one('SELECT * FROM rq_requests WHERE id = ? FOR UPDATE', [$head['id']]);
            if ($r === null || $r['status'] !== 'approved') {
                throw new BusinessException('requisition.error.already_issued', [], 409);
            }
            $this->release($r);
            $db->run("UPDATE rq_requests SET status = 'rejected', pickup_code = NULL, reason = ?, closed_at = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?", [mb_substr($reason, 0, 500), $this->now(), $this->stamp(), $r['id']]);
            $this->event((int) $r['id'], 'refused', $az->userId, $reason);
        });
        $this->app->notifier()->notify((int) $head['requester_id'], 'requisition.issued', 'requisition.notify.refused', ['ref' => (string) $head['ref']], '/requisition/r/' . $pid, 'rq.ref:' . $pid);
        $this->app->audit()->record('requisition.refused', 'success', 'rq_request', $pid, (int) $head['legal_entity_id']);
    }

    // ---- housekeeping ---------------------------------------------------------------------------------------------------

    /** Approved requests not picked up within the company's days expire and release their reservation (no days = never). */
    public function tick(): int
    {
        $db = $this->app->db();
        $n = 0;
        foreach ($db->all('SELECT legal_entity_id, expire_days FROM rq_policies WHERE expire_days IS NOT NULL') as $p) {
            $cut = $this->nowLocal()->modify('-' . (int) $p['expire_days'] . ' days')->format('Y-m-d H:i:s');
            foreach ($db->all("SELECT id, public_id, ref, requester_id FROM rq_requests WHERE legal_entity_id = ? AND status = 'approved' AND approved_at < ? LIMIT 500", [$p['legal_entity_id'], $cut]) as $x) {
                $done = $db->transactional(function () use ($db, $x): bool {
                    $r = $db->one('SELECT * FROM rq_requests WHERE id = ? FOR UPDATE', [$x['id']]);
                    if ($r === null || $r['status'] !== 'approved') {
                        return false;
                    }
                    $this->release($r);
                    $db->run("UPDATE rq_requests SET status = 'expired', pickup_code = NULL, closed_at = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$this->now(), $this->stamp(), $r['id']]);
                    $this->event((int) $r['id'], 'expired', null, null);
                    return true;
                });
                if ($done) {
                    $this->app->notifier()->notify((int) $x['requester_id'], 'requisition.issued', 'requisition.notify.expired', ['ref' => (string) $x['ref']], '/requisition/r/' . $x['public_id'], 'rq.exp:' . $x['public_id']);
                    $n++;
                }
            }
        }
        return $n;
    }

    public static function tickJob(App $app): string
    {
        return 'requisition: ' . self::of($app)->tick();
    }

    // ---- lists and reports --------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> my requests (not the cart) */
    public function mine(Authorizer $az): array
    {
        return $this->app->db()->all(
            "SELECT r.public_id, r.ref, r.status, r.submitted_at, r.over_quota, s.code AS site_code, (SELECT COUNT(*) FROM rq_lines l WHERE l.request_id = r.id) AS line_count
               FROM rq_requests r JOIN sites s ON s.id = r.site_id WHERE r.requester_id = ? AND r.status <> 'draft' ORDER BY r.id DESC LIMIT 100",
            [$az->userId]
        );
    }

    /**
     * Stock of a site, quota use of the current periods, requests and movements of a month.
     *
     * @return array<string, mixed>
     */
    public function report(Authorizer $az, int $le, ?int $siteId, string $month): array
    {
        $this->require($az, 'requisition.manage', $le);
        $db = $this->app->db();
        $from = $month . '-01 00:00:00';
        $to = (DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01') ?: $this->nowLocal())->modify('+1 month')->format('Y-m-d') . ' 00:00:00';
        $siteSql = $siteId !== null ? ' AND r.site_id = ' . $siteId : '';
        $requests = $db->all(
            "SELECT r.public_id, r.ref, r.status, r.submitted_at, r.issued_at, r.over_quota, s.code AS site_code, d.code AS dept_code, COALESCE(u.display_name, u.username) AS requester_name,
                    (SELECT COUNT(*) FROM rq_lines l WHERE l.request_id = r.id) AS line_count, (SELECT COALESCE(SUM(l.qty_issued), 0) FROM rq_lines l WHERE l.request_id = r.id) AS issued
               FROM rq_requests r JOIN sites s ON s.id = r.site_id JOIN users u ON u.id = r.requester_id LEFT JOIN departments d ON d.id = r.department_id
              WHERE r.legal_entity_id = ? AND r.status <> 'draft' AND r.submitted_at >= ? AND r.submitted_at < ?{$siteSql} ORDER BY r.submitted_at DESC LIMIT 2000",
            [$le, $from, $to]
        );
        $byStatus = [];
        foreach ($requests as $r) {
            $byStatus[$r['status']] = ($byStatus[$r['status']] ?? 0) + 1;
        }
        $quota = [];
        $today = $this->today();
        foreach ($db->all('SELECT q.*, d.code AS dept_code, i.code AS item_code, i.name AS item_name, i.unit FROM rq_quotas q JOIN departments d ON d.id = q.department_id JOIN rq_items i ON i.id = q.item_id WHERE q.legal_entity_id = ? AND q.valid_from <= ? AND (q.valid_to IS NULL OR q.valid_to >= ?) ORDER BY d.code, i.code', [$le, $today, $today]) as $q) {
            [$a, $b] = $this->period((string) $q['period'], $today);
            $used = $this->used((int) $q['department_id'], (int) $q['item_id'], $a, $b);
            $quota[] = $q + ['used' => $used, 'left' => max(0, (int) $q['qty'] - $used), 'from' => $a, 'to' => $b];
        }
        return [
            'stock' => $siteId !== null ? $this->items($le, $siteId, [], null, false) : [],
            'requests' => $requests,
            'by_status' => $byStatus,
            'quota' => $quota,
            'ledger' => $this->ledger($le, $siteId, $month, 2000),
        ];
    }

    /** @return array{bytes: string, name: string} */
    public function export(Authorizer $az, int $le, ?int $siteId, string $month): array
    {
        $r = $this->report($az, $le, $siteId, $month);
        $t = $this->app->translator();
        $code = (string) $this->app->db()->scalar('SELECT code FROM legal_entities WHERE id = ?', [$le]);
        $site = $siteId !== null ? (string) $this->app->db()->scalar('SELECT code FROM sites WHERE id = ?', [$siteId]) : '';
        $sheets = [];
        if ($siteId !== null) {
            $sheets['stock'] = ['headers' => [$t->get('requisition.field.code'), $t->get('requisition.field.name'), $t->get('requisition.field.unit'), $t->get('requisition.col.on_hand'), $t->get('requisition.col.reserved'), $t->get('requisition.col.available'), $t->get('requisition.field.reorder_level')],
                'rows' => array_map(static fn (array $x): array => [(string) $x['code'], (string) $x['name'], (string) $x['unit'], (string) (int) $x['on_hand'], (string) (int) $x['reserved'], (string) (int) $x['available'], (string) ($x['reorder_level'] ?? '')], $r['stock']),
                'numeric' => [3, 4, 5, 6], 'widths' => [14, 36, 10, 10, 10, 10, 12]];
        }
        $sheets['movements'] = ['headers' => [$t->get('requisition.col.when'), $t->get('requisition.field.site'), $t->get('requisition.field.code'), $t->get('requisition.field.name'), $t->get('requisition.col.kind'), $t->get('requisition.col.qty'), $t->get('requisition.col.balance'), $t->get('requisition.col.ref'), $t->get('requisition.field.note')],
            'rows' => array_map(static fn (array $x): array => [(string) $x['at_local'], (string) $x['site_code'], (string) $x['code'], (string) $x['name'], $t->get('requisition.kind.' . $x['kind']), (string) (int) $x['qty'], (string) (int) $x['balance_after'], (string) ($x['ref'] ?? ''), (string) ($x['note'] ?? '')], $r['ledger']),
            'numeric' => [5, 6], 'widths' => [18, 10, 14, 32, 12, 8, 10, 14, 30]];
        $sheets['requests'] = ['headers' => [$t->get('requisition.col.ref'), $t->get('requisition.col.submitted'), $t->get('requisition.field.site'), $t->get('requisition.col.department'), $t->get('requisition.col.requester'), $t->get('requisition.col.lines'), $t->get('requisition.col.issued_qty'), $t->get('requisition.col.status'), $t->get('requisition.col.over_quota')],
            'rows' => array_map(static fn (array $x): array => [(string) $x['ref'], (string) $x['submitted_at'], (string) $x['site_code'], (string) ($x['dept_code'] ?? ''), (string) $x['requester_name'], (string) (int) $x['line_count'], (string) (int) $x['issued'], $t->get('requisition.status.' . $x['status']), (int) $x['over_quota'] === 1 ? '✓' : ''], $r['requests']),
            'numeric' => [5, 6], 'widths' => [14, 18, 10, 12, 26, 8, 10, 16, 8]];
        $sheets['quota'] = ['headers' => [$t->get('requisition.col.department'), $t->get('requisition.field.code'), $t->get('requisition.field.name'), $t->get('requisition.col.quota'), $t->get('requisition.col.used'), $t->get('requisition.col.left'), $t->get('requisition.col.period')],
            'rows' => array_map(static fn (array $x): array => [(string) $x['dept_code'], (string) $x['item_code'], (string) $x['item_name'], (string) (int) $x['qty'], (string) $x['used'], (string) $x['left'], $x['from'] . ' – ' . $x['to']], $r['quota']),
            'numeric' => [3, 4, 5], 'widths' => [12, 14, 32, 10, 10, 10, 24]];
        $bytes = Xlsx::write($sheets, 'Blue OS office supplies');
        $this->app->audit()->record('requisition.report_exported', 'success', 'rq_report', $code . '-' . $month, $le, null, ['site' => $site, 'sha256' => hash('sha256', $bytes)]);
        return ['bytes' => $bytes, 'name' => 'supplies-' . $code . ($site !== '' ? '-' . $site : '') . '-' . $month . '.xlsx'];
    }

    private function event(int $requestId, string $event, ?int $actor, ?string $note): void
    {
        $this->app->db()->run('INSERT INTO rq_events (request_id, event, actor_id, note, created_at) VALUES (?, ?, ?, ?, ?)', [$requestId, $event, $actor, $note === null ? null : mb_substr($note, 0, 500), $this->stamp()]);
    }
}
