<?php

declare(strict_types=1);

namespace Blue\Modules\Vehicles;

use DateTimeImmutable;
use DateTimeZone;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\Xlsx;
use Blue\Core\Support\Money;
use Blue\Core\Support\Ulid;
use Blue\Modules\Employees\EmployeeService;

/**
 * M05 vehicle booking (9A-2 · DEC-90).
 *
 * A person asks for a vehicle: when, why, how many people (and who), from where to where. Each end is a pin on the map,
 * a pasted map link (its point is read when the link carries one) or a saved place; the distance is estimated as a
 * straight line here — or along roads by OpenRouteService when the system administrator added a key — and the source
 * and its limits are always shown. The request goes to the requester's line manager (the vehicle approver of the
 * company when there is none). The workplace admin then picks a vehicle and a driver (or lets the requester drive):
 * vehicles under repair, too few seats, licences that ran out and clashing trips are refused; a driver outside the
 * working hours needs a stated reason. The driver (or the admin) logs the trip — odometer, fuel, costs, anything odd —
 * once; the odometer is the trip's real distance. Statuses: draft → pending → approved → assigned → in_progress →
 * completed; rejected / cancelled / no_show.
 *
 * Privacy: a booking is seen by the requester, the approvers, the admin and the assigned driver only; audit and
 * notifications never carry places, coordinates or passenger names; the company can set how long locations are kept.
 */
final class VehicleService
{
    public const LIVE = ['pending', 'approved', 'assigned', 'in_progress'];

    private const ORS_URL = 'https://api.openrouteservice.org/v2/directions/driving-car';

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    // ---- time and scope ------------------------------------------------------------------------------------------------------

    public function tz(): DateTimeZone
    {
        return new DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'));
    }

