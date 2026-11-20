<?php

declare(strict_types=1);

namespace Blue\Core\Payments;

use Blue\Core\Audit\AuditLogger;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Database\Connection;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * Bank file formats of a company (0.48.0 · R8-3 · DEC-117 · Q-10 "several banks, added and removed, the layout given with
 * variables, with made-up examples").
 *
 * A format is a code (one per bank layout the company uses) with versions: a draft (blank, copied from a made-up sample,
 * or a new version of one in use) is edited by someone who prepares payments and switched on by ANOTHER person who may
 * approve payments, who confirms it was checked against the bank's specification and names it. Switching a version on
 * retires the one in use of the same code; a format can be retired. A batch's file can then be made in any active format
 * of its company, or in the standard file.
 */
final class BankFormats
{
    public const ENCODINGS = ['UTF-8', 'TIS-620'];

    public const LINE_ENDS = ['CRLF', 'LF'];

    public const EXTENSIONS = ['txt', 'csv'];

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
    ) {
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }

    private function requireLe(Authorizer $az, string $perm, int $le): void
    {
        if (!$az->scope($perm)->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
    }

    /** @return array<string, mixed> */
    private function row(string $pid): array
    {
        $f = $this->db->one('SELECT f.*, l.code AS le_code FROM pmt_bank_formats f JOIN legal_entities l ON l.id = f.legal_entity_id WHERE f.public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $f['params'] = (array) (json_decode((string) $f['params_json'], true) ?: []);
        return $f;
    }

    /** @return array<string, mixed> a format the person may see (payment view of its company) */
    public function get(Authorizer $az, string $pid): array
    {
        $f = $this->row($pid);
        if (!$az->scope('core.payment.view')->coversLegalEntity((int) $f['legal_entity_id'])) {
            throw HttpException::notFound();
        }
        $f['can_edit'] = $f['status'] === 'draft' && $az->scope('core.payment.prepare')->coversLegalEntity((int) $f['legal_entity_id']);
        $f['can_activate'] = $f['status'] === 'draft' && $az->scope('core.payment.approve')->coversLegalEntity((int) $f['legal_entity_id'])
            && $az->userId !== (int) $f['created_by'] && $az->userId !== (int) $f['updated_by'];
        $f['can_retire'] = $f['status'] === 'active' && $az->scope('core.payment.approve')->coversLegalEntity((int) $f['legal_entity_id']);
        $f['can_copy'] = $f['status'] !== 'draft' && $az->scope('core.payment.prepare')->coversLegalEntity((int) $f['legal_entity_id']);
        $f['names'] = $this->db->one('SELECT (SELECT display_name FROM users WHERE id = ?) AS created, (SELECT display_name FROM users WHERE id = ?) AS activated', [$f['created_by'], $f['activated_by']]) ?? [];
        return $f;
    }

    /**
     * @param list<int> $les companies the person may see
     * @return list<array<string, mixed>> formats of those companies, newest version first per code
     */
    public function list(array $les): array
    {
        if ($les === []) {
            return [];
        }
        return $this->db->all(
            'SELECT f.public_id, f.code, f.version, f.name, f.bank_code, f.status, f.from_sample, f.extension, f.encoding, f.updated_at, f.activated_at, l.code AS le_code
               FROM pmt_bank_formats f JOIN legal_entities l ON l.id = f.legal_entity_id WHERE f.legal_entity_id IN (' . implode(',', array_map('intval', $les)) . ")
              ORDER BY l.code, f.code, f.version DESC"
        );
    }

    /** @return list<array<string, mixed>> the active formats of a company (to make a batch's file) */
    public function active(int $le): array
    {
        $out = [];
        foreach ($this->db->all("SELECT * FROM pmt_bank_formats WHERE legal_entity_id = ? AND status = 'active' ORDER BY code", [$le]) as $f) {
            $f['params'] = (array) (json_decode((string) $f['params_json'], true) ?: []);
            $out[] = $f;
        }
        return $out;
    }

    /**
     * A new draft: from a made-up sample ("sample:fixed" / "sample:csv"), blank, or — given an existing format — its next
     * version (same code, copied).
     */
    public function create(Authorizer $az, int $le, string $from, string $code): string
    {
        $this->requireLe($az, 'core.payment.prepare', $le);
        $now = $this->now();
        $pid = Ulid::generate($this->clock);
        if (str_starts_with($from, 'copy:')) {
            $src = $this->row(substr($from, 5));
            if ((int) $src['legal_entity_id'] !== $le) {
                throw HttpException::notFound();
            }
            if ($this->db->scalar("SELECT id FROM pmt_bank_formats WHERE legal_entity_id = ? AND code = ? AND status = 'draft'", [$le, $src['code']]) !== null) {
                throw new BusinessException('payment.format.error_draft_exists', [], 409);
            }
            $version = (int) $this->db->scalar('SELECT MAX(version) FROM pmt_bank_formats WHERE legal_entity_id = ? AND code = ?', [$le, $src['code']]) + 1;
            $d = ['code' => (string) $src['code'], 'name' => (string) $src['name'], 'bank_code' => $src['bank_code'], 'from_sample' => (int) $src['from_sample'], 'extension' => (string) $src['extension'],
                'encoding' => (string) $src['encoding'], 'line_end' => (string) $src['line_end'], 'final_newline' => (int) $src['final_newline'], 'header' => $src['header_tpl'],
                'detail' => (string) $src['detail_tpl'], 'footer' => $src['footer_tpl'], 'params' => $src['params']];
        } else {
            $code = strtoupper(trim($code));
            if (preg_match('/^[A-Z][A-Z0-9_]{1,29}$/', $code) !== 1) {
                throw new ValidationException(['code' => 'payment.format.error_code']);
            }
            if ($this->db->scalar('SELECT id FROM pmt_bank_formats WHERE legal_entity_id = ? AND code = ?', [$le, $code]) !== null) {
                throw new ValidationException(['code' => 'payment.format.error_code_taken']);
            }
            $version = 1;
            $sample = BankTemplate::samples()[substr($from, 7)] ?? null;
            if (str_starts_with($from, 'sample:') && $sample !== null) {
                $d = ['code' => $code, 'name' => $sample['name'], 'bank_code' => null, 'from_sample' => 1, 'extension' => $sample['extension'], 'encoding' => $sample['encoding'],
                    'line_end' => $sample['line_end'], 'final_newline' => (int) $sample['final_newline'], 'header' => $sample['header'], 'detail' => $sample['detail'], 'footer' => $sample['footer'],
                    'params' => $sample['params']];
            } elseif ($from === 'blank') {
                $d = ['code' => $code, 'name' => $code, 'bank_code' => null, 'from_sample' => 0, 'extension' => 'txt', 'encoding' => 'UTF-8', 'line_end' => 'CRLF', 'final_newline' => 1,
                    'header' => '', 'detail' => '{seq}|{account|digits}|{amount}', 'footer' => '', 'params' => []];
            } else {
                throw new ValidationException(['from' => 'payment.format.error_from']);
            }
        }
        $this->db->run(
            'INSERT INTO pmt_bank_formats (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, code, version, name, bank_code, status, from_sample, extension, encoding, line_end,
                final_newline, header_tpl, detail_tpl, footer_tpl, params_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'draft\', ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$pid, $now, $az->userId, $now, $az->userId, $le, $d['code'], $version, $d['name'], $d['bank_code'], $d['from_sample'], $d['extension'], $d['encoding'], $d['line_end'], $d['final_newline'],
                $d['header'], $d['detail'], $d['footer'], json_encode((object) $d['params'], JSON_UNESCAPED_UNICODE)]
        );
        $this->audit->record('payment.format_created', 'success', 'bank_format', $pid, $le, null, ['code' => $d['code'], 'version' => $version, 'from' => explode(':', $from)[0] . (str_starts_with($from, 'sample:') ? ':' . substr($from, 7) : '')]);
        return $pid;
    }

    /**
     * Checks a format's fields; returns the clean values.
     *
     * @param array<string, mixed> $in name, bank_code, extension, encoding, line_end, final_newline, header, detail, footer, params (text: "key=value" per line)
     * @return array<string, mixed>
     */
    public static function validate(array $in): array
    {
        $e = [];
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $e['name'] = 'payment.format.error_name';
        }
        $bank = trim((string) ($in['bank_code'] ?? ''));
        if ($bank !== '' && preg_match('/^[0-9A-Za-z]{1,10}$/', $bank) !== 1) {
            $e['bank_code'] = 'payment.format.error_bank_code';
        }
        $params = [];
        foreach (preg_split('/\r\n|\n|\r/', (string) ($in['params'] ?? '')) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $line, 2), 2, null);
            $k = trim((string) $k);
            if ($v === null || preg_match('/^[a-z_][a-z0-9_]{0,30}$/', $k) !== 1 || mb_strlen($v) > 100) {
                $e['params'] = 'payment.format.error_params';
                continue;
            }
            $params[$k] = trim($v);
        }
        $out = ['name' => $name, 'bank_code' => $bank === '' ? null : $bank, 'params' => $params,
            'extension' => in_array($in['extension'] ?? '', self::EXTENSIONS, true) ? (string) $in['extension'] : 'txt',
            'encoding' => in_array($in['encoding'] ?? '', self::ENCODINGS, true) ? (string) $in['encoding'] : 'UTF-8',
            'line_end' => in_array($in['line_end'] ?? '', self::LINE_ENDS, true) ? (string) $in['line_end'] : 'CRLF',
            'final_newline' => !empty($in['final_newline']) ? 1 : 0];
        foreach (['header' => false, 'detail' => true, 'footer' => false] as $part => $detail) {
            $tpl = str_replace(["\r\n", "\r"], "\n", (string) ($in[$part] ?? ''));
            if ($part === 'detail' && trim($tpl) === '') {
                $e['detail'] = 'payment.format.error_detail_empty';
            } elseif (mb_strlen($tpl) > 4000) {
                $e[$part] = 'payment.format.error_long';
            } else {
                $problems = BankTemplate::check($tpl, $detail, array_keys($params));
                if ($problems !== []) {
                    $e[$part] = $problems[0]['key']; // the screen lists every problem token with BankTemplate::check()
                }
            }
            $out[$part] = $tpl;
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        return $out;
    }

    /** @param array<string, mixed> $in */
    public function save(Authorizer $az, string $pid, array $in): void
    {
        $f = $this->row($pid);
        $this->requireLe($az, 'core.payment.prepare', (int) $f['legal_entity_id']);
        if ($f['status'] !== 'draft') {
            throw new BusinessException('payment.format.error_not_draft', [], 409);
        }
        $v = self::validate($in);
        $this->db->run(
            'UPDATE pmt_bank_formats SET name = ?, bank_code = ?, extension = ?, encoding = ?, line_end = ?, final_newline = ?, header_tpl = ?, detail_tpl = ?, footer_tpl = ?, params_json = ?,
                updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ? AND status = \'draft\'',
            [$v['name'], $v['bank_code'], $v['extension'], $v['encoding'], $v['line_end'], $v['final_newline'], $v['header'] === '' ? null : $v['header'], $v['detail'],
                $v['footer'] === '' ? null : $v['footer'], json_encode((object) $v['params'], JSON_UNESCAPED_UNICODE), $this->now(), $az->userId, $f['id']]
        );
        $this->audit->record('payment.format_saved', 'success', 'bank_format', $pid, (int) $f['legal_entity_id'], null, ['code' => $f['code'], 'version' => (int) $f['version']]);
    }

    public function discard(Authorizer $az, string $pid): void
    {
        $f = $this->row($pid);
        $this->requireLe($az, 'core.payment.prepare', (int) $f['legal_entity_id']);
        if ($f['status'] !== 'draft') {
            throw new BusinessException('payment.format.error_not_draft', [], 409);
        }
        $this->db->run("DELETE FROM pmt_bank_formats WHERE id = ? AND status = 'draft'", [$f['id']]);
        $this->audit->record('payment.format_discarded', 'success', 'bank_format', $pid, (int) $f['legal_entity_id'], null, ['code' => $f['code'], 'version' => (int) $f['version']]);
    }

    /**
     * Switches a draft on — by someone who may approve payments and did not make or last edit it, who confirms it was
     * checked against the bank's specification (named). The previous active version of the code is retired.
     */
    public function activate(Authorizer $az, string $pid, bool $checked, string $reference): void
    {
        $f = $this->get($az, $pid);
        $le = (int) $f['legal_entity_id'];
        $this->requireLe($az, 'core.payment.approve', $le);
        if ($f['status'] !== 'draft') {
            throw new BusinessException('payment.format.error_not_draft', [], 409);
        }
        if (!$f['can_activate']) {
            throw new BusinessException('payment.format.error_same_person', [], 403);
        }
        $reference = trim($reference);
        $e = [];
        if (!$checked) {
            $e['checked'] = 'payment.format.error_checked';
        }
        if (mb_strlen($reference) < 5 || mb_strlen($reference) > 200) {
            $e['reference'] = 'payment.format.error_reference';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        self::validate(['name' => $f['name'], 'bank_code' => $f['bank_code'], 'header' => $f['header_tpl'], 'detail' => $f['detail_tpl'], 'footer' => $f['footer_tpl'],
            'params' => implode("\n", array_map(static fn ($k, $v): string => $k . '=' . $v, array_keys($f['params']), $f['params']))]);
        $this->db->transactional(function () use ($f, $az, $reference, $le): void {
            $now = $this->now();
            $this->db->run(
                "UPDATE pmt_bank_formats SET status = 'retired', retired_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE legal_entity_id = ? AND code = ? AND status = 'active'",
                [$now, $now, $az->userId, $le, $f['code']]
            );
            $this->db->run(
                "UPDATE pmt_bank_formats SET status = 'active', spec_reference = ?, activated_by = ?, activated_at = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ? AND status = 'draft'",
                [$reference, $az->userId, $now, $now, $f['id']]
            );
            $this->audit->record('payment.format_activated', 'success', 'bank_format', (string) $f['public_id'], $le, $reference, ['code' => $f['code'], 'version' => (int) $f['version']]);
        });
    }

    public function retire(Authorizer $az, string $pid, string $reason): void
    {
        $f = $this->row($pid);
        $le = (int) $f['legal_entity_id'];
        $this->requireLe($az, 'core.payment.approve', $le);
        if ($f['status'] !== 'active') {
            throw new BusinessException('payment.format.error_not_active', [], 409);
        }
        if (mb_strlen(trim($reason)) < 3) {
            throw new ValidationException(['reason' => 'iam.error.reason_required']);
        }
        $this->db->run("UPDATE pmt_bank_formats SET status = 'retired', retired_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$this->now(), $this->now(), $az->userId, $f['id']]);
        $this->audit->record('payment.format_retired', 'success', 'bank_format', $pid, $le, mb_substr(trim($reason), 0, 200), ['code' => $f['code'], 'version' => (int) $f['version']]);
    }

    /**
     * The format applied to made-up data (three payees, "สมมุติ") — what the file will look like.
     *
     * @param array<string, mixed> $f a format row (with params)
     * @return array{text: ?string, problems: list<array{key: string, params: array<string, string>}>, lines: list<string>}
     */
    public static function preview(array $f, string $today): array
    {
        $items = [
            ['seq' => 1, 'ref' => 'PAY-SAMPLE-0001', 'payee_code' => 'E0001', 'payee_name' => 'สมมุติ ใจดี', 'bank_code' => '001', 'account' => '0001234567', 'amount' => 25000 * 10000, 'site' => 'HQ'],
            ['seq' => 2, 'ref' => 'PAY-SAMPLE-0002', 'payee_code' => 'E0002', 'payee_name' => 'ตัวอย่าง รักงาน', 'bank_code' => '002', 'account' => '000-9-87654-3', 'amount' => 18350_5000, 'site' => 'HQ'],
            ['seq' => 3, 'ref' => 'PAY-SAMPLE-0003', 'payee_code' => 'E0003', 'payee_name' => 'ทดสอบ "นามสมมุติ"', 'bank_code' => '001', 'account' => '0005550001', 'amount' => 9999_9900, 'site' => 'BR1'],
        ];
        $common = ['batch_no' => 'PAY-SAMPLE', 'company_code' => (string) ($f['le_code'] ?? 'DEMO'), 'pay_date' => $today, 'today' => $today, 'time' => '090000', 'count' => 3,
            'total' => array_sum(array_column($items, 'amount')), 'currency' => 'THB', 'period' => 'ตัวอย่าง'];
        $out = BankTemplate::build(['header' => $f['header_tpl'] ?? null, 'detail' => (string) $f['detail_tpl'], 'footer' => $f['footer_tpl'] ?? null, 'encoding' => 'UTF-8',
            'line_end' => 'LF', 'final_newline' => false, 'params' => (array) $f['params']], $common, $items);
        return ['text' => $out['bytes'], 'problems' => $out['problems'], 'lines' => $out['bytes'] === null ? [] : explode("\n", $out['bytes'])];
    }
}
