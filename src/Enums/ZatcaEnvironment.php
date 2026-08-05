<?php

namespace Asas\Zatca\Enums;

enum ZatcaEnvironment: string
{
    case Sandbox = 'sandbox';
    case Simulation = 'simulation';
    case Production = 'production';

    public function isProduction(): bool
    {
        return $this === self::Production;
    }

    public function sdkFlag(): ?string
    {
        return match ($this) {
            self::Sandbox => '-nonprod',
            self::Simulation => '-sim',
            self::Production => null,
        };
    }
}
