<?php

namespace Asas\Zatca\State;

use Asas\Zatca\Enums\ZatcaCredentialType;

class CompliancePreviousInvoiceHashManager extends AbstractPreviousInvoiceHashManager
{
    protected function stateType(): ZatcaCredentialType
    {
        return ZatcaCredentialType::Compliance;
    }
}
