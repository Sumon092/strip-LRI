<?php

namespace StripeLri\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function handle(Request $request): Response
    {
        $secret = (string) config('stripe-lri.stripe.webhook_secret', '');
        if ($secret === '') {
            return response('Stripe webhook secret not configured.', 503);
        }

        $payload = $request->getContent();
        $sigHeader = (string) $request->header('Stripe-Signature', '');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (\UnexpectedValueException|SignatureVerificationException) {
            return response('Invalid payload.', 400);
        }

        // Dispatch domain events from your app by listening to `stripe-lri` events in a future release.
        logger()->info('stripe-lri.webhook', ['type' => $event->type, 'id' => $event->id]);

        return response('OK', 200);
    }
}
