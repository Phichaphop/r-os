<?php

declare(strict_types=1);

namespace Blue\Core\Import;

use Blue\Core\Audit\AuditLogger;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Database\Connection;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\Csv;
use Blue\Core\Files\FileType;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Files\Xlsx;
use Blue\Core\I18n\Translator;
use Blue\Core\Registry\ConfigRegistry;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Crypto;
use Blue\Core\Support\Ulid;

/**
 * Excel/CSV import with template, preview, validation, error report and confirmation (03 §6.10 item 1, 6.30.12).
 *
 * upload → content check (xlsx/csv only) → header mapping (column key or its Thai/English label) → every row
 * validated by the definition → batch + rows stored (row data encrypted) → preview → the SAME user confirms →
 * valid rows applied in one transaction → audit. Nothing is written to business tables before confirmation.
 * Unconfirmed batches expire after 24 hours and their row data is wiped.
 */
final class ImportService
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const DEFAULT_MAX_ROWS = 5000;
    public const TTL_HOURS = 24;

    /** @var array<string, ImportDefinition> */
    private array $definitions = [];

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly Crypto $crypto,
        private readonly Translator $translator,
        private readonly ?ConfigRegistry $registry = null,
    ) {
    }

    public function register(ImportDefinition $d): void
    {
        $this->definitions[$d->code()] = $d;
    }

    public function definition(string $code): ImportDefinition
    {
        return $this->definitions[$code] ?? throw HttpException::notFound();
    }

    /**
     * Template workbook: sheet 1 = header row to fill in, sheet 2 = instructions (required, example, notes).
     *
     * @param array<string, string> $params
     */
    public function template(Authorizer $az, string $code, array $params): string
    {
        $d = $this->definition($code);
        $d->prepare($az, $params);
        $t = $this->translator;
        $cols = $d->columns();
        $help = [];
        foreach ($cols as $c) {
            $help[] = [$c['key'], $t->get($c['label']), $t->get($c['required'] ? 'form.required_tag' : 'form.optional_tag'), $c['example'], isset($c['help']) ? $t->get($c['help']) : ''];
        }
        return Xlsx::write([
            'data' => ['headers' => array_map(static fn (array $c): string => $t->get($c['label']), $cols), 'rows' => []],
            $t->get('import.template.instructions') => [
                'headers' => [$t->get('import.template.col_key'), $t->get('import.template.col_label'), $t->get('import.template.col_required'), $t->get('import.template.col_example'), $t->get('import.template.col_notes')],
                'rows' => $help, 'widths' => [18, 28, 14, 22, 70],
            ],
        ], $t->get($d->titleKey()) . ' ' . $d->version());
    }

    /**
     * @param array<string, string> $params
     * @return string batch public id
     */
    public function upload(Authorizer $az, string $code, array $params, ?UploadedFile $file): string
    {
        $d = $this->definition($code);
        $prepared = $d->prepare($az, $params);
        if ($file === null || !$file->ok()) {
            throw new ValidationException(['file' => $file?->errorKey() ?? 'file.error.missing']);
        }
        if ($file->size > self::MAX_BYTES) {
            throw new ValidationException(['file' => 'file.error.too_large']);
        }
        $bytes = $file->bytes();
        $type = FileType::sniff($bytes, $file->safeName());
        if ($type['problem'] !== null) {
            throw new ValidationException(['file' => $type['problem']]);
        }
        if (!in_array($type['type'], ['xlsx', 'csv', 'txt'], true)) {
            throw new ValidationException(['file' => 'import.error.type']);
        }
        $max = $this->maxRows();
        try {
            $rows = $type['type'] === 'xlsx' ? Xlsx::read($bytes, null, $max + 1) : Csv::read($bytes, $max + 1);
        } catch (BusinessException $e) {
            throw new ValidationException(['file' => $e->messageKey]);
        }
        if (count($rows) < 2) {
            throw new ValidationException(['file' => 'import.error.empty']);
        }
        $map = $this->mapHeader($d, $rows[0]);
        $missing = array_values(array_filter($d->columns(), static fn (array $c): bool => $c['required'] && !in_array($c['key'], $map, true)));
        if ($missing !== []) {
            throw new BusinessException('import.error.missing_columns', ['columns' => implode(', ', array_map(fn (array $c): string => $this->translator->get($c['label']), $missing))], 422);
        }
        $data = array_slice($rows, 1);
        if (count($data) > $max) {
            throw new ValidationException(['file' => 'import.error.too_many_rows']);
        }
        $pid = Ulid::generate($this->clock);
        $now = $this->now();
        $this->db->transactional(function () use ($d, $prepared, $data, $map, $az, $file, $bytes, $pid, $now, $code): void {
            $this->db->run(
                'INSERT INTO import_batches (public_id, created_at, created_by, updated_at, updated_by, definition_code, template_version, params_json, legal_entity_id,
                    filename, file_sha256, status, uploaded_by, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'validated\', ?, ?)',
                [$pid, $now, $az->userId, $now, $az->userId, $code, $d->version(), json_encode($prepared, JSON_UNESCAPED_UNICODE), $prepared['legal_entity_id'] ?? null,
                    $file->safeName(), hash('sha256', $bytes), $az->userId, $this->clock->now()->modify('+' . self::TTL_HOURS . ' hours')->format('Y-m-d H:i:s.u')]
            );
            $batchId = (int) $this->db->scalar('SELECT id FROM import_batches WHERE public_id = ?', [$pid]);
            $seen = [];
            $ok = 0;
            $bad = 0;
            $n = 0;
            foreach ($data as $i => $cells) {
                if (implode('', $cells) === '') {
                    continue; // blank line in the middle
                }
                $n++;
                $row = [];
                foreach ($map as $idx => $key) {
                    $row[$key] = (string) ($cells[$idx] ?? '');
                }
                $res = $d->validateRow($row, $prepared, $seen);
                $isOk = $res['errors'] === [];
                if ($isOk) {
                    $ok++;
                } else {
                    $bad++;
                }
                $this->db->run(
                    'INSERT INTO import_rows (batch_id, row_no, status, action, data_enc, errors_json) VALUES (?, ?, ?, ?, ?, ?)',
                    [$batchId, $i + 2, $isOk ? 'ok' : 'error', $res['action'], $this->crypto->encrypt((string) json_encode(['raw' => $row, 'data' => $res['data']], JSON_UNESCAPED_UNICODE)),
                        $isOk ? null : json_encode($res['errors'], JSON_UNESCAPED_UNICODE)]
                );
            }
            $this->db->run('UPDATE import_batches SET total_rows = ?, ok_rows = ?, error_rows = ? WHERE id = ?', [$n, $ok, $bad, $batchId]);
        });
        $b = $this->batchRow($pid);
        $this->audit->record(
            'import.validated',
            'success',
            'import_batch',
            $pid,
            $b['legal_entity_id'] !== null ? (int) $b['legal_entity_id'] : null,
            null,
            ['definition' => $code, 'rows' => (int) $b['total_rows'], 'errors' => (int) $b['error_rows'], 'sha256' => $b['file_sha256']]
        );
        return $pid;
    }

    /**
     * @return array{batch: array<string, mixed>, rows: list<array<string, mixed>>, definition: ImportDefinition, total: int}
     */
    public function preview(Authorizer $az, string $pid, string $filter, int $page, int $per): array
    {
        $b = $this->own($az, $pid);
        $where = 'batch_id = ?';
        $params = [$b['id']];
        if (in_array($filter, ['ok', 'error', 'applied'], true)) {
            $where .= ' AND status = ?';
            $params[] = $filter;
        }
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM import_rows WHERE {$where}", $params);
        $rows = $this->db->all("SELECT * FROM import_rows WHERE {$where} ORDER BY row_no LIMIT {$per} OFFSET " . (($page - 1) * $per), $params);
        foreach ($rows as &$r) {
            $r['payload'] = $r['data_enc'] !== null ? (array) json_decode($this->crypto->decrypt((string) $r['data_enc']), true) : ['raw' => [], 'data' => []];
            $r['errors'] = $r['errors_json'] !== null ? (array) json_decode((string) $r['errors_json'], true) : [];
            unset($r['data_enc']);
        }
        return ['batch' => $b, 'rows' => $rows, 'definition' => $this->definition((string) $b['definition_code']), 'total' => $total];
    }

    /** Applies valid rows. With errors present the user must explicitly choose to skip them. */
    public function confirm(Authorizer $az, string $pid, bool $skipErrors): int
    {
        $b = $this->own($az, $pid);
        if ($b['status'] !== 'validated') {
            throw new BusinessException('import.error.not_pending', [], 409);
        }
        if ((string) $b['expires_at'] < $this->now()) {
            throw new BusinessException('import.error.expired', [], 409);
        }
        if ((int) $b['error_rows'] > 0 && !$skipErrors) {
            throw new BusinessException('import.error.has_errors', [], 422);
        }
        if ((int) $b['ok_rows'] === 0) {
            throw new BusinessException('import.error.nothing', [], 422);
        }
        $d = $this->definition((string) $b['definition_code']);
        $params = (array) json_decode((string) $b['params_json'], true);
        $d->prepare($az, array_map('strval', array_filter($params, 'is_scalar'))); // permission may have changed since upload
        $n = $this->db->transactional(function () use ($b, $d, $az, $params): int {
            $locked = $this->db->one("SELECT status FROM import_batches WHERE id = ? FOR UPDATE", [$b['id']]);
            if (($locked['status'] ?? '') !== 'validated') {
                throw new BusinessException('import.error.not_pending', [], 409); // double click / two tabs
            }
            $rows = [];
            foreach ($this->db->all("SELECT id, data_enc FROM import_rows WHERE batch_id = ? AND status = 'ok' ORDER BY row_no", [$b['id']]) as $r) {
                $rows[] = (array) ((array) json_decode($this->crypto->decrypt((string) $r['data_enc']), true))['data'];
            }
            $n = $d->apply($az, $params, $rows, (int) $b['id']);
            $now = $this->now();
            $this->db->run("UPDATE import_rows SET status = 'applied' WHERE batch_id = ? AND status = 'ok'", [$b['id']]);
            $this->db->run(
                "UPDATE import_batches SET status = 'applied', applied_rows = ?, applied_by = ?, applied_at = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?",
                [$n, $az->userId, $now, $now, $b['id']]
            );
            return $n;
        });
        $this->audit->record(
            'import.applied',
            'success',
            'import_batch',
            $pid,
            $b['legal_entity_id'] !== null ? (int) $b['legal_entity_id'] : null,
            null,
            ['definition' => $b['definition_code'], 'applied' => $n, 'skipped_errors' => (int) $b['error_rows']]
        );
        return $n;
    }

    public function cancel(Authorizer $az, string $pid): void
    {
        $b = $this->own($az, $pid);
        if ($b['status'] === 'validated') {
            $this->db->run("UPDATE import_batches SET status = 'cancelled', updated_at = ? WHERE id = ?", [$this->now(), $b['id']]);
            $this->db->run('UPDATE import_rows SET data_enc = NULL WHERE batch_id = ?', [$b['id']]);
            $this->audit->record('import.cancelled', 'success', 'import_batch', $pid);
        }
    }

    /** Excel file of the rows with errors (row number, original values, messages) to fix and upload again. */
    public function errorReport(Authorizer $az, string $pid): string
    {
        $b = $this->own($az, $pid);
        $d = $this->definition((string) $b['definition_code']);
        $cols = $d->columns();
        $t = $this->translator;
        $out = [];
        foreach ($this->db->all("SELECT * FROM import_rows WHERE batch_id = ? AND status = 'error' ORDER BY row_no", [$b['id']]) as $r) {
            $payload = $r['data_enc'] !== null ? (array) json_decode($this->crypto->decrypt((string) $r['data_enc']), true) : ['raw' => []];
            $msgs = [];
            foreach ((array) json_decode((string) $r['errors_json'], true) as $field => $key) {
                $label = '';
                foreach ($cols as $c) {
                    if ($c['key'] === $field) {
                        $label = $t->get($c['label']) . ': ';
                    }
                }
                $msgs[] = $label . $t->get((string) $key);
            }
            $line = [(string) $r['row_no']];
            foreach ($cols as $c) {
                $line[] = (string) ($payload['raw'][$c['key']] ?? '');
            }
            $line[] = implode(' · ', $msgs);
            $out[] = $line;
        }
        return Xlsx::write(['errors' => ['headers' => array_merge([$t->get('import.field.row')], array_map(static fn (array $c): string => $t->get($c['label']), $cols), [$t->get('import.field.errors')]), 'rows' => $out]]);
    }

    /** Job: wipe row data of batches nobody confirmed (TTL). */
    public function expire(): int
    {
        $ids = $this->db->all("SELECT id FROM import_batches WHERE status = 'validated' AND expires_at < ?", [$this->now()]);
        foreach ($ids as $r) {
            $this->db->run("UPDATE import_batches SET status = 'expired', updated_at = ? WHERE id = ?", [$this->now(), $r['id']]);
            $this->db->run('UPDATE import_rows SET data_enc = NULL WHERE batch_id = ?', [$r['id']]);
        }
        return count($ids);
    }

    /** @return list<array<string, mixed>> the user's recent batches for one definition */
    public function recent(Authorizer $az, string $code): array
    {
        return $this->db->all('SELECT * FROM import_batches WHERE uploaded_by = ? AND definition_code = ? ORDER BY id DESC LIMIT 10', [$az->userId, $code]);
    }

    /** @return array<string, mixed> */
    private function own(Authorizer $az, string $pid): array
    {
        $b = $this->batchRow($pid);
        if ((int) $b['uploaded_by'] !== $az->userId) {
            throw HttpException::notFound(); // import batches are personal working copies
        }
        return $b;
    }

    /** @return array<string, mixed> */
    private function batchRow(string $pid): array
    {
        return $this->db->one('SELECT * FROM import_batches WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
    }

    /**
     * @param list<string> $header
     * @return array<int, string> column index => key
     */
    private function mapHeader(ImportDefinition $d, array $header): array
    {
        $known = [];
        foreach ($d->columns() as $c) {
            $known[self::norm($c['key'])] = $c['key'];
            foreach (['th', 'en'] as $loc) {
                $msgs = $this->translator->messages($loc);
                if (isset($msgs[$c['label']])) {
                    $known[self::norm((string) $msgs[$c['label']])] = $c['key'];
                }
            }
        }
        $map = [];
        foreach ($header as $i => $h) {
            $k = $known[self::norm($h)] ?? null;
            if ($k !== null && !in_array($k, $map, true)) {
                $map[$i] = $k;
            }
        }
        return $map;
    }

    private static function norm(string $s): string
    {
        return mb_strtolower((string) preg_replace('/[\s*:()（）]+/u', '', $s));
    }

    private function maxRows(): int
    {
        $r = $this->registry?->resolveOrNull('import.max_rows');
        return $r !== null && is_int($r->value) ? $r->value : self::DEFAULT_MAX_ROWS;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
