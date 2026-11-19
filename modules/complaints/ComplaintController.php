<?php

declare(strict_types=1);

namespace Blue\Modules\Complaints;

use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Records\DocumentsController;
use Blue\Core\Support\Qr\QrEncoder;
use Blue\Core\Support\Qr\QrRender;

/**
 * M16 screens (R6-3 · 0.44.0): send + my list + follow with the code (signed in or the public page), the handlers'
 * inbox and case page, settings (intake, SLAs, retention) and anonymous figures.
 * The tracking code is never put in the session, a flash message, a redirect or an address: pages that show or use it
 * are rendered straight from the POST that carried it.
 */
final class ComplaintController extends Controller
{
    private function svc(): ComplaintService
    {
        return ComplaintService::of($this->app);
    }

    /** @param array<string, string> $p */
    private function t(string $key, array $p = []): string
    {
        return $this->app->translator()->get($key, $p);
    }

    private function az(Request $r, string $perm): Authorizer
    {
        $az = $this->ctx($r)->az();
        if (!$az->can($perm)) {
            throw HttpException::forbidden();
        }
        return $az;
    }

    /** @return list<UploadedFile> */
    private function files(Request $r): array
    {
        return array_values(array_filter((array) ($r->files['files'] ?? []), static fn ($f): bool => $f instanceof UploadedFile && $f->error !== UPLOAD_ERR_NO_FILE));
    }

    /** @return array{0: list<array{id: int, code: string, name: string}>, 1: int} */
    private function company(Request $r, string ...$perms): array
    {
        $list = $this->svc()->companies($this->ctx($r)->az(), ...$perms);
        if ($list === []) {
            throw HttpException::forbidden();
        }
        $want = (int) ($r->query['le'] ?? $r->input('le') ?? 0);
        foreach ($list as $c) {
            if ($c['id'] === $want) {
                return [$list, $want];
            }
        }
        return [$list, $list[0]['id']];
    }

    private function back(Request $r, string $to, BusinessException $e): Response
    {
        if ($e instanceof HttpException) {
            throw $e;
        }
        $this->flash($r, 'danger', $e->messageKey, $e->params);
        return $this->redirect($r, $to);
    }

    // ---- sender -------------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function mine(Request $r, array $errors = [], array $old = [], int $status = 200, ?string $alert = null): Response
    {
        $az = $this->az($r, 'complaints.submit');
        $le = $this->svc()->employerOf($az->userId);
        return $this->page('@complaints/mine', [
            'title' => $this->t('complaints.mine.title'), 'le' => $le, 'settings' => $le === null ? null : $this->svc()->settings($le),
            'notice' => $le === null ? null : $this->svc()->noticeUrl($le), 'alternate' => $le !== null && $this->svc()->alternateAvailable($le),
            'company' => $le === null ? '' : $this->svc()->companyName($le), 'rows' => $this->svc()->mine($az->userId),
            'errors' => $errors, 'old' => $old, 'alert' => $alert,
        ], $status);
    }

