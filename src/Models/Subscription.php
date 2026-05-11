<?php

declare(strict_types=1);

namespace StripeLri\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /** @return HasMany<SubscriptionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class, 'subscription_id');
    }

    /**
     * Normalize Stripe {@code cancellation_details} (object or array) for JSON storage.
     *
     * @return array<string, mixed>|null
     */
    public static function normalizeCancellationDetails(mixed $cancellationDetails): ?array
    {
        if ($cancellationDetails === null) {
            return null;
        }
        if (is_array($cancellationDetails)) {
            return $cancellationDetails === [] ? null : $cancellationDetails;
        }
        if (is_object($cancellationDetails)) {
            $decoded = json_decode(json_encode($cancellationDetails), true);
            if (! is_array($decoded) || $decoded === []) {
                return null;
            }

            return $decoded;
        }

        return null;
    }

    /**
     * @param  object|array<string, mixed>  $subscription  Stripe Subscription API object (or decoded array)
     * @return array<string, mixed>|null
     */
    public static function extractCancellationDetailsFromApi(mixed $subscription): ?array
    {
        if (is_array($subscription)) {
            $cd = $subscription['cancellation_details'] ?? null;
        } elseif (is_object($subscription)) {
            $cd = $subscription->cancellation_details ?? null;
        } else {
            return null;
        }

        return self::normalizeCancellationDetails($cd);
    }

    /**
     * Merge a new Stripe {@code cancellation_details} payload into what we already stored.
     * Stripe often sends a full comment on one event and a slimmer object (reason only) on the next;
     * replacing the column would drop the customer's written message.
     *
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>|null  $incoming
     * @return array<string, mixed>|null
     */
    public static function mergeCancellationDetails(?array $previous, ?array $incoming): ?array
    {
        $prev = $previous ?? [];
        $inc  = $incoming ?? [];
        if ($prev === [] && $inc === []) {
            return null;
        }

        $out = array_replace($prev, $inc);

        foreach (['comment', 'feedback'] as $key) {
            $prevVal = isset($prev[$key]) ? trim((string) $prev[$key]) : '';
            if ($prevVal === '') {
                continue;
            }
            if (! array_key_exists($key, $inc)) {
                continue;
            }
            $incVal = trim((string) ($inc[$key] ?? ''));
            if ($incVal === '') {
                $out[$key] = $prev[$key];
            }
        }

        return $out === [] ? null : $out;
    }
}
