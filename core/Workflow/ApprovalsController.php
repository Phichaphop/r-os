<?php

declare(strict_types=1);

namespace Blue\Core\Workflow;

use DateTimeImmutable;
use DateTimeZone;
use Blue\Core\Authz\Catalog;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\I18n\DateFormat;
use Blue\Core\Registry\ConfigValue;
use Blue\Core\Ui\Paging;

/** Approval Inbox (6.9 item 2), My Requests (6.9b), request detail + decisions, delegation (6.30.3). */
final class ApprovalsController extends Controller
{
    public function inbox(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $paging = Paging::from($r, $this->app->preferences(), $ctx->userId(), 'approvals');
        $wf = $this->app->workflow();
        $list = $wf->inbox($ctx->userId(), $paging->page, $paging->per);
        if ($paging->clamp($list['total'])) {
            $list = $wf->inbox($ctx->userId(), $paging->page, $paging->per);
        }
        return $this->page('approvals/inbox', [
            'title' => $this->app->translator()->get('workflow.inbox.title'),
            'list' => $list, 'page' => $paging->page, 'per' => $paging->per,
            'delegations' => $wf->delegationsOf($ctx->userId()),
        ]);
    }

    /** Delegation of my approvals while I am away (6.30.3) — its own page under the approval menu. */
    public function delegatePage(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $users = $this->app->db()->all("SELECT id, username, display_name FROM users WHERE status = 'active' AND id <> ? ORDER BY username LIMIT 300", [$ctx->userId()]);
        return $this->page('approvals/delegate', [
            'title' => $this->app->translator()->get('workflow.delegate.title'),
            'delegations' => $this->app->workflow()->delegationsOf($ctx->userId()),
            'users' => $users,
            'errors' => $this->store($r)->get('delegation_errors') ?? [],
            // "today" where the people are, not in UTC (after midnight Bangkok it is already the next day)
            'today' => $this->app->clock->now()->setTimezone(new DateTimeZone((string) ($this->app->org()->organization()['default_timezone'] ?? 'Asia/Bangkok')))->format('Y-m-d'),
        ]);
    }

    public function mine(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $paging = Paging::from($r, $this->app->preferences(), $ctx->userId(), 'requests');
        $status = $this->str($r, 'status', 20);
        $wf = $this->app->workflow();
        $list = $wf->myRequests($ctx->userId(), $status, $paging->page, $paging->per);
        if ($paging->clamp($list['total'])) {
            $list = $wf->myRequests($ctx->userId(), $status, $paging->page, $paging->per);
        }
        return $this->page('approvals/mine', [
            'title' => $this->app->translator()->get('workflow.mine.title'),
            'list' => $list, 'page' => $paging->page, 'per' => $paging->per, 'status' => $status,
        ]);
    }

    public function show(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $d = $this->app->workflow()->detail((string) $r->params['id'], $ctx->az());
        $inst = $d['instance'];
        return $this->page('approvals/show', [
            'title' => (string) $inst['title'],
            'd' => $d,
            'summary' => $this->summary($inst),
            'isRequester' => (int) $inst['requester_id'] === $ctx->userId(),
            'canRetry' => $ctx->az()->scope('core.workflow.manage')->all && $inst['status'] === 'approved' && $inst['execution_status'] === 'failed',
            'from' => str_starts_with($r->path, '/requests') ? 'requests' : 'approvals',
        ]);
    }

