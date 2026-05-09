<?php

namespace StripeLri\Support;

/**
 * Guards admin package writes behind a configured Stripe webhook secret.
 */
final class StripeWebhookCatalogGate
{
    public static function allowsPackageWrites(): bool
    {
        return self::blockingReason() === null;
    }

    public static function denyMessage(): string
    {
        return self::blockingReason() ?? 'Package creation is not allowed.';
    }

    private static function blockingReason(): ?string
    {
        if (! config('stripe-lri.register_webhook', true)) {
            return 'Stripe webhook routes are disabled (STRIPE_LRI_REGISTER_WEBHOOK). Enable them in .env before creating packages.';
        }

        if (trim((string) config('stripe-lri.stripe.webhook_secret', '')) === '') {
            return 'Set STRIPE_WEBHOOK_SECRET in .env so '.url('/stripe/webhook').' can verify Stripe signatures.';
        }

        return null;
    }
}
