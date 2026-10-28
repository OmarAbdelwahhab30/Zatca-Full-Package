<?php

namespace Zatca\Dashboard\Demo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DemoOrder extends Model
{
    protected $table = 'zatca_demo_orders';

    protected $guarded = [];

    protected $casts = ['paid_at' => 'datetime', 'supplied_at' => 'datetime'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(DemoCustomer::class, 'customer_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DemoOrderItem::class, 'order_id');
    }
}
