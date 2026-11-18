<?php

declare(strict_types=1);

namespace Blue\Core\Records;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\TableExport;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Ui\Paging;

/** Document register (04 §6.17): list, upload, detail, versions, download/print, finalize, supersede, verify, hold. */
final class DocumentsController extends Controller
{
    public function index(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $az = $ctx->az();
        [$where] = $this->app->documents()->visibleSql($az);
        $uploadable = $this->app->documents()->uploadableClasses($az);
        if ($where === '1=0' && $uploadable === []) {
            throw \Blue\Core\Error\HttpException::forbidden();
        }
        $f = $this->filters($r);
        $paging = Paging::from($r, $this->app->preferences(), $ctx->userId(), 'documents');
        $res = $this->app->documents()->list($az, $f, $paging->page, $paging->per);
        if ($paging->clamp($res['total'])) {
            $res = $this->app->documents()->list($az, $f, $paging->page, $paging->per);
        }
        return $this->page('documents/index', [
            'title' => $this->app->translator()->get('document.title'),
            'rows' => $res['rows'], 'total' => $res['total'], 'page' => $paging->page, 'per' => $paging->per, 'f' => $f,
            'classes' => array_keys($this->app->documents()->classes()), 'canUpload' => $uploadable !== [],
            'companies' => $this->app->org()->scopeOptions($az->reachableScope(), $this->app->translator()->locale())['legal_entity'],
        ]);
    }

    public function export(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $f = $this->filters($r);
        $max = $this->exportMax();
        $res = $this->app->documents()->list($ctx->az(), $f, 1, $max);
        $t = $this->app->translator();
        $rows = array_map(static fn (array $d): array => [
            $d['public_id'], $d['document_number'], $d['title'], $t->get('document.class.' . $d['document_class']), $d['le_code'], $t->get('document.status.' . $d['lifecycle_status']),
            (int) $d['current_version_no'], $d['original_filename'], $d['size_bytes'], $d['created_at'], $d['scheduled_disposition_date'],
        ], $res['rows']);
        $this->app->audit()->record('document.list_exported', 'success', null, null, null, http_build_query($f), ['rows' => count($rows), 'total' => $res['total']]);
        return TableExport::response(TableExport::format($r->query['format'] ?? 'xlsx'), 'documents-' . $this->app->clock->now()->format('Ymd-His'), [
            'id', $t->get('document.field.number'), $t->get('document.field.title'), $t->get('document.field.class'), $t->get('document.field.company'),
            $t->get('document.field.status'), $t->get('document.field.version'), $t->get('document.field.filename'), $t->get('document.field.size'),
            $t->get('document.field.created') . ' (UTC)', $t->get('document.field.disposal'),
        ], $rows, [6, 8]);
    }

    public function createForm(Request $r): Response
    {
        return $this->form($r, [], []);
    }

    public function create(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $in = $this->meta($r);
        try {
            $res = $this->app->documents()->upload($ctx->az(), $in['class'], $r->file('file') ?? new \Blue\Core\Files\UploadedFile('', '', '', 0, UPLOAD_ERR_NO_FILE), $in);
        } catch (ValidationException $e) {
            return $this->form($r, $this->errors($e), $in, 422);
        } catch (BusinessException $e) {
            return $this->form($r, ['class' => $this->app->translator()->get($e->messageKey)], $in, 422);
        }
        $this->flashDuplicates($r, $res['duplicates'], 'document.flash.uploaded');
        return $this->redirect($r, '/documents/' . $res['document']);
    }

    public function show(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $doc = $this->app->documents()->get($ctx->az(), (string) $r->params['id']);
        return $this->page('documents/show', [
            'title' => (string) $doc['title'], 'doc' => $doc,
            'verify' => $this->store($r)->get('verify_' . $doc['public_id']),
            'errors' => [],
        ]);
    }

