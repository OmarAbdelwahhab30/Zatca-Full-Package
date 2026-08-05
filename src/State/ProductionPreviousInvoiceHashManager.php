<?php

namespace Asas\Zatca\State;

use Asas\Zatca\Enums\ZatcaCredentialType;

class ProductionPreviousInvoiceHashManager extends AbstractPreviousInvoiceHashManager
{
    protected function stateType(): ZatcaCredentialType
    {
        return ZatcaCredentialType::Production;
    }
}
