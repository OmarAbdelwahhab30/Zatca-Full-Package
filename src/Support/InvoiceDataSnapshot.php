<?php

namespace Zatca\Support;

use Zatca\DTOs\ZatcaAllowanceCharge;
use Zatca\DTOs\BuyerAddress;
use Zatca\DTOs\BuyerOtherId;
use Zatca\DTOs\BuyerParty;
use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Enums\VatPriceMode;
use Zatca\Enums\ZatcaComplianceDocumentType;
use Zatca\Enums\ZatcaVatCategory;
use Zatca\Exceptions\ZatcaException;
use DateTimeImmutable;

/**
 * Freezes the business values behind one ZATCA document, and hands them back
 * unchanged later.
 *
 * The point is what it deliberately cannot do. Rebuilding a document's XML needs the
 * same ZatcaInvoiceData the original was built from, and the only alternative source
 * is the host's Transaction/Order — which may have moved since. Re-reading it would
 * let a regenerated document carry different amounts from the one it replaces, which
 * is not a regeneration but a silent re-pricing. This class exists so that never
 * happens: the values are captured once, at generation, and every later rebuild reads
 * them from here.
 *
 * Round-trip fidelity matters more than compactness. ZATCA rejects an invoice whose
 * lines do not reconcile with its document totals, so a snapshot that loses a cent on
 * the way through is worse than no snapshot at all.
 *
 * Business data only. No credential, key, certificate or token material is ever part
 * of ZatcaInvoiceData, so none can enter a snapshot — the sensitive-data guarantee
 * holds by construction rather than by filtering.
 */
class InvoiceDataSnapshot
{
    /**
     * Version tag written for a plain invoice (ZatcaInvoiceData::$documentType ===
     * Invoice). Read back by fromArray() so that if the DTO ever grows a field, an old
     * snapshot is recognised as old instead of being silently hydrated with defaults
     * that were never actually on the invoice.
     *
     * Kept at 1 and never bumped for invoices: every existing invoice snapshot, and
     * every one written from here on, stays byte-for-byte the same shape, so rollback
     * of the Credit Note feature is safe.
     */
    public const VERSION = 1;

    /**
     * Version written for an ADJUSTMENT NOTE — a Credit Note (381) or a Debit Note
     * (383). Adds document_type, billing_reference_id and adjustment_reason, none of
     * which a plain invoice ever needs to carry.
     *
     * Deliberately ONE version for both note kinds rather than a third version for
     * debit notes: the three extra fields are exactly the same three, carrying
     * exactly the same meaning, and `document_type` inside the snapshot is already
     * what distinguishes 381 from 383 on the way back out. A separate version number
     * would have to be understood by every reader for no additional information.
     *
     * VERSION_CREDIT_NOTE is kept as the canonical name it was introduced under, so
     * nothing that already references it breaks; VERSION_ADJUSTMENT_NOTE is the name
     * that describes what it now means.
     */
    public const VERSION_CREDIT_NOTE = 2;

    /** Preferred alias for VERSION_CREDIT_NOTE — same value, accurate name. */
    public const VERSION_ADJUSTMENT_NOTE = self::VERSION_CREDIT_NOTE;

    /**
     * Version written for every STANDARD (B2B) document — invoice, credit note or debit
     * note. Always carries document_type, the structured buyer and the supply date, plus
     * the two note fields for notes. Versions 1 and 2 stay Simplified-only and their
     * bytes are unchanged; a version-3 snapshot with a Simplified type (or a version-2 one
     * with a Standard type) is refused rather than guessed at.
     */
    public const VERSION_STANDARD = 3;

