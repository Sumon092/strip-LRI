<?php

namespace StripeLri\Http\Controllers\Webhooks;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Browsers issue GET; Stripe issues POST. This endpoint explains GET visits.
 */
final class StripeWebhookInfoController extends Controller
{
    public function __invoke(): Response
    {
        return response(
            "Stripe webhook endpoint (POST only)\n\n".
            "Stripe and the Stripe CLI send signed POST requests to this path. A normal browser visit uses GET, which is not how webhooks work.\n\n".
            "Local testing:\n".
            '  stripe listen --forward-to '.url('/stripe/webhook')."\n".
            "  stripe trigger checkout.session.completed\n",
            200,
            ['Content-Type' => 'text/plain; charset=UTF-8']
        );
    }
}
