<?php

return [
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

    // EGS registry: one entry today (default), more later without code changes.
    // Everything EGS-scoped (credentials, ICV, PIH, certificate state) is resolved
    // through EgsRegistry::for($egsIdentifier) — never read directly by code.
    'egs' => [
        env('ZATCA_EGS_IDENTIFIER', 'default') => [
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

            // Displayed by the admin ZATCA Settings screen. Not yet consumed by
            // invoice generation — VAT reaches the XML per-line via
            // ZatcaInvoiceLine::vatRate, built by the host app's
            // InvoiceSourceContract implementation (not written yet).
            'vat_percentage' => (float) env('ZATCA_VAT_PERCENTAGE', 15),

            // Structured national address. Maps 1:1 onto the seller's
            // cac:PostalAddress in the invoice XML; joined into one line for the
            // CSR's free-text csr.location.address.
            'street' => env('ZATCA_STREET'),
            'district' => env('ZATCA_DISTRICT'),
            'building_number' => env('ZATCA_BUILDING_NUMBER'),
            'additional_number' => env('ZATCA_ADDITIONAL_NUMBER'),
            'city' => env('ZATCA_CITY'),
            'postal_code' => env('ZATCA_POSTAL_CODE'),
        ],
    ],

    // Supported package/SDK/API-version combinations. See docs/COMPATIBILITY.md.
    'compatibility' => [
        ['api_version' => 'V2', 'sdk_version' => '3.4.8', 'ubl_version' => '2.1'],
    ],
];
