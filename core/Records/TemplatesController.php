<?php

declare(strict_types=1);

namespace Blue\Core\Records;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;

/** Document templates (03 §6.15): list, create, edit draft, publish, compare versions, preview, issue. */
final class TemplatesController extends Controller
{
    public function index(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        $manage = $az->scope('core.template.manage')->all;
        if (!$manage && !$az->can('core.template.issue')) {
            throw HttpException::forbidden();
        }
        $rows = $this->app->templates()->list();
        if (!$manage) {
            $rows = array_values(array_filter($rows, static fn (array $t): bool => $t['published_no'] !== null));
        }
        return $this->page('templates/index', ['title' => $this->app->translator()->get('template.title'), 'rows' => $rows, 'manage' => $manage]);
    }

    public function createForm(Request $r): Response
    {
        return $this->createPage($r, [], ['code' => '', 'name' => '', 'document_class' => 'core.letter', 'locale' => 'th', 'number_pattern' => '', 'legal_entity' => '']);
    }

    public function create(Request $r): Response
    {
        $in = [
            'code' => strtoupper($this->str($r, 'code', 40)), 'name' => $this->str($r, 'name', 150), 'document_class' => $this->str($r, 'document_class', 60),
            'locale' => $this->str($r, 'locale', 5), 'number_pattern' => $this->str($r, 'number_pattern', 60), 'legal_entity' => $this->str($r, 'legal_entity', 20),
        ];
        try {
            $pid = $this->app->templates()->create($this->ctx($r)->az(), $in + ['legal_entity_id' => ctype_digit($in['legal_entity']) ? (int) $in['legal_entity'] : null]);
        } catch (ValidationException $e) {
            return $this->createPage($r, $this->errors($e), $in, 422);
        }
        $this->flash($r, 'success', 'template.flash.created');
        return $this->redirect($r, '/templates/' . $pid);
    }

    public function show(Request $r): Response
    {
        return $this->showPage($r, [], null);
    }

    public function saveDraft(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $in = [
            'title' => $this->str($r, 'title', 200), 'body' => is_string($r->input('body')) ? mb_substr((string) $r->input('body'), 0, 200000) : '',
            'fields' => is_string($r->input('fields')) ? mb_substr((string) $r->input('fields'), 0, 5000) : '', 'font_family' => $this->str($r, 'font_family', 80),
            'show_header' => $r->input('show_header') === '1', 'show_footer' => $r->input('show_footer') === '1', 'reason' => $this->str($r, 'reason', 500),
        ];
        try {
            $this->app->templates()->saveDraft($this->ctx($r)->az(), $pid, $in);
        } catch (ValidationException $e) {
            $errors = $this->errors($e);
            if (isset($e->errors['body']) && $e->errors['body'] === 'template.error.unknown_placeholders') {
                [$fields] = TemplateService::parseFields($in['fields']);
                $unknown = array_diff(TemplateService::placeholders(\Blue\Core\Support\HtmlSanitizer::clean($in['body'])), array_merge(TemplateService::SYSTEM_FIELDS, array_column($fields, 'key')));
                $errors['body'] = $this->app->translator()->get('template.error.unknown_placeholders', ['names' => implode(', ', $unknown)]);
            }
            return $this->showPage($r, $errors, $in, 422);
        }
        $this->flash($r, 'success', 'template.flash.draft_saved');
        return $this->redirect($r, '/templates/' . $pid . '#editor');
    }

    public function publish(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        try {
            $no = $this->app->templates()->publish($this->ctx($r)->az(), $pid, $this->str($r, 'reason', 500));
            $this->flash($r, 'success', 'template.flash.published', ['n' => $no]);
        } catch (ValidationException) {
            $this->flash($r, 'danger', 'iam.error.reason_required');
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $this->flash($r, 'danger', $e->messageKey);
        }
        return $this->redirect($r, '/templates/' . $pid);
    }

    public function discard(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $this->app->templates()->discardDraft($this->ctx($r)->az(), $pid);
        $this->flash($r, 'success', 'template.flash.discarded');
        return $this->redirect($r, '/templates/' . $pid);
    }

