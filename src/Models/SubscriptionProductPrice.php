<?php

namespace StripeLri\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionProductPrice extends Model
{
    protected $table = 'subscription_product_prices';

    /** @var list<string> */
    protected $fillable = [
        'subscription_product_id',
        'plan_type',
        'stripe_price_id',
        'amount',
        'currency',
        'nickname',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'metadata' => 'array',
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
