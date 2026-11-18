<?php

declare(strict_types=1);

namespace Blue\Modules\Crypto;

use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;

/**
 * M28 screens. Results are rendered straight into the POST response (never into the session, the URL or a redirect),
 * the response is marked no-store so the browser does not keep it, and nothing the user typed is logged or audited.
 */
final class CryptoController extends Controller
{
    public const TOOLS = ['hash', 'verify', 'digest', 'hmac', 'encode', 'random'];
    private const MAX_FILE = 20 * 1024 * 1024;

    public function index(Request $r): Response
    {
        $this->guard($r);
        $tool = in_array($r->query['tool'] ?? '', self::TOOLS, true) ? (string) $r->query['tool'] : 'hash';
        return $this->render($tool, [], null, [], 200);
    }

    public function run(Request $r): Response
    {
        $this->guard($r);
        $tool = in_array($r->input('tool'), self::TOOLS, true) ? (string) $r->input('tool') : 'hash';
        $raw = static fn (string $k): string => is_string($r->body[$k] ?? null) ? (string) $r->body[$k] : '';
        $keep = [];
        try {
            switch ($tool) {
                case 'hash':
                    $res = CryptoTools::passwordHash($raw('password'), $raw('algo'), (int) $raw('cost'));
                    $keep = ['algo' => $raw('algo'), 'cost' => $raw('cost')];
                    break;
                case 'verify':
                    $res = CryptoTools::passwordVerify($raw('password'), $raw('hash'));
                    $keep = ['hash' => $raw('hash')];
                    break;
                case 'digest':
                    $file = $r->file('file');
                    if ($file !== null && $file->ok()) {
                        if ($file->size > self::MAX_FILE) {
                            throw new ValidationException(['file' => 'crypto.error.file_too_large']);
                        }
                        $res = CryptoTools::digest($file->bytes(), $raw('algo'), true);
                        $res['facts']['crypto.fact.file'] = $file->safeName();
                    } else {
                        $res = CryptoTools::digest($raw('text'), $raw('algo'));
                        $keep = ['text' => $raw('text')];
                    }
                    $keep['algo'] = $raw('algo');
                    break;
                case 'hmac':
                    $res = CryptoTools::hmac($raw('text'), $raw('key'), $raw('algo'));
                    $keep = ['text' => $raw('text'), 'algo' => $raw('algo')];
                    break;
                case 'encode':
                    $res = CryptoTools::codec($raw('text'), $raw('codec'), $raw('direction'));
                    $keep = ['text' => $raw('text'), 'codec' => $raw('codec'), 'direction' => $raw('direction')];
                    break;
                default:
                    $sets = $r->body['sets'] ?? [];
                    $res = CryptoTools::random($raw('kind'), (int) $raw('length'), is_array($sets) ? array_values(array_filter($sets, 'is_string')) : []);
                    $keep = ['kind' => $raw('kind'), 'length' => $raw('length'), 'sets' => is_array($sets) ? $sets : []];
            }
        } catch (ValidationException $e) {
            return $this->render($tool, $keep + $this->keepOnError($tool, $raw), null, $this->errors($e), 422);
        }
        return $this->render($tool, $keep, $res, [], 200);
    }

    /**
     * Only non-secret fields are put back into the form after an error; passwords and keys never are.
     *
     * @param callable(string): string $raw
     * @return array<string, string>
     */
    private function keepOnError(string $tool, callable $raw): array
    {
        return match ($tool) {
            'verify' => ['hash' => $raw('hash')],
            'digest', 'encode' => ['text' => $raw('text'), 'algo' => $raw('algo'), 'codec' => $raw('codec'), 'direction' => $raw('direction')],
            'hmac' => ['text' => $raw('text'), 'algo' => $raw('algo')],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $old
     * @param array{value: string, facts: array<string, string|int>, warnings: list<string>}|null $result
     * @param array<string, string> $errors
     */
    private function render(string $tool, array $old, ?array $result, array $errors, int $status): Response
    {
        return $this->page('@crypto/index', [
            'title' => $this->app->translator()->get('crypto.title'), 'tool' => $tool, 'old' => $old, 'result' => $result, 'errors' => $errors,
            'argon2' => CryptoTools::argon2Available(),
        ], $status)
            ->withHeader('Cache-Control', 'no-store, max-age=0')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    private function guard(Request $r): void
    {
        if (!$this->ctx($r)->az()->can('crypto.use')) {
            throw HttpException::forbidden();
        }
    }
}
