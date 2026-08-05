<?php

namespace Zatca\State;

use Zatca\Enums\ZatcaCredentialType;

class CompliancePreviousInvoiceHashManager extends AbstractPreviousInvoiceHashManager
{
    protected function stateType(): ZatcaCredentialType
    {
        return ZatcaCredentialType::Compliance;
    }
}
