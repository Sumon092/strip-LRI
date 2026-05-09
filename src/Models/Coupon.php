<?php

declare(strict_types=1);

namespace StripeLri\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Coupon extends Model
{
    use SoftDeletes;

    protected $table = 'coupons';

    /** @var list<string> */
    protected $fillable = [
        'code', 'name', 'stripe_coupon_id',
        'percent_off', 'amount_off', 'currency',
        'duration', 'duration_in_months',
        'max_redemptions', 'times_redeemed',
        'redeem_by', 'active', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'percent_off'       => 'float',
            'amount_off'        => 'integer',
            'max_redemptions'   => 'integer',
            'times_redeemed'    => 'integer',
            'redeem_by'         => 'datetime',
            'active'            => 'boolean',
            'metadata'          => 'array',
        ];
    }

    /** Derive display type from which discount column is set. */
    public function getCouponTypeAttribute(): string
    {
        if ($this->percent_off !== null) {
            return 'percent';
        }
        if ($this->amount_off !== null) {
            return 'fixed_amount';
        }

        return 'percent';
    }
}
