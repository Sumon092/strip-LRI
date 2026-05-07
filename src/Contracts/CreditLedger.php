<?php

namespace StripeLri\Contracts;

/**
 * Application hook for Indexchecker-style credit rules:
 * - Monthly subscription renewals: typically Stripe webhooks reset the period allowance.
 * - Yearly + lifetime (one-time) with monthly allowance: cron refills using last refilled timestamp.
 */
interface CreditLedger
{
    /**
     * Reset monthly allowance for yearly and lifetime credit rows (see CreditTypeService::addMonthlyCreditsForYearlySubscriptions).
     *
     * @return int Number of rows updated
     */
    public function addMonthlyCreditsForYearlyAndLifetime(): int;

    /**
     * Archive expired rows / move to history (see ProcessCreditsHistory command).
     */
    public function processCreditsHistory(): void;
}
