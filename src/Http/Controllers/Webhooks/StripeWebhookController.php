<?php

namespace StripeLri\Http\Controllers\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use StripeLri\Services\StripeWebhookProcessor;

/**
 * In production, Stripe billing is webhook-driven: configure the endpoint in Stripe
 * and set {@code stripe-lri.stripe.webhook_secret} (STRIPE_WEBHOOK_SECRET).
 */
class StripeWebhookController extends Controller
{
    public function handle(Request $request): Response
    {
        $secret = (string) config('stripe-lri.stripe.webhook_secret', '');
        if ($secret === '') {
            return response('Stripe webhook secret not configured.', 503);
        }

        $payload   = $request->getContent();
        $sigHeader = (string) $request->header('Stripe-Signature', '');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (\UnexpectedValueException|SignatureVerificationException) {
            return response('Invalid payload.', 400);
        }

        logger()->info('stripe-lri.webhook', ['type' => $event->type, 'id' => $event->id]);

        if (Schema::hasTable('stripe_lri_webhook_health')) {
            DB::table('stripe_lri_webhook_health')->updateOrInsert(
                ['id' => 1],
                ['last_valid_event_at' => now(), 'last_stripe_event_id' => $event->id],
            );
        }

        try {
            (new StripeWebhookProcessor)->process($event);
        } catch (\Throwable $e) {
            logger()->error('stripe-lri.webhook_processing_failed', [
                'type'  => $event->type,
                'id'    => $event->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response('OK', 200);
    }
}
