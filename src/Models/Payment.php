<?php

declare(strict_types=1);

namespace StripeLri\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $table = 'payments';

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'subscription_id', 'subscription_product_id',
        'stripe_payment_intent_id', 'stripe_charge_id', 'stripe_subscription_id',
        'amount', 'currency', 'payment_type', 'status',
        'billing_details', 'payment_method_details', 'stripe_metadata',
        'paid_at', 'failed_at', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount'                  => 'decimal:2',
            'billing_details'         => 'array',
            'payment_method_details'  => 'array',
            'stripe_metadata'         => 'array',
            'paid_at'                 => 'datetime',
            'failed_at'               => 'datetime',
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
