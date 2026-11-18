<?php

declare(strict_types=1);

namespace Blue\Modules\Pdf;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;

/** S01 screens: tool list, upload, preflight + options, result, administrator's feature registry. */
final class PdfController extends Controller
{
    public function index(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        if (!$az->can('pdf.use')) {
            throw HttpException::forbidden();
        }
        $svc = PdfService::of($this->app);
        $svc->cleanup(); // hosts without cron still drop expired files
        return $this->page('@pdf/index', ['title' => $this->app->translator()->get('pdf.title'), 'features' => $svc->features()->all(), 'limits' => $svc->features()->limits(),
            'jobs' => $svc->myJobs($az), 'canManage' => $az->can('pdf.manage')]);
    }

    /** @param array<string, string> $errors */
    public function tool(Request $r, array $errors = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $op = (string) $r->params['op'];
        $svc = PdfService::of($this->app);
        $all = $svc->features()->all();
        if (!$az->can('pdf.use') || !isset(PdfFeatures::BUILT_IN[$op])) {
            throw HttpException::notFound();
        }
        return $this->page('@pdf/tool', ['title' => $this->app->translator()->get('pdf.tool.' . $op), 'op' => $op, 'feature' => $all[$op], 'limits' => $svc->features()->limits(),
            'errors' => $errors], $status);
    }

    /**
     * 0.39.0 (item 3.1): draw a signature and place it on a PDF — entirely in the browser. The page has no form that
     * sends the drawing or the PDF anywhere; the server never receives, keeps or audits either.
     */
    public function sign(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $f = PdfService::of($this->app)->features()->all()['sign'];
        if (!$az->can('pdf.use')) {
            throw HttpException::notFound();
        }
        return $this->page('@pdf/sign', ['title' => $this->app->translator()->get('pdf.tool.sign'), 'feature' => $f, 'limits' => PdfService::of($this->app)->features()->limits()]);
    }