    public function decide(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $id = (string) $r->params['id'];
        $decision = $this->str($r, 'decision', 10);
        $inst = $this->app->workflow()->instanceRow($id);
        if ($decision === 'approve' && $this->highRisk($inst)) {
            $ctx->requireFreshReauth();
        }
        try {
            $this->app->workflow()->decide($id, $ctx->userId(), $decision, $this->str($r, 'comment', 500));
        } catch (ValidationException) {
            $this->flash($r, 'danger', 'workflow.error.comment_required');
            return $this->redirect($r, '/approvals/' . $id);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException && $e->httpStatus === 404) {
                throw $e;
            }
            $this->flash($r, 'danger', $e instanceof HttpException ? 'workflow.error.not_assignee' : $e->messageKey);
            return $this->redirect($r, '/approvals/' . $id);
        }
        $after = $this->app->workflow()->instanceRow($id);
        $key = match (true) {
            $after['status'] === 'approved' && $after['execution_status'] === 'failed' => 'workflow.flash.execution_failed',
            $after['status'] === 'approved' => 'workflow.flash.approved',
            $after['status'] === 'pending' => 'workflow.flash.next_step',
            default => 'workflow.flash.' . $after['status'],
        };
        $this->flash($r, $after['execution_status'] === 'failed' ? 'warning' : 'success', $key);
        return $this->redirect($r, '/approvals');
    }

    public function retry(Request $r): Response
    {
        $id = (string) $r->params['id'];
        $this->ctx($r)->requireFreshReauth();
        try {
            $this->app->workflow()->retryExecution($id, $this->ctx($r)->az());
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/approvals/' . $id);
        }
        $ok = $this->app->workflow()->instanceRow($id)['execution_status'] === 'succeeded';
        $this->flash($r, $ok ? 'success' : 'warning', $ok ? 'workflow.flash.approved' : 'workflow.flash.execution_failed');
        return $this->redirect($r, '/approvals/' . $id);
    }

    public function cancel(Request $r): Response
    {
        $id = (string) $r->params['id'];
        $reason = $this->str($r, 'reason', 255);
        if ($reason === '') {
            $this->flash($r, 'danger', 'iam.error.reason_required');
            return $this->redirect($r, '/requests/' . $id);
        }
        try {
            $this->app->workflow()->cancel($id, $this->ctx($r)->userId(), $reason);
            $this->flash($r, 'success', 'workflow.flash.cancelled');
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
        }
        return $this->redirect($r, '/requests/' . $id);
    }

    public function resubmit(Request $r): Response
    {
        $id = (string) $r->params['id'];
        try {
            $this->app->workflow()->resubmit($id, $this->ctx($r)->userId(), $this->str($r, 'comment', 500));
            $this->flash($r, 'success', 'workflow.flash.resubmitted');
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
        }
        return $this->redirect($r, '/requests/' . $id);
    }

    public function delegate(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $tz = new DateTimeZone((string) ($this->app->org()->organization()['default_timezone'] ?? 'Asia/Bangkok'));
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', $this->str($r, 'valid_from', 10), $tz);
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', $this->str($r, 'valid_to', 10), $tz);
        try {
            if ($from === false || $to === false) {
                throw new ValidationException(['valid_to' => 'form.invalid_date']);
            }
            // the end date is inclusive for people: the delegation ends at the start of the next day
            $this->app->workflow()->delegate(
                $ctx->userId(),
                (int) $r->input('to_user', 0),
                $from->setTimezone(new DateTimeZone('UTC')),
                $to->modify('+1 day')->setTimezone(new DateTimeZone('UTC')),
                $this->str($r, 'reason', 255)
            );
        } catch (ValidationException $e) {
            $this->store($r)->set('delegation_errors', $this->errors($e));
            $this->flash($r, 'danger', 'form.has_errors');
            return $this->redirect($r, '/approvals/delegate');
        }
        $this->store($r)->forget('delegation_errors');
        $this->flash($r, 'success', 'workflow.flash.delegated');
        return $this->redirect($r, '/approvals/delegate');
    }

    public function revokeDelegation(Request $r): Response
    {
        $this->app->workflow()->revokeDelegation((string) $r->params['id'], $this->ctx($r)->userId());
        $this->flash($r, 'success', 'workflow.flash.delegation_revoked');
        return $this->redirect($r, '/approvals/delegate');
    }

    /** @param array<string, mixed> $inst */
    private function highRisk(array $inst): bool
    {
        return match ($inst['subject_type']) {
            'config_version' => (string) $this->app->db()->scalar(
                'SELECT d.risk_level FROM config_versions v JOIN config_definitions d ON d.id = v.definition_id WHERE v.public_id = ?',
                [$inst['subject_id']]
            ) === 'high',
            'access_request' => (bool) (Catalog::ROLES[(string) $this->app->db()->scalar('SELECT role_code FROM access_requests WHERE public_id = ?', [$inst['subject_id']])][1] ?? false),
            'amendment', 'lock_reopen', 'retention_rule', 'legal_hold', 'disposition', 'posting_rule', 'journal', 'payment_batch', 'restore_request' => true,
            default => false,
        };
    }

    /**
     * What is being decided — shown to the approver next to the decision buttons (6.28.2: diff, impact, context).
     *
     * @param array<string, mixed> $inst
     * @return array{rows: list<array{0: string, 1: string}>, changes: list<array<string, mixed>>, link: ?string}
     */
    private function summary(array $inst): array
    {
        $t = $this->app->translator();
        $db = $this->app->db();
        $rows = [];
        $changes = [];
        $link = null;
        switch ($inst['subject_type']) {
            case 'config_version':
                $v = $this->app->registry()->version((string) $inst['subject_id']);
                $impact = $this->app->registry()->impact($v);
                $rows[] = [$t->get('config.field.key'), (string) $v['config_key']];
                $describe = (string) ($this->app->registry()->owner((string) $v['config_key'])['describe'] ?? '');
                if ($describe !== '' && is_callable($describe)) { // module-owned key: the module explains its value (e.g. leave policy per type)
                    foreach ($describe($this->app, json_decode((string) $v['value_json'], true)) as $row) {
                        $rows[] = $row;
                    }
                    $rows[] = [$t->get('config.impact.before'), $impact['current'] === null ? $t->get('config.no_value') : $t->get('config.has_value')];
                } else {
                    $rows[] = [$t->get('config.field.value'), ConfigValue::display(json_decode((string) $v['value_json'], true)) . ' ' . (string) $v['unit']];
                    $rows[] = [$t->get('config.impact.before'), $impact['current'] === null ? $t->get('config.no_value') : ConfigValue::display($impact['current']->value)];
                }
                $scopeCode = match ((string) $v['scope_level']) { // the code people know (DEMO1, BR1) instead of an internal number
                    'legal_entity' => $this->app->db()->scalar('SELECT code FROM legal_entities WHERE id = ?', [$v['scope_id']]),
                    'site' => $this->app->db()->scalar('SELECT code FROM sites WHERE id = ?', [$v['scope_id']]),
                    default => null,
                };
                $rows[] = [$t->get('config.field.scope'), $t->get('config.scope.' . $v['scope_level']) . ($v['scope_level'] !== 'organization' ? ' ' . (is_string($scopeCode) ? $scopeCode : '#' . $v['scope_id']) : '')];
                $tz = $this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok');
                $rows[] = [$t->get('config.field.effective'), DateFormat::display((string) $v['effective_from'], $t->locale(), $tz, true) . ' → '
                    . ($v['effective_to'] !== null ? DateFormat::display((string) $v['effective_to'], $t->locale(), $tz, true) : $t->get('config.open_ended'))];
                $rows[] = [$t->get('config.field.risk'), $t->get('config.risk.' . $v['risk_level'])];
                foreach ($impact['warnings'] as $w) {
                    $rows[] = [$t->get('config.impact.warning'), $t->get($w)];
                }
                $link = '/admin/config/version/' . $v['public_id'];
                break;
            case 'access_request':
                $a = $this->app->accessRequests()->bySubject((string) $inst['subject_id']) ?? [];
                $rows[] = [$t->get('access.field.user'), ($a['display_name'] ?? '') . ' (' . ($a['username'] ?? '') . ')'];
                if (($a['module_code'] ?? null) !== null) {
                    $rows[] = [$t->get('access.field.app'), \Blue\Core\Ui\AppHub::appLabel($this->app, (string) $a['module_code'])];
                }
                $rows[] = [$t->get('access.field.role'), ($a['role_code'] ?? '') . ' — ' . $t->get('role.' . ($a['role_code'] ?? ''))];
                $table = ['legal_entity' => 'legal_entities', 'site' => 'sites', 'department' => 'departments'][$a['scope_level'] ?? ''] ?? null;
                $code = $table !== null ? (string) ($db->scalar("SELECT code FROM {$table} WHERE id = ?", [$a['scope_id'] ?? 0]) ?? '') : '';
                $rows[] = [$t->get('access.field.scope'), $t->get('iam.scope.' . ($a['scope_level'] ?? 'organization')) . ' ' . $code];
                $rows[] = [$t->get('iam.field.reason'), (string) ($a['reason'] ?? '')];
                if ((bool) (Catalog::ROLES[(string) ($a['role_code'] ?? '')][1] ?? false)) {
                    $rows[] = [$t->get('config.impact.warning'), $t->get('access.high_risk_note')];
                }
                break;
            case 'amendment':
                $am = $this->app->amendments()->get((string) $inst['subject_id']);
                $rows[] = [$t->get('amend.field.target'), $am['request']['target_type'] . ' ' . $am['request']['target_id'] . ' (rev ' . $am['request']['target_revision'] . ')'];
                $rows[] = [$t->get('amend.field.action'), $t->get('amend.action.' . $am['request']['action'])];
                $rows[] = [$t->get('iam.field.reason'), $t->get('amend.reason.' . $am['request']['reason_code']) . ' — ' . $am['request']['reason']];
                $changes = $am['changes'];
                break;
            case 'retention_rule':
                $rule = $this->app->retention()->get((string) $inst['subject_id']);
                $cur = $this->app->retention()->current((string) $rule['record_class']);
                $rows[] = [$t->get('records.field.record_class'), $t->get('records.class.' . $rule['record_class'])];
                $rows[] = [$t->get('records.field.rule'), $t->get('records.rule_summary', ['months' => (int) $rule['duration_months'], 'trigger' => $t->get('records.trigger.' . $rule['trigger_event']), 'action' => $t->get('records.action.' . $rule['disposition_action'])])];
                $rows[] = [$t->get('config.impact.before'), $cur === null ? $t->get('records.no_rule') : $t->get('records.months_n', ['n' => (int) $cur['duration_months']])];
                $rows[] = [$t->get('records.field.source'), (string) ($rule['source_reference'] ?? '')];
                $rows[] = [$t->get('records.field.effective_from'), (string) $rule['effective_from']];
                $rows[] = [$t->get('iam.field.reason'), (string) $rule['reason']];
                $link = '/admin/records/rules/' . $rule['record_class'];
                break;
            case 'legal_hold':
                $h = $this->app->holds()->get((string) $inst['subject_id']);
                $rows[] = [$t->get('records.field.reference'), (string) $h['reference_code']];
                $rows[] = [$t->get('records.field.scope'), $t->get('records.scope.' . $h['scope_type'])];
                $rows[] = [$t->get('records.hold.reason_placed'), (string) $h['reason']];
                $rows[] = [$t->get('records.release_reason'), (string) $h['release_reason']];
                $link = '/admin/records/holds#' . $h['public_id'];
                break;
            case 'disposition':
                $b = $this->app->db()->one('SELECT * FROM disposition_batches WHERE public_id = ?', [$inst['subject_id']]) ?? [];
                $rows[] = [$t->get('records.items'), (string) ($b['item_count'] ?? 0)];
                $rows[] = [$t->get('iam.field.reason'), (string) ($b['reason'] ?? '')];
                $rows[] = [$t->get('config.impact.warning'), $t->get('records.disposition.approve_warning')];
                $link = '/admin/records/disposition/' . $inst['subject_id'];
                break;
            case 'posting_rule':
                $pr = $this->app->postingRules()->get((string) $inst['subject_id']);
                $acc = fn (int $id): string => (string) ($db->scalar("SELECT CONCAT(code, ' · ', name) FROM fin_accounts WHERE id = ?", [$id]) ?? '?');
                $rows[] = [$t->get('finance.field.company'), (string) $db->scalar('SELECT code FROM legal_entities WHERE id = ?', [$pr['legal_entity_id']])];
                $rows[] = [$t->get('finance.field.source_module'), $pr['source_module'] . ' / ' . $pr['line_type'] . ' · v' . $pr['version_no']];
                $rows[] = [$t->get('finance.field.debit'), $acc((int) $pr['debit_account_id'])];
                $rows[] = [$t->get('finance.field.credit'), $acc((int) $pr['credit_account_id'])];
                $rows[] = [$t->get('records.field.effective_from'), (string) $pr['effective_from']];
                $rows[] = [$t->get('records.field.source'), (string) ($pr['source_reference'] ?? '')];
                $rows[] = [$t->get('iam.field.reason'), (string) $pr['reason']];
                $link = '/finance/rules?le=' . $pr['legal_entity_id'];
                break;
            case 'journal':
                $j = $this->app->journals()->row((string) $inst['subject_id']);
                $rows[] = [$t->get('finance.field.company'), (string) $db->scalar('SELECT code FROM legal_entities WHERE id = ?', [$j['legal_entity_id']])];
                $rows[] = [$t->get('finance.field.date'), (string) $j['journal_date']];
                $rows[] = [$t->get('finance.field.description'), (string) $j['description']];
                $rows[] = [$t->get('finance.field.source'), (string) $j['source_module'] . ($j['source_ref'] !== null ? ' · ' . $j['source_ref'] : '')];
                foreach (array_slice($this->app->journals()->lines((int) $j['id']), 0, 12) as $l) {
                    $dr = \Blue\Core\Support\Money::fromDb($l['debit']);
                    $rows[] = [$l['account_code'] . ' · ' . $l['account_name'], ($dr > 0 ? $t->get('finance.dr') . ' ' . \Blue\Core\Support\Money::format($dr) : $t->get('finance.cr') . ' ' . \Blue\Core\Support\Money::format(\Blue\Core\Support\Money::fromDb($l['credit'])))];
                }
                $rows[] = [$t->get('finance.field.total'), \Blue\Core\Support\Money::format(\Blue\Core\Support\Money::fromDb($j['total_debit'])) . ' ' . $j['currency_code']];
                if ($j['reversal_of_id'] !== null) {
                    $rows[] = [$t->get('config.impact.warning'), $t->get('finance.reversal_note')];
                }
                $link = '/finance/journals/' . $j['public_id'];
                break;
            case 'payment_batch':
                $pb = $this->app->payments()->row((string) $inst['subject_id']);
                $rows[] = [$t->get('finance.field.company'), (string) $db->scalar('SELECT code FROM legal_entities WHERE id = ?', [$pb['legal_entity_id']])];
                $rows[] = [$t->get('payment.field.batch_no'), (string) $pb['batch_no'] . ' · ' . $t->get('payment.type.' . $pb['payment_type'])];
                $rows[] = [$t->get('payment.field.pay_date'), (string) $pb['pay_date']];
                $rows[] = [$t->get('payment.field.items'), (string) $pb['item_count']];
                $rows[] = [$t->get('payment.field.total'), \Blue\Core\Support\Money::format(\Blue\Core\Support\Money::fromDb($pb['total_amount'])) . ' ' . $pb['currency_code']];
                if ($pb['expected_total'] !== null) {
                    $rows[] = [$t->get('payment.field.expected_total'), \Blue\Core\Support\Money::format(\Blue\Core\Support\Money::fromDb($pb['expected_total']))];
                }
                $rows[] = [$t->get('payment.field.source'), $t->get('payment.source.' . $pb['source_type']) . ($pb['source_id'] !== null ? ' · ' . $pb['source_id'] : '')];
                $link = '/payments/' . $pb['public_id'];
                break;
            case 'restore_request':
                $rq = $this->app->restores()->requestRow((string) $inst['subject_id']);
                $rows[] = [$t->get('backup.field.backup'), (string) $rq['backup_started'] . ' UTC'];
                $rows[] = [$t->get('backup.field.incident'), (string) $rq['incident_ref']];
                $rows[] = [$t->get('iam.field.reason'), (string) $rq['reason']];
                $rows[] = [$t->get('config.impact.warning'), $t->get('backup.restore.approve_warning')];
                $link = '/admin/backups/restore/' . $rq['public_id'];
                break;
            case 'lock_reopen':
                $l = $this->app->locks()->history((string) $inst['subject_id'])['lock'];
                $rows[] = [$t->get('lock.field.type'), (string) $l['lock_type']];
                $rows[] = [$t->get('lock.field.period'), (string) $l['period_ref'] . ' · rev ' . $l['revision_no']];
                $rows[] = [$t->get('iam.field.reason'), (string) $l['reopen_reason']];
                break;
            default: // module subjects describe themselves (DescribedSubject)
                $subject = $this->app->workflow()->subject((string) $inst['subject_type']);
                if ($subject instanceof \Blue\Core\Workflow\DescribedSubject) {
                    $d = $subject->describe($inst);
                    $rows = $d['rows'];
                    $link = $d['link'];
                }
        }
        return ['rows' => $rows, 'changes' => $changes, 'link' => $link];
    }
}
