<?php

namespace Asas\Zatca\Services\Invoice;

class ZatcaInvoiceResult
{
    public function __construct(
        public readonly string $uuid,
        public readonly int $icv,
        public readonly string $previousInvoiceHash,
        public readonly string $invoiceHash,
        public readonly string $unsignedXml,
        public readonly string $signedXmlBase64,
        public readonly string $qrCode,
    ) {}
}
