<?php

namespace Zatca\State;

use Zatca\Enums\ZatcaCredentialType;

class ProductionInvoiceCounterManager extends AbstractInvoiceCounterManager
{
    protected function stateType(): ZatcaCredentialType
    {
        return ZatcaCredentialType::Production;
    }
}