    /** @return array<string, mixed> */
    public static function toArray(ZatcaInvoiceData $data): array
    {
        // True for both 381 and 383 — requiresBillingReference() is exactly the
        // "is an adjustment note" test, and reusing it means a future note type
        // cannot be forgotten here.
        $isAdjustmentNote = $data->documentType->requiresBillingReference();
        $isStandard = $data->documentType->isStandard();

        if ($isStandard && $data->buyer === null) {
            throw new ZatcaException("A {$data->documentType->label()} [{$data->sourceReference}] needs a buyer; none was given.");
        }

        $snapshot = [
            'version' => match (true) {
                $isStandard => self::VERSION_STANDARD,
                $isAdjustmentNote => self::VERSION_ADJUSTMENT_NOTE,
                default => self::VERSION,
            },
            'egs_identifier' => $data->egsIdentifier,
            'source_reference' => $data->sourceReference,
            'buyer_name' => $data->buyerName,
            'buyer_vat_number' => $data->buyerVatNumber,
            // Full ISO-8601 including offset: the invoice's issue instant is a fiscal
            // value, and reconstructing it in a different timezone would change the XML.
            'issue_date_time' => $data->issueDateTime->format(DateTimeImmutable::ATOM),
            'currency' => $data->currency,
            'lines' => array_map(static fn (ZatcaInvoiceLine $line) => [
                'description' => $line->description,
                'quantity' => $line->quantity,
                // The NET unit price — the one in the XML.
                'unit_price' => $line->netUnitPrice,
                'vat_rate' => $line->vatRate,
                'line_total_excl_vat' => $line->lineTotalExclVat,
                'vat_amount' => $line->vatAmount,
            ] + self::vatCategoryFields($line->vatCategory, $line->vatExemptionReasonCode, $line->vatExemptionReason)
              + self::pricingFields($line), $data->lines),
            'total_excl_vat' => $data->totalExclVat,
            'total_vat' => $data->totalVat,
            'total_incl_vat' => $data->totalInclVat,
        ];

        // Omitted entirely when empty, so every snapshot written before this
        // field existed — and every plain invoice/note written after it —
        // stays byte-for-byte identical to before.
        if ($data->allowanceCharges !== []) {
            $snapshot['allowance_charges'] = array_map(static fn (ZatcaAllowanceCharge $allowance) => [
                'reason' => $allowance->reason,
                'amount_excl_vat' => $allowance->amountExclVat,
                'vat_rate' => $allowance->vatRate,
                'vat_amount' => $allowance->vatAmount,
                'pool_key' => $allowance->poolKey,
                'line_indexes' => $allowance->lineIndexes,
            ] + self::vatCategoryFields($allowance->vatCategory, $allowance->vatExemptionReasonCode, $allowance->vatExemptionReason), $data->allowanceCharges);
        }

        if ($isAdjustmentNote || $isStandard) {
            $snapshot['document_type'] = $data->documentType->value;
        }

        if ($isAdjustmentNote) {
            $snapshot['billing_reference_id'] = $data->billingReferenceId;
            $snapshot['adjustment_reason'] = $data->adjustmentReason;
        }

        // Standard only — a Simplified snapshot never carries these, whatever the DTO holds.
        if ($isStandard) {
            $snapshot['buyer'] = self::buyerToArray($data->buyer);
            $snapshot['supply_date'] = $data->supplyDate?->format(DateTimeImmutable::ATOM);
        }

        return $snapshot;
    }

    /** @return array<string, mixed> */
    private static function buyerToArray(BuyerParty $buyer): array
    {
        return [
            'registration_name' => $buyer->registrationName,
            'vat_number' => $buyer->vatNumber,
            'other_id' => $buyer->otherId === null ? null : [
                'scheme' => $buyer->otherId->scheme,
                'value' => $buyer->otherId->value,
            ],
            'address' => [
                'street' => $buyer->address->street,
                'building_number' => $buyer->address->buildingNumber,
                'district' => $buyer->address->district,
                'city' => $buyer->address->city,
                'postal_code' => $buyer->address->postalCode,
                'country_code' => $buyer->address->countryCode,
                'additional_number' => $buyer->address->additionalNumber,
            ],
        ];
    }

