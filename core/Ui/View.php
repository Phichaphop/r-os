<?php

declare(strict_types=1);

namespace Blue\Core\Ui;

use Blue\Core\I18n\Translator;
use RuntimeException;
use Throwable;

/**
 * Server-side PHP templates (ADR-006). Views contain no SQL and no business rules (P0-02 §4 rule 2).
 *
 * Inside a template:
 *   $e($value)              HTML-escape
 *   $t('key', [...])        translated text
 *   $ui('button', [...])    shared component from ui/components (never copied into modules — 02 §4)
 *   $asset('ui/app.css')    URL of a published asset (cache-busted)
 *   $url('/path')           URL with the install base path
 *   $date($utc, $withTime)  UTC timestamp → display timezone, Buddhist-era year in Thai (UX-04)
 *   $v                      shared view globals (nonce, csrf, theme, …)
 */
final class View
{
    /** @var array<string, mixed> */
    private array $globals = [];

    /** @var array<string, string> asset path => version hash */
    private array $assetVersions = [];

    public function __construct(
        private readonly string $viewsDir,
        private readonly string $componentsDir,
        private readonly Translator $t,
        private readonly string $publicDir,
    ) {
    }

    public function share(string $key, mixed $value): void
    {
        $this->globals[$key] = $value;
    }

    public function global(string $key, mixed $default = null): mixed
    {
        return $this->globals[$key] ?? $default;
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = $this->renderFile($this->templateFile($template), $data);
        if ($layout === null) {
            return $content;
        }
        return $this->renderFile($this->viewsDir . '/' . $layout . '.php', $data + ['content' => $content]);
    }

    /** "admin/users" → ui/views/admin/users.php · "@links/index" → modules/links/Views/index.php (module views) */
    public function templateFile(string $template): string
    {
        if (preg_match('#^@([a-z][a-z0-9_]*)/([a-z0-9_/-]+)$#', $template, $m)) {
            return dirname($this->viewsDir, 2) . '/modules/' . $m[1] . '/Views/' . $m[2] . '.php';
        }
        return $this->viewsDir . '/' . $template . '.php';
    }

    /** @param array<string, mixed> $props */
    public function component(string $name, array $props = []): string
    {
        if (!preg_match('/^[a-z][a-z0-9-]*$/', $name)) {
            throw new RuntimeException("Invalid component name {$name}");
        }
        return $this->renderFile($this->componentsDir . "/{$name}/{$name}.php", ['p' => $props]);
    }

    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function asset(string $path): string
    {
        $path = ltrim($path, '/');
        if (!isset($this->assetVersions[$path])) {
            $file = $this->publicDir . '/assets/' . $path;
            $this->assetVersions[$path] = is_file($file) ? substr(md5((string) filemtime($file) . filesize($file)), 0, 8) : '0';
        }
        return $this->url('/assets/' . $path) . '?v=' . $this->assetVersions[$path];
    }

    public function url(string $path): string
    {
        return ((string) ($this->globals['base_path'] ?? '')) . '/' . ltrim($path, '/');
    }

    /** @param array<string, mixed> $data */
    private function renderFile(string $file, array $data): string
    {
        if (!is_file($file)) {
            throw new RuntimeException('View not found: ' . basename(dirname($file)) . '/' . basename($file));
        }
        $e = static fn (mixed $v): string => self::escape($v);
        $t = fn (string $k, array $p = []): string => $this->t->get($k, $p);
        $ui = fn (string $n, array $p = []): string => $this->component($n, $p);
        $asset = fn (string $p): string => $this->asset($p);
        $url = fn (string $p): string => $this->url($p);
        $partial = fn (string $tpl, array $d = []): string => $this->render($tpl, $d, null);
        $date = fn (?string $utc, bool $time = true): string => $utc === null || $utc === ''
            ? '—'
            : \Blue\Core\I18n\DateFormat::display($utc, $this->t->locale(), (string) ($this->globals['timezone'] ?? 'Asia/Bangkok'), $time);
        $v = $this->globals;

        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (Throwable $ex) {
            ob_end_clean();
            throw $ex;
        }
        return (string) ob_get_clean();
    }
}
