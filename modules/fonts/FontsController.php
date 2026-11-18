<?php

declare(strict_types=1);

namespace Blue\Modules\Fonts;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;

/** M30 screens: the library (search + live preview), a family page, and the administrators' screens. */
final class FontsController extends Controller
{
    private const FIELDS = ['name' => 120, 'category' => 20, 'description' => 2000, 'dos' => 2000, 'donts' => 2000, 'license_name' => 120,
        'license_source' => 255, 'license_terms' => 4000, 'restricted' => 1];

    public function index(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $q = mb_substr(trim((string) ($r->query['q'] ?? '')), 0, 80);
        $cat = (string) ($r->query['category'] ?? '');
        return $this->page('@fonts/index', ['title' => $this->app->translator()->get('fonts.title'), 'rows' => FontsService::of($this->app)->library($az, $q, $cat),
            'q' => $q, 'category' => $cat, 'canManage' => $az->can('fonts.manage')]);
    }

    public function show(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $svc = FontsService::of($this->app);
        $f = $svc->family($az, (string) $r->params['id']);
        if (!in_array($f['status'], FontsService::VISIBLE, true)) {
            return $this->redirect($r, '/fonts/manage/' . $f['public_id']); // administrators: drafts/archived live in the manage screen
        }
        return $this->page('@fonts/show', ['title' => (string) $f['name'], 'f' => $f, 'files' => $svc->files((int) $f['id'], true), 'canManage' => $az->can('fonts.manage')]);
    }

    public function preview(Request $r): Response
    {
        $p = FontsService::of($this->app)->preview($this->ctx($r)->az(), (string) $r->params['id']);
        return new Response($p['bytes'], 200, ['Content-Type' => $p['mime'], 'Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline']);
    }

    public function download(Request $r): Response
    {
        $d = FontsService::of($this->app)->download($this->ctx($r)->az(), (string) $r->params['id']);
        return Response::attachment($d['bytes'], $d['mime'], $d['filename']);
    }

    public function zip(Request $r): Response
    {
        try {
            $d = FontsService::of($this->app)->bundle($this->ctx($r)->az(), (string) $r->params['id']);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e; // 403/404 stay what they are
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/fonts/' . $r->params['id']);
        }
        return Response::attachment($d['bytes'], 'application/zip', $d['filename']);
    }

