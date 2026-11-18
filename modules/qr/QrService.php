<?php

declare(strict_types=1);

namespace Blue\Modules\Qr;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Qr\QrEncoder;
use Blue\Core\Support\Qr\QrRender;
use Blue\Core\Support\Ulid;
use Blue\Core\Ui\ThemeBuilder;

/**
 * M27 QR codes. Two kinds:
 *  - static: the text/URL itself is in the symbol (cannot be changed or revoked once printed);
 *  - managed: the symbol holds a short link of this system (/q/<token>) that redirects to the destination and can
 *    expire, be revoked or point elsewhere later (7B items 3–4: Generated → Active/Revoked).
 * Nothing is saved unless the person presses Save; previews and batch files are generated on the fly.
 */
final class QrService
{
    public const GENERATOR = 'blue-qr/1';
    public const SIZES = ['s' => 6, 'm' => 10, 'l' => 16];
    public const MAX_MANAGED_DAYS = 1095;
    public const BATCH_MAX = 500;
    /** Minimum contrast of dark modules / eyes against the background (0.38.0). */
    public const MIN_CONTRAST = 4.0;
    /** Default logo size for new codes: ×2 the size before 0.38.0 (user request — scan-tested with ZXing). */
    public const DEFAULT_LOGO_SIZE = 'xl';

