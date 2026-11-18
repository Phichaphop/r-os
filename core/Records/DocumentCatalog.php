<?php

declare(strict_types=1);

namespace Blue\Core\Records;

/**
 * Document classes shipped with the core (6.17.1, 6.17.4 step 4 "classify"). Modules add their own classes from
 * their manifest later (e.g. medical certificates with classification R and their own permissions).
 *
 * code => [classification, view permission, manage permission, allowed types, max MB, encrypt at rest,
 *          record class (retention schedule key), retention trigger]
 *
 * Classification C and R are encrypted at rest (P0-04 §2). Brand assets are Public (shown on the sign-in page).
 * Confidential classes have their own permissions: the System Admin role does not get them (6.4 — no business data by role).
 *
 * @phpstan-type ClassDef array{0:string, 1:string, 2:string, 3:list<string>, 4:int, 5:bool, 6:?string, 7:string}
 */
final class DocumentCatalog
{
    /** @var array<string, array{0:string, 1:string, 2:string, 3:list<string>, 4:int, 5:bool, 6:?string, 7:string}> */
    public const CLASSES = [
        'core.general' => ['I', 'core.document.view', 'core.document.manage', ['pdf', 'png', 'jpeg', 'webp', 'xlsx', 'docx', 'pptx', 'csv', 'txt'], 20, false, 'general_document', 'finalized'],
        'core.confidential' => ['C', 'core.document.view_confidential', 'core.document.manage_confidential', ['pdf', 'png', 'jpeg', 'webp', 'xlsx', 'docx', 'csv', 'txt'], 20, true, 'general_confidential', 'finalized'],
        'core.letter' => ['C', 'core.document.view_confidential', 'core.document.manage_confidential', ['html', 'pdf'], 5, true, 'issued_letter', 'finalized'],
        'core.brand_asset' => ['P', 'core.document.view', 'core.config.approve', ['png', 'jpeg', 'webp', 'ico'], 1, false, null, 'finalized'],
        // phase 3C — bank transfer files (DEC-31): Restricted, encrypted, only people who may download payment files see them
        'core.payment_file' => ['R', 'core.payment.download', 'core.payment.download', ['xlsx'], 20, true, 'accounting_tax', 'finalized'],
    ];

    /** Retention record classes (04 §6.17.7). Durations are NOT here — they live in retention_rules and need approval. */
    public const RECORD_CLASSES = [
        'general_document', 'general_confidential', 'issued_letter', 'applicant_unsuccessful', 'employee_file', 'wage_time_records',
        'payroll_tax_sso', 'accounting_tax', 'medical_claims', 'raw_punch', 'gps_selfie', 'audit_log', 'notifications_chat', 'backup',
    ];

    /** Retention start events a rule may use. */
    public const TRIGGERS = ['finalized', 'created', 'related_closed', 'employment_ended', 'tax_year_end', 'fiscal_year_closed', 'payroll_period_closed'];

    /**
     * The AI draft retention schedule from P0-04 §6 (DEC-48). Seeded as DRAFT rules (is_ai_draft = 1) so the register is
     * not empty — a draft never schedules any disposal; Legal / the accountant must confirm (source reference) and a
     * second person must approve before a rule takes effect.
     *
     * record class => [trigger, months, action, note]
     *
     * @var array<string, array{0:string, 1:int, 2:string, 3:string}>
     */
    public const AI_DRAFT_RULES = [
        'applicant_unsuccessful' => ['related_closed', 12, 'review', 'AI draft (DEC-48): 1 year after the result is sent; talent pool longer only with consent. Not a legal figure.'],
        'employee_file' => ['employment_ended', 120, 'review', 'AI draft (DEC-48): 10 years after leaving, allowing for limitation periods — must be checked by Legal.'],
        'wage_time_records' => ['payroll_period_closed', 60, 'review', 'AI draft (DEC-48): 5 years from payment (secondary sources mention a 2-year legal minimum).'],
        'payroll_tax_sso' => ['tax_year_end', 60, 'review', 'AI draft (DEC-48): 5 years after the tax year (7 if Legal advises).'],
        'accounting_tax' => ['fiscal_year_closed', 60, 'review', 'AI draft (DEC-48): 5 years from closing the books; authorities may require longer.'],
        'medical_claims' => ['related_closed', 24, 'review', 'AI draft (DEC-48): 2 years after the leave/claim year — keep health data short.'],
        'raw_punch' => ['payroll_period_closed', 60, 'review', 'AI draft (DEC-48): 5 years (same as wage records).'],
        'gps_selfie' => ['payroll_period_closed', 12, 'anonymize', 'AI draft (DEC-48): 1 year, then delete photo/coordinates and keep only in/out-of-area status.'],
        'audit_log' => ['created', 24, 'review', 'AI draft (DEC-48): 2 years — security practice.'],
        'notifications_chat' => ['created', 12, 'destroy', 'AI draft (DEC-48): 1 year.'],
    ];
}
