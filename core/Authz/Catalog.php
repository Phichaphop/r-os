<?php

declare(strict_types=1);

namespace Blue\Core\Authz;

/**
 * Permission + standard role catalogue for the core platform (P0-04 §4.2–4.3).
 * Modules add their own permissions/roles through module.json from phase 3; RbacSync merges them.
 * Permission names: <module>.<resource>.<action>.
 */
final class Catalog
{
    /** @var array<string, 'normal'|'high'> */
    public const PERMISSIONS = [
        'core.org.view' => 'normal',
        'core.org.manage' => 'high',
        'core.user.view' => 'normal',
        'core.user.manage' => 'high',
        'core.user.grant' => 'high',
        'core.role.view' => 'normal',
        'core.audit.view' => 'high',
        'core.audit.export' => 'high',
        'core.system.status' => 'normal',
        'core.system.showcase' => 'normal',
        'core.system.mailbox' => 'normal',
        'core.system.update' => 'high',
        'core.config.view' => 'normal',
        'core.config.propose' => 'high',
        'core.config.approve' => 'high',
        'core.workflow.manage' => 'high',
        'core.lock.manage' => 'high',
        'core.amendment.approve' => 'high',
        // phase 3B — documents & records, templates, calendars, exports
        'core.document.view' => 'normal',
        'core.document.view_confidential' => 'high',
        'core.document.manage' => 'high',
        'core.document.manage_confidential' => 'high',
        'core.template.manage' => 'high',
        'core.template.issue' => 'normal',
        'core.records.manage' => 'high',
        'core.records.approve' => 'high',
        'core.calendar.view' => 'normal',
        'core.calendar.manage' => 'high',
        'core.user.export' => 'high',
        // phase 3C — finance core (minimal) + posting interface, payments, backup & restore, integrations
        'core.finance.view' => 'normal',
        'core.finance.manage' => 'high',
        'core.finance.rules' => 'high',
        'core.finance.approve' => 'high',
        'core.finance.close' => 'high',
        'core.payment.view' => 'normal',
        'core.payment.prepare' => 'high',
        'core.payment.approve' => 'high',
        'core.payment.download' => 'high',
        'core.payment.reconcile' => 'high',
        'core.backup.view' => 'normal',
        'core.backup.run' => 'high',
        'core.backup.download' => 'high',
        'core.backup.restore' => 'high',
        'core.backup.restore_approve' => 'high',
        'core.integration.view' => 'normal',
        'core.integration.manage' => 'high',
        'core.integration.credentials' => 'high',
        // phase 3U — module registry (switch modules on/off)
        'core.module.manage' => 'high',
        // phase 5 gate (DEC-82) — data-subject requests (privacy officer)
        'core.privacy.manage' => 'high',
    ];

