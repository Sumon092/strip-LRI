<?php

declare(strict_types=1);

namespace StripeLri\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stripe\StripeClient;
use StripeLri\Models\Invoice;
use StripeLri\Models\Package;
use StripeLri\Models\Payment;
use StripeLri\Models\Subscription;
use StripeLri\Models\SubscriptionItem;
use StripeLri\Models\SubscriptionProductPrice;
use StripeLri\Models\SubscriptionProductUser;
use StripeLri\Services\DatabaseCreditLedger;

/**
 * Processes Stripe webhook events into local billing records.
 *
 * Stripe API 2026-03-25 (dahlia) structural changes handled:
 *  - invoice line price:  line.pricing.price_details.price  (was line.price.id)
 *  - invoice subscription: invoice.parent.subscription_details.subscription (was invoice.subscription)
 *  - payment_intent not on invoice → Payment is created from charge.succeeded instead
 */
class StripeWebhookProcessor
{
    public function process(\Stripe\Event $event): void
    {
        match ($event->type) {
            'customer.subscription.created' => $this->handleSubscriptionCreated($event->data->object),
            'checkout.session.completed'    => $this->handleCheckoutCompleted($event->data->object),
            'charge.succeeded'              => $this->handleChargeSucceeded($event->data->object),
            'invoice.paid'                  => $this->handleInvoicePaid($event->data->object),
            'customer.subscription.updated' => $this->handleSubscriptionUpdated($event->data->object),
            'customer.subscription.deleted' => $this->handleSubscriptionDeleted($event->data->object),
            default                         => null,
        };
    }

    // ── Event handlers ────────────────────────────────────────────────────────

    /**
     * Creates Subscription + SubscriptionItem records and upserts subscription_product_user.
     * Fetches the Stripe customer to resolve the user email (subscription object has no email).
     */
    private function handleSubscriptionCreated(object $sub): void
    {
        $stripeSubId = (string) ($sub->id ?? '');
        $customerId  = (string) ($sub->customer ?? '');
        if ($stripeSubId === '' || $customerId === '') {
            return;
        }

        $email = $this->fetchCustomerEmail($customerId);
        $user  = $this->findUserByEmail($email);
        if ($user === null) {
            return;
        }

        $firstItem         = $sub->items?->data[0] ?? null;
        $stripePriceId     = $this->extractPriceIdFromItem($firstItem);
        [$product, $price] = $this->findProductAndPrice($stripePriceId);

        $now       = Carbon::now();
        $periodEnd = isset($sub->current_period_end)
            ? Carbon::createFromTimestamp((int) $sub->current_period_end)
            : null;
        $planType  = (string) ($price?->plan_type ?? 'monthly');

        // Upsert local Subscription record
        $localSub = Subscription::updateOrCreate(
            ['stripe_subscription_id' => $stripeSubId],
            [
                'user_id'                 => $user->getKey(),
                'subscription_product_id' => $product?->getKey(),
                'name'                    => (string) ($product?->plan_name ?? ''),
                'status'                  => (string) ($sub->status ?? 'active'),
                'cancel_at_period_end'    => (bool) ($sub->cancel_at_period_end ?? false),
                'cancel_at'               => isset($sub->cancel_at) ? Carbon::createFromTimestamp((int) $sub->cancel_at) : null,
                'current_period_end'      => $periodEnd,
                'canceled_at'             => isset($sub->canceled_at) ? Carbon::createFromTimestamp((int) $sub->canceled_at) : null,
                'created_time'            => isset($sub->created) ? Carbon::createFromTimestamp((int) $sub->created) : $now,
                'start_date'              => isset($sub->start_date) ? Carbon::createFromTimestamp((int) $sub->start_date) : $now,
                'quantity'                => (int) ($firstItem?->quantity ?? 1),
                'default_payment_method'  => (string) ($sub->default_payment_method ?? ''),
            ],
        );

        // Upsert SubscriptionItem records
        foreach ($sub->items?->data ?? [] as $item) {
            $itemPriceId = $this->extractPriceIdFromItem($item);
            SubscriptionItem::updateOrCreate(
                ['stripe_id' => (string) ($item->id ?? '')],
                [
                    'subscription_id' => $localSub->getKey(),
                    'stripe_price'    => $itemPriceId,
                    'amount'          => (int) ($item->price?->unit_amount ?? $item->plan?->amount ?? 0) / 100,
                    'interval'        => (string) ($item->price?->recurring?->interval ?? $item->plan?->interval ?? ''),
                    'interval_count'  => (int) ($item->price?->recurring?->interval_count ?? $item->plan?->interval_count ?? 1),
                    'stripe_product'  => (string) ($item->price?->product ?? $item->plan?->product ?? ''),
                    'nickname'        => (string) ($item->price?->nickname ?? $item->plan?->nickname ?? ''),
                ],
            );
        }

        // Upsert subscription_product_user
        if ($product !== null) {
            $expiresAt = $periodEnd ?? match ($planType) {
                'monthly' => $now->copy()->addMonth(),
                'yearly'  => $now->copy()->addYear(),
                default   => null,
            };

            SubscriptionProductUser::updateOrCreate(
                ['user_id' => $user->getKey(), 'subscription_product_id' => $product->getKey()],
                [
                    'stripe_subscription_id' => $stripeSubId,
                    'is_active'              => in_array((string) ($sub->status ?? ''), ['active', 'trialing'], true),
                    'started_at'             => $now,
                    'expires_at'             => $expiresAt,
                ],
            );

            if (config('stripe-lri.credit_based')) {
                $creditsLimit = (int) ($product->getAttribute('credits_limit') ?? 0);
                if ($creditsLimit > 0) {
                    DatabaseCreditLedger::recordEntry(
                        userId: (int) $user->getKey(),
                        productId: (int) $product->getKey(),
                        delta: $creditsLimit,
                        entryType: 'purchase',
                        description: 'Initial credits for '.$product->plan_name.' subscription',
                    );
                }
            }
        }
    }

