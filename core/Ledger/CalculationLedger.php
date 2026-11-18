<?php

declare(strict_types=1);

namespace Blue\Core\Ledger;

use Blue\Core\Audit\AuditLogger;
use Blue\Core\Database\Connection;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Registry\ResolvedConfig;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Crypto;
use Blue\Core\Support\Ulid;

/**
 * Calculation Ledger (6.26): every business calculation is recorded with the exact configuration versions,
 * inputs, intermediate values and outputs it used — encrypted (they may contain pay data) and hashed.
 *
 * - Idempotency key: recording the same calculation twice returns the first run (no duplicate side effects).
 * - Old runs are never rewritten: a recalculation is a new run linked by supersedes_run_id.
 * - replay() recomputes from the stored snapshot only (no live config) and reports whether the output matches.
 */
final class CalculationLedger
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly Crypto $crypto,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param list<ResolvedConfig> $configs
     * @param array<string, mixed> $inputs
     * @param array<string, mixed> $intermediate
     * @param array<string, mixed> $outputs
     * @param array{legal_entity_id?: ?int, period_ref?: ?string, source_type?: ?string, source_id?: ?string, source_revision?: ?int,
     *              initiated_by?: ?int, initiated_type?: string, supersedes?: ?string, idempotency_key?: ?string, status?: string, warnings?: list<string>} $meta
     * @return string run public id
     */
    public function record(string $calcType, string $engineVersion, string $formulaRef, \DateTimeImmutable $asOf, array $configs, array $inputs, array $intermediate, array $outputs, array $meta = []): string
    {
        $idem = $meta['idempotency_key'] ?? null;
        if ($idem !== null) {
            $existing = $this->db->scalar('SELECT public_id FROM calculation_runs WHERE idempotency_key = ?', [$idem]);
            if ($existing !== null) {
                return (string) $existing;
            }
        }
        $configPayload = array_map(static fn (ResolvedConfig $c): array => $c->toArray(), $configs);
        $inputHash = self::hash(['config' => $configPayload, 'inputs' => $inputs]);
        $outputHash = self::hash($outputs);
        return $this->db->transactional(function () use ($calcType, $engineVersion, $formulaRef, $asOf, $configPayload, $inputs, $intermediate, $outputs, $meta, $idem, $inputHash, $outputHash): string {
            $supersedesId = null;
            if (($meta['supersedes'] ?? null) !== null) {
                $old = $this->db->one('SELECT id, status FROM calculation_runs WHERE public_id = ? FOR UPDATE', [$meta['supersedes']]) ?? throw HttpException::notFound();
                $supersedesId = (int) $old['id'];
                // an approved/posted run keeps its status (the owning domain corrects it by reversal/adjustment);
                // an unapproved run is marked as replaced. Its snapshots are never touched.
                if (in_array($old['status'], ['preview', 'calculated', 'validated'], true)) {
                    $this->db->run("UPDATE calculation_runs SET status = 'recalculated', updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$this->now(), $old['id']]);
                }
            }
            $pid = Ulid::generate($this->clock);
            $now = $this->now();
            $this->db->run(
                'INSERT INTO calculation_runs (public_id, created_at, created_by, updated_at, updated_by, calc_type, legal_entity_id, period_ref, as_of,
                    engine_version, formula_ref, status, initiated_by, initiated_type, supersedes_run_id, source_type, source_id, source_revision,
                    correlation_id, idempotency_key, input_hash, output_hash, warnings_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $now, $meta['initiated_by'] ?? null, $now, $meta['initiated_by'] ?? null, $calcType, $meta['legal_entity_id'] ?? null, $meta['period_ref'] ?? null,
                    $asOf->format('Y-m-d H:i:s.u'), $engineVersion, $formulaRef, $meta['status'] ?? 'calculated', $meta['initiated_by'] ?? null,
                    $meta['initiated_type'] ?? 'user', $supersedesId, $meta['source_type'] ?? null, $meta['source_id'] ?? null, $meta['source_revision'] ?? null,
                    null, $idem, $inputHash, $outputHash, isset($meta['warnings']) ? json_encode($meta['warnings']) : null]
            );
            $runId = (int) $this->db->pdo()->lastInsertId();
            foreach (['config_versions' => $configPayload, 'inputs' => $inputs, 'intermediate' => $intermediate, 'outputs' => $outputs] as $kind => $payload) {
                $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION); // 1.0 stays a float when read back (open() re-hashes)
                $this->db->run(
                    'INSERT INTO calculation_snapshots (run_id, kind, payload_enc, payload_sha256, created_at) VALUES (?, ?, ?, ?, ?)',
                    [$runId, $kind, $this->crypto->encrypt($json), hash('sha256', $json), $now]
                );
            }
            $this->audit->record('calculation.recorded', 'success', 'calculation_run', $pid, $meta['legal_entity_id'] ?? null, null, ['type' => $calcType, 'supersedes' => $meta['supersedes'] ?? null]);
            return $pid;
        });
    }

    /**
     * Opens a run exactly as it was calculated (config versions, inputs, outputs) — verified against the stored hashes.
     *
     * @return array{run: array<string,mixed>, config_versions: list<array<string,mixed>>, inputs: array<string,mixed>, intermediate: array<string,mixed>, outputs: array<string,mixed>, intact: bool}
     */
    public function open(string $runPublicId): array
    {
        $run = $this->db->one('SELECT * FROM calculation_runs WHERE public_id = ?', [$runPublicId]) ?? throw HttpException::notFound();
        $out = ['run' => $run, 'config_versions' => [], 'inputs' => [], 'intermediate' => [], 'outputs' => [], 'intact' => true];
        foreach ($this->db->all('SELECT kind, payload_enc, payload_sha256 FROM calculation_snapshots WHERE run_id = ?', [$run['id']]) as $s) {
            $json = $this->crypto->decrypt((string) $s['payload_enc']);
            if (!hash_equals((string) $s['payload_sha256'], hash('sha256', $json))) {
                $out['intact'] = false;
            }
            $out[(string) $s['kind']] = json_decode($json, true) ?? [];
        }
        if (!hash_equals((string) $run['output_hash'], self::hash($out['outputs']))) {
            $out['intact'] = false;
        }
        return $out;
    }

    /**
     * Re-runs the formula on the stored snapshot only. No rows are written (no side effects).
     *
     * @param callable(array<string,mixed> $inputs, array<string,mixed> $configByKey): array<string,mixed> $formula
     * @return array{matches: bool, outputs: array<string,mixed>}
     */
    public function replay(string $runPublicId, callable $formula): array
    {
        $snap = $this->open($runPublicId);
        $config = [];
        foreach ($snap['config_versions'] as $c) {
            $config[(string) $c['config_key']] = $c['resolved_value'];
        }
        $outputs = $formula($snap['inputs'], $config);
        return ['matches' => self::hash($outputs) === (string) $snap['run']['output_hash'], 'outputs' => $outputs];
    }

    /** Status transitions of a run (validated → approved → posted …). The calculation itself never changes. */
    public function transition(string $runPublicId, string $to): void
    {
        $allowed = ['calculated' => ['validated', 'approved'], 'validated' => ['approved'], 'approved' => ['posted'], 'posted' => ['reversed']];
        $run = $this->db->one('SELECT id, status FROM calculation_runs WHERE public_id = ?', [$runPublicId]) ?? throw HttpException::notFound();
        if (!in_array($to, $allowed[(string) $run['status']] ?? [], true)) {
            throw new BusinessException('calc.error.transition', ['from' => (string) $run['status'], 'to' => $to], 409);
        }
        $this->db->run('UPDATE calculation_runs SET status = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?', [$to, $this->now(), $run['id']]);
        $this->audit->record('calculation.' . $to, 'success', 'calculation_run', $runPublicId);
    }

    /** @return list<array<string, mixed>> runs of a source record, newest first, with the supersede chain */
    public function history(string $sourceType, string $sourceId): array
    {
        return $this->db->all(
            'SELECT r.public_id, r.calc_type, r.status, r.as_of, r.engine_version, r.formula_ref, r.created_at, s.public_id AS supersedes
               FROM calculation_runs r LEFT JOIN calculation_runs s ON s.id = r.supersedes_run_id
              WHERE r.source_type = ? AND r.source_id = ? ORDER BY r.id DESC',
            [$sourceType, $sourceId]
        );
    }

    /** Stable hash of a value (keys sorted) — the same data always gives the same hash. */
    public static function hash(mixed $data): string
    {
        $norm = static function (mixed $v) use (&$norm): mixed {
            if (is_array($v)) {
                if (!array_is_list($v)) {
                    ksort($v);
                }
                return array_map($norm, $v);
            }
            return $v;
        };
        return hash('sha256', (string) json_encode($norm($data), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
