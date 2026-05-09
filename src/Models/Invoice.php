<?php

declare(strict_types=1);

namespace StripeLri\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $table = 'invoices';

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'payment_id', 'subscription_product_id',
        'invoice_number', 'stripe_invoice_id',
        'payment_intent_id', 'payment_charge_id',
        'customer_name', 'customer_email', 'billing_address',
        'amount', 'tax_amount', 'total_amount', 'currency',
        'status', 'stripe_invoice_url', 'stripe_invoice_pdf', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'billing_address' => 'array',
            'amount'          => 'decimal:2',
            'tax_amount'      => 'decimal:2',
            'total_amount'    => 'decimal:2',
            'paid_at'         => 'datetime',
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