    public function nowLocal(): DateTimeImmutable
    {
        $n = $this->app->clock->now()->setTimezone($this->tz());
        return DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $n->format('Y-m-d H:i:00')) ?: $n;
    }

    public function today(): string
    {
        return $this->nowLocal()->format('Y-m-d');
    }

    private function stamp(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    /** @return list<int> companies the person covers with a permission */
    public function companies(Authorizer $az, string $perm): array
    {
        $s = $az->scope($perm);
        return array_values(array_filter(array_map('intval', array_column($this->app->db()->all('SELECT id FROM legal_entities ORDER BY code'), 'id')), static fn (int $id): bool => $s->coversLegalEntity($id)));
    }

    private function require(Authorizer $az, string $perm, int $le): void
    {
        if (!$az->scope($perm)->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
    }

    public static function local(string $v): ?DateTimeImmutable
    {
        $x = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $v) ?: DateTimeImmutable::createFromFormat('!Y-m-d H:i', $v);
        return $x !== false && in_array($x->format('Y-m-d\TH:i'), [$v, str_replace(' ', 'T', $v)], true) ? $x : null;
    }

    private static function hm(string $t): ?int
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:00)?$/', $t, $m) !== 1) {
            return null;
        }
        return (int) $m[1] * 60 + (int) $m[2];
    }

    /** @return list<array<string, mixed>> */
    public function sites(int $le): array
    {
        return $this->app->db()->all("SELECT id, public_id, code FROM sites WHERE legal_entity_id = ? AND status = 'active' ORDER BY code", [$le]);
    }

    private function siteId(int $le, string $pid): ?int
    {
        $id = $this->app->db()->scalar('SELECT id FROM sites WHERE public_id = ? AND legal_entity_id = ?', [$pid, $le]);
        return $id === null ? null : (int) $id;
    }

    // ---- map settings (system administrator) --------------------------------------------------------------------------------

    /** @return array{map_enabled: bool, tile_url: string, tile_attribution: string, tile_origin: string, routing: string, has_key: bool, updated_at: string} */
    public function settings(): array
    {
        $s = $this->app->db()->one('SELECT * FROM vh_settings WHERE id = 1') ?? ['map_enabled' => 0, 'tile_url' => '', 'tile_attribution' => '', 'routing' => 'none', 'ors_key_enc' => null, 'updated_at' => ''];
        $url = (string) $s['tile_url'];
        return ['map_enabled' => (int) $s['map_enabled'] === 1 && $url !== '', 'tile_url' => $url, 'tile_attribution' => (string) $s['tile_attribution'], 'tile_origin' => self::origin($url),
            'routing' => (string) $s['routing'], 'has_key' => $s['ors_key_enc'] !== null && $s['ors_key_enc'] !== '', 'updated_at' => (string) $s['updated_at']];
    }

    public static function origin(string $tileUrl): string
    {
        return preg_match('#^(https://[a-z0-9.-]+(:\d{2,5})?)/#i', $tileUrl, $m) === 1 ? strtolower($m[1]) : '';
    }

    /**
     * @param array<string, mixed> $in map_enabled, tile_url, tile_attribution, routing (none|ors), ors_key (blank = keep), clear_key
     */
    public function saveSettings(Authorizer $az, array $in): void
    {
        if (!$az->can('vehicles.settings')) {
            throw HttpException::forbidden();
        }
        $e = [];
        $url = trim((string) ($in['tile_url'] ?? ''));
        if (
            strlen($url) > 300 || self::origin($url) === '' || !str_contains($url, '{z}') || !str_contains($url, '{x}') || !str_contains($url, '{y}')
            || preg_match('#[\s"\'<>]#', $url) === 1
        ) {
            $e['tile_url'] = 'vehicles.error.tile_url';
        }
        $attr = trim((string) ($in['tile_attribution'] ?? ''));
        if (mb_strlen($attr) < 3 || mb_strlen($attr) > 300) {
            $e['tile_attribution'] = 'vehicles.error.attribution';
        }
        $routing = (string) ($in['routing'] ?? 'none');
        if (!in_array($routing, ['none', 'ors'], true)) {
            $e['routing'] = 'vehicles.error.routing';
        }
        $key = trim((string) ($in['ors_key'] ?? ''));
        if ($key !== '' && preg_match('/^[A-Za-z0-9=_\-.]{20,300}$/', $key) !== 1) {
            $e['ors_key'] = 'vehicles.error.ors_key';
        }
        $cur = $this->settings();
        $clear = !empty($in['clear_key']);
        if ($routing === 'ors' && $key === '' && (!$cur['has_key'] || $clear)) {
            $e['ors_key'] = 'vehicles.error.ors_key_needed';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $db = $this->app->db();
        $db->run(
            'UPDATE vh_settings SET map_enabled = ?, tile_url = ?, tile_attribution = ?, routing = ?, updated_at = ?, updated_by = ? WHERE id = 1',
            [!empty($in['map_enabled']) ? 1 : 0, $url, $attr, $routing, $this->stamp(), $az->userId]
        );
        if ($key !== '') {
            $db->run('UPDATE vh_settings SET ors_key_enc = ? WHERE id = 1', [$this->app->crypto()->encrypt($key)]);
        } elseif ($clear) {
            $db->run('UPDATE vh_settings SET ors_key_enc = NULL WHERE id = 1');
        }
        // the key itself never reaches the audit trail — only whether it changed
        $this->app->audit()->record('vehicles.settings_changed', 'success', 'vh_settings', '1', null, null, [
            'map_enabled' => !empty($in['map_enabled']), 'tile_origin' => self::origin($url), 'routing' => $routing, 'key' => $key !== '' ? 'replaced' : ($clear ? 'removed' : 'unchanged'),
        ]);
    }

    private function orsKey(): ?string
    {
        $enc = $this->app->db()->scalar("SELECT ors_key_enc FROM vh_settings WHERE id = 1 AND routing = 'ors'");
        if ($enc === null || $enc === '') {
            return null;
        }
        try {
            return $this->app->crypto()->decrypt((string) $enc);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Estimated distance and time between two points: along roads when a road service is set up and answers,
     * otherwise a straight line (always shorter than the drive — said on screen).
     *
     * @return array{km: float, minutes: ?int, source: string, straight_km: float, road_failed: bool}
     */
    public function estimate(float $lat1, float $lng1, float $lat2, float $lng2): array
    {
        $straight = Geo::straightKm($lat1, $lng1, $lat2, $lng2); // not rounded here — callers round once (a return trip doubles first)
        $out = ['km' => $straight, 'minutes' => null, 'source' => 'straight', 'straight_km' => $straight, 'road_failed' => false];
        $key = $this->orsKey();
        if ($key === null) {
            return $out;
        }
        $url = self::ORS_URL . '?start=' . rawurlencode(sprintf('%.6F,%.6F', $lng1, $lat1)) . '&end=' . rawurlencode(sprintf('%.6F,%.6F', $lng2, $lat2));
        $res = $this->app->http()->get($url, ['Authorization' => $key, 'Accept' => 'application/json, application/geo+json'], 8, 2_000_000);
        $json = $res['status'] === 200 ? json_decode($res['body'], true) : null;
        $sum = is_array($json) ? ($json['features'][0]['properties']['summary'] ?? null) : null;
        if (!is_array($sum) || !is_numeric($sum['distance'] ?? null)) {
            $this->app->logger->warning('vehicles_road_estimate_failed', ['status' => $res['status'], 'error' => $res['error']]); // never the key or the points
            $out['road_failed'] = true;
            return $out;
        }
        return ['km' => (float) $sum['distance'] / 1000, 'minutes' => is_numeric($sum['duration'] ?? null) ? (int) round((float) $sum['duration'] / 60) : null,
            'source' => 'road', 'straight_km' => $straight, 'road_failed' => false];
    }

    // ---- company policy, types, vehicles, drivers, places (workplace admin) -----------------------------------------------

    /** @return array{confirm_required: bool, location_keep_days: ?int} */
    public function policy(int $le): array
    {
        $p = $this->app->db()->one('SELECT * FROM vh_policies WHERE legal_entity_id = ?', [$le]);
        return ['confirm_required' => $p !== null && (int) $p['confirm_required'] === 1, 'location_keep_days' => $p !== null && $p['location_keep_days'] !== null ? (int) $p['location_keep_days'] : null];
    }

    /** @param array<string, mixed> $in confirm_required, location_keep_days (blank = keep until erased) */
    public function savePolicy(Authorizer $az, int $le, array $in): void
    {
        $this->require($az, 'vehicles.manage', $le);
        $days = trim((string) ($in['location_keep_days'] ?? ''));
        if ($days !== '' && (!ctype_digit($days) || (int) $days < 1 || (int) $days > 3650)) {
            throw new ValidationException(['location_keep_days' => 'vehicles.error.keep_days']);
        }
        $this->app->db()->run(
            'INSERT INTO vh_policies (legal_entity_id, confirm_required, location_keep_days, updated_at, updated_by) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE confirm_required = VALUES(confirm_required), location_keep_days = VALUES(location_keep_days), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)',
            [$le, !empty($in['confirm_required']) ? 1 : 0, $days === '' ? null : (int) $days, $this->stamp(), $az->userId]
        );
        $this->app->audit()->record('vehicles.policy_changed', 'success', 'vh_policy', (string) $le, $le, null, ['confirm_required' => !empty($in['confirm_required']), 'keep_days' => $days === '' ? null : (int) $days]);
    }

    /** @return list<array<string, mixed>> */
    public function types(int $le): array
    {
        return $this->app->db()->all('SELECT id, public_id, name FROM vh_types WHERE legal_entity_id = ? ORDER BY name', [$le]);
    }

    public function addType(Authorizer $az, int $le, string $name): void
    {
        $this->require($az, 'vehicles.manage', $le);
        $name = trim($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            throw new ValidationException(['type_name' => 'vehicles.error.name']);
        }
        if ($this->app->db()->scalar('SELECT id FROM vh_types WHERE legal_entity_id = ? AND name = ?', [$le, $name]) !== null) {
            throw new ValidationException(['type_name' => 'vehicles.error.type_taken']);
        }
        $now = $this->stamp();
        $this->app->db()->run('INSERT INTO vh_types (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, name) VALUES (?, ?, ?, ?, ?, ?, ?)', [Ulid::generate($this->app->clock), $now, $az->userId, $now, $az->userId, $le, $name]);
        $this->app->audit()->record('vehicles.type_added', 'success', 'vh_type', $name, $le);
    }

    /** @return list<array<string, mixed>> */
    public function vehicles(int $le): array
    {
        return $this->app->db()->all(
            "SELECT v.*, s.code AS site_code, s.public_id AS site_pid, t.name AS type_name, t.public_id AS type_pid FROM vh_vehicles v LEFT JOIN sites s ON s.id = v.site_id LEFT JOIN vh_types t ON t.id = v.type_id
              WHERE v.legal_entity_id = ? ORDER BY v.status = 'inactive', s.code, v.plate",
            [$le]
        );
    }

    /** @return array<string, mixed> */
    public function vehicle(string $pid): array
    {
        return $this->app->db()->one('SELECT v.*, t.public_id AS type_pid, s.public_id AS site_pid FROM vh_vehicles v LEFT JOIN vh_types t ON t.id = v.type_id LEFT JOIN sites s ON s.id = v.site_id WHERE v.public_id = ?', [$pid])
            ?? throw HttpException::notFound();
    }

    /** @param array<string, mixed> $in plate, name, type, site, seats, odometer_km (new only) */
    public function saveVehicle(Authorizer $az, int $le, array $in, ?string $pid = null): string
    {
        $this->require($az, 'vehicles.manage', $le);
        $db = $this->app->db();
        $e = [];
        $plate = trim((string) ($in['plate'] ?? ''));
        if (mb_strlen($plate) < 2 || mb_strlen($plate) > 20) {
            $e['plate'] = 'vehicles.error.plate';
        } elseif ($db->scalar('SELECT id FROM vh_vehicles WHERE legal_entity_id = ? AND plate = ? AND public_id <> ?', [$le, $plate, $pid ?? '']) !== null) {
            $e['plate'] = 'vehicles.error.plate_taken';
        }
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $e['name'] = 'vehicles.error.name';
        }
        $type = null;
        if ((string) ($in['type'] ?? '') !== '') {
            $type = $db->scalar('SELECT id FROM vh_types WHERE public_id = ? AND legal_entity_id = ?', [(string) $in['type'], $le]);
            if ($type === null) {
                $e['type'] = 'vehicles.error.type';
            }
        }
        $site = null;
        if ((string) ($in['site'] ?? '') !== '') {
            $site = $this->siteId($le, (string) $in['site']);
            if ($site === null) {
                $e['site'] = 'vehicles.error.site';
            }
        }
        $seats = trim((string) ($in['seats'] ?? ''));
        if (!ctype_digit($seats) || (int) $seats < 1 || (int) $seats > 99) {
            $e['seats'] = 'vehicles.error.seats';
        }
        $odo = trim((string) ($in['odometer_km'] ?? '0'));
        if ($pid === null && (!ctype_digit($odo) || (int) $odo > 9_999_999)) {
            $e['odometer_km'] = 'vehicles.error.odometer';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $now = $this->stamp();
        if ($pid === null) {
            $pid = Ulid::generate($this->app->clock);
            $db->run(
                'INSERT INTO vh_vehicles (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, site_id, type_id, plate, name, seats, odometer_km) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $now, $az->userId, $now, $az->userId, $le, $site, $type, $plate, $name, (int) $seats, (int) $odo]
            );
            $this->app->audit()->record('vehicles.vehicle_added', 'success', 'vh_vehicle', $pid, $le, null, ['plate' => $plate]);
            return $pid;
        }
        $v = $this->vehicle($pid);
        if ((int) $v['legal_entity_id'] !== $le) {
            throw HttpException::notFound();
        }
        $db->run(
            'UPDATE vh_vehicles SET site_id = ?, type_id = ?, plate = ?, name = ?, seats = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
            [$site, $type, $plate, $name, (int) $seats, $now, $az->userId, $v['id']]
        );
        $this->app->audit()->record('vehicles.vehicle_changed', 'success', 'vh_vehicle', $pid, $le, null, ['plate' => $plate]);
        return $pid;
    }

    /** Available / under repair / out of use. Future trips already assigned to it are listed back (the admin moves them). */
    public function setVehicleStatus(Authorizer $az, string $pid, string $status, string $note): int
    {
        $v = $this->vehicle($pid);
        $this->require($az, 'vehicles.manage', (int) $v['legal_entity_id']);
        $note = trim($note);
        if (!in_array($status, ['available', 'maintenance', 'inactive'], true)) {
            throw new ValidationException(['status' => 'vehicles.error.status']);
        }
        if ($status !== 'available' && (mb_strlen($note) < 3 || mb_strlen($note) > 300)) {
            throw new ValidationException(['status_note' => 'vehicles.error.reason']);
        }
        $this->app->db()->run(
            'UPDATE vh_vehicles SET status = ?, status_note = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
            [$status, $status === 'available' ? null : $note, $this->stamp(), $az->userId, $v['id']]
        );
        $this->app->audit()->record('vehicles.vehicle_status', 'success', 'vh_vehicle', $pid, (int) $v['legal_entity_id'], $status === 'available' ? null : $note, ['status' => $status]);
        return $status === 'available' ? 0 : (int) $this->app->db()->scalar("SELECT COUNT(*) FROM vh_bookings WHERE vehicle_id = ? AND status = 'assigned' AND ends_at > ?", [$v['id'], $this->nowLocal()->format('Y-m-d H:i:s')]);
    }

    /** @return list<array<string, mixed>> */
    public function drivers(int $le): array
    {
        return $this->app->db()->all(
            'SELECT d.*, s.code AS site_code, s.public_id AS site_pid, COALESCE(u.display_name, u.username) AS user_name, u.username FROM vh_drivers d LEFT JOIN sites s ON s.id = d.site_id LEFT JOIN users u ON u.id = d.user_id
              WHERE d.legal_entity_id = ? ORDER BY d.active DESC, d.name',
            [$le]
        );
    }

    /** @return array<string, mixed> */
    public function driver(string $pid): array
    {
        return $this->app->db()->one('SELECT d.*, s.public_id AS site_pid, u.username FROM vh_drivers d LEFT JOIN sites s ON s.id = d.site_id LEFT JOIN users u ON u.id = d.user_id WHERE d.public_id = ?', [$pid])
            ?? throw HttpException::notFound();
    }

    /**
     * A driver: name, phone, the account that logs trips (optional), licence class and expiry (no licence number),
     * working hours and days — the company's own, no defaults.
     *
     * @param array<string, mixed> $in name, phone, account (user name or employee code), site, licence_class, licence_expiry, work_from, work_to, work_days[], active
     */
    public function saveDriver(Authorizer $az, int $le, array $in, ?string $pid = null): string
    {
        $this->require($az, 'vehicles.manage', $le);
        $db = $this->app->db();
        $e = [];
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $e['name'] = 'vehicles.error.name';
        }
        $phone = trim((string) ($in['phone'] ?? ''));
        if ($phone !== '' && preg_match('/^[0-9+\-\s()]{6,30}$/', $phone) !== 1) {
            $e['phone'] = 'vehicles.error.phone';
        }
        $user = null;
        $acct = trim((string) ($in['account'] ?? ''));
        if ($acct !== '') {
            $user = $db->scalar(
                "SELECT u.id FROM users u LEFT JOIN employees x ON x.id = u.employee_id WHERE u.status = 'active' AND (u.username = ? OR x.employee_code = ?) LIMIT 1",
                [$acct, strtoupper($acct)]
            );
            if ($user === null || !(new Authorizer($db, $this->app->clock, (int) $user))->scope('vehicles.use')->coversLegalEntity($le)) {
                $e['account'] = 'vehicles.error.account';
                $user = null;
            }
        }
        $site = null;
        if ((string) ($in['site'] ?? '') !== '') {
            $site = $this->siteId($le, (string) $in['site']);
            if ($site === null) {
                $e['site'] = 'vehicles.error.site';
            }
        }
        $class = trim((string) ($in['licence_class'] ?? ''));
        if (mb_strlen($class) > 60) {
            $e['licence_class'] = 'vehicles.error.text';
        }
        $exp = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($in['licence_expiry'] ?? ''));
        if ($exp === false || $exp->format('Y-m-d') !== (string) ($in['licence_expiry'] ?? '')) {
            $e['licence_expiry'] = 'vehicles.error.date';
        }
        $from = self::hm((string) ($in['work_from'] ?? ''));
        $to = self::hm((string) ($in['work_to'] ?? ''));
        if ($from === null || $to === null || $to <= $from) {
            $e['work_to'] = 'vehicles.error.hours';
        }
        $days = array_values(array_unique(array_filter(array_map('intval', (array) ($in['work_days'] ?? [])), static fn (int $d): bool => $d >= 1 && $d <= 7)));
        sort($days);
        if ($days === []) {
            $e['work_days'] = 'vehicles.error.days';
        }
        if ($e !== [] || $exp === false || $from === null || $to === null) {
            throw new ValidationException($e);
        }
        $now = $this->stamp();
        $vals = [$site, $name, $phone === '' ? null : $phone, $user, $class === '' ? null : $class, $exp->format('Y-m-d'), sprintf('%02d:%02d:00', intdiv($from, 60), $from % 60),
            sprintf('%02d:%02d:00', intdiv($to, 60), $to % 60), implode(',', $days), $pid === null || !empty($in['active']) ? 1 : 0];
        if ($pid === null) {
            $pid = Ulid::generate($this->app->clock);
            $db->run(
                'INSERT INTO vh_drivers (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, site_id, name, phone, user_id, licence_class, licence_expiry, work_from, work_to, work_days, active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $now, $az->userId, $now, $az->userId, $le, ...$vals]
            );
            $this->app->audit()->record('vehicles.driver_added', 'success', 'vh_driver', $pid, $le);
            return $pid;
        }
        $d = $this->driver($pid);
        if ((int) $d['legal_entity_id'] !== $le) {
            throw HttpException::notFound();
        }
        $db->run(
            'UPDATE vh_drivers SET site_id = ?, name = ?, phone = ?, user_id = ?, licence_class = ?, licence_expiry = ?, work_from = ?, work_to = ?, work_days = ?, active = ?, updated_at = ?, updated_by = ?,
                row_version = row_version + 1 WHERE id = ?',
            [...$vals, $now, $az->userId, $d['id']]
        );
        $this->app->audit()->record('vehicles.driver_changed', 'success', 'vh_driver', $pid, $le);
        return $pid;
    }

    /** @return list<array<string, mixed>> */
    public function places(int $le, bool $activeOnly = false): array
    {
        return $this->app->db()->all('SELECT * FROM vh_places WHERE legal_entity_id = ?' . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY active DESC, name', [$le]);
    }

    /** @param array<string, mixed> $in name, address, point ("lat, lng") */
    public function savePlace(Authorizer $az, int $le, array $in): string
    {
        $this->require($az, 'vehicles.manage', $le);
        $e = [];
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            $e['place_name'] = 'vehicles.error.name';
        }
        $addr = trim((string) ($in['address'] ?? ''));
        if (mb_strlen($addr) > 300) {
            $e['address'] = 'vehicles.error.text';
        }
        $pt = Geo::point((string) ($in['point'] ?? ''));
        if ($pt === null) {
            $e['place_point'] = 'vehicles.error.point';
        }
        if ($e !== [] || $pt === null) {
            throw new ValidationException($e);
        }
        $pid = Ulid::generate($this->app->clock);
        $now = $this->stamp();
        $this->app->db()->run(
            'INSERT INTO vh_places (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, name, address, lat, lng) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$pid, $now, $az->userId, $now, $az->userId, $le, $name, $addr === '' ? null : $addr, $pt[0], $pt[1]]
        );
        $this->app->audit()->record('vehicles.place_added', 'success', 'vh_place', $pid, $le);
        return $pid;
    }

    public function setPlaceActive(Authorizer $az, string $pid, bool $active): void
    {
        $p = $this->app->db()->one('SELECT * FROM vh_places WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $this->require($az, 'vehicles.manage', (int) $p['legal_entity_id']);
        $this->app->db()->run('UPDATE vh_places SET active = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [$active ? 1 : 0, $this->stamp(), $az->userId, $p['id']]);
        $this->app->audit()->record($active ? 'vehicles.place_shown' : 'vehicles.place_hidden', 'success', 'vh_place', $pid, (int) $p['legal_entity_id']);
    }

    // ---- requests ------------------------------------------------------------------------------------------------------------

    /**
     * One end of the trip: a saved place, or a name with a pin ("lat, lng") and/or a pasted map link.
     *
     * @param array<string, mixed> $in
     * @param array<string, string> $e
     * @return array{name: string, lat: ?float, lng: ?float, link: ?string}
     */
    private function end(array $in, string $side, int $le, array &$e): array
    {
        $place = (string) ($in[$side . '_place'] ?? '');
        if ($place !== '') {
            $p = $this->app->db()->one('SELECT * FROM vh_places WHERE public_id = ? AND legal_entity_id = ? AND active = 1', [$place, $le]);
            if ($p === null) {
                $e[$side . '_place'] = 'vehicles.error.place';
                return ['name' => '', 'lat' => null, 'lng' => null, 'link' => null];
            }
            return ['name' => (string) $p['name'], 'lat' => (float) $p['lat'], 'lng' => (float) $p['lng'], 'link' => null];
        }
        $name = trim((string) ($in[$side . '_name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            $e[$side . '_name'] = 'vehicles.error.place_name';
        }
        $link = trim((string) ($in[$side . '_link'] ?? ''));
        if ($link !== '' && !Geo::linkAllowed($link)) {
            $e[$side . '_link'] = 'vehicles.error.link';
            $link = '';
        }
        $text = trim((string) ($in[$side . '_point'] ?? ''));
        $pt = $text === '' ? null : Geo::point($text);
        if ($text !== '' && $pt === null) {
            $e[$side . '_point'] = 'vehicles.error.point';
        }
        if ($pt === null && $link !== '') {
            $pt = Geo::fromLink($link); // a full map link carries its point; a short link does not
        }
        return ['name' => $name, 'lat' => $pt[0] ?? null, 'lng' => $pt[1] ?? null, 'link' => $link === '' ? null : $link];
    }

    /**
     * Passengers: one per line — an employee code of the company (linked to the person) or a name typed by hand.
     *
     * @return list<array{employee_id: ?int, name: string}>
     */
    private function passengers(string $text, int $le): array
    {
        $out = [];
        $db = $this->app->db();
        foreach (array_filter(array_map('trim', preg_split('/\R/', $text) ?: [])) as $line) {
            if (count($out) >= 60) {
                throw new ValidationException(['passengers' => 'vehicles.error.passengers_many']);
            }
            if (mb_strlen($line) > 120) {
                throw new ValidationException(['passengers' => 'vehicles.error.passenger_name']);
            }
            $emp = preg_match('/^[A-Za-z0-9-]{2,20}$/', $line) === 1
                ? $db->one(
                    "SELECT e.id, e.name_th FROM employees e JOIN employment_history h ON h.employee_id = e.id AND h.effective_to IS NULL WHERE e.employee_code = ? AND h.legal_entity_id = ? AND e.status IN ('active','on_leave')",
                    [strtoupper($line), $le]
                )
                : null;
            $out[] = $emp !== null ? ['employee_id' => (int) $emp['id'], 'name' => (string) $emp['name_th']] : ['employee_id' => null, 'name' => $line];
        }
        return $out;
    }

    private function nextRef(int $le, DateTimeImmutable $start): string
    {
        $prefix = 'VB' . $start->format('ym') . '-';
        $last = (string) ($this->app->db()->scalar('SELECT MAX(ref) FROM vh_bookings WHERE legal_entity_id = ? AND ref LIKE ?', [$le, $prefix . '%']) ?? '');
        return $prefix . str_pad((string) ((int) substr($last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Saves a request (new, or a draft / returned one of mine) and — when asked — sends it for approval.
     *
     * @param array<string, mixed> $in le, site, start, end, purpose, passenger_count, passengers, from_*, to_* (place | name, point, link), round_trip, est_km_manual, note
     * @return array{pid: string, submitted: bool, no_vehicle_free: bool}
     */
    public function save(Authorizer $az, int $le, array $in, bool $submit, ?string $pid = null): array
    {
        $db = $this->app->db();
        $old = null;
        if ($pid !== null) {
            $old = $db->one('SELECT * FROM vh_bookings WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
            if ((int) $old['requester_id'] !== $az->userId) {
                throw HttpException::notFound();
            }
            if ($old['status'] !== 'draft') {
                throw new BusinessException('vehicles.error.cannot_change', [], 409);
            }
            $le = (int) $old['legal_entity_id'];
        }
        $this->require($az, 'vehicles.use', $le);
        $e = [];
        $site = $this->siteId($le, (string) ($in['site'] ?? ''));
        if ($site === null) {
            $e['site'] = 'vehicles.error.site';
        }
        $start = self::local((string) ($in['start'] ?? ''));
        $end = self::local((string) ($in['end'] ?? ''));
        if ($start === null) {
            $e['start'] = 'vehicles.error.datetime';
        } elseif ($start < $this->nowLocal()) {
            $e['start'] = 'vehicles.error.past';
        }
        if ($end === null || ($start !== null && $end <= $start)) {
            $e['end'] = 'vehicles.error.end';
        }
        $purpose = trim((string) ($in['purpose'] ?? ''));
        if (mb_strlen($purpose) < 3 || mb_strlen($purpose) > 300) {
            $e['purpose'] = 'vehicles.error.purpose';
        }
        $count = trim((string) ($in['passenger_count'] ?? ''));
        if (!ctype_digit($count) || (int) $count < 1 || (int) $count > 99) {
            $e['passenger_count'] = 'vehicles.error.count';
        }
        $from = $this->end($in, 'from', $le, $e);
        $to = $this->end($in, 'to', $le, $e);
        $manual = trim((string) ($in['est_km_manual'] ?? ''));
        if ($manual !== '' && preg_match('/^\d{1,5}(\.\d)?$/', $manual) !== 1) {
            $e['est_km_manual'] = 'vehicles.error.km';
        }
        $note = trim((string) ($in['note'] ?? ''));
        if (mb_strlen($note) > 500) {
            $e['note'] = 'vehicles.error.text';
        }
        $people = $e === [] ? $this->passengers((string) ($in['passengers'] ?? ''), $le) : [];
        if ($e === [] && count($people) > (int) $count) {
            $e['passengers'] = 'vehicles.error.passengers_count';
        }
        if ($e !== [] || $start === null || $end === null || $site === null) {
            throw new ValidationException($e);
        }
        $a = $start->format('Y-m-d H:i:s');
        $b = $end->format('Y-m-d H:i:s');
        // the same person cannot hold two live requests at the same time
        if ($db->scalar("SELECT id FROM vh_bookings WHERE requester_id = ? AND status IN ('pending','approved','assigned','in_progress') AND starts_at < ? AND ends_at > ? AND public_id <> ? LIMIT 1", [$az->userId, $b, $a, $pid ?? '']) !== null) {
            throw new ValidationException(['start' => 'vehicles.error.own_overlap']);
        }
        $fleet = $db->all("SELECT id, seats FROM vh_vehicles WHERE legal_entity_id = ? AND status = 'available' AND (site_id = ? OR site_id IS NULL)", [$le, $site]);
        if ($fleet !== [] && max(array_map(static fn (array $v): int => (int) $v['seats'], $fleet)) < (int) $count) {
            throw new ValidationException(['passenger_count' => 'vehicles.error.no_vehicle_big']);
        }
        $est = ['km' => null, 'minutes' => null, 'source' => null, 'straight_km' => null];
        if ($from['lat'] !== null && $to['lat'] !== null && $from['lng'] !== null && $to['lng'] !== null) {
            // a draft saved again with the same pins keeps its road estimate (no second call to the road service)
            $samePoints = $old !== null && $old['est_source'] === 'road' && (string) $old['from_lat'] === sprintf('%.6F', $from['lat']) && (string) $old['from_lng'] === sprintf('%.6F', $from['lng'])
                && (string) $old['to_lat'] === sprintf('%.6F', $to['lat']) && (string) $old['to_lng'] === sprintf('%.6F', $to['lng']);
            $div = $old !== null && (int) $old['round_trip'] === 1 ? 2 : 1;
            $r = $samePoints
                ? ['km' => (float) $old['est_km'] / $div, 'minutes' => $old['est_minutes'] !== null ? (int) $old['est_minutes'] / $div : null, 'source' => 'road',
                    'straight_km' => Geo::straightKm($from['lat'], $from['lng'], $to['lat'], $to['lng'])]
                : $this->estimate($from['lat'], $from['lng'], $to['lat'], $to['lng']);
            $k = !empty($in['round_trip']) ? 2 : 1;
            $est = ['km' => round((float) $r['km'] * $k, 1), 'minutes' => $r['minutes'] !== null ? (int) round($r['minutes'] * $k) : null, 'source' => $r['source'], 'straight_km' => round((float) $r['straight_km'] * $k, 1)];
        }
        if ($manual !== '') {
            $est['km'] = (float) $manual;
            $est['source'] = 'manual';
            $est['minutes'] = null;
        }
        $now = $this->stamp();
        $vals = [$site, $a, $b, $purpose, (int) $count, $from['name'], $from['lat'], $from['lng'], $from['link'], $to['name'], $to['lat'], $to['lng'], $to['link'], !empty($in['round_trip']) ? 1 : 0,
            $note === '' ? null : $note, $est['km'], $est['minutes'], $est['source'], $est['straight_km']];
        $pid = $db->transactional(function () use ($db, $az, $le, $old, $vals, $people, $now, $start): string {
            if ($old === null) {
                $pid = Ulid::generate($this->app->clock);
                $db->run(
                    'INSERT INTO vh_bookings (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, ref, requester_id, site_id, starts_at, ends_at, purpose, passenger_count,
                        from_name, from_lat, from_lng, from_link, to_name, to_lat, to_lng, to_link, round_trip, note, est_km, est_minutes, est_source, straight_km)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$pid, $now, $az->userId, $now, $az->userId, $le, $this->nextRef($le, $start), $az->userId, ...$vals]
                );
                $id = (int) $db->pdo()->lastInsertId();
                $this->event($id, 'created', $az->userId, null);
            } else {
                $pid = (string) $old['public_id'];
                $id = (int) $old['id'];
                $db->run(
                    'UPDATE vh_bookings SET site_id = ?, starts_at = ?, ends_at = ?, purpose = ?, passenger_count = ?, from_name = ?, from_lat = ?, from_lng = ?, from_link = ?, to_name = ?, to_lat = ?, to_lng = ?,
                        to_link = ?, round_trip = ?, note = ?, est_km = ?, est_minutes = ?, est_source = ?, straight_km = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
                    [...$vals, $now, $az->userId, $id]
                );
                $db->run('DELETE FROM vh_passengers WHERE booking_id = ?', [$id]);
                $this->event($id, 'edited', $az->userId, null);
            }
            foreach ($people as $p) {
                $db->run('INSERT INTO vh_passengers (booking_id, employee_id, name) VALUES (?, ?, ?)', [$id, $p['employee_id'], $p['name']]);
            }
            return $pid;
        });
        $this->app->audit()->record($old === null ? 'vehicles.request_created' : 'vehicles.request_changed', 'success', 'vh_booking', $pid, $le, null, ['passengers' => (int) $count, 'estimate' => $est['source']]);
        $free = $this->freeVehicles($le, $site, $a, $b, (int) $count);
        if ($submit) {
            $this->submit($az, $pid);
        }
        return ['pid' => $pid, 'submitted' => $submit, 'no_vehicle_free' => $free === 0];
    }

    /** how many vehicles of the site (or the whole company) are available, big enough and free at a time */
    private function freeVehicles(int $le, int $site, string $a, string $b, int $count): int
    {
        return (int) $this->app->db()->scalar(
            "SELECT COUNT(*) FROM vh_vehicles v WHERE v.legal_entity_id = ? AND v.status = 'available' AND (v.site_id = ? OR v.site_id IS NULL) AND v.seats >= ?
                AND NOT EXISTS (SELECT 1 FROM vh_bookings x WHERE x.vehicle_id = v.id AND x.status IN ('assigned','in_progress') AND x.starts_at < ? AND x.ends_at > ?)",
            [$le, $site, $count, $b, $a]
        );
    }

    /** Sends a draft of mine for approval (a request returned for changes goes back to the same approval). */
    public function submit(Authorizer $az, string $pid): void
    {
        $db = $this->app->db();
        $b = $db->one('SELECT * FROM vh_bookings WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        if ((int) $b['requester_id'] !== $az->userId || $b['status'] !== 'draft') {
            throw new BusinessException('vehicles.error.cannot_change', [], 409);
        }
        if ((string) $b['starts_at'] < $this->nowLocal()->format('Y-m-d H:i:s')) {
            throw new BusinessException('vehicles.error.past', [], 409);
        }
        $wf = $this->app->workflow();
        $db->run("UPDATE vh_bookings SET status = 'pending', updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ? AND status = 'draft'", [$this->stamp(), $az->userId, $b['id']]);
        $title = $this->app->translator()->get('vehicles.wf_title', ['ref' => (string) $b['ref'], 'when' => substr((string) $b['starts_at'], 0, 16)]);
        if ($b['workflow_id'] !== null && (string) $db->scalar('SELECT status FROM workflow_instances WHERE public_id = ?', [$b['workflow_id']]) === 'returned') {
            $wf->resubmit((string) $b['workflow_id'], $az->userId);
        } else {
            $inst = $wf->start('vehicles.request', $pid, $title, (int) $b['legal_entity_id'], $az->userId, (int) $b['site_id'], $this->departmentOf($az->userId));
            $db->run('UPDATE vh_bookings SET workflow_id = ? WHERE id = ?', [$inst, $b['id']]);
        }
        $this->event((int) $b['id'], 'submitted', $az->userId, null);
        $this->app->audit()->record('vehicles.request_submitted', 'success', 'vh_booking', $pid, (int) $b['legal_entity_id']);
    }

    private function departmentOf(int $userId): ?int
    {
        $emp = $this->app->db()->scalar('SELECT employee_id FROM users WHERE id = ?', [$userId]);
        if ($emp === null) {
            return null;
        }
        $d = $this->app->db()->scalar('SELECT department_id FROM employment_history WHERE employee_id = ? AND effective_to IS NULL ORDER BY id DESC LIMIT 1', [$emp]);
        return $d === null ? null : (int) $d;
    }

    /** @return list<int> line manager(s) of the requester */
    public function managerUserIds(string $pid): array
    {
        $emp = $this->app->db()->scalar('SELECT u.employee_id FROM vh_bookings b JOIN users u ON u.id = b.requester_id WHERE b.public_id = ?', [$pid]);
        return $emp === null ? [] : EmployeeService::of($this->app)->managerUserIds((int) $emp, $this->today());
    }

    public function onApproved(string $pid, ?int $approverId = null): void
    {
        $db = $this->app->db();
        $b = $db->one('SELECT * FROM vh_bookings WHERE public_id = ?', [$pid]);
        if ($b === null || $b['status'] !== 'pending') {
            return; // already handled (retry) or cancelled meanwhile
        }
        $db->run("UPDATE vh_bookings SET status = 'approved', updated_at = ?, row_version = row_version + 1 WHERE id = ? AND status = 'pending'", [$this->stamp(), $b['id']]);
        $this->event((int) $b['id'], 'approved', $approverId, null);
        $when = substr((string) $b['starts_at'], 0, 16);
        $this->app->notifier()->notify((int) $b['requester_id'], 'vehicles.approved', 'vehicles.notify.approved', ['ref' => (string) $b['ref'], 'when' => $when], '/vehicles/b/' . $pid, 'vh.ok:' . $pid);
        foreach ($this->admins((int) $b['legal_entity_id']) as $u) {
            $this->app->notifier()->notify($u, 'vehicles.dispatch', 'vehicles.notify.to_assign', ['ref' => (string) $b['ref'], 'when' => $when], '/vehicles/b/' . $pid, 'vh.as:' . $pid . ':' . $u);
        }
    }

    /** @param 'rejected'|'returned'|'cancelled' $outcome */
    public function onClosed(string $pid, string $outcome): void
    {
        $db = $this->app->db();
        $b = $db->one('SELECT * FROM vh_bookings WHERE public_id = ?', [$pid]);
        if ($b === null || $b['status'] !== 'pending') {
            return;
        }
        $to = ['rejected' => 'rejected', 'returned' => 'draft', 'cancelled' => 'cancelled'][$outcome];
        $db->run('UPDATE vh_bookings SET status = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ? AND status = ?', [$to, $this->stamp(), $b['id'], 'pending']);
        $this->event((int) $b['id'], $outcome, $outcome === 'cancelled' ? (int) $b['requester_id'] : null, null); // only the requester can withdraw
    }

    public function onResubmitted(string $pid): void
    {
        // submit() already moved the request back to "pending"
    }

    // ---- one booking ---------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> a booking the person may open: requester, approver, workplace admin, assigned driver */
    public function booking(Authorizer $az, string $pid): array
    {
        $db = $this->app->db();
        $b = $db->one(
            'SELECT b.*, l.code AS le_code, s.code AS site_code, s.public_id AS site_pid, v.public_id AS vehicle_pid, v.plate, v.name AS vehicle_name, v.seats, v.odometer_km,
                    d.public_id AS driver_pid, d.name AS driver_name, d.phone AS driver_phone, d.user_id AS driver_user, COALESCE(u.display_name, u.username) AS requester_name
               FROM vh_bookings b JOIN legal_entities l ON l.id = b.legal_entity_id JOIN sites s ON s.id = b.site_id JOIN users u ON u.id = b.requester_id
               LEFT JOIN vh_vehicles v ON v.id = b.vehicle_id LEFT JOIN vh_drivers d ON d.id = b.driver_id WHERE b.public_id = ?',
            [$pid]
        ) ?? throw HttpException::notFound();
        $le = (int) $b['legal_entity_id'];
        $b['mine'] = (int) $b['requester_id'] === $az->userId;
        $b['can_manage'] = $az->scope('vehicles.manage')->coversLegalEntity($le);
        $b['is_driver'] = $b['driver_user'] !== null && (int) $b['driver_user'] === $az->userId;
        $b['is_approver'] = $b['status'] !== 'draft' && ($az->scope('vehicles.approve')->coversLegalEntity($le) || in_array($az->userId, $this->managerUserIds($pid), true));
        if (!$b['mine'] && !$b['can_manage'] && !$b['is_driver'] && !$b['is_approver']) {
            throw HttpException::notFound();
        }
        $b['passengers'] = $db->all('SELECT p.name, x.employee_code FROM vh_passengers p LEFT JOIN employees x ON x.id = p.employee_id WHERE p.booking_id = ? ORDER BY p.id', [$b['id']]);
        $b['events'] = $db->all('SELECT e.event, e.note, e.created_at, COALESCE(u.display_name, u.username) AS actor FROM vh_events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.booking_id = ? ORDER BY e.id', [$b['id']]);
        $b['policy'] = $this->policy($le);
        $st = (string) $b['status'];
        $now = $this->nowLocal()->format('Y-m-d H:i:s');
        $driving = $b['can_manage'] || $b['is_driver'] || ($b['mine'] && (int) $b['self_drive'] === 1);
        $b['can_edit'] = $b['mine'] && $st === 'draft';
        $b['can_cancel'] = ($b['mine'] && in_array($st, ['draft', 'pending', 'approved', 'assigned'], true)) || ($b['can_manage'] && in_array($st, ['approved', 'assigned'], true));
        $b['can_assign'] = $b['can_manage'] && in_array($st, ['approved', 'assigned'], true);
        $b['can_start'] = $driving && $st === 'assigned';
        $b['can_finish'] = $driving && $st === 'in_progress';
        $b['can_no_show'] = $b['can_manage'] && $st === 'assigned' && $now >= (string) $b['starts_at'];
        $b['can_confirm'] = $b['mine'] && $st === 'completed' && $b['policy']['confirm_required'] && $b['confirmed_at'] === null;
        return $b;
    }

    /**
     * Vehicles and drivers for a booking with whether each can take it (ok · maintenance · inactive · small · busy ·
     * expired · hours).
     *
     * @param array<string, mixed> $b
     * @return array{vehicles: list<array<string, mixed>>, drivers: list<array<string, mixed>>}
     */
    public function options(array $b): array
    {
        $le = (int) $b['legal_entity_id'];
        $vs = [];
        foreach ($this->vehicles($le) as $v) {
            if ($v['status'] === 'inactive') {
                continue;
            }
            $v['flag'] = $this->vehicleProblem($v, $b) ?? 'ok';
            $vs[] = $v;
        }
        $ds = [];
        foreach ($this->drivers($le) as $d) {
            if ((int) $d['active'] !== 1) {
                continue;
            }
            $d['flag'] = $this->driverProblem($d, $b) ?? ($this->driverFits($d, (string) $b['starts_at'], (string) $b['ends_at']) ? 'ok' : 'hours');
            $ds[] = $d;
        }
        return ['vehicles' => $vs, 'drivers' => $ds];
    }

    /**
     * @param array<string, mixed> $v
     * @param array<string, mixed> $b
     */
    private function vehicleProblem(array $v, array $b): ?string
    {
        if ($v['status'] !== 'available') {
            return $v['status'] === 'maintenance' ? 'maintenance' : 'inactive';
        }
        if ((int) $v['seats'] < (int) $b['passenger_count']) {
            return 'small';
        }
        $clash = $this->app->db()->scalar(
            "SELECT id FROM vh_bookings WHERE vehicle_id = ? AND status IN ('assigned','in_progress') AND id <> ? AND starts_at < ? AND ends_at > ? LIMIT 1",
            [$v['id'], $b['id'], $b['ends_at'], $b['starts_at']]
        );
        return $clash !== null ? 'busy' : null;
    }

    /**
     * @param array<string, mixed> $d
     * @param array<string, mixed> $b
     */
    private function driverProblem(array $d, array $b): ?string
    {
        if ((string) $d['licence_expiry'] < substr((string) $b['ends_at'], 0, 10)) {
            return 'expired';
        }
        $clash = $this->app->db()->scalar(
            "SELECT id FROM vh_bookings WHERE driver_id = ? AND status IN ('assigned','in_progress') AND id <> ? AND starts_at < ? AND ends_at > ? LIMIT 1",
            [$d['id'], $b['id'], $b['ends_at'], $b['starts_at']]
        );
        return $clash !== null ? 'busy' : null;
    }

    /** @param array<string, mixed> $d a one-day trip inside the driver's working day and days */
    private function driverFits(array $d, string $start, string $end): bool
    {
        if (substr($start, 0, 10) !== substr($end, 0, 10)) {
            return false;
        }
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', substr($start, 0, 10));
        $days = array_map('intval', explode(',', (string) $d['work_days']));
        return $day !== false && in_array((int) $day->format('N'), $days, true) && substr($start, 11, 8) >= (string) $d['work_from'] && substr($end, 11, 8) <= (string) $d['work_to'];
    }

    /**
     * The workplace admin gives an approved booking a vehicle and a driver (or lets the requester drive), or changes
     * them. Locked per vehicle and driver while checked.
     *
     * @param array<string, mixed> $in vehicle, driver ('' with self_drive), self_drive, outside_hours, reason
     */
    public function assign(Authorizer $az, string $pid, array $in): void
    {
        $b = $this->booking($az, $pid);
        if (!$b['can_assign']) {
            throw new BusinessException('vehicles.error.cannot_change', [], 409);
        }
        $db = $this->app->db();
        $selfDrive = !empty($in['self_drive']);
        $reason = trim((string) ($in['reason'] ?? ''));
        $db->transactional(function () use ($db, $az, $b, $in, $selfDrive, $reason, $pid): void {
            $v = $db->one('SELECT * FROM vh_vehicles WHERE public_id = ? AND legal_entity_id = ? FOR UPDATE', [(string) ($in['vehicle'] ?? ''), $b['legal_entity_id']]);
            if ($v === null) {
                throw new ValidationException(['vehicle' => 'vehicles.error.vehicle']);
            }
            $p = $this->vehicleProblem($v, $b);
            if ($p !== null) {
                throw new ValidationException(['vehicle' => 'vehicles.error.vehicle_' . $p]);
            }
            $d = null;
            $note = null;
            if (!$selfDrive) {
                $d = $db->one('SELECT * FROM vh_drivers WHERE public_id = ? AND legal_entity_id = ? AND active = 1 FOR UPDATE', [(string) ($in['driver'] ?? ''), $b['legal_entity_id']]);
                if ($d === null) {
                    throw new ValidationException(['driver' => 'vehicles.error.driver']);
                }
                $p = $this->driverProblem($d, $b);
                if ($p !== null) {
                    throw new ValidationException(['driver' => 'vehicles.error.driver_' . $p]);
                }
                if (!$this->driverFits($d, (string) $b['starts_at'], (string) $b['ends_at'])) {
                    if (empty($in['outside_hours'])) {
                        throw new ValidationException(['driver' => 'vehicles.error.driver_hours']);
                    }
                    if (mb_strlen($reason) < 3 || mb_strlen($reason) > 300) {
                        throw new ValidationException(['reason' => 'vehicles.error.reason']);
                    }
                    $note = $reason;
                }
            }
            $oldDriver = $b['driver_user'] !== null ? (int) $b['driver_user'] : null;
            $changed = $b['status'] === 'assigned';
            $db->run(
                "UPDATE vh_bookings SET status = 'assigned', vehicle_id = ?, driver_id = ?, self_drive = ?, assigned_at = ?, assigned_by = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1
                  WHERE id = ? AND status IN ('approved','assigned')",
                [$v['id'], $d['id'] ?? null, $selfDrive ? 1 : 0, $this->stamp(), $az->userId, $this->stamp(), $az->userId, $b['id']]
            );
            $this->event((int) $b['id'], $changed ? 'reassigned' : 'assigned', $az->userId, trim($v['plate'] . ' · ' . ($d['name'] ?? $this->app->translator()->get('vehicles.self_drive')) . ($note !== null ? ' — ' . $note : '')));
            $this->app->audit()->record($changed ? 'vehicles.reassigned' : 'vehicles.assigned', 'success', 'vh_booking', $pid, (int) $b['legal_entity_id'], $note, ['self_drive' => $selfDrive, 'outside_hours' => $note !== null]);
            $params = ['ref' => (string) $b['ref'], 'when' => substr((string) $b['starts_at'], 0, 16), 'plate' => (string) $v['plate']];
            $this->app->notifier()->notify((int) $b['requester_id'], 'vehicles.assigned', 'vehicles.notify.assigned', $params, '/vehicles/b/' . $pid);
            $newDriver = $d !== null && $d['user_id'] !== null ? (int) $d['user_id'] : null;
            if ($newDriver !== null) {
                $this->app->notifier()->notify($newDriver, 'vehicles.driver', 'vehicles.notify.driver_job', $params, '/vehicles/b/' . $pid);
            }
            if ($oldDriver !== null && $oldDriver !== $newDriver) {
                $this->app->notifier()->notify($oldDriver, 'vehicles.driver', 'vehicles.notify.driver_removed', $params, '/vehicles');
            }
        });
    }

    /** @param array<string, mixed> $in odo_start, started_at (default now) */
    public function startTrip(Authorizer $az, string $pid, array $in): void
    {
        $b = $this->booking($az, $pid);
        if (!$b['can_start']) {
            throw new BusinessException('vehicles.error.cannot_change', [], 409);
        }
        $odo = trim((string) ($in['odo_start'] ?? ''));
        $at = self::local((string) ($in['started_at'] ?? '')) ?? $this->nowLocal();
        $e = [];
        if (!ctype_digit($odo) || (int) $odo > 9_999_999) {
            $e['odo_start'] = 'vehicles.error.odometer';
        } elseif ((int) $odo < (int) $b['odometer_km']) {
            $e['odo_start'] = 'vehicles.error.odo_back';
        }
        if ($at > $this->nowLocal()) {
            $e['started_at'] = 'vehicles.error.future';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $n = $this->app->db()->run(
            "UPDATE vh_bookings SET status = 'in_progress', trip_started_at = ?, odo_start = ?, trip_by = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ? AND status = 'assigned'",
            [$at->format('Y-m-d H:i:s'), (int) $odo, $az->userId, $this->stamp(), $az->userId, $b['id']]
        )->rowCount();
        if ($n !== 1) {
            throw new BusinessException('vehicles.error.cannot_change', [], 409);
        }
        $this->event((int) $b['id'], 'trip_started', $az->userId, null);
        $this->app->audit()->record('vehicles.trip_started', 'success', 'vh_booking', $pid, (int) $b['legal_entity_id']);
    }

    /**
     * Logs the end of the trip once (a second log is refused): odometer, time, fuel and costs, anything odd. The
     * vehicle's odometer moves on.
     *
     * @param array<string, mixed> $in odo_end, ended_at, fuel_litres, fuel_cost, other_cost, incident
     */
    public function finishTrip(Authorizer $az, string $pid, array $in): void
    {
        $b = $this->booking($az, $pid);
        if (!$b['can_finish']) {
            throw new BusinessException('vehicles.error.trip_logged', [], 409);
        }
        $e = [];
        $odo = trim((string) ($in['odo_end'] ?? ''));
        if (!ctype_digit($odo) || (int) $odo > 9_999_999 || (int) $odo <= (int) $b['odo_start']) {
            $e['odo_end'] = 'vehicles.error.odo_end';
        }
        $at = self::local((string) ($in['ended_at'] ?? '')) ?? $this->nowLocal();
        if ($at > $this->nowLocal() || $at->format('Y-m-d H:i:s') <= (string) $b['trip_started_at']) {
            $e['ended_at'] = 'vehicles.error.trip_end';
        }
        $dec = static function (string $k, int $places) use ($in, &$e): ?string {
            $v = trim((string) ($in[$k] ?? ''));
            if ($v === '') {
                return null;
            }
            $u = Money::parse($v, $places);
            if ($u === null || $u < 0) {
                $e[$k] = 'vehicles.error.amount';
                return null;
            }
            return Money::toDb($u);
        };
        $litres = $dec('fuel_litres', 2);
        $fuel = $dec('fuel_cost', 2);
        $other = $dec('other_cost', 2);
        $incident = trim((string) ($in['incident'] ?? ''));
        if (mb_strlen($incident) > 500) {
            $e['incident'] = 'vehicles.error.text';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $db = $this->app->db();
        $db->transactional(function () use ($db, $az, $b, $odo, $at, $litres, $fuel, $other, $incident, $pid): void {
            $n = $db->run(
                "UPDATE vh_bookings SET status = 'completed', trip_ended_at = ?, odo_end = ?, fuel_litres = ?, fuel_cost = ?, other_cost = ?, incident = ?, trip_by = ?, updated_at = ?, updated_by = ?,
                    row_version = row_version + 1 WHERE id = ? AND status = 'in_progress'",
                [$at->format('Y-m-d H:i:s'), (int) $odo, $litres, $fuel, $other, $incident === '' ? null : $incident, $az->userId, $this->stamp(), $az->userId, $b['id']]
            )->rowCount();
            if ($n !== 1) {
                throw new BusinessException('vehicles.error.trip_logged', [], 409);
            }
            $db->run('UPDATE vh_vehicles SET odometer_km = GREATEST(odometer_km, ?), updated_at = ? WHERE id = ?', [(int) $odo, $this->stamp(), $b['vehicle_id']]);
            $this->event((int) $b['id'], 'trip_completed', $az->userId, ((int) $odo - (int) $b['odo_start']) . ' km' . ($incident !== '' ? ' · !' : ''));
            $this->app->audit()->record('vehicles.trip_completed', 'success', 'vh_booking', $pid, (int) $b['legal_entity_id'], null, ['km' => (int) $odo - (int) $b['odo_start'], 'incident' => $incident !== '']);
            if (!$b['mine'] && $b['policy']['confirm_required']) {
                $this->app->notifier()->notify((int) $b['requester_id'], 'vehicles.confirm', 'vehicles.notify.confirm', ['ref' => (string) $b['ref']], '/vehicles/b/' . $pid, 'vh.cf:' . $pid);
            }
        });
    }

    public function confirm(Authorizer $az, string $pid): void
    {
        $b = $this->booking($az, $pid);
        if (!$b['can_confirm']) {
            throw new BusinessException('vehicles.error.cannot_change', [], 409);
        }
        $this->app->db()->run("UPDATE vh_bookings SET confirmed_at = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ? AND confirmed_at IS NULL", [$this->stamp(), $this->stamp(), $b['id']]);
        $this->event((int) $b['id'], 'confirmed', $az->userId, null);
        $this->app->audit()->record('vehicles.trip_confirmed', 'success', 'vh_booking', $pid, (int) $b['legal_entity_id']);
    }

    /** The requester did not turn up for an assigned trip (after its start) — the vehicle and driver are free again. */
    public function noShow(Authorizer $az, string $pid, string $reason): void
    {
        $b = $this->booking($az, $pid);
        $reason = trim($reason);
        if (!$b['can_no_show']) {
            throw new BusinessException('vehicles.error.cannot_change', [], 409);
        }
        if (mb_strlen($reason) > 300) {
            throw new ValidationException(['reason' => 'vehicles.error.reason']);
        }
        $this->app->db()->run(
            "UPDATE vh_bookings SET status = 'no_show', cancel_reason = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ? AND status = 'assigned'",
            [$reason === '' ? null : $reason, $this->stamp(), $az->userId, $b['id']]
        );
        $this->event((int) $b['id'], 'no_show', $az->userId, $reason === '' ? null : $reason);
        $this->app->audit()->record('vehicles.no_show', 'success', 'vh_booking', $pid, (int) $b['legal_entity_id'], $reason === '' ? null : $reason);
        $this->app->notifier()->notify((int) $b['requester_id'], 'vehicles.change', 'vehicles.notify.no_show', ['ref' => (string) $b['ref']], '/vehicles/b/' . $pid);
        if ($b['driver_user'] !== null) {
            $this->app->notifier()->notify((int) $b['driver_user'], 'vehicles.driver', 'vehicles.notify.driver_removed', ['ref' => (string) $b['ref'], 'when' => substr((string) $b['starts_at'], 0, 16), 'plate' => (string) $b['plate']], '/vehicles');
        }
    }

    /**
     * Cancels: the requester before the trip starts (a request still waiting is withdrawn from the approval), the
     * workplace admin an approved / assigned one with a reason. An assigned driver is told; the vehicle is free again.
     */
    public function cancel(Authorizer $az, string $pid, string $reason): void
    {
        $b = $this->booking($az, $pid);
        $reason = trim($reason);
        if (!$b['can_cancel']) {
            throw new BusinessException('vehicles.error.cannot_change', [], 409);
        }
        if ((!$b['mine'] && mb_strlen($reason) < 3) || mb_strlen($reason) > 300) {
            throw new ValidationException(['reason' => 'vehicles.error.reason']);
        }
        $db = $this->app->db();
        $logged = false;
        if (
            in_array($b['status'], ['pending', 'draft'], true) && $b['workflow_id'] !== null
            && in_array((string) $db->scalar('SELECT status FROM workflow_instances WHERE public_id = ?', [$b['workflow_id']]), ['pending', 'returned', 'needs_attention'], true)
        ) {
            $this->app->workflow()->cancel((string) $b['workflow_id'], $az->userId, $reason === '' ? '-' : $reason); // → onClosed('cancelled') logs it
            $logged = $b['status'] === 'pending';
        }
        $db->run(
            "UPDATE vh_bookings SET status = 'cancelled', cancel_reason = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ? AND status IN ('draft','pending','approved','assigned','cancelled')",
            [$reason === '' ? null : $reason, $this->stamp(), $az->userId, $b['id']]
        );
        if (!$logged) {
            $this->event((int) $b['id'], 'cancelled', $az->userId, $reason === '' ? null : $reason);
        }
        $this->app->audit()->record('vehicles.cancelled', 'success', 'vh_booking', $pid, (int) $b['legal_entity_id'], $reason === '' ? null : $reason, ['by_admin' => !$b['mine'], 'was' => $b['status']]);
        $params = ['ref' => (string) $b['ref'], 'when' => substr((string) $b['starts_at'], 0, 16), 'plate' => (string) ($b['plate'] ?? '')];
        if (!$b['mine']) {
            $this->app->notifier()->notify((int) $b['requester_id'], 'vehicles.change', 'vehicles.notify.cancelled', $params, '/vehicles/b/' . $pid);
        }
        if ($b['status'] === 'assigned' && $b['driver_user'] !== null) {
            $this->app->notifier()->notify((int) $b['driver_user'], 'vehicles.driver', 'vehicles.notify.driver_removed', $params, '/vehicles');
        }
    }

    // ---- lists and report ----------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> my requests (latest first) */
    public function mine(Authorizer $az): array
    {
        return $this->app->db()->all(
            'SELECT b.public_id, b.ref, b.status, b.starts_at, b.ends_at, b.to_name, b.purpose, b.confirmed_at, v.plate, l.code AS le_code FROM vh_bookings b JOIN legal_entities l ON l.id = b.legal_entity_id
               LEFT JOIN vh_vehicles v ON v.id = b.vehicle_id WHERE b.requester_id = ? ORDER BY b.starts_at DESC LIMIT 200',
            [$az->userId]
        );
    }

    /** @return list<array<string, mixed>> trips I drive (assigned, under way, and the last month done) */
    public function driverJobs(Authorizer $az): array
    {
        return $this->app->db()->all(
            "SELECT b.public_id, b.ref, b.status, b.starts_at, b.ends_at, b.from_name, b.to_name, b.passenger_count, v.plate FROM vh_bookings b JOIN vh_drivers d ON d.id = b.driver_id LEFT JOIN vh_vehicles v ON v.id = b.vehicle_id
              WHERE d.user_id = ? AND (b.status IN ('assigned','in_progress') OR (b.status = 'completed' AND b.ends_at >= ?)) ORDER BY b.starts_at LIMIT 200",
            [$az->userId, $this->nowLocal()->modify('-31 days')->format('Y-m-d H:i:s')]
        );
    }

    /**
     * The admin's board: waiting for a vehicle, assigned and under way, waiting for approval, recently done.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function board(Authorizer $az, int $le): array
    {
        $this->require($az, 'vehicles.manage', $le);
        $sql = 'SELECT b.public_id, b.ref, b.status, b.starts_at, b.ends_at, b.to_name, b.passenger_count, b.est_km, b.confirmed_at, s.code AS site_code, v.plate, d.name AS driver_name, b.self_drive,
                       COALESCE(u.display_name, u.username) AS requester_name
                  FROM vh_bookings b JOIN sites s ON s.id = b.site_id JOIN users u ON u.id = b.requester_id LEFT JOIN vh_vehicles v ON v.id = b.vehicle_id LEFT JOIN vh_drivers d ON d.id = b.driver_id
                 WHERE b.legal_entity_id = ? AND ';
        $db = $this->app->db();
        $since = $this->nowLocal()->modify('-14 days')->format('Y-m-d H:i:s');
        return [
            'to_assign' => $db->all($sql . "b.status = 'approved' ORDER BY b.starts_at", [$le]),
            'active' => $db->all($sql . "b.status IN ('assigned','in_progress') ORDER BY b.starts_at", [$le]),
            'pending' => $db->all($sql . "b.status = 'pending' ORDER BY b.starts_at", [$le]),
            'done' => $db->all($sql . "b.status IN ('completed','no_show','cancelled','rejected') AND b.ends_at >= ? ORDER BY b.ends_at DESC LIMIT 100", [$le, $since]),
        ];
    }

    /**
     * A month of trips: per vehicle (trips, km by odometer, fuel, costs) and per driver (trips, hours driven), with
     * the requests by status. Numbers only — no places or passengers.
     *
     * @return array{vehicles: list<array<string, mixed>>, drivers: list<array<string, mixed>>, statuses: array<string, int>}
     */
    public function report(Authorizer $az, int $le, string $month): array
    {
        $this->require($az, 'vehicles.manage', $le);
        $first = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01') ?: throw new ValidationException(['month' => 'vehicles.error.date']);
        $a = $first->format('Y-m-d 00:00:00');
        $z = $first->modify('last day of this month')->format('Y-m-d 23:59:59');
        $db = $this->app->db();
        $vehicles = $db->all(
            "SELECT v.plate, v.name, COUNT(b.id) AS trips, COALESCE(SUM(b.odo_end - b.odo_start), 0) AS km, COALESCE(SUM(b.fuel_litres), 0) AS litres, COALESCE(SUM(b.fuel_cost), 0) AS fuel,
                    COALESCE(SUM(b.other_cost), 0) AS other, SUM(b.incident IS NOT NULL) AS incidents
               FROM vh_vehicles v LEFT JOIN vh_bookings b ON b.vehicle_id = v.id AND b.status = 'completed' AND b.trip_started_at BETWEEN ? AND ?
              WHERE v.legal_entity_id = ? GROUP BY v.id ORDER BY v.plate",
            [$a, $z, $le]
        );
        $drivers = $db->all(
            "SELECT d.name, COUNT(b.id) AS trips, COALESCE(SUM(TIMESTAMPDIFF(MINUTE, b.trip_started_at, b.trip_ended_at)), 0) AS minutes, COALESCE(SUM(b.odo_end - b.odo_start), 0) AS km
               FROM vh_drivers d LEFT JOIN vh_bookings b ON b.driver_id = d.id AND b.status = 'completed' AND b.trip_started_at BETWEEN ? AND ?
              WHERE d.legal_entity_id = ? GROUP BY d.id ORDER BY d.name",
            [$a, $z, $le]
        );
        $statuses = [];
        foreach ($db->all('SELECT status, COUNT(*) AS n FROM vh_bookings WHERE legal_entity_id = ? AND starts_at BETWEEN ? AND ? GROUP BY status', [$le, $a, $z]) as $r) {
            $statuses[(string) $r['status']] = (int) $r['n'];
        }
        return ['vehicles' => $vehicles, 'drivers' => $drivers, 'statuses' => $statuses];
    }

    /** @return array{bytes: string, name: string} */
    public function export(Authorizer $az, int $le, string $month): array
    {
        $r = $this->report($az, $le, $month);
        $t = $this->app->translator();
        $code = (string) $this->app->db()->scalar('SELECT code FROM legal_entities WHERE id = ?', [$le]);
        $money = static fn (mixed $v): string => Money::format(Money::fromDb($v), 2, false);
        $bytes = Xlsx::write([
            'vehicles' => [
                'headers' => [$t->get('vehicles.col.plate'), $t->get('vehicles.col.vehicle'), $t->get('vehicles.col.trips'), $t->get('vehicles.col.km'), $t->get('vehicles.col.litres'), $t->get('vehicles.col.fuel'), $t->get('vehicles.col.other'), $t->get('vehicles.col.incidents')],
                'rows' => array_map(static fn (array $x): array => [(string) $x['plate'], (string) $x['name'], (string) $x['trips'], (string) $x['km'], (string) $x['litres'], $money($x['fuel']), $money($x['other']), (string) (int) $x['incidents']], $r['vehicles']),
                'numeric' => [2, 3, 4, 5, 6, 7], 'widths' => [14, 30, 10, 12, 12, 14, 14, 12],
            ],
            'drivers' => [
                'headers' => [$t->get('vehicles.col.driver'), $t->get('vehicles.col.trips'), $t->get('vehicles.col.hours'), $t->get('vehicles.col.km')],
                'rows' => array_map(static fn (array $x): array => [(string) $x['name'], (string) $x['trips'], (string) round((int) $x['minutes'] / 60, 2), (string) $x['km']], $r['drivers']),
                'numeric' => [1, 2, 3], 'widths' => [30, 10, 12, 12],
            ],
        ], 'Blue OS vehicle use');
        $this->app->audit()->record('vehicles.report_exported', 'success', 'vh_report', $code . '-' . $month, $le, null, ['sha256' => hash('sha256', $bytes)]);
        return ['bytes' => $bytes, 'name' => 'vehicle-use-' . $code . '-' . $month . '.xlsx'];
    }

    /**
     * Location retention (company policy): finished bookings older than the kept days lose their places, pins, links
     * and passenger names; distances, odometer and costs stay for the report.
     */
    public function purge(): int
    {
        $db = $this->app->db();
        $n = 0;
        foreach ($db->all('SELECT legal_entity_id, location_keep_days FROM vh_policies WHERE location_keep_days IS NOT NULL') as $p) {
            $cut = $this->nowLocal()->modify('-' . (int) $p['location_keep_days'] . ' days')->format('Y-m-d H:i:s');
            $ids = array_map('intval', array_column($db->all(
                "SELECT id FROM vh_bookings WHERE legal_entity_id = ? AND status IN ('completed','cancelled','rejected','no_show') AND ends_at < ? AND location_purged_at IS NULL LIMIT 1000",
                [$p['legal_entity_id'], $cut]
            ), 'id'));
            foreach ($ids as $id) {
                $db->run(
                    "UPDATE vh_bookings SET from_name = '-', from_lat = NULL, from_lng = NULL, from_link = NULL, to_name = '-', to_lat = NULL, to_lng = NULL, to_link = NULL, note = NULL, location_purged_at = ? WHERE id = ?",
                    [$this->stamp(), $id]
                );
                $db->run("UPDATE vh_passengers SET name = '-', employee_id = NULL WHERE booking_id = ?", [$id]);
                $n++;
            }
        }
        return $n;
    }

    public static function purgeJob(App $app): string
    {
        return 'vehicles: ' . self::of($app)->purge();
    }

    // ---- helpers -------------------------------------------------------------------------------------------------------------

    private function event(int $bookingId, string $event, ?int $actor, ?string $note): void
    {
        $this->app->db()->run('INSERT INTO vh_events (booking_id, event, actor_id, note, created_at) VALUES (?, ?, ?, ?, ?)', [$bookingId, $event, $actor, $note === null ? null : mb_substr($note, 0, 300), $this->stamp()]);
    }

    /** @return list<int> people who manage vehicles in a company */
    private function admins(int $le): array
    {
        $out = [];
        foreach ($this->app->db()->all("SELECT DISTINCT a.user_id FROM user_role_assignments a JOIN users u ON u.id = a.user_id WHERE a.status = 'active' AND u.status = 'active'") as $r) {
            $az = new Authorizer($this->app->db(), $this->app->clock, (int) $r['user_id']);
            if ($az->scope('vehicles.manage')->coversLegalEntity($le)) {
                $out[] = (int) $r['user_id'];
            }
        }
        return $out;
    }
}
