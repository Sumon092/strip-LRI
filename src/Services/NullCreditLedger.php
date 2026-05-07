<?php

namespace StripeLri\Services;

use Illuminate\Support\Facades\Log;
use StripeLri\Contracts\CreditLedger;

/**
 * Default no-op until the host app binds a real implementation (e.g. port of Indexchecker CreditTypeService).
 */
final class NullCreditLedger implements CreditLedger
{
    public function addMonthlyCreditsForYearlyAndLifetime(): int
    {
        Log::debug('stripe-lri: NullCreditLedger::addMonthlyCreditsForYearlyAndLifetime — bind CreditLedger in a service provider.');

        return 0;
    }

    public function processCreditsHistory(): void
    {
        Log::debug('stripe-lri: NullCreditLedger::processCreditsHistory — bind CreditLedger in a service provider.');
    }
}
