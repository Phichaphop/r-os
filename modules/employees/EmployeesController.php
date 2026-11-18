<?php

declare(strict_types=1);

namespace Blue\Modules\Employees;

use Blue\Core\Authz\Authorizer;
use Blue\Core\Authz\ScopeSet;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\Xlsx;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Ui\Paging;

/**
 * M09 Employee Records (DEC-63 → completed in 5A, DEC-67): list, add, and the employee page with tabs
 * (overview · personal · employment · file · changes · log) — every tab and every action checks its own permission.
 */
final class EmployeesController extends Controller
{
    private const PERSON = ['employee_code' => 30, 'name_th' => 120, 'name_en' => 120, 'preferred_name' => 60, 'hire_date' => 10];
    private const JOB = ['legal_entity_id' => 12, 'site_id' => 12, 'department_id' => 12, 'position_title' => 120, 'manager_employee_id' => 12,
        'employee_group' => 20, 'employment_type' => 12, 'effective_from' => 10, 'change_reason' => 255];
    public const TABS = ['overview', 'personal', 'job', 'file', 'changes', 'log'];

    public function index(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $az = $ctx->az();
        $svc = EmployeeService::of($this->app);
        $f = ['q' => $this->str($r, 'q', 60), 'le' => (int) $r->input('le', 0), 'status' => $this->str($r, 'status', 10)];
        $paging = Paging::from($r, $this->app->preferences(), $ctx->userId(), 'employees');
        $res = $svc->list($az, $f['q'], $f['le'] ?: null, $f['status'], $paging->page, $paging->per);
        if ($paging->clamp($res['total'])) {
            $res = $svc->list($az, $f['q'], $f['le'] ?: null, $f['status'], $paging->page, $paging->per);
        }
        return $this->page('@employees/index', [
            'title' => $this->app->translator()->get('employees.title'), 'rows' => $res['rows'], 'total' => $res['total'], 'page' => $paging->page, 'per' => $paging->per,
            'f' => $f, 'org' => $this->orgLabels($az, 'employees.view'), 'canManage' => !$az->scope('employees.manage')->isEmpty(),
            'canReport' => !$az->scope('employees.report')->isEmpty(),
        ]);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    public function createForm(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        if ($az->scope('employees.manage')->isEmpty()) {
            throw HttpException::forbidden();
        }
        $org = $this->orgLabels($az, 'employees.manage');
        return $this->page('@employees/new', [
            'title' => $this->app->translator()->get('employees.new'), 'org' => $org, 'managers' => EmployeeService::of($this->app)->managerOptions($az->scope('employees.view')->legalEntities()),
            'errors' => $errors, 'old' => $old + ['employment_type' => 'permanent', 'hire_date' => EmployeeService::of($this->app)->today()],
        ], $status);
    }

    public function create(Request $r): Response
    {
        $in = $this->input($r, self::PERSON + self::JOB);
        try {
            $pid = EmployeeService::of($this->app)->create($this->ctx($r)->az(), $in);
        } catch (ValidationException $e) {
            return $this->createForm($r, $this->errors($e), $in, 422);
        }
        $this->flash($r, 'success', 'employees.flash.created');
        return $this->redirect($r, '/employees/' . $pid);
    }

    /**
     * The employee page. $form names the form that failed validation (its tab opens with the errors).
     *
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     * @param array<string, string> $revealed identifier public id => full number (shown once, never stored)
     */
    public function show(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200, array $revealed = []): Response
    {
        $az = $this->ctx($r)->az();
        $svc = EmployeeService::of($this->app);
        $emp = $svc->get($az, (string) $r->params['id']);
        $acc = ProfileService::of($this->app)->access($az, $emp);
        $tabs = ['overview'];
        if ($acc['personal'] || $acc['identifiers']) {
            $tabs[] = 'personal';
        }
        $tabs[] = 'job';
        if ($acc['categories'] !== []) {
            $tabs[] = 'file';
        }
        if ($acc['manage'] || $acc['approve']) {
            $tabs[] = 'changes';
            $tabs[] = 'log';
        }
        $formTab = ['person' => 'overview', 'user' => 'overview', 'personal' => 'personal', 'contacts' => 'personal', 'identifier' => 'personal', 'education' => 'personal',
            'prior_job' => 'personal', 'job' => 'changes', 'status' => 'changes', 'upload' => 'file', 'verify' => 'file', 'offboarding' => 'changes'][$form] ?? null;
        $tab = $formTab ?? (string) ($r->query['tab'] ?? 'overview');
        if ($revealed !== []) {
            $tab = 'personal';
        }
        if (!in_array($tab, $tabs, true)) {
            $tab = 'overview';
        }
        $files = PersonnelFileService::of($this->app);
        $changes = ChangeService::of($this->app);
        $data = [
            'title' => $svc->displayName($emp), 'emp' => $emp, 'acc' => $acc, 'tabs' => $tabs, 'tab' => $tab, 'canManage' => $acc['manage'],
            'org' => $this->orgLabels($az, $acc['manage'] ? 'employees.manage' : 'employees.view'), 'allOrg' => $this->orgLabels($az, 'employees.view'),
            'errors' => $errors, 'old' => $old, 'form' => $form, 'today' => $svc->today(), 'revealed' => $revealed, 'userId' => $az->userId,
            'completeness' => $acc['categories'] !== [] || $acc['manage'] ? $files->completeness($emp) : null,
            'statusHistory' => $changes->statusHistory((int) $emp['id']),
            'openRequests' => array_values(array_filter($changes->requests((int) $emp['id']), static fn (array $q): bool => in_array($q['status'], ['pending', 'returned', 'approved', 'failed'], true))),
        ];
        if ($tab === 'personal') {
            $data['profile'] = ProfileService::of($this->app)->profile($emp, $acc);
        }
        if ($tab === 'file') {
            $data['files'] = $files->items($emp, $acc);
            $data['noticeDays'] = $emp['job'] !== null ? (int) ($files->rules((int) $emp['job']['legal_entity_id'])['expiry_notice_days'] ?? 0) : 0;
        }
        if ($tab === 'changes') {
            $data['requests'] = $changes->requests((int) $emp['id']);
            $data['offboarding'] = $changes->offboarding((int) $emp['id']);
            $data['managers'] = $acc['manage'] ? $svc->managerOptions($az->scope('employees.view')->legalEntities(), (int) $emp['id']) : [];
            $data['labelled'] = static fn (array $p): array => $changes->labelled($p);
        }
        if ($tab === 'log') {
            $data['log'] = $this->app->db()->all(
                "SELECT a.action, a.outcome, a.reason, a.occurred_at, u.display_name FROM audit_events a LEFT JOIN users u ON u.id = a.actor_id
                  WHERE a.target_type = 'employee' AND a.target_id = ? ORDER BY a.id DESC LIMIT 100",
                [(string) $emp['public_id']]
            );
        }
        $res = $this->page('@employees/show', $data, $status);
        return $revealed !== [] ? $res->withHeader('Cache-Control', 'no-store') : $res;
    }

    public function update(Request $r): Response
    {
        $in = $this->input($r, self::PERSON);
        return $this->act($r, 'person', 'employees.flash.saved', fn (Authorizer $az) => EmployeeService::of($this->app)->update($az, (string) $r->params['id'], $in), $in);
    }

    public function personal(Request $r): Response
    {
        $in = $this->input($r, ['title' => 20, 'birth_date' => 10, 'gender' => 12, 'nationality' => 2, 'work_email' => 190, 'work_phone' => 40]);
        return $this->act($r, 'personal', 'employees.flash.saved', fn (Authorizer $az) => ProfileService::of($this->app)->savePersonal($az, (string) $r->params['id'], $in), $in, 'personal');
    }

    public function contacts(Request $r): Response
    {
        $in = $this->contactInput($r);
        return $this->act($r, 'contacts', 'employees.flash.saved', fn (Authorizer $az) => ProfileService::of($this->app)->saveContacts($az, (string) $r->params['id'], $in, false), $in, 'personal');
    }

    public function identifier(Request $r): Response
    {
        $in = $this->input($r, ['id_type' => 30, 'id_value' => 40, 'issue_country' => 2, 'expiry_date' => 10]);
        return $this->act($r, 'identifier', 'employees.flash.identifier', fn (Authorizer $az) => ProfileService::of($this->app)->addIdentifier(
            $az,
            (string) $r->params['id'],
            $in['id_type'],
            $in['id_value'],
            $in['issue_country'],
            $in['expiry_date']
        ), ['id_type' => $in['id_type'], 'issue_country' => $in['issue_country'], 'expiry_date' => $in['expiry_date']], 'personal');
    }

    /** Full ID number: needs a fresh sign-in check, shown once on the page (no-store), audited. */
    public function reveal(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $emp = EmployeeService::of($this->app)->get($az, (string) $r->params['id']);
        if (!ProfileService::of($this->app)->access($az, $emp)['identifiers']) {
            throw HttpException::forbidden(); // rights first, then the sign-in check
        }
        $this->ctx($r)->requireFreshReauth();
        $num = ProfileService::of($this->app)->revealIdentifier($az, (string) $r->params['id'], (string) $r->params['iid']);
        return $this->show($r, [], [], '', 200, [(string) $r->params['iid'] => $num]);
    }

    public function education(Request $r): Response
    {
        $in = $this->input($r, ['level' => 20, 'institution' => 160, 'field' => 160, 'graduated_year' => 4]);
        return $this->act($r, 'education', 'employees.flash.saved', fn (Authorizer $az) => ProfileService::of($this->app)->addEducation($az, (string) $r->params['id'], $in), $in, 'personal');
    }

    public function priorJob(Request $r): Response
    {
        $in = $this->input($r, ['company' => 160, 'position' => 120, 'from_month' => 7, 'to_month' => 7]);
        return $this->act($r, 'prior_job', 'employees.flash.saved', fn (Authorizer $az) => ProfileService::of($this->app)->addPriorJob($az, (string) $r->params['id'], $in), $in, 'personal');
    }

    public function removeRow(Request $r): Response
    {
        ProfileService::of($this->app)->removeRow($this->ctx($r)->az(), (string) $r->params['id'], (string) $r->params['kind'], (string) $r->params['rid']);
        $this->flash($r, 'success', 'employees.flash.removed');
        return $this->redirect($r, '/employees/' . $r->params['id'] . '?tab=personal');
    }

    public function upload(Request $r): Response
    {
        $in = $this->input($r, ['doc_type' => 40, 'category' => 1, 'title' => 160, 'expiry_date' => 10, 'employee_visible' => 1, 'replaces' => 26]);
        return $this->act($r, 'upload', 'employees.flash.file_added', fn (Authorizer $az) => PersonnelFileService::of($this->app)->upload($az, (string) $r->params['id'], $in, $r->file('file')), $in, 'file');
    }

    public function verify(Request $r): Response
    {
        $in = $this->input($r, ['result' => 10, 'method' => 20, 'note' => 255]);
        return $this->act($r, 'verify', 'employees.flash.verified', fn (Authorizer $az) => PersonnelFileService::of($this->app)->verify(
            $az,
            (string) $r->params['id'],
            (string) $r->params['fid'],
            $in['result'],
            $in['method'],
            $in['note']
        ), $in + ['item' => (string) $r->params['fid']], 'file');
    }

    public function archive(Request $r): Response
    {
        $in = $this->input($r, ['reason' => 255]);
        return $this->act($r, 'verify', 'employees.flash.archived', fn (Authorizer $az) => PersonnelFileService::of($this->app)->archive($az, (string) $r->params['id'], (string) $r->params['fid'], $in['reason']), $in + [
            'item' => (string) $r->params['fid'],
        ], 'file');
    }

    public function download(Request $r): Response
    {
        $f = PersonnelFileService::of($this->app)->download($this->ctx($r)->az(), (string) $r->params['id'], (string) $r->params['fid'], false);
        return Response::attachment($f['bytes'], $f['mime'], $f['filename']);
    }

    public function employment(Request $r): Response
    {
        $in = $this->input($r, self::JOB);
        return $this->act($r, 'job', 'employees.flash.change_requested', fn (Authorizer $az) => ChangeService::of($this->app)->requestEmployment($az, (string) $r->params['id'], $in), $in, 'changes');
    }

    public function status(Request $r): Response
    {
        $in = $this->input($r, ['to_status' => 12, 'effective_from' => 10, 'last_working_day' => 10, 'separation_type' => 20, 'reason' => 500]);
        return $this->act($r, 'status', 'employees.flash.change_requested', fn (Authorizer $az) => ChangeService::of($this->app)->requestStatus($az, (string) $r->params['id'], $in), $in, 'changes');
    }

    public function withdraw(Request $r): Response
    {
        ChangeService::of($this->app)->withdraw($this->ctx($r)->az(), (string) $r->params['rid']);
        $this->flash($r, 'success', 'employees.flash.withdrawn');
        return $this->redirect($r, '/employees/' . $r->params['id'] . '?tab=changes');
    }

    public function retry(Request $r): Response
    {
        try {
            $ok = ChangeService::of($this->app)->retry($this->ctx($r)->az(), (string) $r->params['rid']);
            $this->flash($r, $ok ? 'success' : 'danger', $ok ? 'employees.flash.retried' : 'employees.flash.retry_failed');
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey, $e->params);
        }
        return $this->redirect($r, '/employees/' . $r->params['id'] . '?tab=changes');
    }

