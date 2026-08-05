<?php

namespace Asas\Zatca\Services\Security;

use Asas\Zatca\Contracts\OwnerResolverInterface;

class NullOwnerResolver implements OwnerResolverInterface
{
    public function resolve(string $egsIdentifier): int|string|null
    {
        return null;
    }
}
