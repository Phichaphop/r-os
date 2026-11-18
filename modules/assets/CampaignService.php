<?php

declare(strict_types=1);

namespace Blue\Modules\Assets;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\Xlsx;
use Blue\Core\Support\Ulid;

/**
 * M42 physical verification campaigns (9C-2 · DEC-95, spec 6.23.3).
 *
 * A campaign is set up (name, cutoff date, optional site/type scope, a cost from which a second independent count is
 * required), then started: the scope is frozen as a snapshot of what the register expected (assets registered by the
 * cutoff, not lost or disposed) so the result can be reproduced. Counters scan the QR (or type the code) and record what
 * they see; the result is matched / other location / custodian mismatch / condition mismatch / tag damaged; a scan of
 * something outside the scope is "unexpected". Duplicate scans are counted once (the item keeps a scan count). Ending the
 * count turns what was not seen into "not found" (never "lost" automatically) and opens a discrepancy case for every
 * difference. Someone other than the counter resolves each case (correct the register with before/after, report lost,
 * custody handled, registered, no change — always with a note). Closing needs every case resolved, or a named
 * disposal approver's documented exception. Nothing here writes off or posts anything to accounting.
 */
final class CampaignService
{
    public const RESULTS = ['pending', 'matched', 'other_location', 'custodian_mismatch', 'condition_mismatch', 'tag_damaged', 'not_found', 'unexpected', 'not_applicable'];

    /** resolutions allowed per case kind */
    public const RESOLUTIONS = [
        'other_location' => ['master_corrected', 'no_change'],
        'condition_mismatch' => ['master_corrected', 'no_change'],
        'tag_damaged' => ['master_corrected', 'no_change'],
        'custodian_mismatch' => ['custody_handled', 'no_change'],
        'not_found' => ['reported_lost', 'no_change'],
        'unexpected' => ['registered', 'no_change'],
    ];

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    private function assets(): AssetService
    {
        return AssetService::of($this->app);
    }

    private function now(): string
    {
        return $this->assets()->nowLocal()->format('Y-m-d H:i:s');
    }