    /**
     * Confirms subscription_product_user access.
     * For lifetime (mode=payment): creates Payment + Invoice here since invoice.paid won't fire.
     */
    private function handleCheckoutCompleted(object $session): void
    {
        $email = (string) ($session->customer_email
            ?? $session->customer_details?->email
            ?? '');
        $user = $this->findUserByEmail($email);
        if ($user === null) {
            return;
        }

        $priceId           = (string) ($session->metadata?->stripe_price_id ?? '');
        [$product, $price] = $this->findProductAndPrice($priceId);

        $planType    = (string) ($price?->plan_type ?? 'monthly');
        $amountCents = (int) ($session->amount_total ?? 0);
        $currency    = strtolower((string) ($session->currency ?? 'usd'));
        $stripeSubId = (string) ($session->subscription ?? '');
        $intentId    = (string) ($session->payment_intent ?? '');
        $now         = Carbon::now();

        // Upsert subscription_product_user (subscription.created may already have done this)
        if ($product !== null) {
            $expiresAt = match ($planType) {
                'monthly' => $now->copy()->addMonth(),
                'yearly'  => $now->copy()->addYear(),
                default   => null,
            };

            SubscriptionProductUser::updateOrCreate(
                ['user_id' => $user->getKey(), 'subscription_product_id' => $product->getKey()],
                array_filter([
                    'stripe_subscription_id' => $stripeSubId ?: null,
                    'is_active'              => true,
                    'started_at'             => $now,
                    'expires_at'             => $expiresAt,
                ], static fn ($v): bool => $v !== null),
            );
        }

        // Lifetime one-time payment: no invoice.paid or charge.succeeded with invoice context
        if ($session->mode === 'payment' && $intentId !== '' && $product !== null) {
            Payment::firstOrCreate(
                ['stripe_payment_intent_id' => $intentId],
                [
                    'user_id'                 => $user->getKey(),
                    'subscription_product_id' => $product->getKey(),
                    'amount'                  => $amountCents / 100,
                    'currency'                => $currency,
                    'payment_type'            => 'single',
                    'status'                  => 'completed',
                    'paid_at'                 => $now,
                ],
            );

            $this->createLocalInvoice(
                userId: (int) $user->getKey(),
                productId: (int) $product->getKey(),
                stripeInvoiceId: null,
                paymentIntentId: $intentId,
                customerName: (string) ($session->customer_details?->name ?? ''),
                customerEmail: $email,
                amountCents: $amountCents,
                currency: $currency,
                paidAt: $now,
            );
        }
    }