    public function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    /**
     * Validates the form and builds the symbol. For a managed code the token is created here (or reused).
     * 0.38.0: content types (link, text, phone, e-mail, Wi-Fi, contact card — a managed code is always a link) and
     * colours/decoration (style; default colour = the system's primary colour).
     *
     * @param array<string, string> $in kind, content_type, content (+ type fields), name, category, open_external, level, logo, expires, fg, bg, eye, module, eye_style, logo_size
     * @return array{kind: string, type: string, fields: array<string, string>, destination: string, encoded: string, level: string, logo: bool, warnings: list<string>, style: array{fg: string, bg: string, eye: string, module: string, eye_style: string, logo_size: string}, qr: array{modules: list<list<bool>>, version: int, level: string, mask: int, mode: string, size: int}, token: ?string, expires: ?string}
     */
    public function prepare(array $in, string $origin, ?string $token = null): array
    {
        $kind = ($in['kind'] ?? '') === 'managed' ? 'managed' : 'static';
        $type = $kind === 'managed' ? 'url' : (in_array($in['content_type'] ?? '', QrPolicy::TYPES, true) ? (string) $in['content_type'] : 'url');
        $isUrl = $type === 'url';
        $built = QrPolicy::compose($type, $in);
        $errors = $built['errors'];
        $content = $built['content'];
        $warnings = [];
        if ($errors === []) {
            $check = QrPolicy::check($content, $isUrl);
            if ($check['errors'] !== []) {
                $errors[self::firstField($type)] = $check['errors'][0];
            }
            // a phone number / e-mail in a phone, e-mail or contact-card code is the point, not an accident
            $warnings = in_array($type, ['tel', 'email', 'vcard'], true) ? array_values(array_diff($check['warnings'], ['qr.warn.personal'])) : $check['warnings'];
            if ($type === 'wifi') {
                $warnings[] = 'qr.warn.wifi';
            }
        }
        $fields = [];
        foreach (QrPolicy::TYPE_FIELDS[$type] ?? [] as $k) {
            $fields[$k] = mb_substr(trim((string) ($in[$k] ?? '')), 0, 500);
        }
        $destination = $content;
        if ($isUrl && $errors === [] && ($in['open_external'] ?? '') === '1') {
            $destination = QrPolicy::withExternalBrowser($content);
        }
        $style = $this->styleFrom($in, $errors);
        $logo = ($in['logo'] ?? '') === '1';
        $level = in_array($in['level'] ?? '', QrEncoder::LEVELS, true) ? (string) $in['level'] : 'M';
        if ($logo) {
            $level = 'H'; // a centre logo hides modules — only H recovers them reliably
        }
        $expires = null;
        if ($kind === 'managed') {
            $exp = (string) ($in['expires'] ?? '');
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $exp, $this->tz());
            $today = $this->app->clock->now()->setTimezone($this->tz())->setTime(0, 0);
            if ($d === false || $d <= $today || $d > $today->modify('+' . self::MAX_MANAGED_DAYS . ' days')) {
                $errors['expires'] = 'qr.error.expires';
            } else {
                $expires = $d->setTime(23, 59, 59)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $token ??= $kind === 'managed' ? self::token() : null;
        $encoded = $kind === 'managed' ? rtrim($origin, '/') . '/q/' . $token : $destination;
        try {
            $qr = QrEncoder::encode($encoded, $level);
        } catch (\InvalidArgumentException) {
            throw new ValidationException([self::firstField($type) => 'qr.error.too_long']);
        }
        return ['kind' => $kind, 'type' => $type, 'fields' => $fields, 'destination' => $destination, 'encoded' => $encoded, 'level' => $level, 'logo' => $logo,
            'warnings' => array_values(array_unique($warnings)), 'style' => $style, 'qr' => $qr, 'token' => $token, 'expires' => $expires];
    }

    /**
     * Colours and decoration from the form. Dark modules must be darker than the background with a contrast of at
     * least 4:1 (light-on-dark or pale codes fail on many phone cameras); the eyes likewise.
     *
     * @param array<string, string> $in
     * @param array<string, string> $errors filled with fg / bg / eye errors
     * @return array{fg: string, bg: string, eye: string, module: string, eye_style: string, logo_size: string}
     */
    public function styleFrom(array $in, array &$errors): array
    {
        $hex = static fn (string $v): ?string => preg_match('/^#[0-9A-Fa-f]{6}$/', trim($v)) === 1 ? strtoupper(trim($v)) : null;
        $fg = ($in['fg'] ?? '') === '' ? $this->app->appearance()->primaryHex() : $hex((string) $in['fg']);
        $bg = ($in['bg'] ?? '') === '' ? '#FFFFFF' : $hex((string) $in['bg']);
        $eye = ($in['eye'] ?? '') === '' ? $fg : $hex((string) $in['eye']);
        foreach (['fg' => $fg, 'bg' => $bg, 'eye' => $eye] as $k => $c) {
            if ($c === null) {
                $errors[$k] = 'qr.error.color';
            }
        }
        if ($fg !== null && $bg !== null && ThemeBuilder::contrast($fg, $bg) < self::MIN_CONTRAST || ($fg !== null && $bg !== null && self::lum($fg) >= self::lum($bg))) {
            $errors['fg'] = 'qr.error.contrast';
        }
        if ($eye !== null && $bg !== null && $eye !== $fg && (ThemeBuilder::contrast($eye, $bg) < self::MIN_CONTRAST || self::lum($eye) >= self::lum($bg))) {
            $errors['eye'] = 'qr.error.contrast';
        }
        return [
            'fg' => $fg ?? '#000000', 'bg' => $bg ?? '#FFFFFF', 'eye' => $eye ?? ($fg ?? '#000000'),
            'module' => in_array($in['module'] ?? '', QrRender::MODULES, true) ? (string) $in['module'] : 'square',
            'eye_style' => in_array($in['eye_style'] ?? '', QrRender::EYES, true) ? (string) $in['eye_style'] : 'square',
            'logo_size' => isset(QrRender::LOGO_SIZES[$in['logo_size'] ?? '']) ? (string) $in['logo_size'] : self::DEFAULT_LOGO_SIZE,
        ];
    }

    /**
     * Style of a saved code (codes saved before 0.38.0 keep their black-on-white look and the small logo).
     *
     * @param array<string, mixed> $row
     * @return array{fg: string, bg: string, eye: string, module: string, eye_style: string, logo_size: string}
     */
    public static function savedStyle(array $row): array
    {
        $s = is_string($row['style_json'] ?? null) ? (array) json_decode((string) $row['style_json'], true) : [];
        $r = QrRender::style($s);
        return ['fg' => $r['fg'], 'bg' => $r['bg'], 'eye' => $r['eye'], 'module' => $r['module'], 'eye_style' => $r['eye_style'],
            'logo_size' => isset(QrRender::LOGO_SIZES[$s['logo_size'] ?? '']) ? (string) $s['logo_size'] : 's'];
    }

    /** The form field an error about the whole content of a type belongs to. */
    private static function firstField(string $type): string
    {
        return isset(QrPolicy::TYPE_FIELDS[$type]) ? QrPolicy::TYPE_FIELDS[$type][0] : 'content';
    }

    private static function lum(string $hex): float
    {
        return ThemeBuilder::contrast($hex, '#000000');
    }

    /**
     * The logo for the centre of a code: the organization's uploaded raster logo (PNG/JPEG/WebP), else the blue star
     * of the system logo (ui/icons/star-blue.png, 0.38.0 — the white Blue wordmark would vanish on the white plate).
     *
     * @return array{bytes: string, mime: string, builtin: bool}
     */
    public function logo(): array
    {
        $pid = $this->app->appearance()->current()['logo'] ?? null;
        if ($pid !== null) {
            $a = $this->app->documents()->publicAsset((string) $pid);
            if ($a !== null && in_array($a['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
                return ['bytes' => $a['bytes'], 'mime' => $a['mime'], 'builtin' => false];
            }
        }
        return ['bytes' => (string) file_get_contents($this->app->root . '/ui/icons/star-blue.png'), 'mime' => 'image/png', 'builtin' => true];
    }

    /**
     * @param array{modules: list<list<bool>>} $qr
     * @param array{fg: string, bg: string, eye: string, module: string, eye_style: string, logo_size: string}|null $style
     */
    public function svg(array $qr, bool $withLogo, string $title = '', ?array $style = null): string
    {
        $logo = $withLogo ? $this->logo() : null;
        // an unnamed code still needs an accessible name (role="img")
        $title = $title !== '' ? $title : $this->app->translator()->get('qr.code');
        return QrRender::svg($qr['modules'], 8, $logo !== null ? 'data:' . $logo['mime'] . ';base64,' . base64_encode($logo['bytes']) : null, $title, self::render($style));
    }

    /**
     * A PNG never loses the logo silently (0.38.0 fix): if this server cannot draw it (no GD, or a WebP logo without
     * WebP support) the person is told to use SVG or upload a PNG logo.
     *
     * @param array{modules: list<list<bool>>} $qr
     * @param array{fg: string, bg: string, eye: string, module: string, eye_style: string, logo_size: string}|null $style
     */
    public function png(array $qr, bool $withLogo, string $size = 'm', ?array $style = null): string
    {
        $logo = $withLogo ? $this->logo() : null;
        if ($logo !== null && !QrRender::logoReadable($logo['bytes'])) {
            throw new ValidationException(['logo' => 'qr.error.logo_png']);
        }
        return QrRender::png($qr['modules'], self::SIZES[$size] ?? 10, $logo['bytes'] ?? null, self::render($style));
    }

    /**
     * @param array{fg: string, bg: string, eye: string, module: string, eye_style: string, logo_size: string}|null $style
     * @return array<string, mixed>
     */
    private static function render(?array $style): array
    {
        if ($style === null) {
            return [];
        }
        return ['fg' => $style['fg'], 'bg' => $style['bg'], 'eye' => $style['eye'], 'module' => $style['module'], 'eye_style' => $style['eye_style'],
            'logo' => QrRender::LOGO_SIZES[$style['logo_size']] ?? QrRender::LOGO_MAX];
    }

    // ---- saved codes ------------------------------------------------------------------------------

    /** @param array<string, string> $in */
    public function save(Authorizer $az, array $in, ?int $legalEntityId, string $origin): string
    {
        $this->guardUse($az);
        $p = $this->prepare($in, $origin);
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw new ValidationException(['name' => 'qr.error.name']);
        }
        $pid = Ulid::generate($this->app->clock);
        $now = $this->now();
        $db = $this->app->db();
        $db->transactional(function () use ($db, $pid, $now, $az, $legalEntityId, $name, $in, $p): void {
            $db->run(
                'INSERT INTO qr_codes (public_id, created_at, created_by, updated_at, updated_by, owner_user_id, legal_entity_id, name, category, kind, content_type, content, fields_json, open_external,
                    ec_level, with_logo, style_json, token, status, expires_at, encoded_sha256, qr_version, generator)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $now, $az->userId, $now, $az->userId, $az->userId, $legalEntityId, $name, mb_substr(trim((string) ($in['category'] ?? '')), 0, 60) ?: null,
                    $p['kind'], $p['type'], $p['destination'], $p['fields'] !== [] ? json_encode($p['fields'], JSON_UNESCAPED_UNICODE) : null, ($in['open_external'] ?? '') === '1' ? 1 : 0,
                    $p['level'], $p['logo'] ? 1 : 0, json_encode($p['style']), $p['token'],
                    $p['kind'] === 'managed' ? 'active' : 'generated', $p['expires'], hash('sha256', $p['encoded']), $p['qr']['version'], self::GENERATOR]
            );
            $this->event((int) $db->pdo()->lastInsertId(), 'created', ['kind' => $p['kind'], 'type' => $p['type'], 'level' => $p['level']], $az->userId);
        });
        return $pid;
    }

