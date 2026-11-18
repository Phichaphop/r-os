<?php

declare(strict_types=1);

namespace Blue\Modules\Eid;

use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Support\Money;

/** M13 screens: my card + wallet · show card (full screen, rotating QR) · verify · HR card admin · wallet admin (rules, top-ups, reconcile). */
final class EidController extends Controller
{
    private function cards(): CardService
    {
        return CardService::of($this->app);
    }

    private function wallets(): WalletService
    {
        return WalletService::of($this->app);
    }

    private function origin(Request $r): string
    {
        $configured = rtrim($this->app->config->string('APP_URL'), '/');
        return $configured !== '' ? $configured : ($r->secure ? 'https' : 'http') . '://' . $r->host . $r->basePath();
    }

    /** @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null} employee, current/latest card */
    private function mine(Authorizer $az): array
    {
        $me = $this->cards()->emp()->forUser($az->userId);
        return [$me, $me !== null ? ($this->cards()->current((int) $me['id']) ?? $this->cards()->latest((int) $me['id'])) : null];
    }

    public function index(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        if (!$az->can('eid.use')) {
            throw HttpException::forbidden();
        }
        [$me, $card] = $this->mine($az);
        $w = $me !== null ? $this->wallets()->wallet((int) $me['id']) : null;
        $ym = substr($this->cards()->today(), 0, 7);
        return $this->page('@eid/index', [
            'title' => $this->app->translator()->get('eid.title'), 'me' => $me, 'card' => $card, 'status' => $card !== null ? $this->cards()->effective($card) : null,
            'wallet' => $w, 'entries' => $w !== null ? $this->wallets()->entries((int) $w['id'], 30) : [], 'month' => $w !== null ? $this->wallets()->month((int) $w['id'], $ym) : null, 'ym' => $ym,
            'rules' => $me !== null && $me['job'] !== null ? $this->wallets()->rules((int) $me['job']['legal_entity_id']) : null,
        ]);
    }

    public function card(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        [$me, $card] = $this->mine($az);
        if ($me === null || $card === null) {
            throw HttpException::notFound();
        }
        $face = $this->cards()->face($card);
        $print = ($r->query['print'] ?? '') === '1' && $face['allow_print'];
        $qr = $face['status'] === 'active' ? $this->cards()->qr($card, $this->origin($r), $print) : null;
        if ($print) {
            $this->app->audit()->record('eid.card_printed', 'success', 'eid_card', (string) $card['public_id'], (int) $card['legal_entity_id']);
        }
        return $this->page('@eid/card', ['title' => $this->app->translator()->get('eid.card_title'), 'face' => $face, 'qr' => $qr, 'print' => $print]);
    }

    public function cardQr(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        [$me, $card] = $this->mine($az);
        if ($me === null || $card === null || $this->cards()->effective($card) !== 'active') {
            return Response::json(['ok' => false], 404);
        }
        $qr = $this->cards()->qr($card, $this->origin($r));
        return Response::json(['ok' => true, 'svg' => $qr['svg'], 'refresh' => $qr['refresh']]);
    }

    public function lost(Request $r): Response
    {
        try {
            $this->cards()->reportLost($this->ctx($r)->az(), $this->str($r, 'reason', 300));
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/eid');
        }
        $this->flash($r, 'success', 'eid.flash.lost');
        return $this->redirect($r, '/eid');
    }

    public function photo(Request $r): Response
    {
        $p = $this->cards()->photo($this->ctx($r)->az(), (string) $r->params['id']);
        return Response::html($p['bytes'])->withHeader('Content-Type', $p['mime'])->withHeader('Content-Disposition', 'inline')->withHeader('Cache-Control', 'private, no-store');
    }

    // ---- verify ---------------------------------------------------------------------------------------------------------

    public function verifyForm(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        if ($az->scope('eid.verify')->isEmpty()) {
            throw HttpException::forbidden();
        }
        return $this->page('@eid/verify', ['title' => $this->app->translator()->get('eid.verify_title'), 'result' => null]);
    }

