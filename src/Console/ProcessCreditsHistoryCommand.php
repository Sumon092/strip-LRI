<?php

namespace StripeLri\Console;

use Illuminate\Console\Command;
use StripeLri\Contracts\CreditLedger;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'stripe-lri:credits:process-history')]
class ProcessCreditsHistoryCommand extends Command
{
    protected $signature = 'stripe-lri:credits:process-history';

    protected $description = 'Archive credit rows / expire handling (Indexchecker: credits:process-history). Bind CreditLedger to implement.';

    public function handle(CreditLedger $ledger): int
    {
        $this->info('Stripe-LRI: processing credits history...');
        $ledger->processCreditsHistory();
        $this->info('Done.');

        return self::SUCCESS;
    }
}
