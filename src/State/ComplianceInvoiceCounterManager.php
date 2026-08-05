<?php

namespace Asas\Zatca\State;

use Asas\Zatca\Enums\ZatcaCredentialType;

class ComplianceInvoiceCounterManager extends AbstractInvoiceCounterManager
{
    protected function stateType(): ZatcaCredentialType
    {
        return ZatcaCredentialType::Compliance;
    }
}