    public function verifyPost(Request $r): Response
    {
        $raw = $this->str($r, 'code', 400);
        $token = preg_match('#/eid/v/([A-Za-z0-9_.-]{20,80})#', $raw, $m) === 1 ? $m[1] : trim($raw);
        if (preg_match('/^[A-Za-z0-9_.-]{20,80}$/', $token) !== 1) {
            $token = 'x' . str_repeat('0', 21) . '.0.' . str_repeat('0', 16); // → "unknown", logged like any scan
        }
        return $this->redirect($r, '/eid/v/' . $token);
    }

    public function verify(Request $r): Response
    {
        $res = $this->cards()->verify($this->ctx($r)->az(), (string) $r->params['token']);
        return $this->page('@eid/verify', ['title' => $this->app->translator()->get('eid.verify_title'), 'result' => $res]);
    }

    // ---- HR: cards ------------------------------------------------------------------------------------------------------

    /** @return array<string, string> companies (public id => code) covered by $permission */
    private function companies(Authorizer $az, string $permission): array
    {
        $scope = $az->scope($permission);
        $out = [];
        $db = $this->app->db();
        $partial = []; // a branch / unit scope reaches its company too
        foreach ($scope->siteIds as $s) {
            $partial[(int) $db->scalar('SELECT legal_entity_id FROM sites WHERE id = ?', [$s])] = true;
        }
        foreach ($scope->departmentIds as $d) {
            $partial[(int) $db->scalar('SELECT legal_entity_id FROM departments WHERE id = ?', [$d])] = true;
        }
        foreach ($db->all('SELECT id, public_id, code FROM legal_entities ORDER BY code') as $l) {
            if ($scope->coversLegalEntity((int) $l['id']) || isset($partial[(int) $l['id']])) {
                $out[(string) $l['public_id']] = (string) $l['code'];
            }
        }
        return $out;
    }