    // ---- administrators ----------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    public function manage(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        if (!$az->can('fonts.manage')) {
            throw HttpException::forbidden();
        }
        $svc = FontsService::of($this->app);
        $st = (string) ($r->query['status'] ?? '');
        return $this->page('@fonts/manage', ['title' => $this->app->translator()->get('fonts.manage.title'), 'rows' => $svc->all($az, $st), 'counts' => $svc->counts(),
            'status' => $st, 'errors' => $errors, 'old' => $old, 'openCreate' => $errors !== []], $status);
    }

    public function create(Request $r): Response
    {
        $in = $this->input($r);
        try {
            $pid = FontsService::of($this->app)->create($this->ctx($r)->az(), $in);
        } catch (ValidationException $e) {
            return $this->manage($r, $this->errors($e), $in, 422);
        }
        $this->flash($r, 'success', 'fonts.flash.created');
        return $this->redirect($r, '/fonts/manage/' . $pid);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string>|null $old
     */
    public function edit(Request $r, array $errors = [], ?array $old = null, int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        if (!$az->can('fonts.manage')) {
            throw HttpException::forbidden();
        }
        $svc = FontsService::of($this->app);
        $f = $svc->family($az, (string) $r->params['id']);
        $others = array_values(array_filter($svc->all($az, 'published'), static fn (array $x): bool => $x['public_id'] !== $f['public_id']));
        return $this->page('@fonts/edit', ['title' => (string) $f['name'], 'f' => $f, 'files' => $svc->files((int) $f['id'], false), 'events' => $svc->events((int) $f['id']),
            'errors' => $errors, 'old' => $old ?? $f, 'others' => $others, 'me' => $az->userId], $status);
    }

    public function update(Request $r): Response
    {
        $in = $this->input($r);
        try {
            FontsService::of($this->app)->update($this->ctx($r)->az(), (string) $r->params['id'], $in);
        } catch (ValidationException $e) {
            return $this->edit($r, $this->errors($e), $in, 422);
        }
        $this->flash($r, 'success', 'fonts.flash.saved');
        return $this->redirect($r, '/fonts/manage/' . $r->params['id']);
    }

    public function upload(Request $r): Response
    {
        $file = $r->file('file');
        $isZip = $file !== null && preg_match('/\.zip$/i', $file->clientName) === 1;
        try {
            if ($isZip) {
                $res = FontsService::of($this->app)->addZip($this->ctx($r)->az(), (string) $r->params['id'], $file);
            } else {
                $res = FontsService::of($this->app)->addFile($this->ctx($r)->az(), (string) $r->params['id'], $file, $this->str($r, 'replaces', 26));
            }
        } catch (ValidationException $e) {
            return $this->edit($r, $this->errors($e), null, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e; // 403/404 stay what they are
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/fonts/manage/' . $r->params['id']);
        }
        if ($isZip && isset($res['added'], $res['skipped'])) {
            $t = $this->app->translator();
            $list = implode(' · ', array_map(static fn (array $x): string => $x['name'] . ' (' . $t->get($x['reason']) . ')', array_slice($res['skipped'], 0, 10)));
            $n = ['n' => (string) count($res['added']), 'm' => (string) count($res['skipped']), 'list' => $list];
            if ($res['skipped'] === []) {
                $this->flash($r, 'success', 'fonts.flash.zip_added', $n);
            } elseif ($res['added'] === []) {
                $this->flash($r, 'danger', 'fonts.flash.zip_none', $n);
            } else {
                $this->flash($r, 'warning', 'fonts.flash.zip_partial', $n);
            }
        } else {
            $this->flash($r, 'success', 'fonts.flash.file_added');
        }
        foreach ($res['warnings'] as $w) {
            $this->flash($r, 'warning', $w);
        }
        return $this->redirect($r, '/fonts/manage/' . $r->params['id'] . '#files');
    }

    public function license(Request $r): Response
    {
        try {
            FontsService::of($this->app)->addLicense($this->ctx($r)->az(), (string) $r->params['id'], $r->file('license_file'));
        } catch (ValidationException $e) {
            return $this->edit($r, $this->errors($e), null, 422);
        }
        $this->flash($r, 'success', 'fonts.flash.license_added');
        return $this->redirect($r, '/fonts/manage/' . $r->params['id'] . '#license');
    }

    public function licenseFile(Request $r): Response
    {
        $d = FontsService::of($this->app)->licenseFile($this->ctx($r)->az(), (string) $r->params['id']);
        return Response::attachment($d['bytes'], $d['mime'], $d['filename']);
    }

    public function publish(Request $r): Response
    {
        try {
            FontsService::of($this->app)->publish($this->ctx($r)->az(), (string) $r->params['id']);
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', (string) (array_values($e->errors)[0] ?? 'form.has_errors'));
            return $this->redirect($r, '/fonts/manage/' . $r->params['id']);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e; // 403/404 stay what they are
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/fonts/manage/' . $r->params['id']);
        }
        $this->flash($r, 'success', 'fonts.flash.published');
        return $this->redirect($r, '/fonts/manage/' . $r->params['id']);
    }

    public function status(Request $r): Response
    {
        try {
            FontsService::of($this->app)->setStatus(
                $this->ctx($r)->az(),
                (string) $r->params['id'],
                $this->str($r, 'to', 12),
                $this->str($r, 'reason', 300),
                $this->str($r, 'replacement', 26)
            );
        } catch (ValidationException $e) {
            return $this->edit($r, $this->errors($e), null, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e; // 403/404 stay what they are
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/fonts/manage/' . $r->params['id']);
        }
        $this->flash($r, 'success', 'fonts.flash.status');
        return $this->redirect($r, '/fonts/manage/' . $r->params['id']);
    }

    /** @return array<string, string> */
    private function input(Request $r): array
    {
        $in = [];
        foreach (self::FIELDS as $k => $max) {
            $in[$k] = $this->str($r, $k, $max);
        }
        return $in;
    }
}
