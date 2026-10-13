<?php

namespace Zatca\Enums;

/**
 * Why a Debit Note was issued. Unlike ZatcaCreditNoteType (full/partial), this is
 * not about how much of the original is affected — a Debit Note adds a charge and
 * affects none of it — but about what kind of charge it is, because each kind has
 * a different admin form and a different downstream consequence.
 */
enum ZatcaDebitNoteReason: string
{
    /** A course/service the student received that was not on the original invoice. */
    case AdditionalCourse = 'additional_course';

    /** The original was under-priced. Records the shortfall only; the original is never edited. */
    case PriceAdjustment = 'price_adjustment';

    /** VAT was under-charged. Records the VAT shortfall only; the original is never edited. */
    case VatAdjustment = 'vat_adjustment';

    /** Anything else. The admin must supply the description by hand. */
    case Other = 'other';

    /**
     * Whether an accepted note of this kind should grant the student access to a
     * course. Only Additional Course does — a price or VAT correction changes no
     * one's entitlement, and `other` is by definition unmodelled.
     */
    public function grantsCourseAccess(): bool
    {
        return $this === self::AdditionalCourse;
    }

    public function label(): string
    {
        return match ($this) {
            self::AdditionalCourse => 'additional course or service',
            self::PriceAdjustment => 'price adjustment',
            self::VatAdjustment => 'VAT adjustment',
            self::Other => 'other',
        };
    }
}
