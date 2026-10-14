<?php

namespace Zatca\Enums;

/**
 * What a supplied price means. Set globally with ZATCA_VAT_MODE, optionally per invoice.
 *
 *  - Inclusive: the price already contains VAT (what the customer pays). The package
 *    takes the VAT out: 115.00 at 15% → net 100.00 + VAT 15.00.
 *  - Exclusive: the price is before VAT. The package adds it: 100.00 at 15% → net
 *    100.00 + VAT 15.00 = 115.00.
 *
 * Zero-rated and exempt items carry no VAT, so both modes treat their price the same.
 */
enum VatPriceMode: string
{
    case Inclusive = 'inclusive';
    case Exclusive = 'exclusive';
}