    /**
     * Creates Payment record. Most reliable source for payment_intent + charge data.
     * Fires for both subscription and one-time payments.
     */
    private function handleChargeSucceeded(object $charge): void
    {
        // Get customer email from billing_details, or fetch from Stripe
        $email = (string) ($charge->billing_details?->email ?? '');
        if ($email === '') {
            $customerId = (string) ($charge->customer ?? '');
            if ($customerId !== '') {
                $email = $this->fetchCustomerEmail($customerId);
            }
        }

        $user = $this->findUserByEmail($email);
        if ($user === null) {
            return;
        }

        $intentId  = (string) ($charge->payment_intent ?? '');
        $chargeId  = (string) ($charge->id ?? '');
        $matchKey  = $intentId !== '' ? ['stripe_payment_intent_id' => $intentId] : ['stripe_charge_id' => $chargeId];

        // Determine payment type and product
        $invoiceId   = (string) ($charge->invoice ?? '');
        $paymentType = $invoiceId !== '' ? 'subscription' : 'single';

        $amountCents = (int) ($charge->amount ?? 0);
        $currency    = strtolower((string) ($charge->currency ?? 'usd'));
        $now         = Carbon::now();

        $methodDetails = is_object($charge->payment_method_details)
            ? json_decode((string) json_encode($charge->payment_method_details), true)
            : [];

        Payment::firstOrCreate(
            $matchKey,
            [
                'user_id'                  => $user->getKey(),
                'subscription_product_id'  => null, // invoice.paid will set this if subscription
                'stripe_payment_intent_id' => $intentId ?: null,
                'stripe_charge_id'         => $chargeId,
                'amount'                   => $amountCents / 100,
                'currency'                 => $currency,
                'payment_type'             => $paymentType,
                'status'                   => 'completed',
                'payment_method_details'   => $methodDetails ?: null,
                'paid_at'                  => $now,
            ],
        );
    }

