<?php

namespace Zatca\State;

use Zatca\Enums\ZatcaCredentialType;

class ComplianceInvoiceCounterManager extends AbstractInvoiceCounterManager
{
    protected function stateType(): ZatcaCredentialType
    {
        return ZatcaCredentialType::Compliance;
    }
}
