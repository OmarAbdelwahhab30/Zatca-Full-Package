<?php

namespace Zatca\Services\DebitNote;

use Zatca\Enums\ZatcaVatCategory;

/**
 * One line an admin is asking a Debit Note to add.
 *
 * This is the boundary between "what the host decided to charge" and "what the
 * package turns into a fiscal document". It exists because a Debit Note line,
 * unlike a Credit Note line, cannot be copied from anywhere: a Credit Note line
 * IS an original invoice line, so CreditNoteDataFactory only needs an index; a
 * Debit Note line is a brand-new charge that nothing in the original describes.
 *
 * Like an invoice item, it carries a price, not calculated amounts: the package
 * works out net, VAT and totals (InvoiceCalculator) in the configured price mode.
 * InvoiceDataValidator then checks the result before an ICV is ever spent.
 *
 * Tax treatment, when the line sets none: a line that adjusts an item keeps that
 * item's own frozen VAT category and rate (original invoice line, or the earlier
 * debit note line); any other line is standard-rated at the configured rate.
 *
 * Immutable, and host-agnostic: `itemId` is an opaque integer to this package (your
 * product, service or SKU id). `courseId` is its deprecated old name.
 */
class DebitNoteLineInput
{
    /** Your own product/service id for an Additional Item line, or null. */
    public readonly ?int $itemId;

    /** @deprecated Use $itemId. Always equal to it. */
    public readonly ?int $courseId;

    /**
     * @param  string  $description  what appears on the document and in the XML.
     * @param  int  $quantity  always ≥ 1.
     * @param  int|float|string  $price  unit price in the configured price mode (VAT-inclusive by
     *   default). For a VAT-only line (vatAdjustment()) it is the VAT amount itself.
     * @param  int|float|string|null  $vatRate  percentage for this line, e.g. 15; null = inherited
     *   from the adjusted item, or the configured default. Standard-rated lines only.
     * @param  int|null  $originalLineIndex  informational back-reference to the original invoice
     *   line this adjusts (Price/VAT Adjustment). Null for Additional Item and Other. Nothing
     *   in this package or the host ever writes to the line it points at.
     * @param  int|null  $sourceDebitNoteLineId  informational back-reference to the Additional
     *   Item line of an ACCEPTED earlier Debit Note this adjusts — for an item that was never
     *   on the original invoice. Mutually exclusive with $originalLineIndex; DebitNoteIssuer
     *   refuses a line that sets both, or one that names anything else.
     * @param  int|null  $itemId  your own product/service id, for an Additional Item line, so your
     *   ZatcaDebitNoteAccepted listener can deliver it without parsing the description. Stored
     *   in the `item_id` column.
     * @param  int|null  $courseId  deprecated alias of $itemId.
     * @param  ZatcaVatCategory|null  $vatCategory  null = inherited from the adjusted item, or S.
     * @param  string|null  $vatExemptionReasonCode  e.g. "VATEX-SA-32". Required for Z/E.
     * @param  string|null  $vatExemptionReason  the reason text. Required for Z/E.
     * @param  bool  $vatOnly  true for a VAT-only correction: net 0, VAT = $price. Use vatAdjustment().
     */
    public function __construct(
        public readonly string $description,
        public readonly int $quantity,
        public readonly int|float|string $price,
        public readonly int|float|string|null $vatRate = null,
        public readonly ?int $originalLineIndex = null,
        public readonly ?int $sourceDebitNoteLineId = null,
        ?int $itemId = null,
        ?int $courseId = null,
        public readonly ?ZatcaVatCategory $vatCategory = null,
        public readonly ?string $vatExemptionReasonCode = null,
        public readonly ?string $vatExemptionReason = null,
        public readonly bool $vatOnly = false,
    ) {
        if ($itemId !== null && $courseId !== null && $itemId !== $courseId) {
            throw new \InvalidArgumentException(
                "Give itemId or its deprecated alias courseId, not two different values ({$itemId} vs {$courseId})."
            );
        }

        $this->itemId = $itemId ?? $courseId;
        $this->courseId = $this->itemId;
    }

    /**
     * A VAT-only correction (Debit Note reason VAT Adjustment): charges $vatAmount of
     * under-collected VAT on an item, with no net amount. The rate is the adjusted item's
     * own, unless given.
     */
    public static function vatAdjustment(
        string $description,
        int|float|string $vatAmount,
        ?int $originalLineIndex = null,
        ?int $sourceDebitNoteLineId = null,
        int|float|string|null $vatRate = null,
    ): self {
        return new self(
            description: $description,
            quantity: 1,
            price: $vatAmount,
            vatRate: $vatRate,
            originalLineIndex: $originalLineIndex,
            sourceDebitNoteLineId: $sourceDebitNoteLineId,
            vatOnly: true,
        );
    }

    /**
     * From a request array: description, quantity, price, and optionally vat_rate,
     * vat_category, vat_exemption_reason_code, vat_exemption_reason, original_line_index,
     * source_debit_note_line_id, item_id (or course_id), vat_only.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $usesOldShape = array_key_exists('unit_price', $row) || array_key_exists('net_amount', $row) || array_key_exists('vat_amount', $row);

        if (! array_key_exists('price', $row) && $usesOldShape) {
            throw new \InvalidArgumentException(
                'DebitNoteLineInput::fromArray() no longer accepts unit_price/net_amount/vat_amount — '
                .'this line is now built from a single price in the configured price mode. Pass '
                .'"price" instead (and optionally "vat_rate"); the package computes net and VAT.'
            );
        }

        $itemId = $row['item_id'] ?? $row['course_id'] ?? null;
        $category = $row['vat_category'] ?? null;

        return new self(
            description: (string) ($row['description'] ?? ''),
            quantity: (int) ($row['quantity'] ?? 1),
            price: $row['price'] ?? 0,
            vatRate: isset($row['vat_rate']) && $row['vat_rate'] !== '' ? $row['vat_rate'] : null,
            originalLineIndex: isset($row['original_line_index']) ? (int) $row['original_line_index'] : null,
            sourceDebitNoteLineId: isset($row['source_debit_note_line_id']) ? (int) $row['source_debit_note_line_id'] : null,
            itemId: $itemId !== null ? (int) $itemId : null,
            vatCategory: $category === null || $category === '' ? null : ZatcaVatCategory::from((string) $category),
            vatExemptionReasonCode: $row['vat_exemption_reason_code'] ?? null,
            vatExemptionReason: $row['vat_exemption_reason'] ?? null,
            vatOnly: (bool) ($row['vat_only'] ?? false),
        );
    }
}
