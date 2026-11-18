<?php

declare(strict_types=1);

namespace Blue\Core\Http;

use Blue\Core\Error\HttpException;

/**
 * Minimal router: METHOD + path pattern with {name} / {name:regex} segments.
 * Routes are registered by core and by each module's routes file (module.json).
 */
final class Router
{
    /** {name} or {name:regex}; the regex may itself contain {n,m} quantifiers. */
    private const PARAM = '#\{([a-z_][a-z0-9_]*)(?::((?:[^{}]|\{[^{}]*\})+))?\}#i';

    /** @var list<array{method:string, regex:string, names:list<string>, handler:callable, name:?string, pattern:string}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler, ?string $name = null): void
    {
        $this->add('GET', $pattern, $handler, $name);
    }

    public function post(string $pattern, callable $handler, ?string $name = null): void
    {
        $this->add('POST', $pattern, $handler, $name);
    }

    public function add(string $method, string $pattern, callable $handler, ?string $name = null): void
    {
        $names = [];
        $regex = preg_replace_callback(
            self::PARAM,
            static function (array $m) use (&$names): string {
                $names[] = $m[1];
                return '(' . ($m[2] ?? '[^/]+') . ')';
            },
            $pattern
        );
        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => '#^' . $regex . '$#u',
            'names' => $names,
            'handler' => $handler,
            'name' => $name,
            'pattern' => $pattern,
        ];
    }

    /**
     * @return callable(Request): Response
     * @throws HttpException 404 / 405
     */
    public function match(Request $request): callable
    {
        $pathMatched = false;
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;
        foreach ($this->routes as $r) {
            if (!preg_match($r['regex'], $request->path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($r['method'] !== $method) {
                continue;
            }
            array_shift($m);
            $request->params = $r['names'] === [] ? [] : array_combine($r['names'], $m);
            return $r['handler'];
        }
        throw $pathMatched ? HttpException::methodNotAllowed() : HttpException::notFound();
    }

    /** @param array<string, string> $params */
    public function url(string $name, array $params = []): string
    {
        foreach ($this->routes as $r) {
            if ($r['name'] === $name) {
                return (string) preg_replace_callback(
                    self::PARAM,
                    static fn (array $m): string => rawurlencode($params[$m[1]] ?? ''),
                    $r['pattern']
                );
            }
        }
        throw new \InvalidArgumentException("Unknown route name {$name}");
    }

    /** @return list<array{method:string, pattern:string, name:?string}> */
    public function list(): array
    {
        return array_map(static fn (array $r): array => ['method' => $r['method'], 'pattern' => $r['pattern'], 'name' => $r['name']], $this->routes);
    }
}
