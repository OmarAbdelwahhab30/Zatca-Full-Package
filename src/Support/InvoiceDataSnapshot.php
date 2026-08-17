<?php

namespace Zatca\Support;

use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceLine;
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
     * Version tag on every snapshot written.
     *
     * Read back by fromArray() so that if the DTO ever grows a field, an old snapshot
     * is recognised as old instead of being silently hydrated with defaults that were
     * never actually on the invoice.
     */
    public const VERSION = 1;

    /** @return array<string, mixed> */
    public static function toArray(ZatcaInvoiceData $data): array
    {
        return [
            'version' => self::VERSION,
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
                'unit_price' => $line->unitPrice,
                'vat_rate' => $line->vatRate,
                'line_total_excl_vat' => $line->lineTotalExclVat,
                'vat_amount' => $line->vatAmount,
            ], $data->lines),
            'total_excl_vat' => $data->totalExclVat,
            'total_vat' => $data->totalVat,
            'total_incl_vat' => $data->totalInclVat,
        ];
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

        if ($version !== self::VERSION) {
            throw new ZatcaException(
                "Business snapshot version [{$version}] is not the version this build understands ("
                .self::VERSION.'). Regenerating from it could produce a document that misrepresents the sale.'
            );
        }

        foreach (['egs_identifier', 'source_reference', 'issue_date_time', 'currency', 'lines'] as $required) {
            if (! array_key_exists($required, $snapshot)) {
                throw new ZatcaException("Business snapshot is missing the required field [{$required}].");
            }
        }

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
                unitPrice: (float) ($line['unit_price'] ?? 0),
                vatRate: (float) ($line['vat_rate'] ?? 0),
                lineTotalExclVat: (float) ($line['line_total_excl_vat'] ?? 0),
                vatAmount: (float) ($line['vat_amount'] ?? 0),
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
}
