<?php

// The primary EGS: the one B2C (Simplified) documents are signed under, and the one the
// seller identity and the admin settings belong to.
$zatcaPrimaryEgs = env('ZATCA_EGS_IDENTIFIER', 'default');

$zatcaPrimaryEgsConfig = [
    'environment' => env('ZATCA_ENVIRONMENT', 'sandbox'),
    'vat_number' => env('ZATCA_VAT_NUMBER'),
    // ZATCA_ORG_NAME is the *English* company legal name. It feeds both
    // csr.organization.name and (as fallback) the invoice RegistrationName.
    // Do not add a separate ZATCA_COMPANY_NAME_EN — the two would drift.
    'organization_name' => env('ZATCA_ORG_NAME'),
    // ZATCA defines csr.organization.unit.name as the branch name (or the
    // branch's 10-digit TIN for VAT-group members). This *is* the
    // "Branch / Platform Name" shown in the admin UI.
    'organization_unit_name' => env('ZATCA_ORG_UNIT_NAME'),
    'common_name' => env('ZATCA_CSR_COMMON_NAME'),
    'serial_number' => env('ZATCA_CSR_SERIAL_NUMBER'),
    // Per ZATCA's CSR spec this is the taxpayer's 15-digit VAT registration
    // number — it must never diverge from vat_number. zatca:health-check
    // asserts they match.
    'organization_identifier' => env('ZATCA_ORG_IDENTIFIER'),
    'country_code' => env('ZATCA_COUNTRY_CODE', 'SA'),
    'invoice_type' => env('ZATCA_INVOICE_TYPE', '0100'),
    // Legacy single-line address. Superseded by the structured fields
    // below; kept as the fallback for deployments that have not set them.
    'location_address' => env('ZATCA_LOCATION_ADDRESS'),
    'business_category' => env('ZATCA_BUSINESS_CATEGORY'),
    'commercial_registration_number' => env('ZATCA_CRN'),

    // Arabic company legal name — used for the invoice's
    // cac:PartyLegalEntity/cbc:RegistrationName. Never fed to the CSR:
    // non-ASCII in a CSR subject is a known ZATCA compliance-failure source.
    'company_name_ar' => env('ZATCA_COMPANY_NAME_AR'),

    // Structured national address. Maps 1:1 onto the seller's
    // cac:PostalAddress in the invoice XML; joined into one line for the
    // CSR's free-text csr.location.address.
    'street' => env('ZATCA_STREET'),
    'district' => env('ZATCA_DISTRICT'),
    'building_number' => env('ZATCA_BUILDING_NUMBER'),
    'additional_number' => env('ZATCA_ADDITIONAL_NUMBER'),
    'city' => env('ZATCA_CITY'),
    'postal_code' => env('ZATCA_POSTAL_CODE'),
];

// Optional second EGS for B2B (Standard) documents — its own CSR, certificate, ICV and
// hash chain. Present only when ZATCA_B2B_EGS_IDENTIFIER is set to an identifier other
// than the primary's. It inherits every seller field from the primary EGS (they are not
// overridable: one taxpayer, one identity) and overrides only the CSR/device fields.
// An unset CSR field or invoice type stays null and zatca:health-check reports it; the
// invoice type value is decided by gate G-02.
$zatcaB2bEgs = env('ZATCA_B2B_EGS_IDENTIFIER');

$zatcaEgs = [$zatcaPrimaryEgs => $zatcaPrimaryEgsConfig];

if (is_string($zatcaB2bEgs) && $zatcaB2bEgs !== '' && $zatcaB2bEgs !== $zatcaPrimaryEgs) {
    $zatcaEgs[$zatcaB2bEgs] = array_merge($zatcaPrimaryEgsConfig, [
        'environment' => env('ZATCA_B2B_ENVIRONMENT', $zatcaPrimaryEgsConfig['environment']),
        'organization_unit_name' => env('ZATCA_B2B_ORG_UNIT_NAME', $zatcaPrimaryEgsConfig['organization_unit_name']),
        'common_name' => env('ZATCA_B2B_CSR_COMMON_NAME'),
        'serial_number' => env('ZATCA_B2B_CSR_SERIAL_NUMBER'),
        'invoice_type' => env('ZATCA_B2B_INVOICE_TYPE'),
    ]);
}