    /**
     * Creates Invoice record and syncs subscription_product_user.expires_at.
     * Also links the existing Payment to the product via subscription_product_id.
     */
    private function handleInvoicePaid(object $invoice): void
    {
        if ((string) ($invoice->status ?? '') !== 'paid') {
            return;
        }

        $email = (string) ($invoice->customer_email ?? '');
        $user  = $this->findUserByEmail($email);
        if ($user === null) {
            return;
        }

        $line    = $invoice->lines?->data[0] ?? null;
        $priceId = $this->extractPriceIdFromLine($line);
        [$product, ] = $this->findProductAndPrice($priceId);

        // subtotal = pre-discount amount; amount_paid = what customer actually paid
        $subtotalCents   = (int) ($invoice->subtotal ?? $invoice->amount_paid ?? $invoice->total ?? 0);
        $amountPaidCents = (int) ($invoice->amount_paid ?? $invoice->total ?? 0);
        $discountCents   = max(0, $subtotalCents - $amountPaidCents);

        // Extract promo/coupon code — check legacy invoice.discount and new invoice.discounts[] array
        $discountObj = $invoice->discount ?? null;
        if ($discountObj === null) {
            // Newer Stripe API surfaces discounts as an iterable list
            $discountsList = $invoice->discounts ?? null;
            if ($discountsList !== null) {
                foreach ($discountsList as $d) {
                    if ($d !== null) {
                        $discountObj = $d;
                        break;
                    }
                }
            }
        }
        $promoCode = null;
        if ($discountObj !== null) {
            $raw = (string) ($discountObj->coupon?->id ?? $discountObj->coupon?->name ?? '');
            $promoCode = $raw !== '' ? $raw : null;
        }

        $currency        = strtolower((string) ($invoice->currency ?? 'usd'));
        $stripeInvoiceId = (string) ($invoice->id ?? '');
        $intentId        = (string) ($invoice->payment_intent ?? '');
        $creditBased     = (bool) config('stripe-lri.credit_based');
        $now             = Carbon::now();

        // Support both old API (invoice.subscription) and new API (invoice.parent.subscription_details.subscription)
        $stripeSubId = (string) (
            $invoice->subscription
            ?? $invoice->parent?->subscription_details?->subscription
            ?? ''
        );

        // Invoice record
        if ($stripeInvoiceId !== '') {
            $localInvoice = Invoice::firstOrCreate(
                ['stripe_invoice_id' => $stripeInvoiceId],
                [
                    'user_id'                 => $user->getKey(),
                    'subscription_product_id' => $product?->getKey(),
                    'invoice_number'          => (string) ($invoice->number ?? 'INV-'.strtoupper(substr($stripeInvoiceId, -8))),
                    'payment_intent_id'       => $intentId ?: null,
                    'customer_name'           => (string) ($invoice->customer_name ?? ''),
                    'customer_email'          => $email,
                    'amount'                  => $subtotalCents / 100,
                    'tax_amount'              => (int) ($invoice->tax ?? 0) / 100,
                    'discount_amount'         => $discountCents / 100,
                    'total_amount'            => $amountPaidCents / 100,
                    'promo_code'              => $promoCode,
                    'credits_purchased'       => ($creditBased && $product !== null) ? (int) ($product->getAttribute('credits_limit') ?? 0) : 0,
                    'currency'                => $currency,
                    'status'                  => 'paid',
                    'stripe_invoice_url'      => $invoice->hosted_invoice_url ?? null,
                    'stripe_invoice_pdf'      => $invoice->invoice_pdf ?? null,
                    'paid_at'                 => $now,
                ],
            );

            // Back-fill product on the Payment row created by charge.succeeded
            if ($product !== null && $intentId !== '') {
                Payment::where('stripe_payment_intent_id', $intentId)
                    ->whereNull('subscription_product_id')
                    ->update([
                        'subscription_product_id' => $product->getKey(),
                        'stripe_subscription_id'  => $stripeSubId ?: null,
                    ]);
            }
        }

        // Sync subscription_product_user.expires_at from actual Stripe period
        $periodEnd = isset($line->period->end)
            ? Carbon::createFromTimestamp((int) $line->period->end)
            : null;

        if ($product !== null && $periodEnd !== null) {
            SubscriptionProductUser::updateOrCreate(
                ['user_id' => $user->getKey(), 'subscription_product_id' => $product->getKey()],
                [
                    'stripe_subscription_id' => $stripeSubId ?: null,
                    'is_active'              => true,
                    'started_at'             => $now,
                    'expires_at'             => $periodEnd,
                ],
            );
        }

        // Update Subscription period
        if ($stripeSubId !== '') {
            Subscription::where('stripe_subscription_id', $stripeSubId)->update([
                'current_period_end' => $periodEnd,
                'status'             => 'active',
            ]);
        }
    }

    private function handleSubscriptionUpdated(object $sub): void
    {
        $stripeSubId = (string) ($sub->id ?? '');
        if ($stripeSubId === '') {
            return;
        }

        $periodEnd = isset($sub->current_period_end)
            ? Carbon::createFromTimestamp((int) $sub->current_period_end)
            : null;
        $isActive = in_array((string) ($sub->status ?? ''), ['active', 'trialing'], true);

        Subscription::where('stripe_subscription_id', $stripeSubId)->update([
            'status'               => (string) ($sub->status ?? 'active'),
            'cancel_at_period_end' => (bool) ($sub->cancel_at_period_end ?? false),
            'cancel_at'            => isset($sub->cancel_at) ? Carbon::createFromTimestamp((int) $sub->cancel_at) : null,
            'current_period_end'   => $periodEnd,
            'canceled_at'          => isset($sub->canceled_at) ? Carbon::createFromTimestamp((int) $sub->canceled_at) : null,
        ]);

        SubscriptionProductUser::where('stripe_subscription_id', $stripeSubId)->update([
            'is_active'  => $isActive,
            'expires_at' => $periodEnd,
        ]);
    }

