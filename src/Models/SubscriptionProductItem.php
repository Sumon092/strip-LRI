<?php

namespace StripeLri\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionProductItem extends Model
{
    protected $table = 'subscription_product_items';

    /** @var list<string> */
    protected $fillable = [
        'subscription_product_id',
        'name',
        'sort_order',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'subscription_product_id');
    }
}
