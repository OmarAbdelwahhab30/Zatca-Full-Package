<?php

namespace Zatca\Enums;

/** Whether a Credit Note credits every line still available on its original, or a chosen subset. */
enum ZatcaCreditNoteType: string
{
    case Full = 'full';
    case Partial = 'partial';
}
