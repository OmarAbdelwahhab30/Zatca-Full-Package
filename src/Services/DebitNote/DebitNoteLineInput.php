<?php

namespace Zatca\Services\DebitNote;

/**
 * One line an admin is asking a Debit Note to add.
 *
 * This is the boundary between "what the host decided to charge" and "what the
 * package turns into a fiscal document". It exists because a Debit Note line,
 * unlike a Credit Note line, cannot be copied from anywhere: a Credit Note line
 * IS an original invoice line, so CreditNoteDataFactory only needs an index; a
 * Debit Note line is a brand-new charge that nothing in the original describes.
 *
 * The amounts are taken exactly as given and never re-derived here — same rule
 * the plain invoice path follows. InvoiceDataValidator is what decides whether
 * they reconcile, and it runs before an ICV is ever spent.
 *
 * Immutable, and host-agnostic: `courseId` is an opaque integer to this package.
 */
class DebitNoteLineInput
{
    /**
     * @param  string  $description  what appears on the document and in the XML.
     * @param  int  $quantity  always ≥ 1 (InvoiceDataValidator enforces it). Every Coursefer
     *   line is quantity 1 today, but nothing here assumes that.
     * @param  float  $unitPrice  per-unit amount excluding VAT.
     * @param  float  $vatRate  percentage, e.g. 15.0.
     * @param  float  $lineTotalExclVat  net for the whole line. May legitimately be 0.00 for a
     *   VAT Adjustment line, where the ONLY thing being charged is under-collected VAT.
     * @param  float  $vatAmount  VAT for the whole line.
     * @param  int|null  $originalLineIndex  informational back-reference to the original invoice
     *   line this adjusts (Price/VAT Adjustment). Null for Additional Course and Other. Nothing
     *   in this package or the host ever writes to the line it points at.
     * @param  int|null  $courseId  host course id, for an Additional Course line, so the host's
     *   acceptance listener can grant access without parsing the description.
     * @param  int|null  $sourceDebitNoteLineId  informational back-reference to the Additional
     *   Course line of an ACCEPTED earlier Debit Note this adjusts — for a course that was never
     *   on the original invoice. Mutually exclusive with $originalLineIndex; DebitNoteIssuer
     *   refuses a line that sets both, or one that names anything else.
     */
    public function __construct(
        public readonly string $description,
        public readonly int $quantity,
        public readonly float $unitPrice,
        public readonly float $vatRate,
        public readonly float $lineTotalExclVat,
        public readonly float $vatAmount,
        public readonly ?int $originalLineIndex = null,
        public readonly ?int $courseId = null,
        public readonly ?int $sourceDebitNoteLineId = null,
    ) {}

    public function lineTotalInclVat(): float
    {
        return round($this->lineTotalExclVat + $this->vatAmount, 2);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            description: (string) ($row['description'] ?? ''),
            quantity: (int) ($row['quantity'] ?? 1),
            unitPrice: round((float) ($row['unit_price'] ?? 0), 2),
            vatRate: (float) ($row['vat_rate'] ?? 0),
            lineTotalExclVat: round((float) ($row['net_amount'] ?? 0), 2),
            vatAmount: round((float) ($row['vat_amount'] ?? 0), 2),
            originalLineIndex: isset($row['original_line_index']) && $row['original_line_index'] !== null
                ? (int) $row['original_line_index']
                : null,
            courseId: isset($row['course_id']) && $row['course_id'] !== null ? (int) $row['course_id'] : null,
            sourceDebitNoteLineId: isset($row['source_debit_note_line_id']) && $row['source_debit_note_line_id'] !== null
                ? (int) $row['source_debit_note_line_id']
                : null,
        );
    }
}