    /** @return list<array<string, mixed>> */
    public function mine(Authorizer $az, string $q = '', bool $all = false): array
    {
        $this->guardUse($az);
        $where = 'owner_user_id = ? AND deleted_at IS NULL';
        $params = [$az->userId];
        if ($all && $az->can('qr.manage')) {
            $where = "kind = 'managed' AND deleted_at IS NULL";
            $params = [];
        }
        if ($q !== '') {
            $where .= ' AND (name LIKE ? OR category LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like);
        }
        $rows = $this->app->db()->all("SELECT q.*, u.display_name AS owner_name FROM qr_codes q JOIN users u ON u.id = q.owner_user_id WHERE {$where} ORDER BY q.id DESC LIMIT 200", $params);
        foreach ($rows as &$r) {
            $r['state'] = $this->state($r);
        }
        return $rows;
    }

    /** @return array<string, mixed> */
    public function get(Authorizer $az, string $pid): array
    {
        $this->guardUse($az);
        $row = $this->app->db()->one('SELECT q.*, u.display_name AS owner_name FROM qr_codes q JOIN users u ON u.id = q.owner_user_id WHERE q.public_id = ?', [$pid]);
        if ($row === null || $row['deleted_at'] !== null || ((int) $row['owner_user_id'] !== $az->userId && !$az->can('qr.manage'))) {
            throw HttpException::notFound(); // someone else's (or a deleted) code is not even confirmed to exist
        }
        $row['state'] = $this->state($row);
        $row['events'] = $this->app->db()->all('SELECT e.*, u.display_name FROM qr_events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.qr_id = ? ORDER BY e.id DESC LIMIT 50', [$row['id']]);
        return $row;
    }

