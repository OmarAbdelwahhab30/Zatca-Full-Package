<?php

namespace Asas\Zatca\Services\Invoice;

use Asas\Zatca\Exceptions\ZatcaException;
use Asas\Zatca\Services\Invoice\XmlGenerators\V2\SimplifiedInvoiceXmlGenerator;
use Illuminate\Support\Facades\Config;

/**
 * Resolves the active XmlGenerators/V{profile} implementation from
 * config('zatca.xml_profile_version') — independent of api_version, since
 * ZATCA's UBL profile and transport API version change on different
 * schedules (plan §2.1).
 */
class XmlGeneratorFactory
{
    public function make(): SimplifiedInvoiceXmlGenerator
    {
        $profile = Config::get('zatca.xml_profile_version');

        return match ($profile) {
            'V2' => app(SimplifiedInvoiceXmlGenerator::class),
            default => throw new ZatcaException("Unsupported ZATCA_XML_PROFILE_VERSION [{$profile}]. Add XmlGenerators/V{$profile}/ before switching config to it."),
        };
    }
}
