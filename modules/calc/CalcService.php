<?php

declare(strict_types=1);

namespace Blue\Modules\Calc;

use DateTimeImmutable;
use DateTimeZone;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;

/**
 * M26 legal calculators: find the rate table in force on the reference date (Config Registry — effective-dated,
 * approved by a second person, source reference required), run CalcEngine, optionally share the result for a few days.
 */
final class CalcService
{
    public const DEFAULT_SHARE_DAYS = 7;

    public function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    /**
     * @return array{state: string, rule?: array<string, mixed>, version?: string, effective_from?: string, source?: ?string, scope?: string, decided_at?: ?string}
     *         state = ok | missing | invalid
     */
    public function rule(string $type, ?int $legalEntityId, DateTimeImmutable $at): array
    {
        $r = $this->app->registry()->resolveOrNull(CalcRules::KEYS[$type], $legalEntityId, null, $at);
        if ($r === null) {
            return ['state' => 'missing'];
        }
        [$rule] = CalcRules::validate($type, is_array($r->value) ? $r->value : []);
        if ($rule === null) {
            return ['state' => 'invalid', 'version' => $r->versionPublicId];
        }
        $v = $this->app->db()->one('SELECT source_reference, decided_at FROM config_versions WHERE id = ?', [$r->versionId]);
        return ['state' => 'ok', 'rule' => $rule, 'version' => $r->versionPublicId, 'effective_from' => $r->effectiveFrom,
            'source' => $v['source_reference'] ?? null, 'scope' => $r->sourceScope, 'decided_at' => $v['decided_at'] ?? null];
    }

    /**
     * @param array<string, mixed> $in calculator input + "at" (reference date, Y-m-d)
     * @return array{rule: array<string, mixed>, result: ?array{outputs: array<string, string>, steps: list<array{key: string, p: array<string, string>, v: string}>}, at: string}
     */
    public function calculate(Authorizer $az, string $type, array $in, ?int $legalEntityId): array
    {
        $this->guard($az);
        if (!in_array($type, CalcRules::TYPES, true) && $type !== 'retirement') {
            throw HttpException::notFound();
        }
        $at = $this->referenceDate((string) ($in['at'] ?? ''));
        $rule = $this->rule($type === 'retirement' ? 'severance' : $type, $legalEntityId, $at->setTimezone(new DateTimeZone('UTC')));
        if ($rule['state'] !== 'ok') {
            return ['rule' => $rule, 'result' => null, 'at' => $at->format('Y-m-d')];
        }
        $result = match ($type) {
            'sso' => CalcEngine::sso($rule['rule'], $in),
            'ewf' => CalcEngine::ewf($rule['rule'], $in),
            'pit' => CalcEngine::pit($rule['rule'], $in),
            'prize' => CalcEngine::prize($rule['rule'], $in),
            default => CalcEngine::severance($rule['rule'], $in),
        };
        return ['rule' => $rule, 'result' => $result, 'at' => $at->format('Y-m-d')];
    }