    public function download(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $pid = (string) $r->params['id'];
        $doc = $this->app->documents()->row($pid);
        if ($doc['classification'] === 'R') {
            $ctx->requireFreshReauth(); // Restricted files need a fresh password (P0-04 §2)
        }
        $v = isset($r->query['v']) && preg_match('/^\d{1,5}$/', (string) $r->query['v']) ? (int) $r->query['v'] : null;
        $inline = ($r->query['inline'] ?? '') === '1';
        $file = $this->app->documents()->download($ctx->az(), $pid, $v, $inline ? 'view' : 'download');
        $isImage = in_array($file['ext'], ['png', 'jpg', 'gif', 'webp', 'ico'], true);
        return self::fileResponse($file['bytes'], $file['mime'], $file['filename'], $inline && $isImage);
    }

    /** Template-issued documents (frozen HTML): shown exactly as stored, with an unprinted toolbar for printing / saving as PDF. */
    public function view(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $pid = (string) $r->params['id'];
        $file = $this->app->documents()->download($ctx->az(), $pid, null, 'print');
        if ($file['ext'] !== 'html') {
            return $this->redirect($r, '/documents/' . $pid . '/download');
        }
        $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        $t = $this->app->translator();
        $bar = '<div id="blue-toolbar" style="position:sticky;top:0;display:flex;gap:8px;align-items:center;padding:8px 12px;background:#0B1F44;color:#fff;font:14px Tahoma,sans-serif">'
            . '<button id="blue-print" type="button" style="font:inherit;padding:6px 14px;border-radius:6px;border:0;cursor:pointer">' . htmlspecialchars($t->get('document.print_button')) . '</button>'
            . '<a href="' . htmlspecialchars($r->basePath() . '/documents/' . $pid) . '" style="color:#fff">' . htmlspecialchars($t->get('document.back_to_record')) . '</a>'
            . '<span style="opacity:.8">' . htmlspecialchars($t->get('document.print_hint')) . '</span></div>'
            . '<style>@media print{#blue-toolbar{display:none}}</style>'
            . '<script nonce="' . $nonce . '">document.getElementById("blue-print").addEventListener("click",function(){window.print()});</script>';
        $html = (string) preg_replace('/<body>/', '<body>' . $bar, $file['bytes'], 1);
        return (new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']))
            ->withHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src data:; script-src 'nonce-{$nonce}'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'")
            ->withHeader('Cache-Control', 'private, no-store');
    }

    public function newVersion(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $pid = (string) $r->params['id'];
        try {
            $res = $this->app->documents()->upload($ctx->az(), '', $r->file('file') ?? new \Blue\Core\Files\UploadedFile('', '', '', 0, UPLOAD_ERR_NO_FILE), [], $pid, $this->str($r, 'note', 500));
            $this->flashDuplicates($r, $res['duplicates'], 'document.flash.version_added');
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
        } catch (BusinessException $e) {
            $this->flash($r, 'danger', $e->messageKey);
        }
        return $this->redirect($r, '/documents/' . $pid);
    }

    public function finalize(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $this->app->documents()->finalize($this->ctx($r)->az(), $pid);
            $this->flash($r, 'success', 'document.flash.finalized');
        } catch (BusinessException $e) {
            $this->rethrowHttp($e);
            $this->flash($r, 'danger', $e->messageKey);
        }
        return $this->redirect($r, '/documents/' . $pid);
    }

