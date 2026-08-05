<?php

namespace Asas\Zatca\Contracts;

/**
 * Optional multi-tenant hook. The system is not multi-tenant today, so no
 * mandatory tenant_id column exists anywhere; this is the seam if that ever
 * becomes necessary. Default binding is NullOwnerResolver (no-op).
 */
interface OwnerResolverInterface
{
    /** @return int|string|null null when the host app has no owner/tenant concept */
    public function resolve(string $egsIdentifier): int|string|null;
}
