<?php

namespace StripeLri\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PremiumFeature extends Model
{
    protected $table = 'premium_features';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<Package, $this>
     */
    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(
            Package::class,
            'subscription_product_premium_feature',
            'premium_feature_id',
            'subscription_product_id',
        )->withPivot('is_included')
            ->withTimestamps();
    }
}