    /**
     * code => [default scope, high risk (needs a second SYS to grant), requires MFA, permissions]
     * MFA (DEC-51): system admin, money approvers, and anyone with cross-company scope (checked at runtime).
     *
     * @var array<string, array{0:string, 1:bool, 2:bool, 3:list<string>}>
     */
    public const ROLES = [
        'EMP' => ['self', false, false, ['core.calendar.view']],
        'MGR' => ['team', false, false, ['core.calendar.view']],
        'HRA' => ['legal_entity', false, false, ['core.org.view', 'core.document.view', 'core.document.view_confidential', 'core.document.manage', 'core.document.manage_confidential', 'core.template.issue', 'core.calendar.view', 'core.calendar.manage']],
        'HRT' => ['legal_entity', false, false, ['core.org.view', 'core.calendar.view', 'core.calendar.manage']],
        'PYO' => ['legal_entity', false, false, ['core.org.view', 'core.payment.view', 'core.payment.prepare', 'core.payment.reconcile']],
        'PYA' => ['legal_entity', true, true, ['core.org.view', 'core.payment.view', 'core.payment.approve', 'core.payment.download']],
        'FNM' => ['legal_entity', false, false, ['core.org.view', 'core.calendar.view', 'core.finance.view', 'core.finance.manage', 'core.finance.rules',
            'core.payment.view', 'core.payment.prepare', 'core.payment.reconcile']],
        'FNC' => ['legal_entity', true, true, ['core.org.view', 'core.calendar.view', 'core.finance.view', 'core.finance.approve', 'core.finance.close', 'core.lock.manage',
            'core.payment.view', 'core.payment.approve', 'core.payment.download']],
        'CSH' => ['site', false, false, []],
        'POSS' => ['site', false, true, []],
        'INV' => ['site', false, false, []],
        'WAL' => ['legal_entity', true, false, []],
        'SYS' => ['organization', true, true, [
            'core.org.view', 'core.org.manage', 'core.user.view', 'core.user.manage', 'core.user.grant',
            'core.role.view', 'core.audit.view', 'core.system.status', 'core.system.showcase', 'core.system.mailbox',
            'core.system.update', 'core.config.view', 'core.config.propose', 'core.config.approve', 'core.workflow.manage',
            'core.lock.manage', 'core.amendment.approve',
            'core.document.view', 'core.document.manage', 'core.template.manage', 'core.records.manage', 'core.records.approve',
            'core.calendar.view', 'core.calendar.manage', 'core.user.export',
            'core.backup.view', 'core.backup.run', 'core.backup.download', 'core.backup.restore', 'core.backup.restore_approve',
            'core.integration.view', 'core.integration.manage', 'core.integration.credentials', 'core.module.manage',
        ]],
        'AUD' => ['organization', true, false, ['core.org.view', 'core.user.view', 'core.role.view', 'core.audit.view', 'core.audit.export', 'core.config.view',
            'core.document.view', 'core.document.view_confidential', 'core.calendar.view', 'core.user.export',
            'core.finance.view', 'core.payment.view', 'core.backup.view', 'core.integration.view']],
        'PRV' => ['organization', true, false, ['core.org.view', 'core.user.view', 'core.role.view', 'core.audit.view', 'core.audit.export',
            'core.document.view', 'core.document.view_confidential', 'core.records.manage', 'core.records.approve', 'core.privacy.manage']],
        // phase 5E-5 (DEC-80): executives — company figures on the executive dashboard (M46) only; no personal records
        'EXE' => ['legal_entity', false, false, ['core.org.view']],
        // phase 9A (DEC-89): workplace / general affairs — meeting rooms, lockers (and later vehicles, helpdesk); no personal records
        'WPA' => ['legal_entity', false, false, ['core.org.view', 'core.calendar.view']],
        // 0.37.0 (DEC-102): safety officer (จป.) — split from WPA; safety incidents and their follow-up (M39); no personal records
        'SFO' => ['legal_entity', false, false, ['core.org.view', 'core.calendar.view']],
        // 0.44.0 (DEC-111): complaint handlers (M16) — receive, triage, assign and investigate anonymous complaints; and the
        // alternate route for complaints about handlers or management (assign to an independent person / board member)
        'CMP' => ['legal_entity', false, false, ['core.org.view']],
        'CMX' => ['legal_entity', false, false, ['core.org.view']],
        // 0.49.0 (DEC-118 · Q-11): Payroll Checker — reviews approved payroll runs afterwards, one run at a time (payroll.review);
        // appointed later; may not also prepare or approve payroll in the same company
        'PYC' => ['legal_entity', true, true, ['core.org.view']],
    ];

    /** Roles a single person must not hold in the same legal entity (P0-04 §4.4) — reported as SoD conflicts. */
    public const CONFLICTING_ROLES = [
        ['PYO', 'PYA'],
        ['PYO', 'PYC'],
        ['PYA', 'PYC'],
        ['FNM', 'FNC'],
        ['WAL', 'FNC'],
    ];
}
