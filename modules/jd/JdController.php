<?php

declare(strict_types=1);

namespace Blue\Modules\Jd;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;

/** M56 screens: my JD (+ history) · read + acknowledge · HR positions, versions and acknowledgement report. */
final class JdController extends Controller
{
    private function svc(): JdService
    {
        return JdService::of($this->app);
    }

    private function t(string $key): string
    {
        return $this->app->translator()->get($key);
    }

    public function index(Request $r): Response
    {
        if (!$this->ctx($r)->can('jd.read')) {
            throw HttpException::forbidden();
        }
        $az = $this->ctx($r)->az();
        return $this->page('@jd/index', ['title' => $this->t('jd.title'), 'mine' => $this->svc()->mine($az), 'canManage' => $this->svc()->companies($az) !== []]);
    }

    /** @param array<string, string> $errors */
    public function show(Request $r, array $errors = [], int $status = 200): Response
    {
        $ver = $this->svc()->view($this->ctx($r)->az(), (string) $r->params['id']);
        return $this->page('@jd/show', ['title' => (string) $ver['position_title'], 'ver' => $ver, 'errors' => $errors], $status);
    }

    public function acknowledge(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $this->svc()->acknowledge($this->ctx($r)->az(), $pid, $r->input('confirm') === '1', $r->ip, (string) $r->header('user-agent'));
        } catch (ValidationException $e) {
            return $this->show($r, $this->errors($e), 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            return $this->show($r, ['confirm' => $this->t($e->messageKey)], 422);
        }
        $this->flash($r, 'success', 'jd.flash.acknowledged');
        return $this->redirect($r, '/jd');
    }

    // ---- HR -------------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function admin(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $les = $this->svc()->companies($this->ctx($r)->az());
        if ($les === []) {
            throw HttpException::forbidden();
        }
        $companies = [];
        foreach ($les as $id) {
            $row = $this->app->db()->one('SELECT public_id, code FROM legal_entities WHERE id = ?', [$id]);
            $companies[(string) $row['public_id']] = (string) $row['code'];
        }
        $lePid = (string) ($old['legal_entity'] ?? $r->query['le'] ?? '');
        if (!isset($companies[$lePid])) {
            $lePid = (string) array_key_first($companies);
        }
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$lePid]);
        $depts = [];
        foreach ($this->app->db()->all("SELECT public_id, code FROM departments WHERE legal_entity_id = ? AND status = 'active' ORDER BY code", [$le]) as $d) {
            $depts[(string) $d['public_id']] = (string) $d['code'];
        }
        $titles = array_column($this->app->db()->all(
            'SELECT DISTINCT position_title FROM employment_history WHERE legal_entity_id = ? AND effective_to IS NULL AND position_title IS NOT NULL ORDER BY position_title LIMIT 300',
            [$le]
        ), 'position_title');
        return $this->page('@jd/admin', ['title' => $this->t('jd.admin_title'), 'companies' => $companies, 'lePid' => $lePid, 'rows' => $this->svc()->adminList($le), 'depts' => $depts,
            'titles' => $titles, 'errors' => $errors, 'old' => $old], $status);
    }

    public function create(Request $r): Response
    {
        $in = ['legal_entity' => $this->str($r, 'legal_entity', 26), 'code' => $this->str($r, 'code', 30), 'position_title' => $this->str($r, 'position_title', 120), 'department' => $this->str($r, 'department', 26)];
        $le = (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$in['legal_entity']]);
        try {
            $pid = $this->svc()->create($this->ctx($r)->az(), $le, $in);
        } catch (ValidationException $e) {
            return $this->admin($r, $this->errors($e), $in, 422);
        }
        $this->flash($r, 'success', 'jd.flash.created');
        return $this->redirect($r, '/jd/admin/p/' . $pid);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function manage(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200): Response
    {
        $p = $this->svc()->managed($this->ctx($r)->az(), (string) $r->params['id']);
        $versions = $this->svc()->versions((int) $p['id']);
        $draft = null;
        $base = null;
        foreach ($versions as $v) {
            if ($v['status'] === 'draft' && $draft === null) {
                $draft = $v;
            }
            if ($v['status'] === 'effective') {
                $base = $v;
            }
        }
        return $this->page('@jd/manage', ['title' => (string) $p['position_title'], 'p' => $p, 'versions' => $versions, 'draft' => $draft,
            'jdContent' => (array) json_decode((string) (($draft ?? $base)['content_json'] ?? '[]'), true), 'report' => $p['current_version_id'] !== null ? $this->svc()->report((int) $p['current_version_id']) : [],
            'errors' => $errors, 'old' => $old, 'form' => $form, 'today' => $this->svc()->today()], $status);
    }

    public function saveDraft(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $in = ['change_summary' => $this->str($r, 'change_summary', 500), 'effective_from' => $this->str($r, 'effective_from', 10)];
        foreach (['th', 'en'] as $l) {
            foreach (JdService::SECTIONS as $s) {
                $in[$l . '_' . $s] = mb_substr((string) ($r->body[$l . '_' . $s] ?? ''), 0, 6000);
            }
        }
        try {
            $this->svc()->saveDraft($this->ctx($r)->az(), $pid, $in);
        } catch (ValidationException $e) {
            return $this->manage($r, $this->errors($e), $in, 'draft', 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/jd/admin/p/' . $pid);
        }
        $this->flash($r, 'success', 'jd.flash.draft');
        return $this->redirect($r, '/jd/admin/p/' . $pid);
    }

    public function versionAction(Request $r): Response
    {
        $vid = (string) $r->params['id'];
        $profile = (string) $this->app->db()->scalar('SELECT p.public_id FROM jd_versions v JOIN jd_profiles p ON p.id = v.profile_id WHERE v.public_id = ?', [$vid]);
        if ($profile === '') {
            throw HttpException::notFound();
        }
        $action = $r->input('action') === 'withdraw' ? 'withdraw' : 'submit';
        try {
            $action === 'submit' ? $this->svc()->submit($this->ctx($r)->az(), $vid) : $this->svc()->withdraw($this->ctx($r)->az(), $vid);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/jd/admin/p/' . $profile);
        }
        $this->flash($r, 'success', 'jd.flash.' . $action);
        return $this->redirect($r, '/jd/admin/p/' . $profile);
    }

    public function action(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $az = $this->ctx($r)->az();
        $action = (string) $r->input('action', '');
        if ($action === 'remind') {
            $this->flash($r, 'success', 'jd.flash.reminded', ['n' => (string) $this->svc()->remind($az, $pid)]);
        } elseif ($action === 'archive') {
            $this->svc()->archive($az, $pid);
            $this->flash($r, 'success', 'jd.flash.archived');
            return $this->redirect($r, '/jd/admin');
        } else {
            throw HttpException::notFound();
        }
        return $this->redirect($r, '/jd/admin/p/' . $pid);
    }

    public function exception(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $this->svc()->exception($this->ctx($r)->az(), $pid, (int) $r->input('ack', '0'), $this->str($r, 'reason', 300));
        } catch (ValidationException $e) {
            return $this->manage($r, $this->errors($e), ['ack' => (string) $r->input('ack', '')], 'exception', 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/jd/admin/p/' . $pid);
        }
        $this->flash($r, 'success', 'jd.flash.exception');
        return $this->redirect($r, '/jd/admin/p/' . $pid);
    }
}