    private static function buyerFromArray(mixed $buyer): BuyerParty
    {
        if (! is_array($buyer) || empty($buyer['registration_name']) || ! is_array($buyer['address'] ?? null)) {
            throw new ZatcaException('Standard business snapshot is missing the buyer (registration name and address).');
        }

        $otherId = $buyer['other_id'] ?? null;
        $address = $buyer['address'];

        try {
            return new BuyerParty(
                registrationName: (string) $buyer['registration_name'],
                vatNumber: self::nullableString($buyer['vat_number'] ?? null),
                otherId: is_array($otherId) ? new BuyerOtherId((string) ($otherId['scheme'] ?? ''), (string) ($otherId['value'] ?? '')) : null,
                address: new BuyerAddress(
                    street: self::nullableString($address['street'] ?? null),
                    buildingNumber: self::nullableString($address['building_number'] ?? null),
                    district: self::nullableString($address['district'] ?? null),
                    city: self::nullableString($address['city'] ?? null),
                    postalCode: self::nullableString($address['postal_code'] ?? null),
                    countryCode: self::nullableString($address['country_code'] ?? null) ?? 'SA',
                    additionalNumber: self::nullableString($address['additional_number'] ?? null),
                ),
            );
        } catch (\InvalidArgumentException $e) {
            throw new ZatcaException('Standard business snapshot has an invalid buyer: '.$e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     *
     * @throws ZatcaException when the snapshot is absent or unusable — never falls back
     *         to the host source, because a rebuild from live data is exactly the
     *         outcome this class exists to prevent.
     */
    public static function fromArray(?array $snapshot): ZatcaInvoiceData
    {
        if (! is_array($snapshot) || $snapshot === []) {
            throw new ZatcaException(
                'No business snapshot recorded for this ZATCA document, so it cannot be regenerated. '
                .'Snapshots are captured at generation time and are never backfilled: reconstructing one '
                .'from the stored XML would be inventing history.'
            );
        }

        $version = (int) ($snapshot['version'] ?? 0);

        if (! in_array($version, [self::VERSION, self::VERSION_ADJUSTMENT_NOTE, self::VERSION_STANDARD], true)) {
            throw new ZatcaException(
                "Business snapshot version [{$version}] is not a version this build understands ("
                .self::VERSION.', '.self::VERSION_ADJUSTMENT_NOTE.' or '.self::VERSION_STANDARD.'). Regenerating from it could produce a '
                .'document that misrepresents the sale.'
            );
        }

        foreach (['egs_identifier', 'source_reference', 'issue_date_time', 'currency', 'lines'] as $required) {
            if (! array_key_exists($required, $snapshot)) {
                throw new ZatcaException("Business snapshot is missing the required field [{$required}].");
            }
        }

        $documentType = ZatcaComplianceDocumentType::Invoice;

        if ($version !== self::VERSION) {
            $documentType = ZatcaComplianceDocumentType::tryFrom((string) ($snapshot['document_type'] ?? ''))
                ?? throw new ZatcaException('Business snapshot has a missing or unknown document_type.');

            // Version and type must agree: v2 is Simplified notes only, v3 Standard only.
            if ($documentType->isStandard() !== ($version === self::VERSION_STANDARD)) {
                throw new ZatcaException("Business snapshot version [{$version}] cannot hold a [{$documentType->value}].");
            }
        }

        if ($documentType->requiresBillingReference()) {
            foreach (['document_type', 'billing_reference_id', 'adjustment_reason'] as $required) {
                if (empty($snapshot[$required])) {
                    throw new ZatcaException("Adjustment note business snapshot is missing the required field [{$required}].");
                }
            }
        }

        $buyer = $version === self::VERSION_STANDARD ? self::buyerFromArray($snapshot['buyer'] ?? null) : null;
        $supplyDate = $version === self::VERSION_STANDARD && ! empty($snapshot['supply_date'])
            ? new DateTimeImmutable((string) $snapshot['supply_date'])
            : null;

        if (! is_array($snapshot['lines']) || $snapshot['lines'] === []) {
            throw new ZatcaException('Business snapshot contains no invoice lines.');
        }

        $lines = [];

        foreach ($snapshot['lines'] as $line) {
            if (! is_array($line)) {
                throw new ZatcaException('Business snapshot contains a malformed invoice line.');
            }

            $lines[] = new ZatcaInvoiceLine(
                description: (string) ($line['description'] ?? ''),
                quantity: (int) ($line['quantity'] ?? 0),
                netUnitPrice: (float) ($line['unit_price'] ?? 0),
                vatRate: (float) ($line['vat_rate'] ?? 0),
                lineTotalExclVat: (float) ($line['line_total_excl_vat'] ?? 0),
                vatAmount: (float) ($line['vat_amount'] ?? 0),
                vatCategory: self::vatCategory($line),
                vatExemptionReasonCode: self::nullableString($line['vat_exemption_reason_code'] ?? null),
                vatExemptionReason: self::nullableString($line['vat_exemption_reason'] ?? null),
                suppliedPrice: self::nullableString($line['supplied_price'] ?? null),
                priceMode: self::priceMode($line),
            );
        }

        $allowanceCharges = [];
        foreach ($snapshot['allowance_charges'] ?? [] as $allowance) {
            if (! is_array($allowance)) {
                throw new ZatcaException('Business snapshot contains a malformed allowance charge.');
            }

            $allowanceCharges[] = new ZatcaAllowanceCharge(
                reason: (string) ($allowance['reason'] ?? ''),
                amountExclVat: (float) ($allowance['amount_excl_vat'] ?? 0),
                vatRate: (float) ($allowance['vat_rate'] ?? 0),
                vatAmount: (float) ($allowance['vat_amount'] ?? 0),
                poolKey: self::nullableString($allowance['pool_key'] ?? null),
                lineIndexes: array_map('intval', $allowance['line_indexes'] ?? []),
                vatCategory: self::vatCategory($allowance),
                vatExemptionReasonCode: self::nullableString($allowance['vat_exemption_reason_code'] ?? null),
                vatExemptionReason: self::nullableString($allowance['vat_exemption_reason'] ?? null),
            );
        }

        return new ZatcaInvoiceData(
            egsIdentifier: (string) $snapshot['egs_identifier'],
            sourceReference: (string) $snapshot['source_reference'],
            buyerName: self::nullableString($snapshot['buyer_name'] ?? null),
            buyerVatNumber: self::nullableString($snapshot['buyer_vat_number'] ?? null),
            issueDateTime: new DateTimeImmutable((string) $snapshot['issue_date_time']),
            currency: (string) $snapshot['currency'],
            lines: $lines,
            totalExclVat: (float) ($snapshot['total_excl_vat'] ?? 0),
            totalVat: (float) ($snapshot['total_vat'] ?? 0),
            totalInclVat: (float) ($snapshot['total_incl_vat'] ?? 0),
            documentType: $documentType,
            billingReferenceId: self::nullableString($snapshot['billing_reference_id'] ?? null),
            adjustmentReason: self::nullableString($snapshot['adjustment_reason'] ?? null),
            allowanceCharges: $allowanceCharges,
            buyer: $buyer,
            supplyDate: $supplyDate,
        );
    }

    /** Whether a stored snapshot is present and this build can hydrate it. */
    public static function isUsable(?array $snapshot): bool
    {
        try {
            self::fromArray($snapshot);

            return true;
        } catch (ZatcaException) {
            return false;
        }
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * Written only for a non-standard category, so every standard-rated snapshot — all
     * of them before VAT categories existed — stays byte-for-byte identical.
     *
     * @return array<string, string|null>
     */
    private static function vatCategoryFields(ZatcaVatCategory $category, ?string $exemptionCode, ?string $exemptionReason): array
    {
        if ($category === ZatcaVatCategory::Standard) {
            return [];
        }

        return [
            'vat_category' => $category->value,
            'vat_exemption_reason_code' => $exemptionCode,
            'vat_exemption_reason' => $exemptionReason,
        ];
    }

    /**
     * The supplied price and its mode, written only when recorded, so lines built without
     * them (restated note lines, compliance samples) keep their existing shape. Frozen here
     * so that what the app supplied is part of the document's history; nothing ever
     * recalculates from it.
     *
     * @return array<string, string>
     */
    private static function pricingFields(ZatcaInvoiceLine $line): array
    {
        return array_filter([
            'supplied_price' => $line->suppliedPrice,
            'price_mode' => $line->priceMode?->value,
        ], static fn ($value) => $value !== null);
    }

    /** @param  array<string, mixed>  $row */
    private static function priceMode(array $row): ?VatPriceMode
    {
        $value = $row['price_mode'] ?? null;

        if ($value === null) {
            return null;
        }

        return VatPriceMode::tryFrom((string) $value)
            ?? throw new ZatcaException("Business snapshot contains an unknown price mode [{$value}].");
    }

    /** @param  array<string, mixed>  $row */
    private static function vatCategory(array $row): ZatcaVatCategory
    {
        $value = $row['vat_category'] ?? null;

        if ($value === null) {
            return ZatcaVatCategory::Standard;
        }

        return ZatcaVatCategory::tryFrom((string) $value)
            ?? throw new ZatcaException("Business snapshot contains an unknown VAT category [{$value}].");
    }
}
