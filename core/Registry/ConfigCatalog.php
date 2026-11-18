<?php

declare(strict_types=1);

namespace Blue\Core\Registry;

/**
 * Configuration DEFINITIONS shipped with the core (6.25.1 — definition ≠ value).
 * No business or legal VALUES are seeded here (P0-03 §14, DM-01): rates/limits are entered and approved by the
 * responsible owner. Brand/theme keys get no seeded value either — the UI falls back to the design-system defaults
 * that live in ui/tokens until someone saves a version.
 *
 * key => [category, data_type, unit, allowed scope levels, validation, risk, source_type, required]
 *
 * @phpstan-type Def array{0:string, 1:string, 2:?string, 3:string, 4:array<string, mixed>, 5:string, 6:string, 7:bool}
 */
final class ConfigCatalog
{
    /** @var array<string, array{0:string, 1:string, 2:?string, 3:string, 4:array<string, mixed>, 5:string, 6:string, 7:bool}> */
    public const DEFINITIONS = [
        'brand.app_name' => ['brand', 'string', null, 'organization', ['min' => 1, 'max' => 40], 'normal', 'BRAND', false],
        'brand.tagline' => ['brand', 'string', null, 'organization', ['max' => 80], 'normal', 'BRAND', false],
        'theme.preset' => ['theme', 'enum', null, 'organization', ['values' => ['steel', 'slate', 'teal', 'paper']], 'normal', 'BRAND', false],
        'theme.primary_color' => ['theme', 'color', null, 'organization', ['nullable' => true], 'normal', 'BRAND', false],
        'theme.menu_color' => ['theme', 'color', null, 'organization', ['nullable' => true], 'normal', 'BRAND', false],
        'theme.accent_color' => ['theme', 'color', null, 'organization', ['nullable' => true], 'normal', 'BRAND', false],
        'theme.background_color' => ['theme', 'color', null, 'organization', ['nullable' => true], 'normal', 'BRAND', false],
        'theme.default_mode' => ['theme', 'enum', null, 'organization', ['values' => ['light', 'dark', 'auto']], 'normal', 'BRAND', false],
        // 0.8.1 (DEC-68): what visitors who have not signed in see on Home and may use without an account (module codes)
        'guest.home_apps' => ['guest', 'json', null, 'organization', [], 'normal', 'COMPANY_POLICY', false],
        'guest.tools' => ['guest', 'json', null, 'organization', [], 'normal', 'COMPANY_POLICY', false],
        // 0.8.1: background pattern of the side bar and banner cards for the whole system (the dot grid — default since 0.8.2 — or sparkles ✦)
        'theme.pattern' => ['theme', 'enum', null, 'organization', ['values' => ['sparkles', 'dots']], 'normal', 'BRAND', false],
        // 0.6.1 — system font: a bundled font, or one imported from a link (stored copy id + family + the link it came from)
        'theme.font' => ['theme', 'enum', null, 'organization', ['values' => ['plex', 'sarabun', 'noto_sans_thai', 'prompt', 'kanit', 'anuphan', 'chakra_petch', 'bai_jamjuree', 'system', 'custom']], 'normal', 'BRAND', false],
        'theme.font_custom' => ['theme', 'string', null, 'organization', ['max' => 40], 'normal', 'BRAND', false],
        'theme.font_family' => ['theme', 'string', null, 'organization', ['max' => 80], 'normal', 'BRAND', false],
        'theme.font_url' => ['theme', 'string', null, 'organization', ['max' => 500], 'normal', 'BRAND', false],
        // phase 3B — brand files (document ids), document font (DEC-28), technical limits (fallbacks live in code)
        'brand.logo_document' => ['brand', 'string', null, 'organization', ['max' => 26], 'normal', 'BRAND', false],
        // 0.37.0: hide the system name (the logo grows) · logo colour on the navy menu areas (white / menu-accent silhouette)
        'brand.show_name' => ['brand', 'enum', null, 'organization', ['values' => ['show', 'hide']], 'normal', 'BRAND', false],
        // 0.37.0: which logo shipped with the system is used when none is uploaded — Blue (main, Main_logo_blue) or Blue OS (alternate)
        'brand.builtin_logo' => ['brand', 'enum', null, 'organization', ['values' => ['blue', 'blue_os']], 'normal', 'BRAND', false],
        'brand.logo_tint' => ['brand', 'enum', null, 'organization', ['values' => ['original', 'light', 'accent']], 'normal', 'BRAND', false],
        'brand.favicon_document' => ['brand', 'string', null, 'organization', ['max' => 26], 'normal', 'BRAND', false],
        'documents.font_family' => ['documents', 'string', null, 'organization,legal_entity', ['min' => 2, 'max' => 80], 'normal', 'COMPANY_POLICY', false],
        'documents.max_upload_mb' => ['documents', 'integer', 'MB', 'organization', ['min' => 1, 'max' => 100], 'normal', 'TECHNICAL', false],
        'documents.max_pdf_pages' => ['documents', 'integer', 'page', 'organization', ['min' => 1, 'max' => 5000], 'normal', 'TECHNICAL', false],
        'import.max_rows' => ['data', 'integer', 'row', 'organization', ['min' => 10, 'max' => 50000], 'normal', 'TECHNICAL', false],
        'export.max_rows' => ['data', 'integer', 'row', 'organization', ['min' => 100, 'max' => 100000], 'normal', 'TECHNICAL', false],
        // phase 3C — finance activation gate (DEC-47/50), numbering patterns, payment import policy, backup schedule (DEC-39 technical defaults in code)
        'finance.activation' => ['finance', 'enum', null, 'organization,legal_entity', ['values' => ['demo_only', 'live']], 'high', 'COMPANY_POLICY', false],
        'finance.journal_number_pattern' => ['finance', 'string', null, 'organization,legal_entity', ['min' => 8, 'max' => 40], 'high', 'COMPANY_POLICY', false],
        'payment.batch_number_pattern' => ['payment', 'string', null, 'organization,legal_entity', ['min' => 8, 'max' => 40], 'high', 'COMPANY_POLICY', false],
        'payment.manual_import' => ['payment', 'enum', null, 'organization,legal_entity', ['values' => ['none', 'ap_claim', 'all']], 'high', 'COMPANY_POLICY', false],
        'backup.interval_hours' => ['backup', 'integer', 'hour', 'organization', ['min' => 1, 'max' => 168], 'normal', 'TECHNICAL', false],
        'backup.max_age_hours' => ['backup', 'integer', 'hour', 'organization', ['min' => 1, 'max' => 336], 'normal', 'TECHNICAL', false],
        'backup.keep_days' => ['backup', 'integer', 'day', 'organization', ['min' => 1, 'max' => 3650], 'normal', 'TECHNICAL', false],
        'backup.keep_monthly' => ['backup', 'integer', 'month', 'organization', ['min' => 0, 'max' => 120], 'normal', 'TECHNICAL', false],
        'backup.include_files' => ['backup', 'enum', null, 'organization', ['values' => ['yes', 'no']], 'normal', 'TECHNICAL', false],
        'backup.step_seconds' => ['backup', 'integer', 'second', 'organization', ['min' => 5, 'max' => 600], 'normal', 'TECHNICAL', false],
        // phase 5 gate (DEC-82): days to answer a data-subject request — set by Legal, no default
        'privacy.dsr_response_days' => ['privacy', 'integer', 'day', 'organization', ['min' => 1, 'max' => 365], 'high', 'LAW', false],
    ];

    /**
     * Demo-only definitions installed with DEC-34 demo data so the maker-checker flow can be tried.
     * Values are not seeded; they are clearly marked DEMO and never used by any calculation.
     *
     * @var array<string, array{0:string, 1:string, 2:?string, 3:string, 4:array<string, mixed>, 5:string, 6:string, 7:bool}>
     */
    public const DEMO_DEFINITIONS = [
        'demo.meal_allowance_per_day' => ['demo', 'decimal', 'THB', 'organization,legal_entity,site', ['min' => '0', 'max' => '100000', 'precision' => 2], 'high', 'DEMO', true],
        'demo.approval_reminder_hours' => ['demo', 'integer', 'hour', 'organization,legal_entity', ['min' => 1, 'max' => 720], 'normal', 'DEMO', false],
    ];
}
