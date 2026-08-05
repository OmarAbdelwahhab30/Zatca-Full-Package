<?php

namespace Zatca\Services\Security;

use Zatca\Contracts\OwnerResolverInterface;

class NullOwnerResolver implements OwnerResolverInterface
{
    public function resolve(string $egsIdentifier): int|string|null
    {
        return null;
    }
}
