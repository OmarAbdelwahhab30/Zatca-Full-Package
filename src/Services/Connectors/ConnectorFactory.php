<?php

namespace Asas\Zatca\Services\Connectors;

use Asas\Zatca\Contracts\ZatcaConnectorInterface;
use Asas\Zatca\Exceptions\ZatcaException;
use Asas\Zatca\Services\Connectors\V2\HttpConnector;
use Illuminate\Support\Facades\Config;

/**
 * Resolves the active ZatcaConnectorInterface implementation from
 * config('zatca.api_version') — the primary versioning seam. Adding V3
 * means adding Connectors/V3/* and one more match arm here; V2 stays
 * untouched (plan §6, phase 10).
 */
class ConnectorFactory
{
    public function make(): ZatcaConnectorInterface
    {
        $version = Config::get('zatca.api_version');

        return match ($version) {
            'V2' => app(HttpConnector::class),
            default => throw new ZatcaException("Unsupported ZATCA_API_VERSION [{$version}]. Add Connectors/{$version}/ before switching config to it."),
        };
    }
}
