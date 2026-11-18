<?php

declare(strict_types=1);

namespace Blue\Core\Numbering;

use Blue\Core\Audit\AuditLogger;
use Blue\Core\Database\Connection;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Support\Clock;

/**
 * Document numbering (6.30.10): one sequence per (company, site, document type, period); the row is locked
 * with SELECT … FOR UPDATE so concurrent requests never get the same number. Every issued number is recorded;
 * a number is never reused — a cancelled document's number is VOIDED with a reason, not given out again.
 * Numbers are not secrets and are never used as access tokens (records are reached by public_id + permission).
 *
 * Pattern tokens: {SEQ:n} zero-padded sequence (required), {YYYY} {YY} {MM} from the period key, {SITE} site code.
 */
final class NumberingService
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
    ) {
    }

    public function next(int $legalEntityId, ?int $siteId, string $docType, string $periodKey, string $pattern, ?string $refType = null, ?string $refId = null, ?int $actorId = null): string
    {
        if (!preg_match('/\{SEQ:([1-9]|1[0-2])\}/', $pattern) || !preg_match('/^[0-9A-Za-z]{2,10}$/', $periodKey) || !preg_match('/^[a-z0-9_.]{2,40}$/', $docType)) {
            throw new BusinessException('numbering.error.pattern', [], 422);
        }
        $site = $siteId ?? 0;
        return $this->db->transactional(function () use ($legalEntityId, $site, $docType, $periodKey, $pattern, $refType, $refId, $actorId): string {
            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            $this->db->run(
                'INSERT IGNORE INTO number_sequences (legal_entity_id, site_id, doc_type, period_key, prefix_pattern, next_value, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?)',
                [$legalEntityId, $site, $docType, $periodKey, $pattern, $now]
            );
            $seq = $this->db->one(
                'SELECT id, next_value, prefix_pattern FROM number_sequences WHERE legal_entity_id = ? AND site_id = ? AND doc_type = ? AND period_key = ? FOR UPDATE',
                [$legalEntityId, $site, $docType, $periodKey]
            ) ?? throw new BusinessException('numbering.error.sequence', [], 500);
            $value = (int) $seq['next_value'];
            $siteCode = $site > 0 ? (string) ($this->db->scalar('SELECT code FROM sites WHERE id = ?', [$site]) ?? '') : '';
            $docNo = self::format((string) $seq['prefix_pattern'], $value, $periodKey, $siteCode);
            $this->db->run('UPDATE number_sequences SET next_value = next_value + 1, updated_at = ? WHERE id = ?', [$now, $seq['id']]);
            $this->db->run(
                'INSERT INTO number_issues (sequence_id, doc_no, seq_value, status, ref_type, ref_id, issued_by, issued_at) VALUES (?, ?, ?, \'issued\', ?, ?, ?, ?)',
                [$seq['id'], $docNo, $value, $refType, $refId, $actorId, $now]
            );
            return $docNo;
        });
    }

    /** Voids an issued number (it stays used — never reissued). */
    public function void(int $legalEntityId, string $docNo, string $reason, ?int $actorId = null): void
    {
        if (trim($reason) === '') {
            throw new BusinessException('iam.error.reason_required', [], 422);
        }
        $n = $this->db->run(
            "UPDATE number_issues ni JOIN number_sequences s ON s.id = ni.sequence_id SET ni.status = 'voided', ni.void_reason = ?
              WHERE s.legal_entity_id = ? AND ni.doc_no = ? AND ni.status = 'issued'",
            [$reason, $legalEntityId, $docNo]
        )->rowCount();
        if ($n !== 1) {
            throw HttpException::notFound();
        }
        $this->audit->record('numbering.voided', 'success', 'document_number', $docNo, $legalEntityId, $reason);
    }

    public static function format(string $pattern, int $value, string $periodKey, string $siteCode = ''): string
    {
        $year = preg_match('/^(\d{4})/', $periodKey, $m) ? $m[1] : '';
        $month = preg_match('/^\d{4}(\d{2})/', $periodKey, $mm) ? $mm[1] : '';
        $out = preg_replace_callback('/\{SEQ:(\d+)\}/', static fn (array $m): string => str_pad((string) $value, (int) $m[1], '0', STR_PAD_LEFT), $pattern) ?? $pattern;
        return strtr($out, ['{YYYY}' => $year, '{YY}' => substr($year, 2), '{MM}' => $month, '{SITE}' => $siteCode]);
    }
}