    private function handleSubscriptionDeleted(object $sub): void
    {
        $stripeSubId = (string) ($sub->id ?? '');
        if ($stripeSubId === '') {
            return;
        }

        Subscription::where('stripe_subscription_id', $stripeSubId)->update([
            'status'   => 'canceled',
            'ended_at' => Carbon::now(),
        ]);

        SubscriptionProductUser::where('stripe_subscription_id', $stripeSubId)->update([
            'is_active'  => false,
            'expires_at' => Carbon::now(),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Extracts the Stripe price ID from a Stripe invoice line item.
     * Handles both old (line.price.id) and new (line.pricing.price_details.price) API formats.
     */
    private function extractPriceIdFromLine(?object $line): string
    {
        if ($line === null) {
            return '';
        }

        return (string) (
            $line->pricing?->price_details?->price  // new API: string value
            ?? $line->price?->id                    // old API: price object
            ?? $line->plan?->id                     // legacy plan alias
            ?? ''
        );
    }

    /**
     * Extracts the Stripe price ID from a subscription item.
     * Works for both customer.subscription.created items and SubscriptionItem objects.
     */
    private function extractPriceIdFromItem(?object $item): string
    {
        if ($item === null) {
            return '';
        }

        return (string) (
            $item->price?->id   // standard
            ?? $item->plan?->id // legacy plan alias
            ?? ''
        );
    }

    private function findUserByEmail(string $email): ?Model
    {
        if ($email === '') {
            return null;
        }
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model', \App\Models\User::class);

        return $userModel::where('email', $email)->first();
    }

    /** @return array{0: ?Package, 1: ?SubscriptionProductPrice} */
    private function findProductAndPrice(string $stripePriceId): array
    {
        if ($stripePriceId === '') {
            return [null, null];
        }

        $price = SubscriptionProductPrice::where('stripe_price_id', $stripePriceId)->first();

        return [$price?->product, $price];
    }

    private function fetchCustomerEmail(string $customerId): string
    {
        $secret = trim((string) config('stripe-lri.stripe.secret', ''));
        if ($secret === '' || $customerId === '') {
            return '';
        }

        try {
            $customer = (new StripeClient($secret))->customers->retrieve($customerId);

            return (string) ($customer->email ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    private function createLocalInvoice(
        int $userId,
        int $productId,
        ?string $stripeInvoiceId,
        ?string $paymentIntentId,
        string $customerName,
        string $customerEmail,
        int $amountCents,
        string $currency,
        Carbon $paidAt,
    ): void {
        $invoiceNumber = 'INV-'.strtoupper(substr(uniqid('', true), -8));

        $match = $stripeInvoiceId
            ? ['stripe_invoice_id' => $stripeInvoiceId]
            : ($paymentIntentId ? ['payment_intent_id' => $paymentIntentId] : ['invoice_number' => $invoiceNumber]);

        Invoice::firstOrCreate($match, [
            'user_id'                 => $userId,
            'subscription_product_id' => $productId,
            'invoice_number'          => $invoiceNumber,
            'stripe_invoice_id'       => $stripeInvoiceId,
            'payment_intent_id'       => $paymentIntentId,
            'customer_name'           => $customerName,
            'customer_email'          => $customerEmail,
            'amount'                  => $amountCents / 100,
            'tax_amount'              => 0,
            'total_amount'            => $amountCents / 100,
            'currency'                => $currency,
            'status'                  => 'paid',
            'paid_at'                 => $paidAt,
        ]);
    }
}
