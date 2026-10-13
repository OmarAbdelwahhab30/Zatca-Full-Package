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

    /**
     * The primary EGS — config('zatca.primary_egs'). B2C documents, the seller identity,
     * the admin settings and the price tax policy belong to it. Use this rather than
     * default(): with a B2B EGS configured, "first key" is an accident of array order.
     *
     * Falls back to default() when the key is absent (a config cached before it existed)
     * or names an EGS that is not configured, which is exactly the behaviour before
     * primary() existed.
     */
    public function primary(): EgsConfig
    {
        $primary = Config::get('zatca.primary_egs');

        if (is_string($primary) && array_key_exists($primary, Config::get('zatca.egs', []))) {
            return $this->for($primary);
        }

        return $this->default();
    }

    /** The first configured EGS. Prefer primary(). */
    public function default(): EgsConfig
    {
        $identifiers = $this->identifiers();

        if (empty($identifiers)) {
            throw new InvalidArgumentException('No ZATCA EGS configured. Set ZATCA_EGS_IDENTIFIER and related env values.');
        }

        return $this->for($identifiers[0]);
    }
}
