<?php

namespace Zatca\Enums;

/**
 * UN/ECE 5305 VAT category of a line or allowance (BT-151 / BT-95).
 *
 *  - Standard (S): the normal rate, e.g. 15%. Rate above zero, no exemption reason.
 *  - ZeroRated (Z): taxable at 0% (exports, qualifying medicines, ...). Needs a reason.
 *  - Exempt (E): exempt supplies (some financial services, residential rent, ...). Needs a reason.
 *
 * Z and E carry a 0% rate, a 0.00 VAT amount, and a ZATCA exemption reason code
 * (VATEX-SA-...) plus its text (BR-KSA-69). "Out of scope" (O) is deliberately not
 * offered: ZATCA restricts how it may be combined with other categories on one
 * document, and that has not been verified for this package.
 */
enum ZatcaVatCategory: string
{
    case Standard = 'S';
    case ZeroRated = 'Z';
    case Exempt = 'E';

    public function requiresExemptionReason(): bool
    {
        return $this !== self::Standard;
    }
}
