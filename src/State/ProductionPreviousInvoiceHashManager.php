<?php

namespace Zatca\State;

use Zatca\Enums\ZatcaCredentialType;

class ProductionPreviousInvoiceHashManager extends AbstractPreviousInvoiceHashManager
{
    protected function stateType(): ZatcaCredentialType
    {
        return ZatcaCredentialType::Production;
    }
}