    public function offboarding(Request $r): Response
    {
        $in = $this->input($r, ['status' => 16, 'note' => 255]);
        return $this->act($r, 'offboarding', 'employees.flash.saved', fn (Authorizer $az) => ChangeService::of($this->app)->setOffboardingItem(
            $az,
            (string) $r->params['id'],
            (string) $r->params['oid'],
            $in['status'],
            $in['note']
        ), $in + ['item' => (string) $r->params['oid']], 'changes');
    }

    public function linkUser(Request $r): Response
    {
        $in = ['username' => $this->str($r, 'username', 60)];
        return $this->act($r, 'user', 'employees.flash.user', fn (Authorizer $az) => EmployeeService::of($this->app)->linkUser($az, (string) $r->params['id'], $in['username']), $in);
    }

    // ---- completeness report (HR / internal audit) ------------------------------------------------------

    public function report(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $le = (int) $r->input('le', 0);
        $rows = PersonnelFileService::of($this->app)->report($az, $le ?: null);
        return $this->page('@employees/report', [
            'title' => $this->app->translator()->get('employees.report_title'), 'rows' => $rows, 'le' => $le, 'org' => $this->orgLabels($az, 'employees.report'),
        ]);
    }

    public function export(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $le = (int) $r->input('le', 0);
        $rows = PersonnelFileService::of($this->app)->report($az, $le ?: null);
        $t = $this->app->translator();
        $org = $this->orgLabels($az, 'employees.report');
        $out = [];
        foreach ($rows as $x) {
            $out[] = [(string) $x['employee_code'], (string) $x['name_th'], $org['legal_entity'][(int) $x['legal_entity_id']] ?? '', $t->get('employees.status.' . $x['status']),
                $t->get('employees.type.' . $x['employment_type']), $x['configured'] ? (string) $x['percent'] : '', (string) $x['missing'], (string) $x['pending'], (string) $x['expired'], (string) $x['expiring']];
        }
        $headers = ['employee_code', 'employee_name', 'company', 'status', 'employment_type', 'complete_percent', 'missing', 'pending_verification', 'expired', 'expiring_soon'];
        $bytes = Xlsx::write(['completeness' => ['headers' => $headers, 'rows' => $out, 'numeric' => [5, 6, 7, 8, 9], 'widths' => [14, 30, 26, 14, 14, 12, 10, 12, 10, 12]]], 'Blue OS personnel file completeness');
        $this->app->audit()->record('employee.report_exported', 'success', 'employee_report', null, $le ?: null, null, ['rows' => count($out), 'sha256' => hash('sha256', $bytes)]);
        $date = EmployeeService::of($this->app)->today();
        return Response::attachment($bytes, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'BLUE_PERSONNEL_FILE_COMPLETENESS_' . $date . '.xlsx');
    }

