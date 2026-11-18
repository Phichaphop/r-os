<?php

declare(strict_types=1);

namespace Blue\Modules\Eid;

use Blue\Core\App;
use Blue\Core\Support\Money;

/**
 * Company wallet rules — Config Registry key `eid.wallet_rules` (COMPANY_POLICY, high risk, DEC-71). Nothing is
 * shipped: the wallet is a CLOSED internal benefit balance (DEC-24 — no transfer between people, no cash-out, no
 * outside shops until Legal confirms the BOT rules), and its limits are the company's decision. HR / the wallet
 * admin proposes with the reference, a finance checker (or another wallet admin) approves. No rules = no top-ups.
 *
 * Value: { "currency": "THB", "max_topup": "1000.00", "max_balance": "3000.00", "low_balance": "100.00"|null, "purpose": "…" }
 */
final class WalletRules
{
    /**
     * @param array<string, mixed> $in
     * @return array{0: array<string, mixed>|null, 1: array<string, string>}
     */
    public static function validate(array $in): array
    {
        $errors = [];
        $cur = strtoupper(trim((string) ($in['currency'] ?? '')));
        if (preg_match('/^[A-Z]{3}$/', $cur) !== 1) {
            $errors['currency'] = 'eid.rules_error.currency';
        }
        $amt = static function (string $raw, bool $optional) {
            $raw = trim($raw);
            if ($raw === '' && $optional) {
                return null;
            }
            $u = Money::parse($raw, 2);
            return $u !== null && $u > 0 ? $u : false;
        };
        $top = $amt((string) ($in['max_topup'] ?? ''), false);
        $bal = $amt((string) ($in['max_balance'] ?? ''), false);
        $low = $amt((string) ($in['low_balance'] ?? ''), true);
        if ($top === false || $top === null) {
            $errors['max_topup'] = 'eid.rules_error.amount';
        }
        if ($bal === false || $bal === null) {
            $errors['max_balance'] = 'eid.rules_error.amount';
        }
        if ($low === false) {
            $errors['low_balance'] = 'eid.rules_error.amount';
        }
        if (is_int($top) && is_int($bal) && $top > $bal) {
            $errors['max_topup'] = 'eid.rules_error.topup_over_balance';
        }
        $purpose = trim((string) ($in['purpose'] ?? ''));
        if (mb_strlen($purpose) < 5 || mb_strlen($purpose) > 300) {
            $errors['purpose'] = 'eid.rules_error.purpose';
        }
        if ($errors !== [] || !is_int($top) || !is_int($bal)) {
            return [null, $errors];
        }
        return [['currency' => $cur, 'max_topup' => Money::format($top, 2, false), 'max_balance' => Money::format($bal, 2, false),
            'low_balance' => is_int($low) ? Money::format($low, 2, false) : null, 'purpose' => $purpose], []];
    }

    /** @return list<array{0: string, 1: string}> approval page rows */
    public static function describe(App $app, mixed $value): array
    {
        $t = $app->translator();
        $v = is_array($value) ? $value : [];
        $c = (string) ($v['currency'] ?? '');
        return [
            [$t->get('eid.rules.currency'), $c],
            [$t->get('eid.rules.max_topup'), (string) ($v['max_topup'] ?? '—') . ' ' . $c],
            [$t->get('eid.rules.max_balance'), (string) ($v['max_balance'] ?? '—') . ' ' . $c],
            [$t->get('eid.rules.low_balance'), ($v['low_balance'] ?? null) === null ? '—' : $v['low_balance'] . ' ' . $c],
            [$t->get('eid.rules.purpose'), (string) ($v['purpose'] ?? '')],
        ];
    }
}