return [
    // Global kill switch, read everywhere through Zatca\Support\ZatcaGate — never as a
    // raw config() call in application code.
    //
    // False stops every ZATCA side effect: no invoice rows are created, no ICV is
    // reserved, no PIH is updated, no XML is generated, the SDK is never invoked, no HTTP
    // request reaches ZATCA, and nothing is reported, retried, regenerated or completed.
    // Jobs already on the queue exit quietly instead of failing, and invoice statuses and
    // submission-attempt counters are left exactly as they were.
    //
    // Unaffected: payments, order fulfilment, your app's own local invoices (e.g. built by
    // an InvoiceBuilder class), and invoice emails. Onboarding and the read-only diagnostic
    // commands also keep working, so an EGS can still be prepared or repaired while the
    // switch is off.
    //
    // This value is config-cached. After changing it, run:
    //     php artisan optimize:clear
    //     php artisan queue:restart
    // Carried through raw, deliberately not cast here. ZatcaGate is the single place this
    // value is interpreted, so the "what counts as off" rule cannot drift between the two.
    // A plain (bool) cast here would in fact be a bug: `ZATCA_ENABLED=` with an empty value
    // arrives as '' and would cast to false, stopping invoicing by accident.
    // Only a recognisable false (false, "false", 0, "0", "off", "no") disables ZATCA.
    'enabled' => env('ZATCA_ENABLED', true),

    // B2B: Standard tax invoices for company buyers, cleared by ZATCA before issue.
    // Read only through ZatcaGate::b2bEnabled(); carried through raw like `enabled`.
    //
    // Fail-closed, the opposite of `enabled`: only an explicit true/1/on/yes turns it
    // on, and only while `enabled` is on too. Unset, empty or malformed means OFF,
    // because this switch starts a new behaviour rather than keeping an existing one.
    //
    // Turning it off stops NEW company sales only. Standard documents already signed
    // keep being cleared by the clearance sweep (decision D-19, "drain"); stopping every
    // ZATCA call is what `enabled` is for.
    'b2b' => [
        'enabled' => env('ZATCA_B2B_ENABLED', false),
        // As configured, even when ignored (equal to the primary) — zatca:health-check
        // reports that case.
        'egs_identifier' => $zatcaB2bEgs,
    ],

    // Independent version knobs — never infer one from another.
    'api_version' => env('ZATCA_API_VERSION', 'V2'),
    'ubl_version' => env('ZATCA_UBL_VERSION', '2.1'),
    'xml_profile_version' => env('ZATCA_XML_PROFILE_VERSION', 'V2'),

    'sdk' => [
        // Absolute path to the fatoora executable/bat shipped in ZACTA-SDK-JAVA/Apps.
        'executable' => env('ZATCA_SDK_EXECUTABLE'),
        // SDK root (contains Apps/, Configuration/, Data/) — used to derive relative config paths.
        'root' => env('ZATCA_SDK_ROOT'),
        // SDK release version this codebase is pinned to. Drives OutputParsers/V{sdk} folder selection.
        'version' => env('ZATCA_SDK_VERSION', '3.4.8'),
        // sha256 of the executable, recorded at onboarding, verified by zatca:health-check.
        'expected_fingerprint' => env('ZATCA_SDK_FINGERPRINT'),
        // Seconds before an SDK subprocess is killed.
        'process_timeout' => (int) env('ZATCA_SDK_PROCESS_TIMEOUT', 60),
    ],

    'http' => [
        // Per-ZATCA-environment base URLs. Override individually if ZATCA changes a host.
        'base_urls' => [
            'sandbox' => env('ZATCA_API_BASE_URL_SANDBOX', 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal'),
            'simulation' => env('ZATCA_API_BASE_URL_SIMULATION', 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation'),
            'production' => env('ZATCA_API_BASE_URL_PRODUCTION', 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core'),
        ],
        'timeout' => (int) env('ZATCA_TIMEOUT', 30),
        'accept_language' => env('ZATCA_ACCEPT_LANGUAGE', 'ar'),
    ],

    // Two distinct retry policies — never confuse the two:
    'retries' => [
        // GenerateZatcaInvoiceJob: signing/creation of the invoice itself (Laravel job-level retry).
        'generation' => [
            'tries' => (int) env('ZATCA_GENERATION_RETRY_TRIES', 5),
            'backoff_seconds' => [5, 30, 120, 600],
        ],
        // ReportZatcaInvoiceJob / RetryZatcaInvoiceJob: how many times a SIGNED invoice may be
        // re-submitted to ZATCA before moving to manual_review (never regenerates the invoice).
        //
        // Attempts are consumed at the pace `zatca:report-pending` runs on the scheduler.
        // At the documented every-five-minutes cadence, this budget is how much ZATCA
        // downtime survives without human intervention:
        //     12 attempts x 5 minutes = ~1 hour
        // Raise it if you want to sleep through a longer ZATCA maintenance window; once
        // the budget is spent the invoice waits in manual_review until someone runs
        // `zatca:requeue`. Nothing is lost either way — the signed invoice is untouched.
        'reporting' => [
            'max_attempts' => (int) env('ZATCA_REPORTING_RETRY_ATTEMPTS', 12),
        ],
    ],

    // Scheduler monitoring. zatca:report-pending (every 5 minutes on the README schedule)
    // records each run in the Laravel cache; zatca:status and zatca:health-check report a
    // sweep that has not run for this many minutes, i.e. a missing or broken cron entry.
    // Needs a cache store that persists between processes (not `array`).
    'scheduler' => [
        'stale_after_minutes' => (int) env('ZATCA_SCHEDULER_STALE_MINUTES', 30),
    ],

    // ZATCA requires a Simplified (B2C) document to be reported within 24 hours of issue.
    // zatca:status warns about unreported B2C documents older than this many hours
    // (1-23) and fails once one is past 24 hours.
    'reporting_deadline' => [
        'warn_after_hours' => (int) env('ZATCA_REPORTING_DEADLINE_WARN_HOURS', 20),
    ],

    'certificate' => [
        'alert_days' => [60, 30, 7],
        // Automatic certificate renewal is OFF by default. ZATCA's dedicated renewal contract
        // (endpoint, whether a new OTP is required, response shape) has not been verified against
        // an official document in this repository — see ZatcaConnectorInterface::renewProductionCsid().
        // Turning this on means `zatca:certificate:renew` (and any future scheduled renewal) will
        // call that method automatically. Leave this false until the real contract is confirmed;
        // until then, renewal must be triggered manually and reviewed per attempt.
        'auto_renewal_enabled' => (bool) env('ZATCA_AUTO_RENEWAL_ENABLED', false),
    ],

    // Credential encryption backend. Swap the binding in ZatcaServiceProvider for Vault/KMS.
    'credential_encrypter' => env('ZATCA_CREDENTIAL_ENCRYPTER', 'laravel'),

    // Queue connection/name used for invoice generation & reporting jobs.
    'queue' => [
        'connection' => env('ZATCA_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
        'queue' => env('ZATCA_QUEUE_NAME', 'zatca'),
    ],

    'submission_attempts' => [
        // How long to keep submission_attempts rows for successfully reported invoices.
        'prune_after_days' => (int) env('ZATCA_PRUNE_ATTEMPTS_AFTER_DAYS', 180),
    ],

    'sdk_files' => [
        // How long to keep leftover files under storage/app/private/zatca (SDK output copies,
        // and workspaces/temp key material orphaned by a killed process). Nothing reads them
        // after a call finishes — the signed invoice itself lives in the database and is never
        // pruned. Used by zatca:prune-sdk-files, which additionally refuses to touch anything
        // younger than one hour so an in-flight SDK call is never disturbed.
        'prune_after_days' => (int) env('ZATCA_PRUNE_SDK_FILES_AFTER_DAYS', 7),
    ],

    // The primary EGS (see $zatcaPrimaryEgs above). Resolve it with
    // EgsRegistry::primary(), never by position in 'egs'.
    'primary_egs' => $zatcaPrimaryEgs,

    // VAT defaults for NEW documents, applied by Zatca\Services\Tax\InvoiceCalculator.
    // Read only through Zatca\Support\VatSettings (config(), never env(), so config:cache
    // works). Carried through raw: VatSettings validates them and treats an empty value as
    // the default.
    //
    // percentage: the rate for standard-rated (S) items unless an item sets its own
    //   vatRate. Above 0, at most 100, at most two decimals. Zero-rated and exempt items
    //   are always 0%, whatever this says.
    // mode: what the prices your app supplies mean.
    //   inclusive — the price already contains VAT (115.00 → net 100.00 + VAT 15.00)
    //   exclusive — VAT is added on top     (100.00 → net 100.00 + VAT 15.00 = 115.00)
    //   An invoice may override it (ZatcaInvoiceInput::$priceMode).
    //
    // Changing either affects only documents created afterwards. A signed document keeps
    // its rates in its snapshot, and its credit notes and regenerations use those.
    'vat' => [
        'percentage' => env('ZATCA_VAT_PERCENTAGE', 15),
        'mode' => env('ZATCA_VAT_MODE', 'inclusive'),
    ],

    // EGS registry: the primary EGS, plus the B2B one when configured (see the top of
    // this file). Everything EGS-scoped (credentials, ICV, PIH, certificate state) is
    // resolved through EgsRegistry::for($egsIdentifier) — never read directly by code.
    'egs' => $zatcaEgs,

    // Supported package/SDK/API-version combinations. See docs/COMPATIBILITY.md.
    'compatibility' => [
        ['api_version' => 'V2', 'sdk_version' => '3.4.8', 'ubl_version' => '2.1'],
    ],

    // Optional built-in dashboard (onboarding, invoice history, six demo scenario buttons).
    // OFF by default. It has NO authentication, so it answers 404 unless enabled and 403
    // whenever APP_ENV or any EGS environment is production. Local/sandbox/simulation tool only.
    // See README, "Try it: built-in dashboard".
    'dashboard' => [
        'enabled' => env('ZATCA_DASHBOARD_ENABLED', false),
        'path' => env('ZATCA_DASHBOARD_PATH', 'zatca-dashboard'),
        // Add your own guards here if you expose it beyond localhost (e.g. 'auth', 'can:...').
        'middleware' => ['web'],
    ],
];
