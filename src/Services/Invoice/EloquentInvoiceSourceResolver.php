<?php

namespace Zatca\Services\Invoice;

use Zatca\Contracts\InvoiceSourceResolverContract;
use Zatca\DTOs\InvoiceSourceReference;
use Illuminate\Database\Eloquent\Model;

/**
 * Default InvoiceSourceResolverContract binding, covering the common case:
 * source_type is an Eloquent model class name, source_id is its primary key.
 * Host apps with a non-Eloquent source (or custom lookup logic) should bind
 * their own implementation instead — this default is a convenience, not a
 * guarantee that every possible source_type is supported.
 */
class EloquentInvoiceSourceResolver implements InvoiceSourceResolverContract
{
    public function resolve(InvoiceSourceReference $reference): mixed
    {
        if (! class_exists($reference->sourceType) || ! is_a($reference->sourceType, Model::class, true)) {
            return null;
        }

        return $reference->sourceType::find($reference->sourceId);
    }
}
