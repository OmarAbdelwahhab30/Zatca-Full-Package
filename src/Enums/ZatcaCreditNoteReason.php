<?php

namespace Zatca\Enums;

/**
 * Why a Credit Note was issued — the counterpart of ZatcaDebitNoteReason. Independent
 * of ZatcaCreditNoteType (full/partial), which only says how many sources a course
 * cancellation takes.
 *
 * The two reasons differ in what they claim and in what follows acceptance:
 *
 *  - CourseCancellation claims whole sources (an original line or an accepted debit
 *    note line). The source is spent, and acceptance ends the student's access to it.
 *  - PriceAdjustment claims part of ONE course's value. The course stays creditable
 *    for whatever is left of it, and acceptance changes nobody's access.
 */
enum ZatcaCreditNoteReason: string
{
    /** A course/service is taken back. Every note issued before reasons existed is one of these. */
    case CourseCancellation = 'course_cancellation';

    /** The course was over-priced. Credits the difference only; the original is never edited. */
    case PriceAdjustment = 'price_adjustment';

    /** Whether an accepted note of this kind should end the student's access to what it credits. */
    public function revokesCourseAccess(): bool
    {
        return $this === self::CourseCancellation;
    }

    public function label(): string
    {
        return match ($this) {
            self::CourseCancellation => 'course cancellation',
            self::PriceAdjustment => 'price adjustment',
        };
    }
}
