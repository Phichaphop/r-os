<?php

declare(strict_types=1);

namespace Blue\Modules\Filing;

use DateTimeImmutable;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Files\Xlsx;
use Blue\Core\Registry\ResolvedConfig;
use Blue\Core\Support\Money;
use Blue\Core\Support\Ulid;
use Blue\Modules\Payroll\PayrollService;
use Blue\Modules\Payroll\TaxService;

/**
 * M58 government filing of payroll (6E · DEC-88). A filing is one company × kind × month; each generation is a
 * revision: the rows of the approved payroll runs paid in the month (Calculation Ledger), the checks, an independent
 * review (another person with filing.approve), the review workbook export (hash), the submission (date, reference,
 * receipt) and the authority's result. Official upload formats are not part of this release: no layout is assumed.
 *
 * Status of a revision: validation_failed | ready → pending → approved (or returned) → exported → submitted →
 * accepted | rejected; superseded when a newer revision replaces it before it was submitted. After a submission a new
 * revision is an additional filing and needs a reason; the submitted one stays as it was.
 */
final class FilingService
{
    /** statuses a newer revision replaces (nothing was submitted yet) */
    private const REPLACEABLE = ['validation_failed', 'ready', 'returned', 'approved', 'exported'];

    /** statuses that mean the revision went to the authority */
    public const SUBMITTED = ['submitted', 'accepted', 'rejected'];

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    public function today(): string
    {
        return PayrollService::of($this->app)->today();
    }

    /** @return list<int> companies the person covers with a permission */
    public function companies(Authorizer $az, string $perm): array
    {
        return PayrollService::of($this->app)->companies($az, $perm);
    }

    private function require(Authorizer $az, string $perm, int $le): void
    {
        if (!$az->scope($perm)->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
    }

    /** @return array{0: string, 1: string}|null first and last day of a "YYYY-MM" month, or of a "YYYY" year (the annual filing) */
    public static function range(string $period): ?array
    {
        if (preg_match('/^(19|20)\d{2}$/', $period) === 1) {
            return [$period . '-01-01', $period . '-12-31'];
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $period . '-01');
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1 || $d === false) {
            return null;
        }
        return [$d->format('Y-m-d'), $d->modify('last day of this month')->format('Y-m-d')];
    }

