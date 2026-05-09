<?php

declare(strict_types=1);

namespace StripeLri\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionItem extends Model
{
    protected $table = 'subscription_items';

    /** @var list<string> */
    protected $fillable = [
        'subscription_id',
        'stripe_id',
        'stripe_price',
        'amount',
        'interval',
        'interval_count',
        'stripe_product',
        'nickname',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