    private function stamp(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    private function require(Authorizer $az, string $perm, int $le): void
    {
        if (!$az->scope($perm)->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
    }

    // ---- set up ---------------------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function campaigns(int $le): array
    {
        return $this->app->db()->all(
            "SELECT c.*, s.code AS site_code, t.name AS type_name, (SELECT COUNT(*) FROM as_campaign_items i WHERE i.campaign_id = c.id AND i.in_scope = 1) AS scope_count,
                    (SELECT COUNT(*) FROM as_cases x WHERE x.campaign_id = c.id AND x.status = 'open') AS open_cases
               FROM as_campaigns c LEFT JOIN sites s ON s.id = c.scope_site_id LEFT JOIN as_types t ON t.id = c.scope_type_id WHERE c.legal_entity_id = ? ORDER BY c.id DESC",
            [$le]
        );
    }

    /** @param array<string, mixed> $in name, cutoff_date, site, type, double_cost */
    public function create(Authorizer $az, int $le, array $in): string
    {
        $this->require($az, 'assets.manage', $le);
        $db = $this->app->db();
        $e = [];
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 160) {
            $e['campaign_name'] = 'assets.error.name';
        }
        $cutoff = (string) ($in['cutoff_date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $cutoff) !== 1 || \DateTimeImmutable::createFromFormat('!Y-m-d', $cutoff) === false) {
            $e['cutoff_date'] = 'assets.error.date';
        } elseif ($cutoff > $this->assets()->today()) {
            $e['cutoff_date'] = 'assets.error.cutoff_future';
        }
        $site = null;
        if (($in['site'] ?? '') !== '') {
            $site = $db->scalar('SELECT id FROM sites WHERE public_id = ? AND legal_entity_id = ?', [(string) $in['site'], $le]);
            if ($site === null) {
                $e['scope_site'] = 'assets.error.site';
            }
        }
        $type = null;
        if (($in['type'] ?? '') !== '') {
            $type = $db->scalar('SELECT id FROM as_types WHERE public_id = ? AND legal_entity_id = ?', [(string) $in['type'], $le]);
            if ($type === null) {
                $e['scope_type'] = 'assets.error.type';
            }
        }
        $double = str_replace(',', '', trim((string) ($in['double_cost'] ?? '')));
        if ($double !== '' && preg_match('/^\d{1,12}(\.\d{1,2})?$/', $double) !== 1) {
            $e['double_cost'] = 'assets.error.amount';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $pid = Ulid::generate($this->app->clock);
        $now = $this->stamp();
        $db->run(
            'INSERT INTO as_campaigns (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, name, cutoff_date, scope_site_id, scope_type_id, double_cost) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$pid, $now, $az->userId, $now, $az->userId, $le, $name, $cutoff, $site, $type, $double === '' ? null : $double]
        );
        $this->app->audit()->record('assets.campaign_created', 'success', 'as_campaign', $pid, $le, null, ['cutoff' => $cutoff, 'site' => $site, 'type' => $type]);
        return $pid;
    }

    /** @return array<string, mixed> */
    public function row(string $pid): array
    {
        return $this->app->db()->one('SELECT * FROM as_campaigns WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
    }

    /**
     * Freezes the scope: every asset registered by the cutoff, in the site/type scope, not lost or disposed — with what the
     * register expected at that moment.
     */
    public function start(Authorizer $az, string $pid): int
    {
        $c = $this->row($pid);
        $le = (int) $c['legal_entity_id'];
        $this->require($az, 'assets.manage', $le);
        $db = $this->app->db();
        return $db->transactional(function () use ($db, $c, $az, $le, $pid): int {
            $x = $db->one('SELECT status FROM as_campaigns WHERE id = ? FOR UPDATE', [$c['id']]);
            if ($x === null || $x['status'] !== 'draft') {
                throw new BusinessException('assets.error.campaign_state', [], 409);
            }
            $where = ["a.legal_entity_id = ?", "a.status NOT IN ('lost','disposed')", 'a.created_at < ?'];
            $p = [$le, (\DateTimeImmutable::createFromFormat('!Y-m-d', (string) $c['cutoff_date'], $this->assets()->tz()) ?: $this->assets()->nowLocal())->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')];
            if ($c['scope_site_id'] !== null) {
                $where[] = 'a.site_id = ?';
                $p[] = (int) $c['scope_site_id'];
            }
            if ($c['scope_type_id'] !== null) {
                $where[] = 'a.type_id = ?';
                $p[] = (int) $c['scope_type_id'];
            }
            $rows = $db->all(
                'SELECT a.id, a.code, a.name, a.site_id, s.code AS site_code, a.location, a.custodian_id, COALESCE(u.display_name, u.username) AS custodian_name, a.asset_condition, a.status, a.cost
                   FROM as_assets a JOIN sites s ON s.id = a.site_id LEFT JOIN users u ON u.id = a.custodian_id WHERE ' . implode(' AND ', $where) . ' ORDER BY a.code',
                $p
            );
            foreach ($rows as $a) {
                $double = $c['double_cost'] !== null && $a['cost'] !== null && (float) $a['cost'] >= (float) $c['double_cost'];
                $expected = ['code' => $a['code'], 'name' => $a['name'], 'site_id' => (int) $a['site_id'], 'site' => $a['site_code'], 'location' => $a['location'],
                    'custodian_id' => $a['custodian_id'] === null ? null : (int) $a['custodian_id'], 'custodian' => $a['custodian_name'], 'condition' => $a['asset_condition'], 'status' => $a['status']];
                $db->run('INSERT INTO as_campaign_items (campaign_id, asset_id, in_scope, expected_json, double_needed) VALUES (?, ?, 1, ?, ?)', [$c['id'], $a['id'], json_encode($expected, JSON_UNESCAPED_UNICODE), $double ? 1 : 0]);
            }
            $db->run("UPDATE as_campaigns SET status = 'counting', started_at = ?, started_by = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$this->now(), $az->userId, $this->stamp(), $c['id']]);
            $this->app->audit()->record('assets.campaign_started', 'success', 'as_campaign', $pid, $le, null, ['scope' => count($rows)]);
            return count($rows);
        });
    }

    // ---- counting ------------------------------------------------------------------------------------------------------------

    /**
     * What a scan or typed code points to: the asset, and its item when it is in the campaign.
     *
     * @return array{asset: ?array<string, mixed>, item: ?array<string, mixed>, input: string, custodian_username: string}
     */
    public function lookup(Authorizer $az, string $pid, string $input): array
    {
        $c = $this->row($pid);
        $this->require($az, 'assets.count', (int) $c['legal_entity_id']);
        $in = trim($input);
        if (preg_match('#/assets/q/([0-9a-f]{32})#', $in, $m) === 1) {
            $in = $m[1];
        }
        $db = $this->app->db();
        $asset = preg_match('/^[0-9a-f]{32}$/', $in) === 1
            ? $db->one('SELECT * FROM as_assets WHERE qr_token = ? AND legal_entity_id = ?', [$in, $c['legal_entity_id']])
            : $db->one('SELECT * FROM as_assets WHERE (code = ? OR serial_no = ?) AND legal_entity_id = ?', [strtoupper($in), $in, $c['legal_entity_id']]);
        $item = $asset === null ? null : $db->one('SELECT * FROM as_campaign_items WHERE campaign_id = ? AND asset_id = ?', [$c['id'], $asset['id']]);
        // the custodian the register expects, as a user name the counter can keep or change
        $exp = $item !== null && $item['expected_json'] !== null ? (array) json_decode((string) $item['expected_json'], true) : [];
        $cust = array_key_exists('custodian_id', $exp) ? $exp['custodian_id'] : ($asset['custodian_id'] ?? null);
        $user = $cust === null ? '' : (string) ($db->scalar('SELECT username FROM users WHERE id = ?', [$cust]) ?? '');
        return ['asset' => $asset, 'item' => $item, 'input' => mb_substr($in, 0, 160), 'custodian_username' => $user];
    }

    /**
     * Records what a counter saw. In scope: the result follows from expected vs observed; outside: "unexpected". A repeat
     * scan by the same counter replaces the observation and is counted once (scans + 1); an item that needs a second count
     * takes it from a different person.
     *
     * @param array<string, mixed> $in asset (public id) or label (unknown thing), site, location, who, condition, tag_ok, note
     * @return array{result: string, duplicate: bool, second: bool}
     */
    public function record(Authorizer $az, string $pid, array $in): array
    {
        $c = $this->row($pid);
        $le = (int) $c['legal_entity_id'];
        $this->require($az, 'assets.count', $le);
        if ($c['status'] !== 'counting') {
            throw new BusinessException('assets.error.campaign_state', [], 409);
        }
        $db = $this->app->db();
        $e = [];
        $asset = ($in['asset'] ?? '') !== '' ? $db->one('SELECT * FROM as_assets WHERE public_id = ? AND legal_entity_id = ?', [(string) $in['asset'], $le]) : null;
        $label = trim((string) ($in['label'] ?? ''));
        if ($asset === null && (mb_strlen($label) < 2 || mb_strlen($label) > 160)) {
            $e['label'] = 'assets.error.unexpected_label';
        }
        $site = $db->scalar('SELECT id FROM sites WHERE public_id = ? AND legal_entity_id = ?', [(string) ($in['site'] ?? ''), $le]);
        if ($site === null) {
            $e['obs_site'] = 'assets.error.site';
        }
        $location = trim((string) ($in['location'] ?? ''));
        $who = null;
        if (trim((string) ($in['who'] ?? '')) !== '') {
            $who = $db->scalar(
                "SELECT u.id FROM users u LEFT JOIN employees e ON e.id = u.employee_id WHERE u.username = ? OR e.employee_code = ? LIMIT 1",
                [trim((string) $in['who']), trim((string) $in['who'])]
            );
            if ($who === null) {
                $e['obs_who'] = 'assets.error.person';
            }
        }
        $cond = (string) ($in['condition'] ?? '');
        if (!in_array($cond, AssetService::CONDITIONS, true)) {
            $e['obs_condition'] = 'assets.error.choice';
        }
        $note = trim((string) ($in['note'] ?? ''));
        if (mb_strlen($location) > 160 || mb_strlen($note) > 500) {
            $e['obs_note'] = 'assets.error.text';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $tagOk = empty($in['tag_damaged']);
        return $db->transactional(function () use ($db, $c, $asset, $label, $site, $location, $who, $cond, $note, $tagOk, $az): array {
            $item = $asset === null ? null : $db->one('SELECT * FROM as_campaign_items WHERE campaign_id = ? AND asset_id = ? FOR UPDATE', [$c['id'], $asset['id']]);
            if ($item === null && $asset === null) {
                $item = $db->one('SELECT * FROM as_campaign_items WHERE campaign_id = ? AND asset_id IS NULL AND unexpected_label = ? FOR UPDATE', [$c['id'], $label]);
            }
            $obs = [(int) $site, $location === '' ? null : $location, $who === null ? null : (int) $who, $cond, $tagOk ? 1 : 0, $note === '' ? null : $note];
            if ($item === null) { // outside the frozen scope
                $db->run(
                    "INSERT INTO as_campaign_items (campaign_id, asset_id, in_scope, result, observed_site_id, observed_location, observed_custodian_id, observed_condition, tag_ok, note, unexpected_label, counted_by, counted_at, scans)
                     VALUES (?, ?, 0, 'unexpected', ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)",
                    [$c['id'], $asset['id'] ?? null, ...$obs, $asset === null ? $label : null, $az->userId, $this->now()]
                );
                return ['result' => 'unexpected', 'duplicate' => false, 'second' => false];
            }
            if ($item['result'] === 'not_applicable') {
                throw new BusinessException('assets.error.item_na', [], 409);
            }
            $second = (int) $item['double_needed'] === 1 && $item['counted_by'] !== null && (int) $item['counted_by'] !== $az->userId && $item['second_by'] === null;
            $duplicate = $item['counted_by'] !== null && !$second;
            $result = (int) $item['in_scope'] === 0 ? 'unexpected' : $this->judge((array) json_decode((string) $item['expected_json'], true), (int) $site, $location, $who === null ? null : (int) $who, $cond, $tagOk);
            if ($second) {
                // the independent second count must agree, otherwise the item becomes the stricter (non-matched) result
                $result = $result === 'matched' ? (string) $item['result'] : $result;
                $db->run('UPDATE as_campaign_items SET result = ?, second_by = ?, second_at = ?, scans = scans + 1 WHERE id = ?', [$result, $az->userId, $this->now(), $item['id']]);
            } else {
                $db->run(
                    'UPDATE as_campaign_items SET result = ?, observed_site_id = ?, observed_location = ?, observed_custodian_id = ?, observed_condition = ?, tag_ok = ?, note = ?, counted_by = COALESCE(counted_by, ?), counted_at = COALESCE(counted_at, ?), scans = scans + 1 WHERE id = ?',
                    [$result, ...$obs, $az->userId, $this->now(), $item['id']]
                );
            }
            return ['result' => $result, 'duplicate' => $duplicate, 'second' => $second];
        });
    }

    /** @param array<string, mixed> $exp */
    private function judge(array $exp, int $site, string $location, ?int $who, string $cond, bool $tagOk): string
    {
        $norm = static fn (?string $s): string => mb_strtolower(trim((string) $s));
        if ((int) ($exp['site_id'] ?? 0) !== $site || ($location !== '' && $norm($location) !== $norm($exp['location'] ?? null))) {
            return 'other_location';
        }
        if (($exp['custodian_id'] ?? null) !== $who) {
            return 'custodian_mismatch';
        }
        if (($exp['condition'] ?? '') !== $cond) {
            return 'condition_mismatch';
        }
        return $tagOk ? 'matched' : 'tag_damaged';
    }

    /** A supply admin takes an item out of the count with a reason (e.g. sent away for repair before the cutoff). */
    public function notApplicable(Authorizer $az, string $pid, int $itemId, string $reason): void
    {
        $c = $this->row($pid);
        $this->require($az, 'assets.manage', (int) $c['legal_entity_id']);
        if ($c['status'] !== 'counting') {
            throw new BusinessException('assets.error.campaign_state', [], 409);
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new ValidationException(['na_reason' => 'assets.error.reason']);
        }
        $n = $this->app->db()->run("UPDATE as_campaign_items SET result = 'not_applicable', note = ?, counted_by = ?, counted_at = ? WHERE id = ? AND campaign_id = ? AND in_scope = 1", [mb_substr(trim($reason), 0, 500), $az->userId, $this->now(), $itemId, $c['id']])->rowCount();
        if ($n === 0) {
            throw HttpException::notFound();
        }
    }

    /**
     * Ends the count: what was not seen becomes "not found" and every difference opens a case. Refused while an item that
     * needs a second count has only one.
     */
    public function finishCounting(Authorizer $az, string $pid): int
    {
        $c = $this->row($pid);
        $le = (int) $c['legal_entity_id'];
        $this->require($az, 'assets.manage', $le);
        $db = $this->app->db();
        return $db->transactional(function () use ($db, $c, $az, $le, $pid): int {
            $x = $db->one('SELECT status FROM as_campaigns WHERE id = ? FOR UPDATE', [$c['id']]);
            if ($x === null || $x['status'] !== 'counting') {
                throw new BusinessException('assets.error.campaign_state', [], 409);
            }
            $missing = (int) $db->scalar("SELECT COUNT(*) FROM as_campaign_items WHERE campaign_id = ? AND double_needed = 1 AND counted_by IS NOT NULL AND second_by IS NULL AND result <> 'not_applicable'", [$c['id']]);
            if ($missing > 0) {
                throw new BusinessException('assets.error.second_count', ['n' => (string) $missing], 409);
            }
            $db->run("UPDATE as_campaign_items SET result = 'not_found' WHERE campaign_id = ? AND result = 'pending'", [$c['id']]);
            $n = 0;
            foreach ($db->all("SELECT * FROM as_campaign_items WHERE campaign_id = ? AND result NOT IN ('matched','not_applicable') ORDER BY id", [$c['id']]) as $i) {
                $now = $this->stamp();
                $db->run(
                    'INSERT INTO as_cases (public_id, created_at, created_by, updated_at, campaign_id, item_id, asset_id, kind) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [Ulid::generate($this->app->clock), $now, $az->userId, $now, $c['id'], $i['id'], $i['asset_id'], $i['result']]
                );
                $n++;
            }
            $db->run("UPDATE as_campaigns SET status = 'reviewing', counting_ended_at = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$this->now(), $this->stamp(), $c['id']]);
            $this->app->audit()->record('assets.campaign_counted', 'success', 'as_campaign', $pid, $le, null, ['cases' => $n]);
            return $n;
        });
    }

    // ---- cases ---------------------------------------------------------------------------------------------------------------

    /**
     * Resolves a discrepancy case. The resolver is never the counter of that item (separation of duties). "Correct the
     * register" writes the observed site/location/condition (or issues a new tag) with before/after in the asset history;
     * "report lost" sets the asset lost (no write-off); the others record what was done outside, with a note.
     */
    public function resolve(Authorizer $az, string $casePid, string $resolution, string $note): void
    {
        $db = $this->app->db();
        $k = $db->one('SELECT x.*, c.legal_entity_id, c.status AS campaign_status, c.public_id AS campaign_pid, c.name AS campaign_name FROM as_cases x JOIN as_campaigns c ON c.id = x.campaign_id WHERE x.public_id = ?', [$casePid])
            ?? throw HttpException::notFound();
        $le = (int) $k['legal_entity_id'];
        $this->require($az, 'assets.manage', $le);
        if ($k['campaign_status'] !== 'reviewing' || $k['status'] !== 'open') {
            throw new BusinessException('assets.error.case_state', [], 409);
        }
        $item = $db->one('SELECT * FROM as_campaign_items WHERE id = ?', [$k['item_id']]) ?? throw HttpException::notFound();
        if (in_array($az->userId, array_map('intval', array_filter([$item['counted_by'], $item['second_by']], static fn (mixed $v): bool => $v !== null)), true)) {
            throw new BusinessException('assets.error.own_count', [], 409);
        }
        if (!in_array($resolution, self::RESOLUTIONS[$k['kind']] ?? [], true)) {
            throw new ValidationException(['resolution' => 'assets.error.choice']);
        }
        if (mb_strlen(trim($note)) < 5) {
            throw new ValidationException(['resolution_note' => 'assets.error.reason']);
        }
        $note = mb_substr(trim($note), 0, 1000);
        $reason = $this->app->translator()->get('assets.campaign_reason', ['name' => (string) $k['campaign_name']]) . ' — ' . $note;
        $db->transactional(function () use ($db, $k, $item, $resolution, $note, $reason, $az): void {
            $before = null;
            $after = null;
            $a = $k['asset_id'] !== null ? $db->one('SELECT * FROM as_assets WHERE id = ? FOR UPDATE', [$k['asset_id']]) : null;
            if ($resolution === 'master_corrected' && $a !== null) {
                $set = [];
                if ($k['kind'] === 'other_location') {
                    $set = ['site_id' => (int) $item['observed_site_id'], 'location' => $item['observed_location'] ?? $a['location']];
                } elseif ($k['kind'] === 'condition_mismatch') {
                    $set = ['asset_condition' => (string) $item['observed_condition']];
                }
                $before = [];
                $after = [];
                foreach ($set as $col => $val) {
                    if ((string) $a[$col] !== (string) $val) {
                        $before[$col === 'site_id' ? 'site' : $col] = $col === 'site_id' ? (string) $db->scalar('SELECT code FROM sites WHERE id = ?', [$a[$col]]) : $a[$col];
                        $after[$col === 'site_id' ? 'site' : $col] = $col === 'site_id' ? (string) $db->scalar('SELECT code FROM sites WHERE id = ?', [$val]) : $val;
                    }
                }
                if ($set !== []) {
                    $db->run('UPDATE as_assets SET ' . implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($set))) . ', updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [...array_values($set), $this->stamp(), $az->userId, $a['id']]);
                }
                if ($k['kind'] === 'tag_damaged') {
                    $db->run('UPDATE as_assets SET qr_token = ?, updated_at = ? WHERE id = ?', [bin2hex(random_bytes(16)), $this->stamp(), $a['id']]);
                    $after = ['tag' => 'reissued'];
                }
                $this->move((int) $a['id'], $k['kind'] === 'tag_damaged' ? 'tag_reissued' : 'verification', $before, $after, $reason, $az->userId);
            } elseif ($resolution === 'reported_lost' && $a !== null) {
                if (!in_array($a['status'], ['available', 'assigned', 'maintenance'], true)) {
                    throw new BusinessException('assets.error.status', [], 409);
                }
                if ($a['status'] === 'assigned') {
                    $db->run('UPDATE as_custody SET returned_at = ?, returned_by = ? WHERE asset_id = ? AND returned_at IS NULL', [$this->now(), $az->userId, $a['id']]);
                }
                $db->run("UPDATE as_assets SET status = 'lost', custodian_id = NULL, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$this->stamp(), $az->userId, $a['id']]);
                $before = ['status' => $a['status']];
                $after = ['status' => 'lost'];
                $this->move((int) $a['id'], 'status', $before, $after, $reason, $az->userId);
            }
            $db->run(
                "UPDATE as_cases SET status = 'resolved', resolution = ?, resolution_note = ?, before_json = ?, after_json = ?, resolved_by = ?, resolved_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ? AND status = 'open'",
                [$resolution, $note, $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE), $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE), $az->userId, $this->now(), $this->stamp(), $az->userId, $k['id']]
            );
        });
        $this->app->audit()->record('assets.case_resolved', 'success', 'as_case', $casePid, $le, null, ['kind' => $k['kind'], 'resolution' => $resolution]);
    }

    /**
     * @param ?array<string, mixed> $before
     * @param ?array<string, mixed> $after
     */
    private function move(int $assetId, string $kind, ?array $before, ?array $after, string $reason, int $actor): void
    {
        $this->app->db()->run(
            'INSERT INTO as_moves (asset_id, kind, before_json, after_json, reason, actor_id, at_local, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$assetId, $kind, $before === null || $before === [] ? null : json_encode($before, JSON_UNESCAPED_UNICODE), $after === null || $after === [] ? null : json_encode($after, JSON_UNESCAPED_UNICODE), mb_substr($reason, 0, 500), $actor, $this->now(), $this->stamp()]
        );
    }

    /**
     * Closes the campaign. Open cases block it unless a disposal approver (named, not the person who started the
     * campaign) accepts a documented exception. The report's hash is kept so the exported pack can be checked later.
     */
    public function close(Authorizer $az, string $pid, string $exception): void
    {
        $c = $this->row($pid);
        $le = (int) $c['legal_entity_id'];
        $db = $this->app->db();
        $open = (int) $db->scalar("SELECT COUNT(*) FROM as_cases WHERE campaign_id = ? AND status = 'open'", [$c['id']]);
        $exception = trim($exception);
        if ($open > 0) {
            if (!$az->scope('assets.dispose')->coversLegalEntity($le) || (int) $c['started_by'] === $az->userId) {
                throw new BusinessException('assets.error.open_cases', ['n' => (string) $open], 409);
            }
            if (mb_strlen($exception) < 10) {
                throw new ValidationException(['exception_note' => 'assets.error.exception_note']);
            }
        } else {
            $this->require($az, 'assets.manage', $le);
        }
        if ($c['status'] !== 'reviewing') {
            throw new BusinessException('assets.error.campaign_state', [], 409);
        }
        $db->run(
            "UPDATE as_campaigns SET status = 'closed', closed_at = ?, closed_by = ?, exception_note = ?, exception_by = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ? AND status = 'reviewing'",
            [$this->now(), $az->userId, $open > 0 ? mb_substr($exception, 0, 1000) : null, $open > 0 ? $az->userId : null, $this->stamp(), $c['id']]
        );
        $hash = hash('sha256', $this->export($az, $pid, true)['bytes']);
        $db->run('UPDATE as_campaigns SET report_sha256 = ? WHERE id = ?', [$hash, $c['id']]);
        $this->app->audit()->record('assets.campaign_closed', 'success', 'as_campaign', $pid, $le, null, ['open_cases' => $open, 'exception' => $open > 0, 'sha256' => $hash]);
    }

    // ---- view and report -----------------------------------------------------------------------------------------------------

    /**
     * A campaign with its items, cases and figures (coverage = counted ÷ items in scope that apply; matched % of counted).
     *
     * @return array<string, mixed>
     */
    public function campaign(Authorizer $az, string $pid): array
    {
        $db = $this->app->db();
        $c = $db->one(
            'SELECT c.*, s.code AS site_code, t.name AS type_name, COALESCE(u.display_name, u.username) AS started_name, COALESCE(x.display_name, x.username) AS exception_name
               FROM as_campaigns c LEFT JOIN sites s ON s.id = c.scope_site_id LEFT JOIN as_types t ON t.id = c.scope_type_id LEFT JOIN users u ON u.id = c.started_by LEFT JOIN users x ON x.id = c.exception_by WHERE c.public_id = ?',
            [$pid]
        ) ?? throw HttpException::notFound();
        $le = (int) $c['legal_entity_id'];
        if (!$az->scope('assets.view')->coversLegalEntity($le) && !$az->scope('assets.count')->coversLegalEntity($le)) {
            throw HttpException::notFound();
        }
        $c['can_manage'] = $az->scope('assets.manage')->coversLegalEntity($le);
        $c['can_count'] = $az->scope('assets.count')->coversLegalEntity($le);
        $c['can_except'] = $az->scope('assets.dispose')->coversLegalEntity($le) && (int) $c['started_by'] !== $az->userId;
        $c['items'] = $db->all(
            'SELECT i.*, a.public_id AS asset_pid, a.code, a.name, s.code AS obs_site_code, COALESCE(o.display_name, o.username) AS obs_custodian, COALESCE(k.display_name, k.username) AS counter, COALESCE(k2.display_name, k2.username) AS second_counter
               FROM as_campaign_items i LEFT JOIN as_assets a ON a.id = i.asset_id LEFT JOIN sites s ON s.id = i.observed_site_id LEFT JOIN users o ON o.id = i.observed_custodian_id
               LEFT JOIN users k ON k.id = i.counted_by LEFT JOIN users k2 ON k2.id = i.second_by WHERE i.campaign_id = ? ORDER BY i.in_scope DESC, a.code, i.id',
            [$c['id']]
        );
        $c['cases'] = $db->all(
            'SELECT x.*, a.public_id AS asset_pid, a.code, a.name, i.unexpected_label, i.counted_by, i.second_by, COALESCE(u.display_name, u.username) AS resolver FROM as_cases x JOIN as_campaign_items i ON i.id = x.item_id
               LEFT JOIN as_assets a ON a.id = x.asset_id LEFT JOIN users u ON u.id = x.resolved_by WHERE x.campaign_id = ? ORDER BY x.status, x.id',
            [$c['id']]
        );
        foreach ($c['cases'] as &$k) { // the counter of an item may not resolve its case (separation of duties)
            $k['counted_by_me'] = in_array($az->userId, array_map('intval', array_filter([$k['counted_by'], $k['second_by']], static fn (mixed $v): bool => $v !== null)), true);
        }
        unset($k);
        $c['stats'] = $this->stats($c['items']);
        return $c;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public function stats(array $items): array
    {
        $by = array_fill_keys(self::RESULTS, 0);
        $scope = 0;
        $na = 0;
        $counted = 0;
        $double = 0;
        $doubleDone = 0;
        foreach ($items as $i) {
            $by[$i['result']]++;
            if ((int) $i['in_scope'] !== 1) {
                continue;
            }
            $scope++;
            if ($i['result'] === 'not_applicable') {
                $na++;
            } elseif (!in_array($i['result'], ['pending', 'not_found'], true)) {
                $counted++;
            }
            if ((int) $i['double_needed'] === 1) {
                $double++;
                $doubleDone += $i['second_by'] !== null ? 1 : 0;
            }
        }
        $base = $scope - $na;
        return ['by' => $by, 'scope' => $scope, 'applicable' => $base, 'counted' => $counted, 'not_applicable' => $na, 'double' => $double, 'double_done' => $doubleDone,
            'coverage' => $base > 0 ? round($counted * 100 / $base, 1) : null, 'matched_pct' => $counted > 0 ? round($by['matched'] * 100 / $counted, 1) : null];
    }

    /**
     * The audit pack: scope and cutoff, method, figures, every item (expected vs observed), the discrepancy log with
     * resolutions, exception, report version.
     *
     * @return array{bytes: string, name: string}
     */
    public function export(Authorizer $az, string $pid, bool $silent = false): array
    {
        $c = $this->campaign($az, $pid);
        $t = $this->app->translator();
        $s = $c['stats'];
        $res = static fn (string $r): string => $t->get('assets.result.' . $r);
        $summary = [
            [$t->get('assets.field.campaign_name'), (string) $c['name']],
            [$t->get('assets.field.cutoff_date'), (string) $c['cutoff_date']],
            [$t->get('assets.col.scope'), ($c['site_code'] ?? $t->get('assets.all')) . ' · ' . ($c['type_name'] ?? $t->get('assets.all'))],
            [$t->get('assets.field.double_cost'), $c['double_cost'] === null ? '—' : (string) $c['double_cost']],
            [$t->get('assets.col.method'), $t->get('assets.method_text')],
            [$t->get('assets.col.in_scope'), (string) $s['scope']],
            [$t->get('assets.result.not_applicable'), (string) $s['not_applicable']],
            [$t->get('assets.col.counted'), (string) $s['counted']],
            [$t->get('assets.col.coverage'), $s['coverage'] === null ? '—' : $s['coverage'] . '%'],
            [$t->get('assets.col.matched_pct'), $s['matched_pct'] === null ? '—' : $s['matched_pct'] . '%'],
            [$t->get('assets.col.status'), $t->get('assets.campaign_status.' . $c['status'])],
            [$t->get('assets.field.exception_note'), (string) ($c['exception_note'] ?? '—') . ($c['exception_name'] !== null ? ' — ' . $c['exception_name'] : '')],
            [$t->get('assets.col.version'), 'Blue OS ' . \Blue\Core\App::RELEASE],
        ];
        foreach (self::RESULTS as $r) {
            $summary[] = [$res($r), (string) $s['by'][$r]];
        }
        $items = array_map(static function (array $i) use ($res): array {
            $x = (array) json_decode((string) ($i['expected_json'] ?? '{}'), true);
            return [(string) ($i['code'] ?? $i['unexpected_label'] ?? ''), (string) ($i['name'] ?? ''), (int) $i['in_scope'] === 1 ? 'Y' : 'N', (string) ($x['site'] ?? ''), (string) ($x['location'] ?? ''), (string) ($x['custodian'] ?? ''),
                (string) ($x['condition'] ?? ''), (string) ($i['obs_site_code'] ?? ''), (string) ($i['observed_location'] ?? ''), (string) ($i['obs_custodian'] ?? ''), (string) ($i['observed_condition'] ?? ''),
                $res((string) $i['result']), (string) $i['scans'], (string) ($i['counter'] ?? ''), (string) ($i['second_counter'] ?? ''), (string) ($i['counted_at'] ?? ''), (string) ($i['note'] ?? '')];
        }, $c['items']);
        $cases = array_map(static fn (array $k): array => [(string) ($k['code'] ?? $k['unexpected_label'] ?? ''), $res((string) $k['kind']), (string) $k['status'], (string) ($k['resolution'] ?? ''), (string) ($k['resolution_note'] ?? ''),
            (string) ($k['before_json'] ?? ''), (string) ($k['after_json'] ?? ''), (string) ($k['resolver'] ?? ''), (string) ($k['resolved_at'] ?? '')], $c['cases']);
        $bytes = Xlsx::write([
            'summary' => ['headers' => ['', ''], 'rows' => $summary, 'widths' => [30, 60]],
            'items' => ['headers' => ['code', 'name', 'in_scope', 'exp_site', 'exp_location', 'exp_custodian', 'exp_condition', 'obs_site', 'obs_location', 'obs_custodian', 'obs_condition', 'result', 'scans', 'counter', 'second', 'counted_at', 'note'], 'rows' => $items],
            'cases' => ['headers' => ['code', 'kind', 'status', 'resolution', 'note', 'before', 'after', 'resolved_by', 'resolved_at'], 'rows' => $cases],
        ], 'Blue OS asset verification');
        if (!$silent) {
            $this->app->audit()->record('assets.campaign_exported', 'success', 'as_campaign', $pid, (int) $c['legal_entity_id'], null, ['sha256' => hash('sha256', $bytes)]);
        }
        return ['bytes' => $bytes, 'name' => 'asset-verification-' . $c['cutoff_date'] . '-' . substr($pid, -6) . '.xlsx'];
    }
}
