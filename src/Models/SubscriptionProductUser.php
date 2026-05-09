<?php

declare(strict_types=1);

namespace StripeLri\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionProductUser extends Model
{
    protected $table = 'subscription_product_user';

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'subscription_product_id', 'stripe_subscription_id',
        'is_active', 'started_at', 'expires_at', 'metadata',
        'credits_balance', 'credits_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active'           => 'boolean',
            'started_at'          => 'datetime',
            'expires_at'          => 'datetime',
            'credits_expires_at'  => 'datetime',
            'credits_balance'     => 'integer',
            'metadata'            => 'array',
        ];
    }

    /** @return BelongsTo<\Illuminate\Database\Eloquent\Model, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('stripe-lri.models.user'), 'user_id');
    }

    /** @return BelongsTo<Package, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'subscription_product_id');
    }
}