    public function submit(Request $r): Response
    {
        $az = $this->az($r, 'complaints.submit');
        try {
            $res = $this->svc()->submit($az, $r->body, $this->files($r));
        } catch (ValidationException $e) {
            return $this->mine($r, $this->errors($e), $r->body, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            return $this->mine($r, [], $r->body, $e->httpStatus, $this->t($e->messageKey, $e->params));
        }
        // shown once, straight from this POST — never stored in the session
        return $this->page('@complaints/sent', ['title' => $this->t('complaints.sent.title'), 'res' => $res, 'ticket' => $this->ticket($r, $az, $res['code'])])
            ->withHeader('Cache-Control', 'no-store');
    }

    private function origin(Request $r): string
    {
        $configured = rtrim($this->app->config->string('APP_URL'), '/');
        return $configured !== '' ? $configured : ($r->secure ? 'https' : 'http') . '://' . $r->host . $r->basePath();
    }

    /**
     * 0.44.2 (DEC-113): the follow-up ticket the sender saves on their own device. Its QR opens the public follow-up page
     * with the code after "#" — a fragment never reaches the server or its logs; the page fills the form from it and then
     * removes it from the address. Nothing here is stored: the ticket is drawn in the browser (picture) or printed (PDF).
     * @return array{url: string, qr: string, modules: string, company: string, day: string}
     */
    private function ticket(Request $r, Authorizer $az, string $code): array
    {
        $url = $this->origin($r) . '/speak-up/track#' . $code;
        $qr = QrEncoder::encode($url, 'M');
        $rows = array_map(static fn (array $row): string => implode('', array_map(static fn (bool $b): string => $b ? '1' : '0', $row)), $qr['modules']);
        $le = $this->svc()->employerOf($az->userId);
        return [
            'url' => $this->origin($r) . '/speak-up/track', 'qr' => QrRender::svg($qr['modules'], 5, null, $this->t('complaints.ticket.qr_alt')),
            'modules' => implode('|', $rows), 'company' => $le === null ? '' : $this->svc()->companyName($le), 'day' => $this->svc()->dayStamp(),
        ];
    }

    public function vault(Request $r): Response
    {
        $az = $this->az($r, 'complaints.submit');
        if ((string) $r->params['what'] === 'remember') {
            try {
                $this->svc()->remember($az->userId, $this->str($r, 'code', 40));
            } catch (ValidationException) {
                $this->flash($r, 'danger', 'complaints.error.code');
                return $this->redirect($r, '/complaints');
            }
            $this->flash($r, 'success', 'complaints.flash.remembered');
        } else {
            $this->svc()->forget($az->userId, $this->str($r, 'ref', 20));
            $this->flash($r, 'success', 'complaints.flash.forgotten');
        }
        return $this->redirect($r, '/complaints');
    }

    /**
     * @param array<string, mixed>|null $case
     * @param array<string, string> $errors
     */
    private function trackPage(Request $r, bool $public, ?array $case, string $code, array $errors = [], int $status = 200, ?string $flash = null, bool $asked = false): Response
    {
        $view = $case === null ? null : $this->svc()->reporterView($case);
        $data = ['title' => $this->t('complaints.track.title'), 'public' => $public, 'case' => $view, 'code' => $case === null ? '' : $code,
            'errors' => $errors, 'asked' => $asked, 'done' => $flash, 'base' => $public ? '/speak-up/track' : '/complaints/track',
            'notice' => $case === null ? null : $this->svc()->noticeUrl((int) $case['legal_entity_id'])];
        $res = $this->page('@complaints/track', $data, $status, $public ? 'layouts/minimal' : 'layouts/app')->withHeader('Cache-Control', 'no-store');
        // 0.44.2: the code form may read the ticket's QR with the camera — allowed on this page only (camera=(self))
        return $case === null ? $res->withHeader('Permissions-Policy', 'geolocation=(), camera=(self), microphone=(), payment=()') : $res;
    }

    private function lookup(Request $r, bool $public): Response
    {
        $code = $this->str($r, 'code', 40);
        if ($r->method !== 'POST') {
            return $this->trackPage($r, $public, null, '');
        }
        try {
            $case = $this->svc()->track($code, $r->ip);
        } catch (BusinessException $e) {
            return $this->trackPage($r, $public, null, '', ['code' => $this->t($e->messageKey)], 429, null, true);
        }
        return $this->trackPage($r, $public, $case, $code, $case === null ? ['code' => $this->t('complaints.error.code')] : [], $case === null ? 404 : 200, null, true);
    }

    private function reporterAct(Request $r, bool $public): Response
    {
        $code = $this->str($r, 'code', 40);
        $action = (string) $r->params['action'];
        $case = $this->svc()->byCode($code);
        if ($case === null) {
            return $this->trackPage($r, $public, null, '', ['code' => $this->t('complaints.error.code')], 404, null, true);
        }
        try {
            $done = $this->svc()->reporterAct($code, $action, $r->body, $this->files($r));
        } catch (ValidationException $e) {
            return $this->trackPage($r, $public, $case, $code, $this->errors($e), 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            return $this->trackPage($r, $public, $case, $code, ['state' => $this->t($e->messageKey)], 409);
        }
        return $this->trackPage($r, $public, $this->svc()->byCode($code), $code, [], 200, $this->t('complaints.flash.' . $done));
    }

    private function reporterFile(Request $r): Response
    {
        $f = $this->svc()->file(null, $this->str($r, 'code', 40), '', (int) $r->params['fid']);
        return DocumentsController::fileResponse($f['bytes'], $f['mime'], $f['filename'], false)->withHeader('Cache-Control', 'private, no-store');
    }

    public function track(Request $r): Response
    {
        $this->az($r, 'complaints.submit');
        return $this->lookup($r, false);
    }

    public function trackAct(Request $r): Response
    {
        $this->az($r, 'complaints.submit');
        return $this->reporterAct($r, false);
    }

    public function trackFile(Request $r): Response
    {
        $this->az($r, 'complaints.submit');
        return $this->reporterFile($r);
    }

    public function publicTrack(Request $r): Response
    {
        return $this->lookup($r, true);
    }

    public function publicAct(Request $r): Response
    {
        return $this->reporterAct($r, true);
    }

    public function publicFile(Request $r): Response
    {
        return $this->reporterFile($r);
    }

    // ---- handlers ----------------------------------------------------------------------------------------------------------

    public function inbox(Request $r): Response
    {
        [$list, $le] = $this->company($r, 'complaints.manage', 'complaints.alternate', 'complaints.investigate');
        $az = $this->ctx($r)->az();
        $filter = in_array((string) ($r->query['f'] ?? ''), ['open', 'done', 'all'], true) ? (string) $r->query['f'] : 'open';
        return $this->page('@complaints/inbox', ['title' => $this->t('complaints.inbox.title'), 'companies' => $list, 'le' => $le, 'filter' => $filter,
            'rows' => $this->svc()->inbox($az, $le, $filter), 'settings' => $this->svc()->settings($le),
            'manage' => $az->scope('complaints.manage')->coversLegalEntity($le)]);
    }

    /** @param array<string, string> $errors */
    public function show(Request $r, array $errors = [], int $status = 200): Response
    {
        $c = $this->svc()->caseFor($this->ctx($r)->az(), (string) $r->params['id']);
        return $this->page('@complaints/case', ['title' => (string) $c['ref'], 'c' => $c, 'errors' => $errors], $status);
    }

    public function act(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $action = (string) $r->params['action'];
        try {
            $this->svc()->act($this->ctx($r)->az(), $pid, $action, $r->body, $this->files($r));
        } catch (ValidationException $e) {
            return $this->show($r, $this->errors($e), 422);
        } catch (BusinessException $e) {
            return $this->back($r, '/complaints/c/' . $pid, $e);
        }
        $this->flash($r, 'success', 'complaints.flash.' . ($action === 'accept' ? 'accept_case' : $action));
        return $this->redirect($r, '/complaints/c/' . $pid);
    }

    public function file(Request $r): Response
    {
        $f = $this->svc()->file($this->ctx($r)->az(), null, (string) $r->params['id'], (int) $r->params['fid']);
        return DocumentsController::fileResponse($f['bytes'], $f['mime'], $f['filename'], true)->withHeader('Cache-Control', 'private, no-store');
    }

    /** @param array<string, string> $errors */
    public function settings(Request $r, array $errors = [], int $status = 200): Response
    {
        [$list, $le] = $this->company($r, 'complaints.manage');
        return $this->page('@complaints/settings', ['title' => $this->t('complaints.settings.title'), 'companies' => $list, 'le' => $le,
            'settings' => $this->svc()->settings($le), 'missing' => $this->svc()->readiness($le), 'notice' => $this->svc()->noticeUrl($le),
            'alternate' => $this->svc()->alternateAvailable($le), 'errors' => $errors, 'old' => $r->body, 'today' => $this->svc()->today()], $status);
    }

    public function settingsPost(Request $r): Response
    {
        [, $le] = $this->company($r, 'complaints.manage');
        $az = $this->ctx($r)->az();
        $what = (string) $r->params['what'];
        try {
            match ($what) {
                'save' => $this->svc()->saveSettings($az, $le, $r->body),
                'open' => $this->svc()->openIntake($az, $le, $r->body),
                'close' => $this->svc()->closeIntake($az, $le, $this->str($r, 'close_reason', 500)),
                default => throw HttpException::notFound(),
            };
        } catch (ValidationException $e) {
            return $this->settings($r, $this->errors($e), 422);
        } catch (BusinessException $e) {
            return $this->back($r, '/complaints/settings?le=' . $le, $e);
        }
        $this->flash($r, 'success', 'complaints.flash.settings_' . $what);
        return $this->redirect($r, '/complaints/settings?le=' . $le);
    }

    public function stats(Request $r): Response
    {
        [$list, $le] = $this->company($r, 'complaints.stats');
        $year = preg_match('/^\d{4}$/', (string) ($r->query['year'] ?? '')) === 1 ? (int) $r->query['year'] : (int) substr($this->svc()->today(), 0, 4);
        return $this->page('@complaints/stats', ['title' => $this->t('complaints.stats.title'), 'companies' => $list, 'le' => $le,
            'stats' => $this->svc()->stats($this->ctx($r)->az(), $le, $year)]);
    }
}
