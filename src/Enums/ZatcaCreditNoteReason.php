<?php

namespace Zatca\Enums;

/**
 * Why a Credit Note was issued — the counterpart of ZatcaDebitNoteReason. Independent
 * of ZatcaCreditNoteType (full/partial), which only says how many sources an item
 * cancellation takes.
 *
 * The two reasons differ in what they claim and in what follows acceptance:
 *
 *  - ItemCancellation claims whole sources (an original line or an accepted debit
 *    note line). The source is spent: the item was returned or cancelled.
 *  - PriceAdjustment claims part of ONE item's value. The item stays creditable
 *    for whatever is left of it; the customer keeps it.
 */
enum ZatcaCreditNoteReason: string
{
    /** An item/service is returned or cancelled. Every note issued before reasons existed is one of these. */
    case ItemCancellation = 'item_cancellation';

    /** The item was over-priced. Credits the difference only; the original is never edited. */
    case PriceAdjustment = 'price_adjustment';

    /** @deprecated Use ItemCancellation. Same case, kept so existing code keeps working. */
    public const CourseCancellation = self::ItemCancellation;

    /** Whether an accepted note of this kind takes the credited items back from the customer. */
    public function cancelsItems(): bool
    {
        return $this === self::ItemCancellation;
    }

    /** @deprecated Use cancelsItems(). */
    public function revokesCourseAccess(): bool
    {
        return $this->cancelsItems();
    }

    public function label(): string
    {
        return match ($this) {
            self::ItemCancellation => 'item return or cancellation',
            self::PriceAdjustment => 'price adjustment',
        };
    }
}
