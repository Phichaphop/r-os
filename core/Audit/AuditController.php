<?php

declare(strict_types=1);

namespace Blue\Core\Audit;

use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;

/** Audit trail viewer + CSV export (P0-04 §7: AUD/PRV/SYS read; viewing and exporting are themselves audited). */
final class AuditController extends Controller
{
    public function index(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $ctx->require('core.audit.view');
        [$where, $params, $filters] = $this->filters($r);
        $paging = \Blue\Core\Ui\Paging::from($r, $this->app->preferences(), $ctx->userId(), 'audit');
        $db = $this->app->db();
        $total = (int) $db->scalar("SELECT COUNT(*) FROM audit_events e LEFT JOIN users u ON u.id = e.actor_id WHERE {$where}", $params);
        $paging->clamp($total);
        $page = $paging->page;
        $per = $paging->per;
        $rows = $db->all(
            "SELECT e.public_id, e.occurred_at, e.actor_type, u.username, e.action, e.target_type, e.target_id, e.legal_entity_id, e.outcome, e.reason, e.correlation_id, e.diff_json
               FROM audit_events e LEFT JOIN users u ON u.id = e.actor_id WHERE {$where} ORDER BY e.id DESC LIMIT {$per} OFFSET " . (($page - 1) * $per),
            $params
        );
        if ($page === 1) {
            $this->app->audit()->record('audit.view', 'success', null, null, null, http_build_query($filters));
        }
        return $this->page('admin/audit', [
            'title' => $this->app->translator()->get('audit.title'),
            'rows' => $rows, 'total' => $total, 'page' => $page, 'per' => $per, 'filters' => $filters,
            'canExport' => $ctx->can('core.audit.export'),
            'guard' => (string) ($db->scalar("SELECT info_value FROM system_info WHERE info_key = 'audit_guard'") ?? 'unknown'),
        ]);
    }

    public function export(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $ctx->require('core.audit.export');
        $ctx->requireFreshReauth();
        [$where, $params, $filters] = $this->filters($r);
        $rows = $this->app->db()->all(
            "SELECT e.public_id, e.occurred_at, e.actor_type, u.username, e.action, e.target_type, e.target_id, e.legal_entity_id, e.outcome, e.reason, e.correlation_id
               FROM audit_events e LEFT JOIN users u ON u.id = e.actor_id WHERE {$where} ORDER BY e.id DESC LIMIT 50000",
            $params
        );
        $this->app->audit()->record('audit.export', 'success', null, null, null, http_build_query($filters) . ' rows=' . count($rows));
        $format = ($r->query['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv';
        return \Blue\Core\Files\TableExport::response(
            $format,
            'audit-' . $this->app->clock->now()->format('Ymd-His'),
            ['id', 'occurred_at_utc', 'actor_type', 'actor', 'action', 'target_type', 'target_id', 'legal_entity_id', 'outcome', 'reason', 'correlation_id'],
            array_map(static fn (array $row): array => array_values($row), $rows)
        );
    }

    /** CSV formula-injection guard (6.10): cells starting with = + - @ are prefixed with an apostrophe. */
    public static function cell(mixed $v): string
    {
        return \Blue\Core\Files\Csv::cell($v);
    }

    /** @return array{0:string, 1:list<mixed>, 2:array<string,string>} */
    private function filters(Request $r): array
    {
        $f = [
            'action' => $this->str($r, 'action', 80),
            'actor' => $this->str($r, 'actor', 60),
            'outcome' => $this->str($r, 'outcome', 10),
            'from' => $this->str($r, 'from', 10),
            'to' => $this->str($r, 'to', 10),
        ];
        $where = '1=1';
        $params = [];
        if ($f['action'] !== '') {
            $where .= ' AND e.action LIKE ?';
            $params[] = addcslashes($f['action'], '%_\\') . '%';
        }
        if ($f['actor'] !== '') {
            $where .= ' AND u.username = ?';
            $params[] = mb_strtolower($f['actor']);
        }
        if (in_array($f['outcome'], ['success', 'denied', 'failure'], true)) {
            $where .= ' AND e.outcome = ?';
            $params[] = $f['outcome'];
        }
        foreach (['from' => '>=', 'to' => '<'] as $k => $op) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k])) {
                $date = $k === 'to' ? (new \DateTimeImmutable($f[$k]))->modify('+1 day')->format('Y-m-d') : $f[$k];
                $where .= " AND e.occurred_at {$op} ?";
                $params[] = $date . ' 00:00:00';
            }
        }
        return [$where, $params, array_filter($f, static fn ($v) => $v !== '')];
    }
}
