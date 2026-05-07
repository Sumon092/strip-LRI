<?php

namespace StripeLri\Console;

use Illuminate\Console\Command;
use StripeLri\Contracts\CreditLedger;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'stripe-lri:credits:add-monthly-for-yearly')]
class AddMonthlyCreditsForYearlyCommand extends Command
{
    protected $signature = 'stripe-lri:credits:add-monthly-for-yearly';

    protected $description = 'Monthly credit reset for yearly subscriptions and lifetime plans (Indexchecker: credits:add-monthly-for-yearly). Bind CreditLedger to implement.';

    public function handle(CreditLedger $ledger): int
    {
        $this->info('Stripe-LRI: monthly credit refill for yearly + lifetime (delegating to CreditLedger)...');

        $count = $ledger->addMonthlyCreditsForYearlyAndLifetime();
        $this->info("Processed {$count} credit row(s).");

        return self::SUCCESS;
    }
}
