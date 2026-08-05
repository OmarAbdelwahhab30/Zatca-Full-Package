<?php

namespace Zatca\State;

use Zatca\DTOs\EgsConfig;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * Single resolution point per egs_identifier. All package code must resolve
 * EGS-specific config/credentials/state through EgsRegistry::for() — never
 * config('zatca.*') directly for anything EGS-scoped. This is what makes
 * adding a 2nd EGS a data change instead of a refactor.
 */
class EgsRegistry
{
    /** @var array<string, EgsConfig> */
    private array $resolved = [];

    public function for(string $egsIdentifier): EgsConfig
    {
        if (isset($this->resolved[$egsIdentifier])) {
            return $this->resolved[$egsIdentifier];
        }

        $all = Config::get('zatca.egs', []);

        if (! array_key_exists($egsIdentifier, $all)) {
            throw new InvalidArgumentException("Unknown ZATCA EGS identifier [{$egsIdentifier}]. Configure it under config('zatca.egs').");
        }

        return $this->resolved[$egsIdentifier] = EgsConfig::fromArray($egsIdentifier, $all[$egsIdentifier]);
    }

    /** @return array<string> */
    public function identifiers(): array
    {
        return array_keys(Config::get('zatca.egs', []));
    }

    public function default(): EgsConfig
    {
        $identifiers = $this->identifiers();

        if (empty($identifiers)) {
            throw new InvalidArgumentException('No ZATCA EGS configured. Set ZATCA_EGS_IDENTIFIER and related env values.');
        }

        return $this->for($identifiers[0]);
    }
}
