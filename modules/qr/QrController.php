<?php

declare(strict_types=1);

namespace Blue\Modules\Qr;

use Blue\Core\Error\ValidationException;
use Blue\Core\Files\Csv;
use Blue\Core\Files\Xlsx;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;

/** M27 screens: generator with preview → download / save, saved codes, managed codes, batch, public short link. */
final class QrController extends Controller
{
    private const FIELDS = ['kind', 'content_type', 'content', 'name', 'category', 'open_external', 'level', 'logo', 'expires',
        // 0.38.0: fields of the other content types + colours and decoration
        'tel', 'email_to', 'email_subject', 'email_body', 'wifi_ssid', 'wifi_pass', 'wifi_enc', 'wifi_hidden',
        'vc_name', 'vc_org', 'vc_title', 'vc_tel', 'vc_email', 'vc_url', 'fg', 'bg', 'eye', 'module', 'eye_style', 'logo_size'];

    public function index(Request $r): Response
    {
        return $this->generator($r, $this->defaults(), null, [], 200);
    }

    /** 0.41.1 — QR reader: read in the browser only; the camera is allowed on this page and nowhere else. */
    public function scan(Request $r): Response
    {
        $this->ctx($r)->az();
        return $this->page('@qr/scan', ['title' => $this->app->translator()->get('qr.scan.title')])
            ->withHeader('Permissions-Policy', 'geolocation=(), camera=(self), microphone=(), payment=()');
    }

    public function preview(Request $r): Response
    {
        $in = $this->input($r);
        try {
            $p = QrService::of($this->app)->prepare($in, $this->origin($r));
        } catch (ValidationException $e) {
            return $this->generator($r, $in, null, $this->errors($e), 422);
        }
        return $this->generator($r, $in, $p, [], 200);
    }

    public function download(Request $r): Response
    {
        $in = $this->input($r);
        $svc = QrService::of($this->app);
        try {
            if ($in['kind'] === 'managed') {
                throw new ValidationException(['kind' => 'qr.error.save_managed_first']); // the short link exists only once saved
            }
            $p = $svc->prepare($in, $this->origin($r));
        } catch (ValidationException $e) {
            return $this->generator($r, $in, null, $this->errors($e), 422);
        }
        $name = QrService::fileName($in['name'] !== '' ? $in['name'] : 'qr');
        if ($r->input('format') === 'svg') {
            return Response::attachment($svc->svg($p['qr'], $p['logo'], $in['name'], $p['style']), 'image/svg+xml', $name . '.svg');
        }
        try {
            return Response::attachment($svc->png($p['qr'], $p['logo'], (string) $r->input('size', 'm'), $p['style']), 'image/png', $name . '.png');
        } catch (ValidationException $e) {
            return $this->generator($r, $in, $p, $this->errors($e), 422); // the logo cannot be drawn into a PNG here — never dropped silently
        }
    }

    public function save(Request $r): Response
    {
        $in = $this->input($r);
        $az = $this->ctx($r)->az();
        $le = $this->store($r)->get('ctx_le');
        try {
            $pid = QrService::of($this->app)->save($az, $in, is_int($le) ? $le : null, $this->origin($r));
        } catch (ValidationException $e) {
            $p = null;
            try {
                $p = QrService::of($this->app)->prepare($in, $this->origin($r));
            } catch (ValidationException) {
                // the field errors below say what to fix
            }
            return $this->generator($r, $in, $p, $this->errors($e), 422);
        }
        $this->flash($r, 'success', 'qr.saved');
        return $this->redirect($r, '/qr/' . $pid);
    }

    public function show(Request $r): Response
    {
        $svc = QrService::of($this->app);
        $row = $svc->get($this->ctx($r)->az(), (string) $r->params['id']);
        $qr = $svc->symbol($row, $this->origin($r));
        return $this->page('@qr/show', [
            'title' => (string) $row['name'], 'row' => $row, 'svg' => $svc->svg($qr, (int) $row['with_logo'] === 1, (string) $row['name'], QrService::savedStyle($row)),
            'qr' => ['version' => $qr['version'], 'level' => $qr['level'], 'size' => $qr['size']],
            'short' => $row['kind'] === 'managed' ? rtrim($this->origin($r), '/') . '/q/' . $row['token'] : null,
            'errors' => [],
        ]);
    }