    /**
     * Shares a result (optional): numbers only, no names; the link needs sign-in and expires.
     *
     * @param array<string, mixed> $inputs
     * @param array<string, mixed> $result
     */
    public function share(Authorizer $az, string $type, array $inputs, array $result, ?string $ruleVersion, ?int $legalEntityId): string
    {
        $this->guard($az);
        $days = $this->app->registry()->resolveOrNull('calc.share_days')?->value;
        $days = is_int($days) ? $days : self::DEFAULT_SHARE_DAYS;
        $token = self::token();
        $now = $this->app->clock->now();
        $this->app->db()->run(
            'INSERT INTO calc_shares (token, calculator, inputs_json, result_json, rule_version, legal_entity_id, owner_user_id, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$token, $type, json_encode($inputs, JSON_UNESCAPED_UNICODE), json_encode($result, JSON_UNESCAPED_UNICODE), $ruleVersion, $legalEntityId, $az->userId,
                $now->format('Y-m-d H:i:s.u'), $now->modify('+' . $days . ' days')->format('Y-m-d H:i:s.u')]
        );
        return $token;
    }

    /** @return array<string, mixed>|null null = unknown or expired (expired rows are removed on sight) */
    public function shared(Authorizer $az, string $token): ?array
    {
        $this->guard($az);
        $row = $this->app->db()->one('SELECT s.*, u.display_name AS owner_name FROM calc_shares s JOIN users u ON u.id = s.owner_user_id WHERE s.token = ?', [$token]);
        if ($row === null) {
            return null;
        }
        if ((string) $row['expires_at'] <= $this->app->clock->now()->format('Y-m-d H:i:s.u')) {
            $this->app->db()->run('DELETE FROM calc_shares WHERE id = ?', [$row['id']]);
            return null;
        }
        $row['inputs'] = (array) json_decode((string) $row['inputs_json'], true);
        $row['result'] = (array) json_decode((string) $row['result_json'], true);
        return $row;
    }

    /** @return list<array<string, mixed>> my shares that have not expired */
    public function myShares(Authorizer $az): array
    {
        $this->app->db()->run('DELETE FROM calc_shares WHERE expires_at <= ?', [$this->app->clock->now()->format('Y-m-d H:i:s.u')]);
        return $this->app->db()->all('SELECT token, calculator, created_at, expires_at FROM calc_shares WHERE owner_user_id = ? ORDER BY id DESC LIMIT 50', [$az->userId]);
    }

    public function unshare(Authorizer $az, string $token): void
    {
        $this->app->db()->run('DELETE FROM calc_shares WHERE token = ? AND owner_user_id = ?', [$token, $az->userId]);
    }

    // ---- rate tables (administrators) ----------------------------------------------------------------

    /**
     * Proposes a new version of a table and sends it for approval by another person (high-risk config).
     *
     * @param array<string, mixed> $in table fields
     */
    public function propose(Authorizer $az, string $type, array $in, string $from, string $source, string $reason, ?int $legalEntityId): string
    {
        if (!$az->can('calc.rates')) {
            throw HttpException::forbidden();
        }
        [$rule, $errors] = CalcRules::validate($type, $in);
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $this->tz());
        if ($d === false) {
            $errors['effective_from'] = 'calc.rule_error.date';
        }
        if (mb_strlen(trim($source)) < 5) {
            $errors['source_reference'] = 'calc.rule_error.source';
        }
        if (mb_strlen(trim($reason)) < 3) {
            $errors['reason'] = 'calc.rule_error.reason';
        }
        if ($errors !== [] || $rule === null || $d === false) {
            throw new ValidationException($errors);
        }
        $reg = $this->app->registry();
        [$level, $scopeId] = $legalEntityId !== null ? ['legal_entity', $legalEntityId] : ['organization', (int) $this->app->db()->scalar('SELECT id FROM organizations ORDER BY id LIMIT 1')];
        $pid = $reg->propose($az, CalcRules::KEYS[$type], $level, $scopeId, (string) json_encode($rule, JSON_UNESCAPED_UNICODE), $d, null, trim($reason), trim($source));
        $reg->submit($az, $pid);
        return $pid;
    }

    /** @return list<array<string, mixed>> versions of a table (newest first) */
    public function versions(string $type): array
    {
        return $this->app->db()->all(
            "SELECT v.public_id, v.status, v.scope_level, v.scope_id, v.effective_from, v.source_reference, v.value_json, v.created_at, v.decided_at,
                    p.display_name AS proposed_name, d.display_name AS decided_name
               FROM config_versions v JOIN config_definitions c ON c.id = v.definition_id
               LEFT JOIN users p ON p.id = v.proposed_by LEFT JOIN users d ON d.id = v.decided_by
              WHERE c.config_key = ? ORDER BY v.id DESC LIMIT 20",
            [CalcRules::KEYS[$type]]
        );
    }

    public function referenceDate(string $raw): DateTimeImmutable
    {
        if ($raw === '') {
            return $this->app->clock->now()->setTimezone($this->tz());
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $this->tz());
        if ($d === false || (int) $d->format('Y') < 2000 || (int) $d->format('Y') > 2100) {
            throw new ValidationException(['at' => 'calc.error.date']);
        }
        return $d->setTime(12, 0);
    }

    private function guard(Authorizer $az): void
    {
        if (!$az->can('calc.use')) {
            throw HttpException::forbidden();
        }
    }

    private function tz(): DateTimeZone
    {
        return new DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'));
    }

    private static function token(): string
    {
        $a = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $t = '';
        for ($i = 0; $i < 12; $i++) {
            $t .= $a[random_int(0, 61)];
        }
        return $t;
    }
}
