<?php

namespace Asas\Zatca\State;

use Asas\Zatca\Enums\ZatcaCredentialType;

class ProductionInvoiceCounterManager extends AbstractInvoiceCounterManager
{
    protected function stateType(): ZatcaCredentialType
    {
        return ZatcaCredentialType::Production;
    }
}