    // ---- company file rules (Config Registry employees.file_rules) -----------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function rules(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $org = $this->orgLabels($az, 'employees.manage');
        if ($org['legal_entity'] === []) {
            throw HttpException::forbidden();
        }
        $le = (int) ($old['le'] ?? $r->input('le', 0));
        if (!isset($org['legal_entity'][$le])) {
            $le = (int) array_key_first($org['legal_entity']);
        }
        $current = PersonnelFileService::of($this->app)->rules($le);
        $versions = $this->app->db()->all(
            "SELECT v.public_id, v.status, v.effective_from, v.source_reference, v.reason, v.created_at, p.display_name AS proposed_name, i.public_id AS instance_pid
               FROM config_versions v JOIN config_definitions c ON c.id = v.definition_id LEFT JOIN users p ON p.id = v.proposed_by
               LEFT JOIN workflow_instances i ON i.id = v.workflow_instance_id
              WHERE c.config_key = 'employees.file_rules' AND v.scope_level = 'legal_entity' AND v.scope_id = ? ORDER BY v.id DESC LIMIT 20",
            [$le]
        );
        return $this->page('@employees/rules', [
            'title' => $this->app->translator()->get('employees.rules_title'), 'org' => $org, 'le' => $le, 'current' => $current, 'versions' => $versions,
            'errors' => $errors, 'old' => $old, 'today' => EmployeeService::of($this->app)->today(), 'describe' => $current !== null ? FileRules::describe($this->app, $current) : [],
        ], $status);
    }

    public function proposeRules(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $le = (int) $r->input('le', 0);
        $in = [
            'required' => (array) ($r->body['required'] ?? []), 'expiry_notice_days' => $this->str($r, 'expiry_notice_days', 3),
            'offboarding' => (array) ($r->body['offboarding'] ?? []),
        ];
        $meta = $this->input($r, ['effective_from' => 10, 'source_reference' => 255, 'reason' => 500]);
        try {
            if (!$az->scope('employees.manage')->coversLegalEntity($le)) {
                throw HttpException::forbidden();
            }
            [$value, $errors] = FileRules::validate($in);
            $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $meta['effective_from'], new \DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok')));
            if ($from === false || $from->format('Y-m-d') !== $meta['effective_from']) {
                $errors['effective_from'] = 'employees.error.date';
            }
            if (mb_strlen(trim($meta['source_reference'])) < 3) {
                $errors['source_reference'] = 'employees.rules_error.source';
            }
            if (mb_strlen(trim($meta['reason'])) < 3) {
                $errors['reason'] = 'iam.error.reason_required';
            }
            if ($errors !== [] || $value === null || $from === false) {
                throw new ValidationException($errors);
            }
            $reg = $this->app->registry();
            $pid = $reg->propose($az, 'employees.file_rules', 'legal_entity', $le, (string) json_encode($value, JSON_UNESCAPED_UNICODE), $from, null, trim($meta['reason']), trim($meta['source_reference']));
            $reg->submit($az, $pid);
        } catch (ValidationException $e) {
            return $this->rules($r, $this->errors($e), $in + $meta + ['le' => $le], 422);
        }
        $this->flash($r, 'success', 'employees.flash.rules_proposed');
        return $this->redirect($r, '/employees/rules?le=' . $le);
    }

    // ---- self-service -----------------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function me(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        if ($az->scope('employees.self')->isEmpty()) {
            throw HttpException::forbidden();
        }
        $svc = EmployeeService::of($this->app);
        $emp = $svc->forUser($az->userId);
        if ($emp === null) {
            return $this->page('@employees/me', ['title' => $this->app->translator()->get('employees.me_title'), 'emp' => null]);
        }
        $acc = ['self' => true, 'personal' => false, 'identifiers' => false, 'categories' => [], 'view' => false, 'manage' => false, 'approve' => false, 'report' => false];
        return $this->page('@employees/me', [
            'title' => $this->app->translator()->get('employees.me_title'), 'emp' => $emp, 'profile' => ProfileService::of($this->app)->profile($emp, $acc),
            'files' => PersonnelFileService::of($this->app)->items($emp, $acc, true), 'org' => $this->app->org()->scopeOptions(new ScopeSet(
                false,
                [(int) ($emp['job']['legal_entity_id'] ?? 0)],
                array_filter([(int) ($emp['job']['site_id'] ?? 0)]),
                array_filter([(int) ($emp['job']['department_id'] ?? 0)])
            ), $this->app->translator()->locale()),
            'manager' => $emp['job'] !== null && $emp['job']['manager_employee_id'] !== null ? $svc->byId((int) $emp['job']['manager_employee_id']) : null,
            'errors' => $errors, 'old' => $old,
        ], $status);
    }

    public function meContacts(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $emp = EmployeeService::of($this->app)->forUser($az->userId) ?? throw HttpException::notFound();
        $in = $this->contactInput($r);
        try {
            ProfileService::of($this->app)->saveContacts($az, (string) $emp['public_id'], $in, true);
        } catch (ValidationException $e) {
            return $this->me($r, $this->errors($e), $in, 422);
        }
        $this->flash($r, 'success', 'employees.flash.saved');
        return $this->redirect($r, '/me/profile');
    }

    public function meFile(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $emp = EmployeeService::of($this->app)->forUser($az->userId) ?? throw HttpException::notFound();
        $f = PersonnelFileService::of($this->app)->download($az, (string) $emp['public_id'], (string) $r->params['fid'], true);
        return Response::attachment($f['bytes'], $f['mime'], $f['filename']);
    }

    // ---- helpers -----------------------------------------------------------------------------------------

    /**
     * Runs a change; validation errors re-open the page on the form's tab with the input kept.
     *
     * @param callable(Authorizer): mixed $fn
     * @param array<string, mixed> $old
     */
    private function act(Request $r, string $form, string $flash, callable $fn, array $old, string $tab = 'overview'): Response
    {
        try {
            $fn($this->ctx($r)->az());
        } catch (ValidationException $e) {
            return $this->show($r, $this->errors($e), $old, $form, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey, $e->params);
            return $this->redirect($r, '/employees/' . $r->params['id'] . '?tab=' . $tab);
        }
        $this->flash($r, 'success', $flash);
        return $this->redirect($r, '/employees/' . $r->params['id'] . '?tab=' . $tab);
    }

    /** @return array<string, mixed> */
    private function contactInput(Request $r): array
    {
        $contacts = [];
        foreach (ProfileService::CONTACTS as $c) {
            $contacts[$c] = mb_substr(trim((string) (((array) ($r->body['contacts'] ?? []))[$c] ?? '')), 0, 500);
        }
        $em = [];
        foreach (array_slice((array) ($r->body['emergency'] ?? []), 0, 3) as $row) {
            $row = (array) $row;
            $em[] = ['name' => mb_substr(trim((string) ($row['name'] ?? '')), 0, 120), 'relation' => mb_substr(trim((string) ($row['relation'] ?? '')), 0, 40),
                'phone' => mb_substr(trim((string) ($row['phone'] ?? '')), 0, 40)];
        }
        return ['contacts' => $contacts, 'emergency' => $em];
    }

    /**
     * @param array<string, int> $fields
     * @return array<string, string>
     */
    private function input(Request $r, array $fields): array
    {
        $out = [];
        foreach ($fields as $k => $max) {
            $out[$k] = $this->str($r, $k, $max);
        }
        return $out;
    }

    /** @return array<string, array<int, string>> companies/sites/departments the user may pick (labels "CODE · name") */
    private function orgLabels(Authorizer $az, string $permission): array
    {
        return $this->app->org()->scopeOptions($az->scope($permission), $this->app->translator()->locale());
    }
}
