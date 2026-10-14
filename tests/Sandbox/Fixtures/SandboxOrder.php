<?php

namespace Zatca\Tests\Sandbox\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** A host app's own order, as a real Laravel app would have it. */
class SandboxOrder extends Model
{
    protected $table = 'sandbox_orders';

    protected $guarded = [];

    protected $casts = [
        'is_company' => 'boolean',
        'items' => 'array',
    ];
}
