<?php

declare(strict_types=1);

namespace Blue\Core\Workflow;

/**
 * Approval routes shipped with the core. They are only the FIRST version: administrators publish new versions
 * from the workflow page, and running requests keep the version they started with (6.30.4).
 *
 * Step: approver = {type: permission|role, value}, scope = subject (approver's data scope must cover the
 * subject's company) | any, sla_hours (null = no SLA), escalate_to = {type, value} | null.
 *
 * @phpstan-type Step array{approver: array{type: string, value: string}, scope: string, sla_hours: ?int, escalate_to: ?array{type: string, value: string}}
 */
final class WorkflowCatalog
{
    /** @var array<string, array{subject: string, steps: list<array<string, mixed>>}> */
    public const DEFINITIONS = [
        'core.config_version' => [
            'subject' => 'config_version',
            'steps' => [
                ['approver' => ['type' => 'permission', 'value' => 'core.config.approve'], 'scope' => 'subject', 'sla_hours' => 48, 'escalate_to' => ['type' => 'role', 'value' => 'SYS']],
            ],
        ],
        'core.access_request' => [
            'subject' => 'access_request',
            'steps' => [
                ['approver' => ['type' => 'permission', 'value' => 'core.user.grant'], 'scope' => 'subject', 'sla_hours' => 48, 'escalate_to' => null],
            ],
        ],
        'core.amendment' => [
            'subject' => 'amendment',
            'steps' => [
                ['approver' => ['type' => 'permission', 'value' => 'core.amendment.approve'], 'scope' => 'subject', 'sla_hours' => 48, 'escalate_to' => ['type' => 'role', 'value' => 'SYS']],
            ],
        ],
        'core.retention_rule' => [
            'subject' => 'retention_rule',
            'steps' => [
                ['approver' => ['type' => 'permission', 'value' => 'core.records.approve'], 'scope' => 'subject', 'sla_hours' => 72, 'escalate_to' => ['type' => 'role', 'value' => 'SYS']],
            ],
        ],
        'core.legal_hold_release' => [
            'subject' => 'legal_hold',
            'steps' => [
                ['approver' => ['type' => 'permission', 'value' => 'core.records.approve'], 'scope' => 'subject', 'sla_hours' => 72, 'escalate_to' => null],
            ],
        ],
        'core.disposition' => [
            'subject' => 'disposition',
            'steps' => [
                ['approver' => ['type' => 'permission', 'value' => 'core.records.approve'], 'scope' => 'subject', 'sla_hours' => 120, 'escalate_to' => null],
            ],
        ],
        // phase 3C — money: the checker must hold the business permission in the same company; no fallback to SYS
        'core.posting_rule' => [
            'subject' => 'posting_rule',
            'steps' => [
                ['approver' => ['type' => 'permission', 'value' => 'core.finance.approve'], 'scope' => 'subject', 'sla_hours' => 72, 'escalate_to' => null],
            ],
        ],
        'core.journal' => [
            'subject' => 'journal',
            'steps' => [
                ['approver' => ['type' => 'permission', 'value' => 'core.finance.approve'], 'scope' => 'subject', 'sla_hours' => 48, 'escalate_to' => null],
            ],
        ],
        'core.payment_batch' => [
            'subject' => 'payment_batch',
            'steps' => [
                ['approver' => ['type' => 'permission', 'value' => 'core.payment.approve'], 'scope' => 'subject', 'sla_hours' => 24, 'escalate_to' => null],
            ],
        ],
        'core.restore' => [
            'subject' => 'restore_request',
            'steps' => [
                ['approver' => ['type' => 'permission', 'value' => 'core.backup.restore_approve'], 'scope' => 'any', 'sla_hours' => 4, 'escalate_to' => null],
            ],
        ],
        'core.lock_reopen' => [
            'subject' => 'lock_reopen',
            'steps' => [
                ['approver' => ['type' => 'permission', 'value' => 'core.lock.manage'], 'scope' => 'subject', 'sla_hours' => 24, 'escalate_to' => ['type' => 'role', 'value' => 'SYS']],
            ],
        ],
    ];

    /**
     * Routes that must never fall back to the system administrator: money decisions belong to the business checker.
     * With no checker the request waits as "needs attention" (6.30.4) instead of reaching someone without the right.
     */
    public const NO_FALLBACK = ['core.posting_rule', 'core.journal', 'core.payment_batch'];

    /** Fallback when no approver can be found for a step (6.30.4 "no approver") — the process owner role. */
    public const FALLBACK = ['type' => 'role', 'value' => 'SYS'];
}