    /** the day a month's filing of a kind is due (day N of the next month, by the approved filing due days), or null */
    public function dueDate(int $le, string $kind, string $period): ?string
    {
        if ($kind === 'wht_annual') {
            return null; // no annual due day is kept in the system — taken from the Revenue Department's notice
        }
        $due = TaxService::of($this->app)->filingSetting('filing_due', $le);
        $day = (int) ($due[$kind === 'wht_monthly' ? 'wht_day' : 'sso_day'] ?? 0);
        $r = self::range($period);
        if ($day < 1 || $r === null) {
            return null;
        }
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $r[1]);
        if ($end === false) {
            return null;
        }
        $next = $end->modify('first day of next month');
        $last = (int) $next->format('t');
        return $next->format('Y-m-') . str_pad((string) min($day, $last), 2, '0', STR_PAD_LEFT);
    }

    private function config(string $key, int $le, string $date): ?ResolvedConfig
    {
        $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' 12:00:00', PayrollService::of($this->app)->tz()) ?: $this->app->clock->now();
        return $this->app->registry()->resolveOrNull($key, $le, null, $at);
    }

    // ---- overview ----------------------------------------------------------------------------------------------------------

    /**
     * Months of a year (up to this month) × kind: the latest revision, the due day, approved runs paid in the month.
     *
     * @return list<array<string, mixed>>
     */
    public function overview(Authorizer $az, int $le, int $year): array
    {
        $this->require($az, 'filing.view', $le);
        $db = $this->app->db();
        $today = $this->today();
        $last = (int) substr($today, 0, 4) === $year ? (int) substr($today, 5, 2) : 12;
        $latest = [];
        foreach (
            $db->all(
                "SELECT f.kind, f.period, r.public_id, r.revision_no, r.purpose, r.status, r.people FROM fl_filings f
                   JOIN fl_revisions r ON r.filing_id = f.id AND r.revision_no = (SELECT MAX(x.revision_no) FROM fl_revisions x WHERE x.filing_id = f.id)
                  WHERE f.legal_entity_id = ? AND f.period LIKE ? AND f.kind <> 'wht_annual'",
                [$le, $year . '-%']
            ) as $x
        ) {
            $latest[$x['period'] . '|' . $x['kind']] = $x;
        }
        $done = [];
        foreach (
            $db->all(
                "SELECT f.kind, f.period FROM fl_filings f JOIN fl_revisions r ON r.filing_id = f.id
                  WHERE f.legal_entity_id = ? AND f.period LIKE ? AND r.status IN ('submitted','accepted') GROUP BY f.kind, f.period",
                [$le, $year . '-%']
            ) as $x
        ) {
            $done[$x['period'] . '|' . $x['kind']] = true;
        }
        $runs = [];
        foreach ($db->all("SELECT DATE_FORMAT(pay_date, '%Y-%m') AS m, COUNT(*) AS n FROM pay_runs WHERE legal_entity_id = ? AND status = 'approved' AND YEAR(pay_date) = ? GROUP BY m", [$le, $year]) as $x) {
            $runs[(string) $x['m']] = (int) $x['n'];
        }
        $out = [];
        for ($m = $last; $m >= 1; $m--) {
            $period = sprintf('%04d-%02d', $year, $m);
            $row = ['period' => $period, 'runs' => $runs[$period] ?? 0, 'kinds' => []];
            foreach (FilingCalc::MONTHLY as $k) {
                $due = $this->dueDate($le, $k, $period);
                $isDone = isset($done[$period . '|' . $k]);
                $row['kinds'][$k] = ['latest' => $latest[$period . '|' . $k] ?? null, 'due' => $due, 'done' => $isDone,
                    'overdue' => !$isDone && $due !== null && $today > $due && ($runs[$period] ?? 0) > 0];
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * The annual withholding tax filing of a year (0.47.0): its latest revision, whether one was submitted, how many
     * approved runs were paid in the year and whether the year has ended.
     *
     * @return array{year: int, latest: ?array<string, mixed>, done: bool, runs: int, ended: bool}
     */
    public function annual(Authorizer $az, int $le, int $year): array
    {
        $this->require($az, 'filing.view', $le);
        $db = $this->app->db();
        $latest = $db->one(
            "SELECT r.public_id, r.revision_no, r.purpose, r.status, r.people FROM fl_filings f JOIN fl_revisions r ON r.filing_id = f.id
              WHERE f.legal_entity_id = ? AND f.kind = 'wht_annual' AND f.period = ? ORDER BY r.revision_no DESC LIMIT 1",
            [$le, (string) $year]
        );
        $done = (int) $db->scalar(
            "SELECT COUNT(*) FROM fl_filings f JOIN fl_revisions r ON r.filing_id = f.id WHERE f.legal_entity_id = ? AND f.kind = 'wht_annual' AND f.period = ? AND r.status IN ('submitted','accepted')",
            [$le, (string) $year]
        ) > 0;
        $runs = (int) $db->scalar("SELECT COUNT(*) FROM pay_runs WHERE legal_entity_id = ? AND status = 'approved' AND YEAR(pay_date) = ?", [$le, $year]);
        return ['year' => $year, 'latest' => $latest, 'done' => $done, 'runs' => $runs, 'ended' => $this->today() > $year . '-12-31'];
    }

    /**
     * Approved opening balances of a tax year (what was paid before the company used the system), per employee.
     *
     * @return array<int, array{code: string, name: string, taxable: int, wht: int, refs: list<string>}>
     */
    private function openings(int $le, int $year): array
    {
        $out = [];
        foreach (
            $this->app->db()->all(
                "SELECT y.employee_id, y.public_id, y.taxable_income, y.wht, e.employee_code, e.name_th FROM pay_ytd_entries y JOIN employees e ON e.id = y.employee_id
                  WHERE y.legal_entity_id = ? AND y.tax_year = ? AND y.source_type = 'opening' AND y.status = 'approved' ORDER BY y.id",
                [$le, $year]
            ) as $y
        ) {
            $emp = (int) $y['employee_id'];
            $o = $out[$emp] ?? ['code' => (string) $y['employee_code'], 'name' => (string) $y['name_th'], 'taxable' => 0, 'wht' => 0, 'refs' => []];
            $o['taxable'] += Money::fromDb($y['taxable_income']);
            $o['wht'] += Money::fromDb($y['wht']);
            $o['refs'][] = (string) $y['public_id'] . ':' . $y['taxable_income'] . ':' . $y['wht'];
            $out[$emp] = $o;
        }
        return $out;
    }

    // ---- generate ----------------------------------------------------------------------------------------------------------

    /**
     * The inputs of a filing: the approved runs paid in the month and each person's amounts, identifiers and
     * year-to-date entries, open runs, people employed but not paid, whether the employer details are set.
     *
     * @return array{inputs: array<string, mixed>, configs: list<ResolvedConfig>, source_hash: string}
     */
    private function gather(int $le, string $kind, string $period): array
    {
        [$from, $to] = self::range($period) ?? throw new ValidationException(['period' => 'filing.error.period']);
        $src = PayrollService::of($this->app)->filingSource($le, $from, $to);
        $db = $this->app->db();
        $ledger = $this->app->ledger();
        $runs = [];
        foreach ($src['runs'] as $r) {
            $intact = false;
            if ($r['calc_run_pid'] !== null) {
                $snap = $ledger->open((string) $r['calc_run_pid']); // the stored payroll result still matches its hash
                $intact = $snap['intact'] && hash_equals((string) $r['output_hash'], (string) $snap['run']['output_hash']);
            }
            $runs[] = ['pid' => (string) $r['public_id'], 'run_no' => (int) $r['run_no'], 'run_type' => (string) $r['run_type'], 'pay_date' => (string) $r['pay_date'], 'output_hash' => (string) $r['output_hash'], 'intact' => $intact];
        }
        $annual = $kind === 'wht_annual';
        $open = $annual ? $this->openings($le, (int) $period) : [];
        $all = array_keys($src['people'] + $open);
        $ids = [];
        $addr = [];
        if ($all !== []) {
            $in = implode(',', array_map('intval', $all));
            foreach ($db->all("SELECT employee_id, last4 FROM employee_identifiers WHERE status = 'current' AND id_type IN ('TH_CITIZEN_ID','TH_TAX_ID') AND employee_id IN (" . $in . ') ORDER BY id') as $i) {
                $ids[(int) $i['employee_id']] = (string) $i['last4'];
            }
            if ($annual) {
                foreach ($db->all('SELECT employee_id FROM employee_tax_addresses WHERE employee_id IN (' . $in . ')') as $a) {
                    $addr[(int) $a['employee_id']] = true;
                }
            }
        }
        $people = [];
        foreach ($src['people'] as $emp => $p) {
            $row = ['code' => $p['code'], 'name' => $p['name'], 'id_ok' => isset($ids[$emp]), 'id_last4' => $ids[$emp] ?? '', 'taxable' => $p['taxable'], 'wht' => $p['wht'],
                'sso_ee' => $p['sso_ee'], 'sso_er' => $p['sso_er'], 'sso_base' => $p['sso_base'], 'sso_wage_used' => $p['sso_wage_used'], 'sso_wage_unknown' => $p['sso_wage_unknown'],
                'ytd' => $src['ytd'][$emp] ?? null];
            if ($annual) {
                $row += ['in_runs' => true, 'opening' => isset($open[$emp]) ? ['taxable' => $open[$emp]['taxable'], 'wht' => $open[$emp]['wht']] : null, 'address_ok' => isset($addr[$emp])];
            }
            $people[] = $row;
        }
        foreach (array_diff_key($open, $src['people']) as $emp => $o) {
            $people[] = ['code' => $o['code'], 'name' => $o['name'], 'id_ok' => isset($ids[$emp]), 'id_last4' => $ids[$emp] ?? '', 'taxable' => 0, 'wht' => 0, 'sso_ee' => 0, 'sso_er' => 0, 'sso_base' => 0,
                'sso_wage_used' => 0, 'sso_wage_unknown' => false, 'ytd' => null, 'in_runs' => false, 'opening' => ['taxable' => $o['taxable'], 'wht' => $o['wht']], 'address_ok' => isset($addr[$emp])];
        }
        $notPaid = array_values(array_diff_key($src['employed'], $src['people'], $open));
        sort($notPaid);
        $wht = FilingCalc::isWht($kind);
        $employer = $this->config($wht ? 'payroll.wht_payer' : 'payroll.sso_employer', $le, $to);
        $present = is_array($employer?->value) && (string) ($employer->value[$wht ? 'tax_id' : 'account_no'] ?? '') !== '';
        $dueCfg = $this->config('payroll.filing_due', $le, $to);
        $inputs = ['kind' => $kind, 'period' => $period, 'schema' => FilingCalc::SCHEMA[$kind], 'employer' => ['present' => $present], 'due_date' => $this->dueDate($le, $kind, $period),
            'runs' => $runs, 'open_runs' => array_map(static fn (array $r): array => ['run_no' => (int) $r['run_no'], 'status' => (string) $r['status']], $src['open_runs']),
            'people' => $people, 'not_paid' => $notPaid];
        if ($annual) {
            $inputs['year_open'] = $this->today() <= $to;
        }
        return ['inputs' => $inputs, 'configs' => array_values(array_filter([$employer, $dueCfg])), 'source_hash' => self::sourceHash($runs, $annual ? $open : [])];
    }

    /**
     * @param list<array<string, mixed>> $runs
     * @param array<int, array{refs: list<string>}> $openings
     */
    private static function sourceHash(array $runs, array $openings = []): string
    {
        $lines = array_map(static fn (array $r): string => $r['pid'] . ':' . $r['output_hash'], $runs);
        foreach ($openings as $o) {
            foreach ($o['refs'] as $ref) {
                $lines[] = 'opening:' . $ref;
            }
        }
        return hash('sha256', implode("\n", $lines));
    }

    /** the fingerprint of the approved runs paid in a month (and, for the year, the approved opening balances) now — a revision made from others is out of date */
    public function currentSourceHash(int $le, string $period, string $kind = ''): string
    {
        [$from, $to] = self::range($period) ?? ['', ''];
        $runs = array_map(static fn (array $r): array => ['pid' => (string) $r['public_id'], 'output_hash' => (string) $r['output_hash']], PayrollService::of($this->app)->filingSource($le, $from, $to)['runs']);
        return self::sourceHash($runs, $kind === 'wht_annual' ? $this->openings($le, (int) $period) : []);
    }

    /**
     * Makes a new revision of a company's filing of a kind and month. Before anything was submitted the new one
     * replaces the previous; after a submission it is an additional filing and needs a reason.
     */
    public function generate(Authorizer $az, int $le, string $kind, string $period, string $reason): string
    {
        $this->require($az, 'filing.prepare', $le);
        $errors = [];
        if (!in_array($kind, FilingCalc::KINDS, true)) {
            $errors['kind'] = 'filing.error.kind';
        }
        $range = self::range($period);
        if ($range === null || $range[0] > $this->today() || ($kind === 'wht_annual') !== (strlen($period) === 4)) {
            $errors['period'] = 'filing.error.period';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $reason = trim($reason);
        $db = $this->app->db();
        return $db->transactional(function () use ($db, $az, $le, $kind, $period, $reason): string {
            $now = $this->now();
            $db->run(
                'INSERT IGNORE INTO fl_filings (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, kind, period) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [Ulid::generate($this->app->clock), $now, $az->userId, $now, $az->userId, $le, $kind, $period]
            );
            $f = $db->one('SELECT * FROM fl_filings WHERE legal_entity_id = ? AND kind = ? AND period = ? FOR UPDATE', [$le, $kind, $period]) ?? throw HttpException::notFound();
            $revs = $db->all('SELECT id, public_id, revision_no, status FROM fl_revisions WHERE filing_id = ? ORDER BY revision_no', [$f['id']]);
            if (array_filter($revs, static fn (array $r): bool => $r['status'] === 'pending') !== []) {
                throw new BusinessException('filing.error.pending', [], 409);
            }
            $additional = array_filter($revs, static fn (array $r): bool => in_array($r['status'], self::SUBMITTED, true)) !== [];
            if ($additional && (mb_strlen($reason) < 5 || mb_strlen($reason) > 500)) {
                throw new ValidationException(['reason' => 'filing.error.reason_additional']);
            }
            if (mb_strlen($reason) > 500) {
                throw new ValidationException(['reason' => 'filing.error.reason_additional']);
            }
            $g = $this->gather($le, $kind, $period);
            $out = FilingCalc::build($g['inputs']);
            $pid = Ulid::generate($this->app->clock);
            $no = count($revs) + 1;
            $calc = $this->app->ledger()->record('filing.' . $kind, FilingCalc::ENGINE, FilingCalc::ENGINE, $this->app->clock->now(), $g['configs'], $g['inputs'], [], $out, [
                'legal_entity_id' => $le, 'period_ref' => $period, 'source_type' => 'fl_revision', 'source_id' => $pid, 'initiated_by' => $az->userId,
            ]);
            foreach ($revs as $r) {
                if (in_array($r['status'], self::REPLACEABLE, true)) {
                    $db->run("UPDATE fl_revisions SET status = 'superseded', updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$now, $az->userId, $r['id']]);
                    $this->event((int) $r['id'], 'superseded', $az->userId, '#' . $no);
                }
            }
            $status = $out['errors'] !== [] ? 'validation_failed' : 'ready';
            $db->run(
                'INSERT INTO fl_revisions (public_id, created_at, created_by, updated_at, updated_by, filing_id, revision_no, purpose, status, reason, schema_version, calc_run_pid, source_hash,
                    people, total_a, total_b, total_c, error_count, warning_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $now, $az->userId, $now, $az->userId, $f['id'], $no, $additional ? 'additional' : 'original', $status, $reason === '' ? null : $reason, FilingCalc::SCHEMA[$kind], $calc, $g['source_hash'],
                    $out['totals']['people'], Money::toDb($out['totals']['a']), Money::toDb($out['totals']['b']), Money::toDb($out['totals']['c']), count($out['errors']), count($out['warnings'])]
            );
            $id = (int) $db->pdo()->lastInsertId();
            $this->event($id, 'generated', $az->userId, $reason === '' ? null : $reason);
            $this->audit('filing.generated', $pid, $le, ['kind' => $kind, 'period' => $period, 'revision' => $no, 'purpose' => $additional ? 'additional' : 'original', 'status' => $status,
                'people' => $out['totals']['people'], 'errors' => count($out['errors']), 'warnings' => count($out['warnings'])]);
            return $pid;
        });
    }

    // ---- reading -----------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> a revision with its filing (raw) */
    private function row(string $pid): array
    {
        return $this->app->db()->one(
            'SELECT r.*, f.public_id AS filing_pid, f.legal_entity_id, f.kind, f.period, l.code AS le_code, l.public_id AS le_pid FROM fl_revisions r JOIN fl_filings f ON f.id = r.filing_id
               JOIN legal_entities l ON l.id = f.legal_entity_id WHERE r.public_id = ?',
            [$pid]
        ) ?? throw HttpException::notFound();
    }

    /** @return array<string, mixed> a revision the person may see, with its rows, checks, history and what they can do */
    public function revision(Authorizer $az, string $pid): array
    {
        $r = $this->row($pid);
        $le = (int) $r['legal_entity_id'];
        if (!$az->scope('filing.view')->coversLegalEntity($le)) {
            throw HttpException::notFound();
        }
        $snap = $this->app->ledger()->open((string) $r['calc_run_pid']);
        $r['intact'] = $snap['intact'];
        $r['rows'] = (array) ($snap['outputs']['rows'] ?? []);
        $r['errors'] = (array) ($snap['outputs']['errors'] ?? []);
        $r['warnings'] = (array) ($snap['outputs']['warnings'] ?? []);
        $r['runs'] = (array) ($snap['inputs']['runs'] ?? []);
        $r['config_versions'] = $snap['config_versions'];
        $db = $this->app->db();
        $r['history'] = $db->all('SELECT public_id, revision_no, purpose, status, created_at, people FROM fl_revisions WHERE filing_id = ? ORDER BY revision_no DESC', [$r['filing_id']]);
        $r['events'] = $db->all('SELECT e.event, e.note, e.created_at, COALESCE(u.display_name, u.username) AS actor FROM fl_events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.revision_id = ? ORDER BY e.id', [$r['id']]);
        $r['due'] = $this->dueDate($le, (string) $r['kind'], (string) $r['period']);
        $r['source_changed'] = !in_array($r['status'], array_merge(['superseded'], self::SUBMITTED), true) && !hash_equals((string) $r['source_hash'], $this->currentSourceHash($le, (string) $r['period'], (string) $r['kind']));
        $r['can_prepare'] = $az->scope('filing.prepare')->coversLegalEntity($le);
        $r['is_latest'] = (int) $r['revision_no'] === (int) ($r['history'][0]['revision_no'] ?? 0);
        $r['additional_next'] = array_filter($r['history'], static fn (array $h): bool => in_array($h['status'], self::SUBMITTED, true)) !== [];
        return $r;
    }

    /** whether the revision's rows and checks come out the same when worked out again from its stored inputs */
    public function replay(Authorizer $az, string $pid): bool
    {
        $r = $this->revision($az, $pid);
        return $this->app->ledger()->replay((string) $r['calc_run_pid'], static fn (array $in): array => FilingCalc::build($in))['matches'];
    }

    // ---- review -----------------------------------------------------------------------------------------------------------

    /** Sends a ready revision to another person with filing.approve (only the person who made it, made from the runs that are still current). */
    public function submit(Authorizer $az, string $pid, string $note): void
    {
        $r = $this->row($pid);
        $le = (int) $r['legal_entity_id'];
        $this->require($az, 'filing.prepare', $le);
        if ($r['status'] !== 'ready') {
            throw new BusinessException($r['status'] === 'validation_failed' ? 'filing.error.has_errors' : 'filing.error.state', [], 409);
        }
        if ((int) $r['created_by'] !== $az->userId) {
            throw new BusinessException('filing.error.not_maker', [], 409);
        }
        if (!hash_equals((string) $r['source_hash'], $this->currentSourceHash($le, (string) $r['period'], (string) $r['kind']))) {
            throw new BusinessException('filing.error.stale', [], 409);
        }
        $db = $this->app->db();
        $db->transactional(function () use ($db, $r, $az, $note, $le): void {
            $t = $this->app->translator();
            $title = $t->get('filing.review_title', ['le' => (string) $r['le_code'], 'kind' => $t->get('filing.kind.' . $r['kind']), 'period' => (string) $r['period'], 'no' => (string) $r['revision_no']]);
            $inst = $this->app->workflow()->start('filing.review', (string) $r['public_id'], $title, $le, $az->userId);
            $db->run(
                "UPDATE fl_revisions SET status = 'pending', workflow_instance_pid = ?, submitted_for_review_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?",
                [$inst, $this->now(), $this->now(), $az->userId, $r['id']]
            );
            $this->event((int) $r['id'], 'submitted', $az->userId, mb_substr(trim($note), 0, 500) ?: null);
            $this->audit('filing.review_submitted', (string) $r['public_id'], $le);
        });
    }

    public function onApproved(string $pid, int $approverId): void
    {
        $r = $this->row($pid);
        if ($r['status'] !== 'pending') {
            return;
        }
        $this->app->db()->run(
            "UPDATE fl_revisions SET status = 'approved', approved_by = ?, approved_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?",
            [$approverId, $this->now(), $this->now(), $approverId, $r['id']]
        );
        $this->event((int) $r['id'], 'approved', $approverId, null);
        $this->audit('filing.approved', (string) $r['public_id'], (int) $r['legal_entity_id']);
    }

    public function onClosed(string $pid, string $outcome): void
    {
        $r = $this->row($pid);
        if ($r['status'] !== 'pending') {
            return;
        }
        $this->app->db()->run("UPDATE fl_revisions SET status = 'returned', updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$this->now(), $r['id']]);
        $this->event((int) $r['id'], 'returned', null, $outcome);
    }

    // ---- export, submission, result -----------------------------------------------------------------------------------------

    /**
     * The review workbook of an approved revision (Excel): who, what, totals and the rows — marked as a review copy, not an
     * official upload file. The first export moves the revision to "exported" and keeps the file's SHA-256.
     *
     * @return array{bytes: string, name: string}
     */
    public function export(Authorizer $az, string $pid): array
    {
        $r = $this->revision($az, $pid);
        $le = (int) $r['legal_entity_id'];
        $this->require($az, 'filing.prepare', $le);
        if (!in_array($r['status'], ['approved', 'exported', 'submitted', 'accepted', 'rejected'], true)) {
            throw new BusinessException('filing.error.approve_first', [], 409);
        }
        if ($r['status'] === 'approved' && $r['source_changed']) {
            throw new BusinessException('filing.error.stale', [], 409); // approved, but the month's runs changed since — make it again
        }
        $t = $this->app->translator();
        $m = static fn (int $u): string => Money::format($u, 2, false);
        $wht = FilingCalc::isWht((string) $r['kind']);
        $approver = (string) ($this->app->db()->scalar('SELECT COALESCE(display_name, username) FROM users WHERE id = ?', [$r['approved_by']]) ?? '');
        $info = [
            [$t->get('filing.xlsx.notice'), ''],
            [$t->get('filing.field.company'), (string) $r['le_code']],
            [$t->get('filing.field.kind'), $t->get('filing.kind.' . $r['kind'])],
            [$t->get('filing.field.period'), (string) $r['period']],
            [$t->get('filing.field.revision'), '#' . $r['revision_no'] . ' · ' . $t->get('filing.purpose.' . $r['purpose'])],
            [$t->get('filing.field.schema'), (string) $r['schema_version']],
            [$t->get('filing.field.ledger'), (string) $r['calc_run_pid']],
            [$t->get('filing.field.approved'), $approver . ' · ' . substr((string) $r['approved_at'], 0, 16)],
            [$t->get('filing.field.people'), (string) $r['people']],
            [$t->get($wht ? 'filing.col.income' : 'filing.col.wage_used'), $m(Money::fromDb($r['total_a']))],
            [$t->get($wht ? 'filing.col.wht' : 'filing.col.ee'), $m(Money::fromDb($r['total_b']))],
        ];
        if (!$wht) {
            $info[] = [$t->get('filing.col.er'), $m(Money::fromDb($r['total_c']))];
        }
        $rows = $wht
            ? ['headers' => [$t->get('filing.col.code'), $t->get('filing.col.name'), $t->get('filing.col.id_last4'), $t->get('filing.col.income'), $t->get('filing.col.wht')],
                'rows' => array_map(static fn (array $x): array => [(string) $x['code'], (string) $x['name'], (string) $x['id_last4'], $m((int) $x['income']), $m((int) $x['wht'])], $r['rows']),
                'numeric' => [3, 4], 'widths' => [12, 30, 10, 16, 16]]
            : ['headers' => [$t->get('filing.col.code'), $t->get('filing.col.name'), $t->get('filing.col.id_last4'), $t->get('filing.col.wage'), $t->get('filing.col.wage_used'), $t->get('filing.col.ee'), $t->get('filing.col.er'), $t->get('filing.col.check')],
                'rows' => array_map(static fn (array $x): array => [(string) $x['code'], (string) $x['name'], (string) $x['id_last4'], $m((int) $x['wage']), $m((int) $x['wage_used']), $m((int) $x['ee']), $m((int) $x['er']),
                    $x['wage_unknown'] ? $t->get('filing.wage_unknown_short') : ''], $r['rows']),
                'numeric' => [3, 4, 5, 6], 'widths' => [12, 30, 10, 16, 16, 14, 14, 24]];
        if ($r['kind'] === 'wht_annual') { // the annual review also shows what came from the opening balances
            $rows['headers'] = array_merge($rows['headers'], [$t->get('filing.col.opening_income'), $t->get('filing.col.opening_wht')]);
            foreach ($r['rows'] as $i => $x) {
                $rows['rows'][$i] = array_merge($rows['rows'][$i], [$m((int) ($x['opening_income'] ?? 0)), $m((int) ($x['opening_wht'] ?? 0))]);
            }
            $rows['numeric'] = [3, 4, 5, 6];
            $rows['widths'] = [12, 30, 10, 16, 16, 16, 16];
        }
        $bytes = Xlsx::write(['summary' => ['headers' => [$t->get('filing.xlsx.item'), $t->get('filing.xlsx.value')], 'rows' => $info, 'widths' => [36, 60]], 'rows' => $rows], 'Blue OS filing review');
        $hash = hash('sha256', $bytes);
        $name = 'filing-review-' . $r['le_code'] . '-' . $r['kind'] . '-' . $r['period'] . '-r' . $r['revision_no'] . '.xlsx';
        if ($r['status'] === 'approved') {
            $this->app->db()->run(
                "UPDATE fl_revisions SET status = 'exported', export_sha256 = ?, export_name = ?, exported_by = ?, exported_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ? AND status = 'approved'",
                [$hash, $name, $az->userId, $this->now(), $this->now(), $az->userId, $r['id']]
            );
            $this->event((int) $r['id'], 'exported', $az->userId, substr($hash, 0, 16));
        }
        $this->audit('filing.exported', (string) $r['public_id'], $le, ['sha256' => $hash]);
        return ['bytes' => $bytes, 'name' => $name];
    }

    /** the statuses from which the official upload file may be built (approved by another person, made from current runs) */
    public const OFFICIAL_FROM = ['approved', 'exported', 'submitted', 'accepted', 'rejected'];

    /**
     * Builds the official upload file of an approved revision in memory (0.46.0 · DEC-115): the text file of the
     * Revenue Department (P.N.D. 1, or P.N.D. 1A for the year — 0.47.0) or the social security workbook (Sor.Por.Sor. 1-10). Full id numbers are decrypted
     * only here and not kept; the file is not stored — its SHA-256 goes to the history and the audit (no amounts, no ids).
     * With $build false only the problems are worked out (nothing is decrypted).
     *
     * @return array{bytes: ?string, name: string, mime: string, problems: list<array{key: string, params: array<string, string>}>, paid_dates: list<string>}
     */
    public function official(Authorizer $az, string $pid, bool $build = true): array
    {
        $r = $this->revision($az, $pid);
        $le = (int) $r['legal_entity_id'];
        $this->require($az, 'filing.prepare', $le);
        if (!in_array($r['status'], self::OFFICIAL_FROM, true)) {
            throw new BusinessException('filing.error.approve_first', [], 409);
        }
        if ($r['status'] === 'approved' && $r['source_changed']) {
            throw new BusinessException('filing.error.stale', [], 409);
        }
        $db = $this->app->db();
        $tax = TaxService::of($this->app);
        $wht = FilingCalc::isWht((string) $r['kind']);
        $annual = $r['kind'] === 'wht_annual';
        $dates = $annual ? [] : array_values(array_unique(array_map(static fn (array $x): string => (string) $x['pay_date'], $r['runs'])));
        rsort($dates);
        $people = [];
        foreach ($r['rows'] as $x) {
            $emp = $db->one('SELECT id, title, name_th FROM employees WHERE employee_code = ?', [(string) $x['code']]);
            $addr = $annual && $emp !== null ? $db->one('SELECT house_no, building, alley, road, subdistrict, district, province, postcode FROM employee_tax_addresses WHERE employee_id = ?', [$emp['id']]) : null;
            $id = $build && $emp !== null ? ($tax->idForFiling((int) $emp['id'])['value'] ?? null) : ($emp !== null ? str_repeat('0', 13) : null);
            $base = ['code' => (string) $x['code'], 'title' => (string) ($emp['title'] ?? ''), 'name' => (string) ($emp['name_th'] ?? $x['name']), 'id' => $id];
            $people[] = $wht ? $base + ['income' => (int) $x['income'], 'wht' => (int) $x['wht'], 'address' => $addr] : $base + ['wage' => (int) $x['wage'], 'ee' => (int) $x['ee']];
        }
        $period = (string) $r['period'];
        if ($annual) {
            $payer = $tax->payer($le);
            $out = OfficialFile::pnd1a($tax->filingSetting('rd_file', $le), is_array($payer) ? (string) ($payer['tax_id'] ?? '') : null, $period, (string) $r['purpose'], $people);
            $bytes = $out['text'];
            $name = 'PND1A-' . $r['le_code'] . '-' . $period . '-r' . $r['revision_no'] . '.txt';
            $mime = 'text/plain; charset=utf-8';
            $format = OfficialFile::PND1A;
        } elseif ($wht) {
            $payer = $tax->payer($le);
            $out = OfficialFile::pnd1($tax->filingSetting('rd_file', $le), is_array($payer) ? (string) ($payer['tax_id'] ?? '') : null, $period, (string) $r['purpose'], $dates[0] ?? $this->today(), $people);
            $bytes = $out['text'];
            $name = 'PND1-' . $r['le_code'] . '-' . $period . '-r' . $r['revision_no'] . '.txt';
            $mime = 'text/plain; charset=utf-8';
            $format = OfficialFile::PND1;
        } else {
            $emp = $tax->filingSetting('sso_employer', $le);
            $branch = is_array($emp) && (string) ($emp['branch_no'] ?? '') !== '' ? (string) $emp['branch_no'] : null;
            $out = OfficialFile::sso($branch, $people);
            $bytes = $out['bytes'];
            $name = 'SSO-1-10-' . $r['le_code'] . '-' . $period . '-r' . $r['revision_no'] . '.xlsx';
            $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            $format = OfficialFile::SSO;
        }
        if ($build && $bytes !== null) {
            $hash = hash('sha256', $bytes);
            $this->event((int) $r['id'], 'official', $az->userId, $format . ' · ' . substr($hash, 0, 16));
            $this->audit('filing.official_exported', (string) $r['public_id'], $le, ['format' => $format, 'sha256' => $hash, 'people' => count($people)]);
        }
        return ['bytes' => $build ? $bytes : null, 'name' => $name, 'mime' => $mime, 'problems' => $out['problems'], 'paid_dates' => $dates];
    }

    /**
     * Records that an exported revision was filed with the authority: the day, the reference the authority gave and
     * (optional) the receipt file (kept as a restricted document).
     *
     * @param array<string, mixed> $in submitted_on, reference
     */
    public function recordSubmission(Authorizer $az, string $pid, array $in, ?UploadedFile $receipt): void
    {
        $r = $this->row($pid);
        $le = (int) $r['legal_entity_id'];
        $this->require($az, 'filing.prepare', $le);
        if ($r['status'] !== 'exported') {
            throw new BusinessException('filing.error.export_first', [], 409);
        }
        $errors = [];
        $on = trim((string) ($in['submitted_on'] ?? ''));
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $on);
        [$from] = self::range((string) $r['period']) ?? [''];
        if ($d === false || $d->format('Y-m-d') !== $on || $on > $this->today() || $on < $from) {
            $errors['submitted_on'] = 'filing.error.submitted_on';
        }
        $ref = trim((string) ($in['reference'] ?? ''));
        if (mb_strlen($ref) < 3 || mb_strlen($ref) > 100) {
            $errors['reference'] = 'filing.error.reference';
        }
        if ($receipt !== null && !$receipt->ok()) {
            $errors['receipt'] = $receipt->errorKey();
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $doc = null;
        if ($receipt !== null) {
            try {
                $doc = (string) $this->app->documents()->storeGenerated($az->userId, 'filing.receipt', $receipt->bytes(), $receipt->safeName(), ['title' => 'Filing receipt ' . $r['le_code'] . ' ' . $r['kind'] . ' ' . $r['period'], 'legal_entity_id' => $le], 'upload', true)['document'];
            } catch (ValidationException $e) {
                throw new ValidationException(['receipt' => (string) (array_values($e->errors)[0] ?? 'filing.error.file')]);
            }
        }
        $this->app->db()->run(
            "UPDATE fl_revisions SET status = 'submitted', submitted_on = ?, submission_ref = ?, receipt_document_pid = ?, submitted_by = ?, submitted_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ? AND status = 'exported'",
            [$on, $ref, $doc, $az->userId, $this->now(), $this->now(), $az->userId, $r['id']]
        );
        $this->event((int) $r['id'], 'filed', $az->userId, $ref);
        $this->audit('filing.submitted', (string) $r['public_id'], $le, ['submitted_on' => $on, 'receipt' => $doc !== null]);
    }

    /** The authority's answer on a submitted revision: accepted, or rejected (with what they said). */
    public function recordResult(Authorizer $az, string $pid, string $result, string $note): void
    {
        $r = $this->row($pid);
        $le = (int) $r['legal_entity_id'];
        $this->require($az, 'filing.prepare', $le);
        if ($r['status'] !== 'submitted') {
            throw new BusinessException('filing.error.state', [], 409);
        }
        $note = trim($note);
        if (!in_array($result, ['accepted', 'rejected'], true)) {
            throw new ValidationException(['result' => 'filing.error.result']);
        }
        if (($result === 'rejected' && mb_strlen($note) < 3) || mb_strlen($note) > 500) {
            throw new ValidationException(['note' => 'filing.error.result_note']);
        }
        $this->app->db()->run(
            "UPDATE fl_revisions SET status = ?, result_note = ?, result_by = ?, result_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ? AND status = 'submitted'",
            [$result, $note === '' ? null : $note, $az->userId, $this->now(), $this->now(), $az->userId, $r['id']]
        );
        $this->event((int) $r['id'], $result, $az->userId, $note === '' ? null : $note);
        $this->audit('filing.' . $result, (string) $r['public_id'], $le);
    }

    /**
     * a receipt file of a revision (restricted document, the read is logged)
     *
     * @return array{bytes: string, mime: string, filename: string, classification: string, ext: string}
     */
    public function receipt(Authorizer $az, string $pid): array
    {
        $r = $this->revision($az, $pid);
        if ($r['receipt_document_pid'] === null) {
            throw HttpException::notFound();
        }
        return $this->app->documents()->moduleRead((string) $r['receipt_document_pid'], 'filing', $az->userId, 'filing receipt');
    }

    // ---- helpers -----------------------------------------------------------------------------------------------------------

    private function event(int $revisionId, string $event, ?int $actor, ?string $note): void
    {
        $this->app->db()->run('INSERT INTO fl_events (revision_id, event, actor_id, note, created_at) VALUES (?, ?, ?, ?, ?)', [$revisionId, $event, $actor, $note === null ? null : mb_substr($note, 0, 500), $this->now()]);
    }

    /** @param array<string, mixed> $details no amounts, no personal data */
    private function audit(string $action, string $pid, int $le, array $details = []): void
    {
        $this->app->audit()->record($action, 'success', 'fl_revision', $pid, $le, null, $details);
    }
}
