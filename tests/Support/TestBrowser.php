<?php

declare(strict_types=1);

namespace Blue\Tests\Support;

use Blue\Core\Http\Kernel;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;

/** One simulated browser: keeps the session (via one Kernel) and the CSRF token. */
final class TestBrowser
{
    public string $csrf = '';

    public function __construct(public readonly Kernel $kernel, private readonly TestApp $t)
    {
    }

    /** @param array<string, mixed> $query */
    public function get(string $path, array $query = []): Response
    {
        $res = $this->kernel->handle(new Request('GET', $path, $query, [], ['user-agent' => 'phpunit'], [], '10.0.0.1'));
        if (preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $res->body, $m)) {
            $this->csrf = $m[1];
        }
        return $res;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, array{0: string, 1: string}|list<array{0: string, 1: string}>> $files field => [file name, bytes] or a list of them
     */
    public function post(string $path, array $body = [], array $files = []): Response
    {
        if ($this->csrf === '') {
            $this->get('/login');
        }
        $req = new Request('POST', $path, [], $body + ['_csrf' => $this->csrf], ['user-agent' => 'phpunit'], [], '10.0.0.1');
        foreach ($files as $field => $f) {
            $list = is_array($f[0] ?? null) ? $f : [$f]; // one [name, bytes] pair, or a list of them (multiple upload)
            foreach ($list as [$name, $bytes]) {
                $req->files[$field][] = \Blue\Core\Files\UploadedFile::fromBytes($name, $bytes, $this->t->tmp);
            }
        }
        return $this->kernel->handle($req);
    }

    /** Signs in; completes MFA (enrolling first if needed) using the frozen clock. */
    public function login(string $username, ?string $password = null): Response
    {
        $res = $this->post('/login', ['identifier' => $username, 'password' => $password ?? ($username === TestApp::ADMIN ? TestApp::ADMIN_PASSWORD : $this->t->demoPassword), 'next' => '/']);
        $loc = $res->headers['Location'] ?? '';
        $after = $loc !== '' ? $this->get((string) parse_url($loc, PHP_URL_PATH)) : $res;
        $next = $after->headers['Location'] ?? '';
        if (str_ends_with($next, '/mfa/verify')) {
            $user = $this->t->user($username);
            $enc = (string) $this->t->app->db()->scalar('SELECT secret_enc FROM user_mfa_factors WHERE user_id = ? AND confirmed_at IS NOT NULL', [$user['id']]);
            $secret = $this->t->app->crypto()->decrypt($enc);
            $this->get('/mfa/verify');
            $this->t->clock->advance('+30 seconds');
            return $this->post('/mfa/verify', ['code' => \Blue\Core\Auth\Totp::code($secret, \Blue\Core\Auth\Totp::stepAt($this->t->clock->now()->getTimestamp()))]);
        }
        if (str_ends_with($next, '/mfa/setup')) {
            $page = $this->get('/mfa/setup');
            preg_match('/class="code-box"[^>]*>([^<]+)</', $page->body, $m);
            $secret = str_replace(' ', '', $m[1] ?? '');
            $this->t->clock->advance('+30 seconds');
            return $this->post('/mfa/setup', ['code' => \Blue\Core\Auth\Totp::code($secret, \Blue\Core\Auth\Totp::stepAt($this->t->clock->now()->getTimestamp()))]);
        }
        return $res;
    }
}
