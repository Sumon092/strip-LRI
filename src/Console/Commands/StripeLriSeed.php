<?php

declare(strict_types=1);

namespace StripeLri\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use StripeLri\Models\Coupon;
use StripeLri\Models\Invoice;
use StripeLri\Models\Package;
use StripeLri\Models\Payment;
use StripeLri\Models\Subscription;
use StripeLri\Models\SubscriptionItem;
use StripeLri\Models\SubscriptionProductItem;
use StripeLri\Models\SubscriptionProductPrice;
use StripeLri\Models\SubscriptionProductUser;

#[AsCommand(name: 'stripe-lri:seed')]
final class StripeLriSeed extends Command
{
    protected $signature = 'stripe-lri:seed
                            {--users=0 : Max users to seed (0 = all users)}
                            {--fresh : Truncate all billing tables before seeding}';

    protected $description = 'Seed billing tables with realistic test data (dev/staging only).';

    private const PLAN_TYPES = ['monthly', 'yearly', 'lifetime'];
    private const STATUSES   = ['completed', 'completed', 'completed', 'failed', 'pending'];
    private const METHODS     = [
        ['type' => 'card', 'card' => ['brand' => 'visa',       'last4' => '4242']],
        ['type' => 'card', 'card' => ['brand' => 'mastercard', 'last4' => '5555']],
        ['type' => 'card', 'card' => ['brand' => 'amex',       'last4' => '0005']],
    ];

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refused: this command must not run in production.');
            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->truncateTables();
            $this->info('Billing tables truncated.');
        }

        $packages = $this->ensurePackages();
        $users    = $this->loadUsers();

        if ($users->isEmpty()) {
            $this->warn('No users found. Create at least one user first.');
            return self::FAILURE;
        }

        $this->ensureCoupons();

        $seeded = 0;
        foreach ($users as $user) {
            // Randomly assign 1-2 packages per user
            $userPackages = $packages->random(min(rand(1, 2), $packages->count()));
            if (! is_iterable($userPackages)) {
                $userPackages = collect([$userPackages]);
            }

            foreach ($userPackages as $pkg) {
                $price    = $pkg->prices->first();
                $planType = $price?->plan_type ?? 'monthly';
                $amount   = (float) ($price?->amount ?? $pkg->price ?? 0);

                $this->seedUserSubscription($user, $pkg, $price, $planType, $amount);
                $seeded++;
            }
        }

        $this->info("Seeded {$seeded} subscription record(s) across {$users->count()} user(s).");
        $this->info('Run: php artisan stripe-lri:seed --fresh to reset and re-seed.');

        return self::SUCCESS;
    }

    // ── Packages ──────────────────────────────────────────────────────────────

    private function ensurePackages(): \Illuminate\Database\Eloquent\Collection
    {
        if (Package::count() > 0) {
            return Package::with(['items', 'prices'])->where('status', 'active')->get();
        }

        $creditBased = (bool) config('stripe-lri.credit_based');
        $seed = [
            ['plan_name' => 'Starter',      'price' => 9.00,  'billing_cycle' => 'monthly', 'credits_limit' => $creditBased ? 100  : null, 'sort_order' => 1],
            ['plan_name' => 'Growth',        'price' => 29.00, 'billing_cycle' => 'monthly', 'credits_limit' => $creditBased ? 500  : null, 'sort_order' => 2],
            ['plan_name' => 'Pro',           'price' => 79.00, 'billing_cycle' => 'monthly', 'credits_limit' => $creditBased ? 2000 : null, 'sort_order' => 3],
            ['plan_name' => 'Starter Yearly','price' => 90.00, 'billing_cycle' => 'yearly',  'credits_limit' => $creditBased ? 100  : null, 'sort_order' => 4],
            ['plan_name' => 'Growth Yearly', 'price' => 290.00,'billing_cycle' => 'yearly',  'credits_limit' => $creditBased ? 500  : null, 'sort_order' => 5],
            ['plan_name' => 'Lifetime',      'price' => 299.00,'billing_cycle' => null,       'credits_limit' => $creditBased ? 99999: null, 'sort_order' => 6],
        ];

        $planTypes = ['monthly' => 'monthly', 'yearly' => 'yearly', null => 'lifetime'];

        foreach ($seed as $row) {
            $pkg = Package::create([
                'plan_name'    => $row['plan_name'],
                'plan_type'    => 'stripe_plan',
                'billing_cycle'=> $row['billing_cycle'],
                'price'        => $row['price'],
                'status'       => 'active',
                'sort_order'   => $row['sort_order'],
                'credits_limit'=> $row['credits_limit'],
                'stripe_product_id' => 'prod_seed_'.Str::lower(Str::slug($row['plan_name'])),
            ]);

            SubscriptionProductItem::create([
                'subscription_product_id' => $pkg->getKey(),
                'name' => 'All features included',
            ]);

            $planType = $planTypes[$row['billing_cycle']] ?? 'monthly';
            SubscriptionProductPrice::create([
                'subscription_product_id' => $pkg->getKey(),
                'plan_type'    => $planType,
                'amount'       => $row['price'],
                'currency'     => 'usd',
                'stripe_price_id' => 'price_seed_'.Str::lower(Str::slug($row['plan_name'])),
            ]);
        }

        $this->info('Created 6 seed packages (monthly, yearly, lifetime).');

        return Package::with(['items', 'prices'])->where('status', 'active')->get();
    }

    // ── Coupons ───────────────────────────────────────────────────────────────

    private function ensureCoupons(): void
    {
        if (Coupon::count() > 0) {
            return;
        }

        $coupons = [
            ['code' => 'SAVE10', 'name' => '10% Off',      'percent_off' => 10, 'active' => true, 'times_redeemed' => 3],
            ['code' => 'SAVE25', 'name' => '25% Off',      'percent_off' => 25, 'active' => true, 'times_redeemed' => 1],
            ['code' => 'FLAT20', 'name' => '$20 Off',      'amount_off'  => 2000, 'currency' => 'usd', 'active' => true, 'times_redeemed' => 0],
            ['code' => 'LAUNCH', 'name' => 'Launch Deal',  'percent_off' => 50, 'active' => false, 'times_redeemed' => 12],
        ];

        foreach ($coupons as $c) {
            Coupon::create(array_merge(['duration' => 'once'], $c));
        }

        $this->info('Created 4 seed coupons.');
    }

    // ── Per-user subscription + payments + invoices ───────────────────────────

    private function seedUserSubscription(
        mixed $user,
        Package $pkg,
        ?SubscriptionProductPrice $price,
        string $planType,
        float $amount,
    ): void {
        $userId    = $user->getKey();
        $productId = (int) $pkg->getKey();
        $now       = Carbon::now();
        $startedAt = $now->copy()->subDays(rand(1, 180));
        $isActive  = rand(0, 9) > 1; // 80% active

        $expiresAt = match ($planType) {
            'yearly'   => $startedAt->copy()->addYear(),
            'lifetime' => null,
            default    => $startedAt->copy()->addMonth(),
        };

        $fakeSubId    = 'sub_seed_'.Str::random(14);
        $fakePriceId  = $price?->stripe_price_id ?? 'price_seed_'.Str::random(10);

        // subscription_product_user
        SubscriptionProductUser::updateOrCreate(
            ['user_id' => $userId, 'subscription_product_id' => $productId],
            [
                'stripe_subscription_id' => $planType !== 'lifetime' ? $fakeSubId : null,
                'is_active'  => $isActive,
                'started_at' => $startedAt,
                'expires_at' => $expiresAt,
                'credits_balance' => Schema::hasColumn('subscription_product_user', 'credits_balance')
                    ? (int) ($pkg->credits_limit ?? 0)
                    : 0,
            ],
        );

        // Subscription + SubscriptionItem (not for lifetime)
        if ($planType !== 'lifetime') {
            $localSub = Subscription::updateOrCreate(
                ['stripe_subscription_id' => $fakeSubId],
                [
                    'user_id'                 => $userId,
                    'subscription_product_id' => $productId,
                    'name'                    => $pkg->plan_name,
                    'status'                  => $isActive ? 'active' : 'canceled',
                    'cancel_at_period_end'    => false,
                    'current_period_end'      => $expiresAt,
                    'created_time'            => $startedAt,
                    'start_date'              => $startedAt,
                    'quantity'                => 1,
                    'default_payment_method'  => '',
                ],
            );

            SubscriptionItem::updateOrCreate(
                ['stripe_id' => 'si_seed_'.Str::random(14)],
                [
                    'subscription_id' => $localSub->getKey(),
                    'stripe_price'    => $fakePriceId,
                    'amount'          => $amount,
                    'interval'        => $planType === 'yearly' ? 'year' : 'month',
                    'interval_count'  => 1,
                    'stripe_product'  => $pkg->stripe_product_id ?? 'prod_seed',
                    'nickname'        => $pkg->plan_name,
                ],
            );
        }

        // Generate 1-3 historical payments + invoices
        $periods = $planType === 'lifetime' ? 1 : rand(1, 3);
        for ($i = 0; $i < $periods; $i++) {
            $paidAt = $startedAt->copy()->addMonths($planType === 'yearly' ? $i * 12 : $i);
            if ($paidAt->isFuture()) {
                break;
            }
            $this->createPaymentAndInvoice($userId, $productId, $pkg, $amount, $planType, $paidAt, $fakeSubId);
        }
    }

    private function createPaymentAndInvoice(
        int $userId,
        int $productId,
        Package $pkg,
        float $amount,
        string $planType,
        Carbon $paidAt,
        string $fakeSubId,
    ): void {
        $intentId  = 'pi_seed_'.Str::random(14);
        $status    = self::STATUSES[array_rand(self::STATUSES)];
        $method    = self::METHODS[array_rand(self::METHODS)];
        $currency  = 'usd';

        $payment = Payment::create([
            'user_id'                  => $userId,
            'subscription_product_id'  => $productId,
            'stripe_payment_intent_id' => $intentId,
            'stripe_subscription_id'   => $planType !== 'lifetime' ? $fakeSubId : null,
            'amount'                   => $amount,
            'currency'                 => $currency,
            'payment_type'             => $planType === 'lifetime' ? 'single' : 'subscription',
            'status'                   => $status,
            'payment_method_details'   => $method,
            'paid_at'                  => $status === 'completed' ? $paidAt : null,
        ]);

        if ($status === 'completed') {
            $invNumber = 'INV-'.strtoupper(Str::random(8));
            Invoice::create([
                'user_id'                 => $userId,
                'subscription_product_id' => $productId,
                'invoice_number'          => $invNumber,
                'stripe_invoice_id'       => 'in_seed_'.Str::random(14),
                'payment_intent_id'       => $intentId,
                'customer_name'           => '',
                'customer_email'          => '',
                'amount'                  => $amount,
                'tax_amount'              => 0,
                'total_amount'            => $amount,
                'currency'                => $currency,
                'status'                  => 'paid',
                'paid_at'                 => $paidAt,
            ]);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function loadUsers(): \Illuminate\Database\Eloquent\Collection
    {
        /** @var class-string<\Illuminate\Database\Eloquent\Model> $userModel */
        $userModel = config('stripe-lri.models.user', \App\Models\User::class);
        $limit     = (int) $this->option('users');

        $query = $userModel::query()->orderBy('id');
        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }

    private function truncateTables(): void
    {
        $tables = [
            'subscription_product_user',
            'subscription_items',
            'subscriptions',
            'payments',
            'invoices',
            'credit_ledger',
            'coupons',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                \Illuminate\Support\Facades\DB::table($table)->truncate();
            }
        }
    }
}
