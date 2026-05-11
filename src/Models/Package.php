<?php

namespace StripeLri\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Package extends Model
{
    use SoftDeletes;

    /** Eloquent model name kept as {@see Package}; table matches admin “subscription products”. */
    protected $table = 'subscription_products';

    /** @var list<string> */
    protected $fillable = [
        'plan_name',
        'description',
        'plan_type',
        'price',
        'billing_cycle',
        'max_devices',
        'is_popular',
        'is_featured',
        'allow_trial',
        'status',
        'sort_order',
        'allowed_user_ids',
        'stripe_product_id',
        'stripe_price_id',
        'temp_request_id',
        'created_by',
        'metadata',
        'credits_limit',
        'site_limit',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'allowed_user_ids' => 'array',
            'metadata' => 'array',
            'is_popular' => 'boolean',
            'is_featured' => 'boolean',
            'allow_trial' => 'boolean',
            'sort_order' => 'integer',
            'credits_limit' => 'integer',
            'site_limit' => 'integer',
        ];
    }

    /**
     * @return HasMany<SubscriptionProductItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SubscriptionProductItem::class, 'subscription_product_id')
            ->orderBy('sort_order');
    }

    /**
     * @return HasMany<SubscriptionProductPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(SubscriptionProductPrice::class, 'subscription_product_id');
    }

    /**
     * @return BelongsToMany<PremiumFeature, $this>
     */
    public function premiumFeatures(): BelongsToMany
    {
        return $this->belongsToMany(
            PremiumFeature::class,
            'subscription_product_premium_feature',
            'subscription_product_id',
            'premium_feature_id',
        )->withPivot('is_included')
            ->withTimestamps();
    }
}