    public function supersede(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $res = $this->app->documents()->supersede($this->ctx($r)->az(), $pid, $r->file('file') ?? new \Blue\Core\Files\UploadedFile('', '', '', 0, UPLOAD_ERR_NO_FILE), $this->str($r, 'reason', 500));
            $this->flash($r, 'success', 'document.flash.superseded');
            return $this->redirect($r, '/documents/' . $res['document']);
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
        } catch (BusinessException $e) {
            $this->rethrowHttp($e);
            $this->flash($r, 'danger', $e->messageKey);
        }
        return $this->redirect($r, '/documents/' . $pid);
    }

    public function verify(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $res = $this->app->documents()->verify($this->ctx($r)->az(), $pid);
        $this->store($r)->set('verify_' . $pid, $res);
        $bad = array_filter($res, static fn (array $x): bool => in_array($x['result'], ['missing', 'mismatch'], true));
        $this->flash($r, $bad === [] ? 'success' : 'danger', $bad === [] ? 'document.flash.verified_ok' : 'document.flash.verified_bad');
        return $this->redirect($r, '/documents/' . $pid);
    }

    public function hold(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $this->app->holds()->place($this->ctx($r)->az(), [
                'scope_type' => 'document', 'document' => $pid, 'reference' => $this->str($r, 'reference', 60), 'reason' => $this->str($r, 'reason', 500), 'review_date' => $this->str($r, 'review_date', 10),
            ]);
            $this->flash($r, 'success', 'records.flash.hold_placed');
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
        }
        return $this->redirect($r, '/documents/' . $pid);
    }

    // ---- helpers ---------------------------------------------------------------------------------

    public static function fileResponse(string $bytes, string $mime, string $filename, bool $inline): Response
    {
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'file';
        return (new Response($bytes, 200, ['Content-Type' => $mime]))
            ->withHeader('Content-Disposition', ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename))
            ->withHeader('Content-Security-Policy', "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox")
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Content-Length', (string) strlen($bytes));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    private function form(Request $r, array $errors, array $old, int $status = 200): Response
    {
        $az = $this->ctx($r)->az();
        $classes = $this->app->documents()->uploadableClasses($az);
        if ($classes === []) {
            throw \Blue\Core\Error\HttpException::forbidden();
        }
        $scope = \Blue\Core\Authz\ScopeSet::none();
        foreach ($classes as $c) {
            $s = $az->scope((string) $c['manage_permission']);
            $scope = new \Blue\Core\Authz\ScopeSet($scope->all || $s->all, array_values(array_unique([...$scope->legalEntityIds, ...$s->legalEntityIds])), array_values(array_unique([...$scope->siteIds, ...$s->siteIds])));
        }
        return $this->page('documents/new', [
            'title' => $this->app->translator()->get('document.new.title'),
            'classes' => $classes, 'options' => $this->app->org()->scopeOptions($scope, $this->app->translator()->locale()),
            'errors' => $errors, 'old' => $old,
        ], $status);
    }

    /** @return array{class: string, title: string, description: string, legal_entity_id: ?int, site_id: ?int, document_number: string, related_type: string, related_id: string} */
    private function meta(Request $r): array
    {
        $le = $this->str($r, 'legal_entity', 20);
        $site = $this->str($r, 'site', 20);
        return [
            'class' => $this->str($r, 'class', 60), 'title' => $this->str($r, 'title', 200), 'description' => $this->str($r, 'description', 500),
            'legal_entity_id' => ctype_digit($le) ? (int) $le : null, 'site_id' => ctype_digit($site) ? (int) $site : null,
            'document_number' => $this->str($r, 'document_number', 60), 'related_type' => '', 'related_id' => '',
        ];
    }

    /** @return array{q: string, class: string, status: string, legal_entity: int} */
    private function filters(Request $r): array
    {
        $status = $this->str($r, 'status', 20);
        return [
            'q' => $this->str($r, 'q', 100), 'class' => $this->str($r, 'class', 60),
            'status' => in_array($status, ['draft', 'final', 'superseded', 'archived', 'disposed'], true) ? $status : '',
            'legal_entity' => (int) $this->str($r, 'legal_entity', 20),
        ];
    }

    /** @param list<array{public_id: string, title: string}> $dups */
    private function flashDuplicates(Request $r, array $dups, string $okKey): void
    {
        if ($dups === []) {
            $this->flash($r, 'success', $okKey);
            return;
        }
        $this->flash($r, 'warning', 'document.flash.duplicate', ['titles' => implode(', ', array_map(static fn (array $d): string => $d['title'], array_slice($dups, 0, 3)))]);
    }

    private function rethrowHttp(BusinessException $e): void
    {
        if ($e instanceof \Blue\Core\Error\HttpException) {
            throw $e;
        }
    }

    private function exportMax(): int
    {
        $c = $this->app->registry()->resolveOrNull('export.max_rows');
        return $c !== null && is_int($c->value) ? $c->value : 20000;
    }
}
