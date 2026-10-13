<?php

namespace Zatca\DTOs;

use InvalidArgumentException;

/**
 * The "other buyer ID" (BT-46) with its scheme (BT-46-1). Required on a Standard document
 * when the buyer has no VAT number (BR-KSA-81, Facts Register F-SDK-05).
 *
 * The scheme is checked against ZATCA's list (BR-KSA-14, F-SDK-04) because a wrong scheme
 * can only ever produce a rejected document; the value itself is validated where the data
 * is captured, not here.
 */
final class BuyerOtherId
{
    /** BR-KSA-14. */
    public const SCHEMES = ['TIN', 'CRN', 'MOM', 'MLS', '700', 'SAG', 'NAT', 'GCC', 'IQA', 'PAS', 'OTH'];

    public function __construct(
        public readonly string $scheme,
        public readonly string $value,
    ) {
        if (! in_array($scheme, self::SCHEMES, true)) {
            throw new InvalidArgumentException("Unknown buyer ID scheme [{$scheme}]. Allowed (BR-KSA-14): ".implode(', ', self::SCHEMES).'.');
        }
    }

    /** A company's Commercial Registration number. */
    public static function commercialRegistration(string $crNumber): self
    {
        return new self('CRN', $crNumber);
    }
}