    public function preview(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        if (!$az->scope('core.template.manage')->all && !$az->can('core.template.issue')) {
            throw HttpException::forbidden();
        }
        $v = $this->app->templates()->version((string) $r->params['id']);
        if ($v['status'] === 'draft' && !$az->scope('core.template.manage')->all) {
            throw HttpException::notFound();
        }
        $le = (int) ($this->store($r)->get('ctx_le') ?? 0);
        if ($le === 0) {
            $le = (int) ($this->app->db()->scalar('SELECT id FROM legal_entities ORDER BY id LIMIT 1') ?? 0);
        }
        $html = $this->app->templates()->preview((string) $r->params['id'], $le, (string) ($this->ctx($r)->user['display_name'] ?? ''));
        return (new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']))
            ->withHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'; frame-ancestors 'self'")
            ->withHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function compare(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        if (!$az->scope('core.template.manage')->all) {
            throw HttpException::forbidden();
        }
        $t = $this->app->templates()->get((string) $r->params['id']);
        $from = $this->str($r, 'from', 26);
        $to = $this->str($r, 'to', 26);
        return $this->page('templates/compare', [
            'title' => $this->app->translator()->get('template.compare.title'), 'tplRow' => $t,
            'diff' => $this->app->templates()->compare($from, $to), 'from' => $this->app->templates()->version($from), 'to' => $this->app->templates()->version($to),
        ]);
    }

    public function issue(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $pid = (string) $r->params['id'];
        $values = [];
        foreach ($r->body as $k => $v) {
            if (is_string($k) && str_starts_with($k, 'f_') && is_string($v)) {
                $values[substr($k, 2)] = $v;
            }
        }
        $le = $this->str($r, 'legal_entity', 20);
        $site = $this->str($r, 'site', 20);
        try {
            $res = $this->app->templates()->issue($ctx->az(), $pid, $values, ctype_digit($le) ? (int) $le : 0, ctype_digit($site) && $site !== '' ? (int) $site : null, $this->str($r, 'doc_title', 200));
        } catch (ValidationException $e) {
            return $this->showPage($r, $this->errors($e), null, 422, $r->body);
        } catch (BusinessException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            return $this->showPage($r, ['issue' => $this->app->translator()->get($e->messageKey)], null, 422, $r->body);
        }
        $this->flash($r, 'success', 'template.flash.issued', ['number' => (string) ($res['number'] ?? '—')]);
        return $this->redirect($r, '/documents/' . $res['document']);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed>|null $draftIn
     * @param array<string, mixed> $issueIn
     */
    private function showPage(Request $r, array $errors, ?array $draftIn, int $status = 200, array $issueIn = []): Response
    {
        $az = $this->ctx($r)->az();
        $manage = $az->scope('core.template.manage')->all;
        $canIssue = $az->can('core.template.issue');
        if (!$manage && !$canIssue) {
            throw HttpException::forbidden();
        }
        $t = $this->app->templates()->get((string) $r->params['id']);
        if (!$manage && $t['published'] === null) {
            throw HttpException::notFound();
        }
        $base = $t['draft'] ?? $t['published'];
        $draft = $draftIn ?? [
            'title' => (string) ($base['title'] ?? $t['name']), 'body' => (string) ($base['body_html'] ?? ''),
            'fields' => $base !== null ? TemplateService::fieldsText((array) json_decode((string) $base['fields_json'], true)) : '',
            'font_family' => (string) ($base['font_family'] ?? ''), 'show_header' => $base === null || (int) $base['show_header'] === 1,
            'show_footer' => $base === null || (int) $base['show_footer'] === 1, 'reason' => (string) ($t['draft']['change_reason'] ?? ''),
        ];
        $options = $this->app->org()->scopeOptions($az->scope('core.template.issue'), $this->app->translator()->locale());
        if ($t['legal_entity_id'] !== null) {
            $options['legal_entity'] = array_intersect_key($options['legal_entity'], [(int) $t['legal_entity_id'] => true]);
        }
        return $this->page('templates/show', [
            'title' => (string) $t['name'], 'tpl' => $t, 'manage' => $manage, 'canIssue' => $canIssue && $t['published'] !== null, 'draft' => $draft,
            'issueFields' => $t['published'] !== null ? (array) json_decode((string) $t['published']['fields_json'], true) : [],
            'options' => $options, 'errors' => $errors, 'issueIn' => $issueIn, 'systemFields' => TemplateService::SYSTEM_FIELDS,
        ], $status);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function createPage(Request $r, array $errors, array $old, int $status = 200): Response
    {
        if (!$this->ctx($r)->az()->scope('core.template.manage')->all) {
            throw HttpException::forbidden();
        }
        return $this->page('templates/new', [
            'title' => $this->app->translator()->get('template.new.title'), 'errors' => $errors, 'old' => $old,
            'companies' => $this->app->org()->scopeOptions($this->ctx($r)->az()->reachableScope(), $this->app->translator()->locale())['legal_entity'],
        ], $status);
    }
}
