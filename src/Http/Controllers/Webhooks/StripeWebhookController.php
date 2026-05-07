<?php

namespace StripeLri\Http\Controllers\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
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

        logger()->info('stripe-lri.webhook', ['type' => $event->type, 'id' => $event->id]);

        return response('OK', 200);
    }
}
