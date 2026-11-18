<?php

declare(strict_types=1);

namespace Blue\Core\Import;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\FileType;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Ui\Paging;

/** Shared import wizard (03 §6.10): template → upload → preview → confirm, with an error report. */
final class ImportController extends Controller
{
    public function form(Request $r): Response
    {
        return $this->formPage($r, []);
    }

    public function template(Request $r): Response
    {
        $code = (string) $r->params['code'];
        $d = $this->app->imports()->definition($code);
        $bytes = $this->app->imports()->template($this->ctx($r)->az(), $code, $this->params($r));
        return (new Response($bytes, 200, ['Content-Type' => FileType::TYPES['xlsx'][0]]))
            ->withHeader('Content-Disposition', 'attachment; filename="' . self::templateFile($d) . '.xlsx"');
    }

    /** A definition may name its template file after a published format (e.g. BLUE_PUNCH_IMPORT_XLSX_v1). */
    private static function templateFile(\Blue\Core\Import\ImportDefinition $d): string
    {
        $name = method_exists($d, 'templateName') ? (string) $d->templateName() : '';
        return preg_match('/^[A-Za-z0-9_-]{3,60}$/', $name) === 1 ? $name : 'template-' . str_replace('.', '-', $d->code()) . '-' . $d->version();
    }

    public function upload(Request $r): Response
    {
        $code = (string) $r->params['code'];
        try {
            $pid = $this->app->imports()->upload($this->ctx($r)->az(), $code, $this->params($r), $r->file('file'));
        } catch (ValidationException $e) {
            return $this->formPage($r, $this->errors($e), 422);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            return $this->formPage($r, ['file' => $this->app->translator()->get($e->messageKey, array_map('strval', $e->params))], 422);
        }
        return $this->redirect($r, '/import/batch/' . $pid);
    }

    public function batch(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $paging = Paging::from($r, $this->app->preferences(), $ctx->userId(), 'import_rows');
        $filter = $this->str($r, 'show', 10);
        $p = $this->app->imports()->preview($ctx->az(), (string) $r->params['id'], $filter, $paging->page, $paging->per);
        $params = (array) json_decode((string) $p['batch']['params_json'], true);
        return $this->page('import/batch', [
            'title' => $this->app->translator()->get($p['definition']->titleKey()), 'p' => $p, 'filter' => $filter,
            'page' => $paging->page, 'per' => $paging->per, 'back' => $p['definition']->backLink($params),
        ]);
    }

    public function confirm(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $n = $this->app->imports()->confirm($this->ctx($r)->az(), $pid, $r->input('skip_errors') === '1');
            $this->flash($r, 'success', 'import.flash.applied', ['n' => $n]);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
        }
        return $this->redirect($r, '/import/batch/' . $pid);
    }

    public function cancel(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $this->app->imports()->cancel($this->ctx($r)->az(), $pid);
        $this->flash($r, 'success', 'import.flash.cancelled');
        return $this->redirect($r, '/import/batch/' . $pid);
    }

    public function errorReport(Request $r): Response
    {
        $bytes = $this->app->imports()->errorReport($this->ctx($r)->az(), (string) $r->params['id']);
        return (new Response($bytes, 200, ['Content-Type' => FileType::TYPES['xlsx'][0]]))
            ->withHeader('Content-Disposition', 'attachment; filename="import-errors-' . $r->params['id'] . '.xlsx"');
    }

    /** @param array<string, string> $errors */
    private function formPage(Request $r, array $errors, int $status = 200): Response
    {
        $ctx = $this->ctx($r);
        $code = (string) $r->params['code'];
        $d = $this->app->imports()->definition($code);
        $params = $this->params($r);
        $prepared = $d->prepare($ctx->az(), $params);
        return $this->page('import/upload', [
            'title' => $this->app->translator()->get($d->titleKey()), 'd' => $d, 'code' => $code, 'params' => $params,
            'back' => $d->backLink($prepared), 'recent' => $this->app->imports()->recent($ctx->az(), $code), 'errors' => $errors,
        ], $status);
    }

    /** @return array<string, string> only simple identifiers are passed through */
    private function params(Request $r): array
    {
        $out = [];
        foreach (['calendar', 'batch', 'le'] as $k) {
            $v = $this->str($r, $k, 40);
            if ($v !== '' && preg_match('/^[0-9A-Za-z_.-]{1,40}$/', $v) === 1) {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
