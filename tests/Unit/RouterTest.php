<?php

declare(strict_types=1);

namespace Blue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Blue\Core\Error\HttpException;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Http\Router;

final class RouterTest extends TestCase
{
    private Router $r;

    protected function setUp(): void
    {
        $this->r = new Router();
        $this->r->get('/leave/{id:[0-9A-Z]{26}}', static fn (Request $q): Response => Response::html($q->params['id']), 'leave.show');
        $this->r->post('/leave', static fn (): Response => Response::html('created'), 'leave.store');
    }

    public function testMatchesAndExtractsParams(): void
    {
        $req = new Request('GET', '/leave/01M4PQDJ7XWG9M9ZHH7D6DKCC9');
        $res = ($this->r->match($req))($req);
        self::assertSame('01M4PQDJ7XWG9M9ZHH7D6DKCC9', $res->body);
    }

    public function testUnknownPathIs404(): void
    {
        try {
            $this->r->match(new Request('GET', '/leave/123'));
            self::fail('expected 404');
        } catch (HttpException $e) {
            self::assertSame(404, $e->httpStatus);
        }
    }

    public function testWrongMethodIs405(): void
    {
        try {
            $this->r->match(new Request('DELETE', '/leave'));
            self::fail('expected 405');
        } catch (HttpException $e) {
            self::assertSame(405, $e->httpStatus);
        }
    }

    public function testHeadIsServedByGet(): void
    {
        $req = new Request('HEAD', '/leave/01M4PQDJ7XWG9M9ZHH7D6DKCC9');
        self::assertIsCallable($this->r->match($req));
    }

    public function testUrlGeneration(): void
    {
        self::assertSame('/leave/ABC', $this->r->url('leave.show', ['id' => 'ABC']));
    }
}
