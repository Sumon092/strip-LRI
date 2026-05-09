<?php

declare(strict_types=1);

namespace StripeLri\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $table = 'subscriptions';

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'subscription_product_id', 'stripe_subscription_id',
        'name', 'cancel_at_period_end', 'cancel_at',
        'current_period_end', 'canceled_at', 'cancellation_details',
        'created_time', 'start_date', 'quantity',
        'default_payment_method', 'ended_at', 'status',
    ];

    protected function casts(): array
    {
        return [
            'cancel_at_period_end'   => 'boolean',
            'cancel_at'              => 'datetime',
            'current_period_end'     => 'datetime',
            'canceled_at'            => 'datetime',
            'cancellation_details'   => 'array',
            'created_time'           => 'datetime',
            'start_date'             => 'datetime',
            'ended_at'               => 'datetime',
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