    /** @param array<string, string> $errors */
    public function admin(Request $r, array $errors = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $companies = $this->companies($az, 'eid.manage');
        if ($companies === []) {
            throw HttpException::forbidden();
        }
        $lePid = (string) ($r->query['le'] ?? $r->body['legal_entity'] ?? '');
        if (!isset($companies[$lePid])) {
            $lePid = (string) array_key_first($companies);
        }
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$lePid]);
        $q = mb_substr(trim((string) ($r->query['q'] ?? '')), 0, 60);
        return $this->page('@eid/admin', [
            'title' => $this->app->translator()->get('eid.admin_title'), 'companies' => $companies, 'lePid' => $lePid, 'settings' => $this->cards()->settings($le),
            'roster' => $this->cards()->roster($az, $le, $q), 'q' => $q, 'errors' => $errors,
        ], $status);
    }

    public function saveSettings(Request $r): Response
    {
        $lePid = $this->str($r, 'legal_entity', 26);
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$lePid]);
        $in = [];
        foreach (['show_position', 'show_department', 'show_site', 'allow_print', 'validity_months'] as $k) {
            $in[$k] = $this->str($r, $k, 4);
        }
        try {
            $this->cards()->saveSettings($this->ctx($r)->az(), $le, $in);
        } catch (ValidationException $e) {
            return $this->admin($r, $this->errors($e), 422);
        }
        $this->flash($r, 'success', 'eid.flash.settings');
        return $this->redirect($r, '/eid/admin?le=' . rawurlencode($lePid));
    }

    public function issueMissing(Request $r): Response
    {
        $lePid = $this->str($r, 'legal_entity', 26);
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$lePid]);
        $n = $this->cards()->issueMissing($this->ctx($r)->az(), $le);
        $this->flash($r, 'success', 'eid.flash.issued_n', ['n' => (string) $n]);
        return $this->redirect($r, '/eid/admin?le=' . rawurlencode($lePid));
    }

    /** @param array<string, string> $errors */
    public function employee(Request $r, array $errors = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $pid = (string) $r->params['id'];
        $id = $this->app->db()->scalar('SELECT id FROM employees WHERE public_id = ?', [$pid]);
        $e = $id !== null ? $this->cards()->emp()->byId((int) $id) : null;
        if ($e === null || !$this->cards()->canManage($az, $e['job'] ?? null)) {
            throw HttpException::notFound();
        }
        $card = $this->cards()->current((int) $e['id']) ?? $this->cards()->latest((int) $e['id']);
        $history = $this->app->db()->all('SELECT * FROM eid_cards WHERE employee_id = ? ORDER BY seq DESC', [$e['id']]);
        return $this->page('@eid/employee', [
            'title' => $this->app->translator()->get('eid.employee_title'), 'emp' => $e, 'card' => $card, 'status' => $card !== null ? $this->cards()->effective($card) : null,
            'face' => $card !== null ? $this->cards()->face($card) : null, 'events' => $card !== null ? $this->cards()->events((int) $card['id']) : [], 'history' => $history, 'errors' => $errors,
        ], $status);
    }

    public function issue(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $this->cards()->issue($this->ctx($r)->az(), $pid);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            return $this->employee($r, ['action' => $this->app->translator()->get($e->messageKey)], 409);
        }
        $this->flash($r, 'success', 'eid.flash.issued');
        return $this->redirect($r, '/eid/admin/e/' . $pid);
    }

    private function employeeOfCard(string $cardPid): string
    {
        $pid = $this->app->db()->scalar('SELECT e.public_id FROM eid_cards c JOIN employees e ON e.id = c.employee_id WHERE c.public_id = ?', [$cardPid]);
        if ($pid === null) {
            throw HttpException::notFound();
        }
        return (string) $pid;
    }

    public function cardAction(Request $r): Response
    {
        $cardPid = (string) $r->params['id'];
        $emp = $this->employeeOfCard($cardPid);
        $action = (string) $r->input('action', '');
        $reason = $this->str($r, 'reason', 300);
        $r->params['id'] = $emp;
        try {
            if ($action === 'reissue') {
                $this->cards()->reissue($this->ctx($r)->az(), $cardPid, $reason);
            } elseif ($action === 'photo') {
                $f = ($r->files['photo'] ?? [])[0] ?? null;
                $this->cards()->uploadPhoto($this->ctx($r)->az(), $cardPid, $f instanceof UploadedFile ? $f : null);
            } else {
                $this->cards()->setStatus($this->ctx($r)->az(), $cardPid, $action, $reason);
            }
        } catch (ValidationException $e) {
            return $this->employee($r, $this->errors($e), 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            return $this->employee($r, ['action' => $this->app->translator()->get($e->messageKey)], 409);
        }
        $this->flash($r, 'success', 'eid.flash.' . (in_array($action, ['suspend', 'resume', 'lost', 'revoke', 'reissue', 'photo'], true) ? $action : 'done'));
        return $this->redirect($r, '/eid/admin/e/' . $emp);
    }

    // ---- wallet admin -----------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function walletAdmin(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $companies = $this->companies($az, 'eid.wallet_view') + $this->companies($az, 'eid.wallet_topup');
        if ($companies === []) {
            throw HttpException::forbidden();
        }
        $lePid = (string) ($old['legal_entity'] ?? $r->query['le'] ?? '');
        if (!isset($companies[$lePid])) {
            $lePid = (string) array_key_first($companies);
        }
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$lePid]);
        $w = $this->wallets();
        $people = [];
        $canTopup = !$az->scope('eid.wallet_topup')->isEmpty();
        if ($canTopup) {
            foreach ($this->app->db()->all("SELECT e.id, e.public_id, e.employee_code, e.name_th, e.name_en, e.preferred_name FROM employees e WHERE e.status IN ('active','on_leave') ORDER BY e.employee_code LIMIT 1000") as $e) {
                $job = $w->emp()->employment((int) $e['id'], $w->emp()->today());
                if ($job !== null && (int) $job['legal_entity_id'] === $le && \Blue\Modules\Employees\EmployeeService::covers($az->scope('eid.wallet_topup'), $job)) {
                    $bal = $w->wallet((int) $e['id']);
                    $people[(string) $e['public_id']] = ['label' => $e['employee_code'] . ' · ' . $w->emp()->displayName($e), 'balance' => $bal !== null ? Money::fromDb($bal['balance']) : 0];
                }
            }
        }
        return $this->page('@eid/wallet_admin', [
            'title' => $this->app->translator()->get('eid.wallet_admin_title'), 'companies' => $companies, 'lePid' => $lePid, 'rules' => $w->rules($le), 'topups' => $w->topups($le),
            'canTopup' => $people !== [], 'canPolicy' => $az->scope('eid.wallet_policy')->coversLegalEntity($le),
            'people' => $people, 'recon' => !$az->scope('eid.wallet_view')->isEmpty() ? $w->reconcile($le) : null, 'errors' => $errors, 'old' => $old, 'form' => $form, 'today' => $w->emp()->today(),
        ], $status);
    }

    public function proposeRules(Request $r): Response
    {
        $in = ['legal_entity' => $this->str($r, 'legal_entity', 26), 'currency' => $this->str($r, 'currency', 3), 'max_topup' => $this->str($r, 'max_topup', 20), 'max_balance' => $this->str($r, 'max_balance', 20),
            'low_balance' => $this->str($r, 'low_balance', 20), 'purpose' => $this->str($r, 'purpose', 300), 'effective_from' => $this->str($r, 'effective_from', 10),
            'source_reference' => $this->str($r, 'source_reference', 255), 'reason' => $this->str($r, 'reason', 255)];
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$in['legal_entity']]);
        try {
            $this->wallets()->proposeRules($this->ctx($r)->az(), $le, $in, $in['effective_from'], $in['source_reference'], $in['reason']);
        } catch (ValidationException $e) {
            return $this->walletAdmin($r, $this->errors($e), $in, 'rules', 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            return $this->walletAdmin($r, ['source_reference' => $this->app->translator()->get($e->messageKey)], $in, 'rules', 422);
        }
        $this->flash($r, 'success', 'eid.flash.rules_proposed');
        return $this->redirect($r, '/eid/wallet/admin?le=' . rawurlencode($in['legal_entity']));
    }

    public function createTopup(Request $r): Response
    {
        $lePid = $this->str($r, 'legal_entity', 26);
        $amount = $this->str($r, 'amount', 20);
        $picked = array_values(array_filter(array_map('strval', (array) ($r->body['employees'] ?? []))));
        $in = ['legal_entity' => $lePid, 'amount' => $amount, 'employees' => $picked, 'reason' => $this->str($r, 'reason', 300)];
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$lePid]);
        $lines = array_map(static fn (string $p): array => ['employee' => $p, 'amount' => $amount], $picked);
        try {
            $pid = $this->wallets()->createTopup($this->ctx($r)->az(), $le, $lines, $in['reason']);
        } catch (ValidationException $e) {
            $errs = $this->errors($e);
            $first = null;
            foreach ($errs as $k => $msg) {
                if (str_starts_with($k, 'lines.')) {
                    $code = $picked[(int) substr($k, 6)] ?? '';
                    $first ??= $this->app->translator()->get('eid.error.line_for', ['msg' => $msg, 'who' => (string) $this->app->db()->scalar('SELECT employee_code FROM employees WHERE public_id = ?', [$code])]);
                    unset($errs[$k]);
                }
            }
            if ($first !== null) {
                $errs['employees'] = $first;
            }
            return $this->walletAdmin($r, $errs, $in, 'topup', 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            return $this->walletAdmin($r, ['employees' => $this->app->translator()->get($e->messageKey)], $in, 'topup', 422);
        }
        $this->flash($r, 'success', 'eid.flash.topup_created');
        return $this->redirect($r, '/eid/wallet/topup/' . $pid);
    }

    public function topup(Request $r): Response
    {
        $t = $this->wallets()->topup($this->ctx($r)->az(), (string) $r->params['id']);
        return $this->page('@eid/topup', ['title' => $this->app->translator()->get('eid.topup_title', ['n' => (string) $t['topup_no']]), 'tp' => $t]);
    }
}