    public function file(Request $r): Response
    {
        $svc = QrService::of($this->app);
        $row = $svc->get($this->ctx($r)->az(), (string) $r->params['id']);
        $qr = $svc->symbol($row, $this->origin($r));
        $name = QrService::fileName((string) $row['name']);
        $style = QrService::savedStyle($row);
        if (($r->query['format'] ?? '') === 'svg') {
            return Response::attachment($svc->svg($qr, (int) $row['with_logo'] === 1, (string) $row['name'], $style), 'image/svg+xml', $name . '.svg');
        }
        try {
            return Response::attachment($svc->png($qr, (int) $row['with_logo'] === 1, (string) ($r->query['size'] ?? 'm'), $style), 'image/png', $name . '.png');
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
            return $this->redirect($r, '/qr/' . $row['public_id']);
        }
    }

    /** 0.38.0: edit a saved code (name, category, colours, logo — and the content of a static code). */
    public function edit(Request $r): Response
    {
        $svc = QrService::of($this->app);
        $row = $svc->get($this->ctx($r)->az(), (string) $r->params['id']);
        return $this->editor($r, $row, QrService::formValues($row), [], 200);
    }

    public function update(Request $r): Response
    {
        $svc = QrService::of($this->app);
        $az = $this->ctx($r)->az();
        $row = $svc->get($az, (string) $r->params['id']);
        $in = $this->input($r);
        $in['kind'] = (string) $row['kind'];
        try {
            $svc->update($az, (string) $row['public_id'], $in, $this->origin($r));
        } catch (ValidationException $e) {
            return $this->editor($r, $row, $in, $this->errors($e), 422);
        }
        $this->flash($r, 'success', 'qr.updated');
        return $this->redirect($r, '/qr/' . $row['public_id']);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $in
     * @param array<string, string> $errors
     */
    private function editor(Request $r, array $row, array $in, array $errors, int $status): Response
    {
        $svc = QrService::of($this->app);
        $preview = null;
        $svg = null;
        if ($errors === []) {
            $err = [];
            $style = $row['kind'] === 'static' ? null : $svc->styleFrom($in, $err);
            try {
                $p = $row['kind'] === 'static' ? $svc->prepare(['kind' => 'static'] + $in, $this->origin($r)) : null;
                $qr = $p !== null ? $p['qr'] : $svc->symbol(['ec_level' => $in['logo'] === '1' ? 'H' : (string) $row['ec_level']] + $row, $this->origin($r));
                $svg = $svc->svg($qr, $in['logo'] === '1', (string) $row['name'], $p !== null ? $p['style'] : $style);
            } catch (ValidationException) {
                $svg = null;
            }
        }
        return $this->page('@qr/edit', ['title' => $this->app->translator()->get('qr.edit_title', ['name' => (string) $row['name']]), 'row' => $row, 'in' => $in,
            'errors' => $errors, 'svg' => $svg, 'logoBuiltin' => $svc->logo()['builtin'], 'qrPrimary' => $this->app->appearance()->primaryHex()], $status);
    }

    public function manage(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $az = $this->ctx($r)->az();
        $svc = QrService::of($this->app);
        try {
            match ((string) $r->input('do')) {
                'destination' => $svc->changeDestination($az, $pid, $this->str($r, 'url', 1000), $r->input('open_external') === '1', $this->str($r, 'reason', 300)),
                'revoke' => $svc->revoke($az, $pid, $this->str($r, 'reason', 300)),
                'extend' => $svc->extend($az, $pid, $this->str($r, 'expires', 10)),
                'delete' => $r->input('confirm') === '1' ? $svc->delete($az, $pid) : throw new ValidationException(['confirm' => 'qr.error.delete_confirm']),
                default => throw new ValidationException(['do' => 'form.has_errors']),
            };
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', $this->firstError($e));
            return $this->redirect($r, '/qr/' . $pid);
        }
        if ($r->input('do') === 'delete') {
            $this->flash($r, 'success', 'qr.deleted');
            return $this->redirect($r, '/qr');
        }
        $this->flash($r, 'success', 'qr.updated');
        return $this->redirect($r, '/qr/' . $pid);
    }

    /** @param array<string, string> $errors */
    public function batchForm(Request $r, array $errors = [], int $status = 200): Response
    {
        $this->ctx($r)->az();
        return $this->page('@qr/batch', ['title' => $this->app->translator()->get('qr.batch.title'), 'errors' => $errors,
            'hasLogo' => true], $status);
    }

    public function batchTemplate(Request $r): Response
    {
        $t = $this->app->translator();
        $bytes = Xlsx::write(['QR' => ['headers' => [$t->get('qr.field.name'), $t->get('qr.field.content'), $t->get('qr.field.category')],
            'rows' => [['ห้องประชุม 1', 'https://example.com/rooms/1', 'rooms'], ['โต๊ะ 12', 'https://example.com/menu?table=12', 'menu']]]]);
        return Response::attachment($bytes, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'qr-batch-template.xlsx');
    }

    public function batch(Request $r): Response
    {
        $file = $r->file('file');
        if ($file === null || !$file->ok()) {
            return $this->batchForm($r, ['file' => $this->app->translator()->get($file?->errorKey() ?? 'file.error.missing')], 422);
        }
        $bytes = $file->bytes();
        try {
            $rows = str_starts_with($bytes, "PK\x03\x04") ? Xlsx::read($bytes, null, QrService::BATCH_MAX + 1) : Csv::read($bytes, QrService::BATCH_MAX + 1);
        } catch (\Throwable) {
            return $this->batchForm($r, ['file' => $this->app->translator()->get('qr.error.batch_file')], 422);
        }
        array_shift($rows); // header
        $rows = array_values(array_filter($rows, static fn (array $row): bool => trim(implode('', $row)) !== ''));
        $opt = [];
        foreach (['level', 'logo', 'format', 'open_external', 'size'] as $k) {
            $opt[$k] = $this->str($r, $k, 10);
        }
        try {
            $res = QrService::of($this->app)->batch($this->ctx($r)->az(), $rows, $opt);
        } catch (ValidationException $e) {
            return $this->batchForm($r, $this->errors($e), 422);
        }
        return Response::attachment($res['zip'], 'application/zip', 'qr-batch-' . $this->app->clock->now()->format('Ymd-His') . '.zip');
    }

    /** Public: /q/<token> → destination, or a plain "no longer valid" page (no detail about the code). */
    public function go(Request $r): Response
    {
        $res = QrService::of($this->app)->resolve((string) $r->params['token']);
        if ($res['url'] !== null) {
            return (new Response('', 302, ['Location' => $res['url']]))->withHeader('Referrer-Policy', 'no-referrer')->withHeader('Cache-Control', 'no-store');
        }
        return $this->page('@qr/gone', ['title' => $this->app->translator()->get('qr.gone.title'), 'state' => $res['state']], $res['state'] === 'unknown' ? 404 : 410, 'layouts/minimal')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @param array<string, string> $in
     * @param array<string, mixed>|null $preview
     * @param array<string, string> $errors
     */
    private function generator(Request $r, array $in, ?array $preview, array $errors, int $status): Response
    {
        $az = $this->ctx($r)->az();
        $svc = QrService::of($this->app);
        $q = mb_substr(trim((string) ($r->query['q'] ?? '')), 0, 80);
        $all = ($r->query['all'] ?? '') === '1' && $az->can('qr.manage');
        return $this->page('@qr/index', [
            'title' => $this->app->translator()->get('qr.title'), 'in' => $in, 'errors' => $errors,
            'preview' => $preview, 'svg' => $preview !== null ? $svc->svg($preview['qr'], $preview['logo'], (string) ($in['name'] ?? ''), $preview['style']) : null,
            'rows' => $svc->mine($az, $q, $all), 'q' => $q, 'all' => $all, 'canManage' => $az->can('qr.manage'),
            'hasLogo' => true, 'logoBuiltin' => $svc->logo()['builtin'], 'qrPrimary' => $this->app->appearance()->primaryHex(),
        ], $status);
    }

    /** @return array<string, string> */
    private function input(Request $r): array
    {
        $in = [];
        foreach (self::FIELDS as $f) {
            $in[$f] = $this->str($r, $f, in_array($f, ['content', 'email_body'], true) ? QrPolicy::MAX_TEXT + 1 : 300);
        }
        if ($this->ctx($r)->isGuest()) {
            $in['kind'] = 'static'; // a visitor (0.8.1) can only make a plain QR — a managed one needs an account
        }
        return $in;
    }

    /** @return array<string, string> */
    private function defaults(): array
    {
        return ['kind' => 'static', 'content_type' => 'url', 'content' => '', 'name' => '', 'category' => '', 'open_external' => '', 'level' => 'M', 'logo' => '',
            'fg' => $this->app->appearance()->primaryHex(), 'bg' => '#FFFFFF', 'eye' => '', 'module' => 'square', 'eye_style' => 'square', 'logo_size' => QrService::DEFAULT_LOGO_SIZE, 'wifi_enc' => 'WPA',
            'expires' => $this->app->clock->now()->setTimezone(new \DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok')))->modify('+365 days')->format('Y-m-d')];
    }

    private function origin(Request $r): string
    {
        $configured = rtrim($this->app->config->string('APP_URL'), '/');
        return $configured !== '' ? $configured : ($r->secure ? 'https' : 'http') . '://' . $r->host . $r->basePath();
    }
}