    public function stage(Request $r): Response
    {
        $op = (string) $r->params['op'];
        if (!isset(PdfFeatures::BUILT_IN[$op])) {
            throw HttpException::notFound();
        }
        $pw = $r->input('password', '');
        try {
            $pid = PdfService::of($this->app)->stage($this->ctx($r)->az(), $op, $r->files['files'] ?? [], is_string($pw) ? $pw : '', $this->le($r));
        } catch (ValidationException $e) {
            return $this->tool($r, $this->errors($e), 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e; // 403/404 stay what they are
            }
            return $this->tool($r, ['files' => $this->app->translator()->get($e->messageKey)], 422);
        }
        return $this->redirect($r, '/pdf/job/' . $pid);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function job(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $svc = PdfService::of($this->app);
        $job = $svc->job($az, (string) $r->params['id']);
        $t = $this->app->translator();
        $classes = [];
        foreach (['core.general', 'core.confidential'] as $c) {
            $def = $this->app->documents()->classes()[$c] ?? null;
            if ($def !== null && !$az->scope((string) $def['manage_permission'])->isEmpty()) {
                $classes[$c] = $t->get('document.class.' . $c);
            }
        }
        return $this->page('@pdf/job', ['title' => $t->get('pdf.tool.' . $job['operation']), 'job' => $job, 'errors' => $errors, 'old' => $old,
            'now' => $this->app->clock->now()->format('Y-m-d H:i:s.u'), 'classes' => $classes,
            'companies' => $this->app->org()->scopeOptions($az->reachableScope(), $t->locale())['legal_entity']], $status);
    }

    public function run(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $body = $r->body;
        try {
            PdfService::of($this->app)->run($this->ctx($r)->az(), $pid, $body);
        } catch (ValidationException $e) {
            unset($body['user_password'], $body['user_password_confirm'], $body['owner_password']); // never echoed back
            return $this->job($r, $this->errors($e), $body, 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e; // 403/404 stay what they are
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/pdf/job/' . $pid);
        }
        return $this->redirect($r, '/pdf/job/' . $pid);
    }

    public function file(Request $r): Response
    {
        try {
            $f = PdfService::of($this->app)->output($this->ctx($r)->az(), (string) $r->params['id'], (int) $r->params['n']);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e; // 403/404 stay what they are
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/pdf/job/' . $r->params['id']);
        }
        return Response::attachment($f['bytes'], 'application/pdf', $f['name']);
    }

    /** Page previews (thumbnails) are drawn by PDF.js in the browser from these bytes. */
    public function preview(Request $r): Response
    {
        try {
            $f = PdfService::of($this->app)->preview($this->ctx($r)->az(), (string) $r->params['id'], (string) $r->params['k'], (int) $r->params['n']);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            return new Response('', 410, ['Cache-Control' => 'no-store']);
        }
        return new Response($f['bytes'], 200, ['Content-Type' => $f['mime'], 'Content-Disposition' => 'inline', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    public function zip(Request $r): Response
    {
        try {
            $f = PdfService::of($this->app)->outputsZip($this->ctx($r)->az(), (string) $r->params['id']);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e; // 403/404 stay what they are
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/pdf/job/' . $r->params['id']);
        }
        return Response::attachment($f['bytes'], 'application/zip', $f['name']);
    }

    public function save(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            PdfService::of($this->app)->saveToRecords(
                $this->ctx($r)->az(),
                $pid,
                (int) $r->params['n'],
                $this->str($r, 'class', 40),
                (int) $this->str($r, 'legal_entity_id', 12),
                $this->str($r, 'title', 200)
            );
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
            return $this->redirect($r, '/pdf/job/' . $pid);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e; // 403/404 stay what they are
            }
            $this->flash($r, 'danger', $e->messageKey);
            return $this->redirect($r, '/pdf/job/' . $pid);
        }
        $this->flash($r, 'success', 'pdf.flash.saved');
        return $this->redirect($r, '/pdf/job/' . $pid);
    }

    public function discard(Request $r): Response
    {
        PdfService::of($this->app)->discard($this->ctx($r)->az(), (string) $r->params['id']);
        $this->flash($r, 'success', 'pdf.flash.discarded');
        return $this->redirect($r, '/pdf');
    }

    /** @param array<string, string> $errors */
    public function admin(Request $r, array $errors = [], int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        if (!$az->can('pdf.manage')) {
            throw HttpException::forbidden();
        }
        $f = PdfService::of($this->app)->features();
        return $this->page('@pdf/admin', ['title' => $this->app->translator()->get('pdf.admin.title'), 'features' => $f->all(), 'limits' => $f->limits(), 'errors' => $errors,
            'php' => ['upload_max_filesize' => (string) ini_get('upload_max_filesize'), 'post_max_size' => (string) ini_get('post_max_size'), 'memory_limit' => (string) ini_get('memory_limit'),
                'max_execution_time' => (string) ini_get('max_execution_time')]], $status);
    }

    public function saveAdmin(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        if (!$az->can('pdf.manage')) {
            throw HttpException::forbidden();
        }
        $reason = $this->str($r, 'reason', 300);
        if (mb_strlen($reason) < 3) {
            return $this->admin($r, ['reason' => $this->app->translator()->get('iam.error.reason_required')], 422);
        }
        $on = (array) ($r->body['enabled'] ?? []);
        $off = array_values(array_filter(PdfFeatures::switchable(), static fn (string $c): bool => ($on[$c] ?? '') !== '1'));
        $reg = $this->app->registry();
        $f = PdfService::of($this->app)->features();
        try {
            if ($off !== $f->disabled()) {
                $reg->applyNow($az, 'pdf.disabled_features', (string) json_encode($off), $reason);
            }
            $cur = $f->limits();
            foreach (['max_mb', 'max_pages', 'max_files', 'result_minutes'] as $k) {
                $v = $this->str($r, $k, 6);
                if ($v !== '' && (int) $v !== $cur[$k]) {
                    $reg->applyNow($az, 'pdf.' . $k, $v, $reason);
                }
            }
        } catch (ValidationException $e) {
            return $this->admin($r, $this->errors($e), 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e; // 403/404 stay what they are
            }
            return $this->admin($r, ['reason' => $this->app->translator()->get($e->messageKey)], 403);
        }
        $this->flash($r, 'success', 'pdf.flash.admin_saved');
        return $this->redirect($r, '/pdf/admin');
    }

    private function le(Request $r): ?int
    {
        $le = $this->store($r)->get('ctx_le');
        return is_int($le) ? $le : null;
    }
}