    /**
     * Rebuilds the symbol of a saved code (same text → same symbol).
     *
     * @param array<string, mixed> $row
     * @return array{modules: list<list<bool>>, version: int, level: string, mask: int, mode: string, size: int}
     */
    public function symbol(array $row, string $origin): array
    {
        $text = $row['kind'] === 'managed' ? rtrim($origin, '/') . '/q/' . $row['token'] : (string) $row['content'];
        return QrEncoder::encode($text, (string) $row['ec_level']);
    }

    public function changeDestination(Authorizer $az, string $pid, string $url, bool $external, string $reason): void
    {
        $row = $this->owned($az, $pid);
        if ($row['kind'] !== 'managed' || $this->state($row) !== 'active') {
            throw new ValidationException(['url' => 'qr.error.not_active']);
        }
        $check = QrPolicy::check($url, true);
        if ($check['errors'] !== []) {
            throw new ValidationException(['url' => $check['errors'][0]]);
        }
        if (mb_strlen(trim($reason)) < 3) {
            throw new ValidationException(['reason' => 'qr.error.reason']);
        }
        $dest = $external ? QrPolicy::withExternalBrowser(trim($url)) : trim($url);
        $this->app->db()->run('UPDATE qr_codes SET content = ?, open_external = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [$dest, $external ? 1 : 0, $this->now(), $az->userId, $row['id']]);
        $this->event((int) $row['id'], 'destination', ['from' => $row['content'], 'to' => $dest, 'reason' => mb_substr($reason, 0, 300)], $az->userId);
    }

    public function revoke(Authorizer $az, string $pid, string $reason): void
    {
        $row = $this->owned($az, $pid);
        if ($row['kind'] !== 'managed' || $row['status'] !== 'active') {
            throw new ValidationException(['reason' => 'qr.error.not_active']);
        }
        if (mb_strlen(trim($reason)) < 3) {
            throw new ValidationException(['reason' => 'qr.error.reason']);
        }
        $this->app->db()->run(
            "UPDATE qr_codes SET status = 'revoked', revoked_at = ?, revoked_by = ?, revoke_reason = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?",
            [$this->now(), $az->userId, mb_substr(trim($reason), 0, 300), $this->now(), $az->userId, $row['id']]
        );
        $this->event((int) $row['id'], 'revoked', ['reason' => mb_substr($reason, 0, 300)], $az->userId);
        $this->app->audit()->record('qr.revoked', 'success', 'qr_code', $pid, null, mb_substr($reason, 0, 200));
    }

    public function extend(Authorizer $az, string $pid, string $date): void
    {
        $row = $this->owned($az, $pid);
        if ($row['kind'] !== 'managed' || $row['status'] !== 'active') {
            throw new ValidationException(['expires' => 'qr.error.not_active']);
        }
        $p = $this->prepare(['kind' => 'managed', 'content' => (string) $row['content'], 'expires' => $date], 'https://x.invalid', (string) $row['token']);
        $this->app->db()->run('UPDATE qr_codes SET expires_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [$p['expires'], $this->now(), $az->userId, $row['id']]);
        $this->event((int) $row['id'], 'expiry', ['from' => $row['expires_at'], 'to' => $p['expires']], $az->userId);
    }

    /**
     * 0.38.0: edit a saved code — name, category, colours/decoration and the logo for every code; the content (and its
     * type) of a static code too (a printed copy keeps the old content — the form says so). A managed code's link is
     * changed with changeDestination (with a reason) and keeps its short link, so printed copies follow.
     *
     * @param array<string, string> $in
     */
    public function update(Authorizer $az, string $pid, array $in, string $origin): void
    {
        $row = $this->owned($az, $pid);
        $name = trim((string) ($in['name'] ?? ''));
        $errors = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['name'] = 'qr.error.name';
        }
        $logo = ($in['logo'] ?? '') === '1';
        if ($row['kind'] === 'managed') {
            $style = $this->styleFrom($in, $errors);
            if ($errors !== []) {
                throw new ValidationException($errors);
            }
            $level = $logo ? 'H' : ((string) $row['ec_level'] === 'H' && (int) $row['with_logo'] === 1 ? 'M' : (string) $row['ec_level']);
            $set = ['content' => (string) $row['content'], 'content_type' => 'url', 'fields_json' => null, 'ec_level' => $level,
                'encoded_sha256' => (string) $row['encoded_sha256'], 'qr_version' => QrEncoder::encode(rtrim($origin, '/') . '/q/' . $row['token'], $level)['version'], 'open_external' => (int) $row['open_external']];
        } else {
            try {
                $p = $this->prepare(['kind' => 'static'] + $in, $origin);
            } catch (ValidationException $e) {
                throw new ValidationException($errors + $e->errors);
            }
            if ($errors !== []) {
                throw new ValidationException($errors);
            }
            $style = $p['style'];
            $set = ['content' => $p['destination'], 'content_type' => $p['type'], 'fields_json' => $p['fields'] !== [] ? json_encode($p['fields'], JSON_UNESCAPED_UNICODE) : null,
                'ec_level' => $p['level'], 'encoded_sha256' => hash('sha256', $p['encoded']), 'qr_version' => $p['qr']['version'], 'open_external' => ($in['open_external'] ?? '') === '1' ? 1 : 0];
        }
        $category = mb_substr(trim((string) ($in['category'] ?? '')), 0, 60) ?: null;
        $changed = [];
        foreach (['name' => $name, 'category' => $category, 'with_logo' => $logo ? 1 : 0, 'style_json' => json_encode($style)] + $set as $k => $v) {
            if ((string) ($row[$k] ?? '') !== (string) ($v ?? '')) {
                $changed[] = $k;
            }
        }
        if ($changed === []) {
            return;
        }
        $this->app->db()->run(
            'UPDATE qr_codes SET name = ?, category = ?, with_logo = ?, style_json = ?, content = ?, content_type = ?, fields_json = ?, ec_level = ?, encoded_sha256 = ?, qr_version = ?, open_external = ?,
                updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
            [$name, $category, $logo ? 1 : 0, json_encode($style), $set['content'], $set['content_type'], $set['fields_json'], $set['ec_level'], $set['encoded_sha256'], $set['qr_version'], $set['open_external'],
                $this->now(), $az->userId, $row['id']]
        );
        // the history names what changed, not the values (a Wi-Fi password or a phone number stays out of it)
        $this->event((int) $row['id'], 'edited', ['changed' => array_values(array_diff($changed, ['encoded_sha256', 'qr_version', 'fields_json']))], $az->userId);
    }

    /**
     * 0.38.0: delete a saved code (soft — the row and its history stay). It leaves the lists; a managed code stops
     * redirecting at once (scanners see "deleted").
     */
    public function delete(Authorizer $az, string $pid): void
    {
        $row = $this->owned($az, $pid);
        $this->app->db()->run(
            'UPDATE qr_codes SET deleted_at = ?, deleted_by = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
            [$this->now(), $az->userId, $this->now(), $az->userId, $row['id']]
        );
        $this->event((int) $row['id'], 'deleted', [], $az->userId);
        $this->app->audit()->record('qr.deleted', 'success', 'qr_code', $pid, null, null, ['kind' => $row['kind']]);
    }

    /**
     * Form values of a saved code (for the edit form).
     *
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    public static function formValues(array $row): array
    {
        $type = (string) ($row['content_type'] ?? 'url');
        $fields = is_string($row['fields_json'] ?? null) ? (array) json_decode((string) $row['fields_json'], true) : [];
        $style = self::savedStyle($row);
        $content = (string) $row['content'];
        if ((int) $row['open_external'] === 1) {
            $content = (string) preg_replace('/([?&])' . QrPolicy::EXTERNAL_PARAM . '=1(&|$)/', '$1', $content);
            $content = rtrim($content, '?&');
        }
        $in = ['kind' => (string) $row['kind'], 'content_type' => $type, 'content' => in_array($type, ['url', 'text'], true) ? $content : '', 'name' => (string) $row['name'],
            'category' => (string) ($row['category'] ?? ''), 'open_external' => (int) $row['open_external'] === 1 ? '1' : '', 'level' => (string) $row['ec_level'],
            'logo' => (int) $row['with_logo'] === 1 ? '1' : '', 'expires' => '', 'fg' => $style['fg'], 'bg' => $style['bg'], 'eye' => $style['eye'] === $style['fg'] ? '' : $style['eye'],
            'module' => $style['module'], 'eye_style' => $style['eye_style'], 'logo_size' => $style['logo_size']];
        foreach ($fields as $k => $v) {
            if (is_string($k) && is_scalar($v)) {
                $in[$k] = (string) $v;
            }
        }
        return $in;
    }

    /**
     * Public redirect of a managed code. Counts the scan (number + time only).
     *
     * @return array{state: string, url: ?string}
     */
    public function resolve(string $token): array
    {
        if (!preg_match('/^[0-9A-Za-z]{10}$/', $token)) {
            return ['state' => 'unknown', 'url' => null];
        }
        $row = $this->app->db()->one("SELECT * FROM qr_codes WHERE token = ? AND kind = 'managed'", [$token]);
        if ($row === null) {
            return ['state' => 'unknown', 'url' => null];
        }
        $state = $row['deleted_at'] !== null ? 'deleted' : $this->state($row);
        if ($state !== 'active') {
            return ['state' => $state, 'url' => null];
        }
        $this->app->db()->run('UPDATE qr_codes SET scan_count = scan_count + 1, last_scan_at = ? WHERE id = ?', [$this->now(), $row['id']]);
        return ['state' => 'active', 'url' => (string) $row['content']];
    }

    /**
     * Batch: rows of [name, content(, category)] → ZIP with one image per row + a report (nothing saved).
     *
     * @param list<list<string>> $rows rows after the header
     * @param array<string, string> $opt level, logo, format (png|svg|both), open_external, size
     * @return array{zip: string, ok: int, failed: int}
     */
    public function batch(Authorizer $az, array $rows, array $opt): array
    {
        $this->guardUse($az);
        if ($rows === []) {
            throw new ValidationException(['file' => 'qr.error.batch_empty']);
        }
        if (count($rows) > self::BATCH_MAX) {
            throw new ValidationException(['file' => 'qr.error.batch_too_many']);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'blueqr');
        if ($tmp === false) {
            throw new \RuntimeException('temp file');
        }
        $zip = \Blue\Core\Files\ZipStream::create($tmp);
        $report = [];
        $ok = 0;
        $used = [];
        $t = $this->app->translator();
        foreach ($rows as $i => $row) {
            $name = trim((string) ($row[0] ?? ''));
            $content = trim((string) ($row[1] ?? ''));
            $line = $i + 2;
            try {
                $p = $this->prepare(['kind' => 'static', 'content_type' => preg_match('#^https?://#i', $content) ? 'url' : 'text', 'content' => $content,
                    'open_external' => $opt['open_external'] ?? '', 'level' => $opt['level'] ?? 'M', 'logo' => $opt['logo'] ?? ''], '');
                $base = sprintf('%03d_', $line) . self::fileName($name !== '' ? $name : 'qr');
                while (isset($used[$base])) {
                    $base .= '_';
                }
                $used[$base] = true;
                if (($opt['format'] ?? 'png') !== 'svg') {
                    $zip->addString($base . '.png', $this->png($p['qr'], $p['logo'], (string) ($opt['size'] ?? 'm'), $p['style']));
                }
                if (($opt['format'] ?? 'png') !== 'png') {
                    $zip->addString($base . '.svg', $this->svg($p['qr'], $p['logo'], $name, $p['style']));
                }
                $report[] = [$line, $name, 'OK', implode(' · ', array_map(static fn (string $w): string => $t->get($w), $p['warnings'])), $p['destination']];
                $ok++;
            } catch (ValidationException $e) {
                $report[] = [$line, $name, $t->get('qr.batch.failed'), $t->get((string) (array_values($e->errors)[0] ?? 'form.has_errors')), ''];
            }
        }
        $zip->addString('report.csv', \Blue\Core\Files\Csv::write([$t->get('qr.batch.col_row'), $t->get('qr.field.name'), $t->get('qr.batch.col_result'), $t->get('qr.batch.col_note'), $t->get('qr.batch.col_encoded')], $report));
        $zip->finish();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return ['zip' => $bytes, 'ok' => $ok, 'failed' => count($rows) - $ok];
    }

    public static function fileName(string $name): string
    {
        $s = (string) preg_replace('/[^\p{L}\p{M}\p{N}._-]+/u', '_', $name);
        return mb_substr(trim($s, '._'), 0, 60) ?: 'qr';
    }

    /** @param array<string, mixed> $row */
    public function state(array $row): string
    {
        if ($row['kind'] === 'static') {
            return 'generated';
        }
        if ($row['status'] === 'revoked') {
            return 'revoked';
        }
        return $row['expires_at'] !== null && (string) $row['expires_at'] < $this->now() ? 'expired' : 'active';
    }

    /** @return array<string, mixed> */
    private function owned(Authorizer $az, string $pid): array
    {
        $row = $this->app->db()->one('SELECT * FROM qr_codes WHERE public_id = ?', [$pid]);
        if ($row === null || $row['deleted_at'] !== null || ((int) $row['owner_user_id'] !== $az->userId && !$az->can('qr.manage'))) {
            throw HttpException::notFound();
        }
        return $row;
    }

    /** @param array<string, mixed> $detail */
    private function event(int $qrId, string $action, array $detail, ?int $actor): void
    {
        $this->app->db()->run(
            'INSERT INTO qr_events (qr_id, action, detail_json, actor_id, occurred_at) VALUES (?, ?, ?, ?, ?)',
            [$qrId, $action, json_encode($detail, JSON_UNESCAPED_UNICODE), $actor, $this->now()]
        );
    }

    private function guardUse(Authorizer $az): void
    {
        if (!$az->can('qr.use')) {
            throw HttpException::forbidden();
        }
    }

    /** 10 characters from 62 → about 59 bits; not guessable, and carries no meaning. */
    private static function token(): string
    {
        $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $out = '';
        for ($i = 0; $i < 10; $i++) {
            $out .= $alphabet[random_int(0, 61)];
        }
        return $out;
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    private function tz(): \DateTimeZone
    {
        return new \DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'));
    }
}
