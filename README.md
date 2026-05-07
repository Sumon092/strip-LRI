# Stripe-LRI (Laravel)

Standalone **Stripe PHP SDK** integration surface for Laravel apps using **Inertia**. Ships demo-backed routes aligned with billing, subscriptions, packages, coupons, transactions, invoices, and premium-customer admin screens.

## Install in any Laravel project

Composer installs this package by **name** (`stripe-lri/laravel`) only after Composer knows **where** to download it from. Pick one approach.

### A) Public Packagist (best for open source)

1. Put this code in a **public Git** repo (GitHub, GitLab, etc.).
2. Ensure `composer.json` has a valid `"name": "stripe-lri/laravel"` (already set).
3. **Tag a release** (Composer treats semver tags as stable), e.g. `git tag v0.1.0 && git push --tags`.
4. Submit the repo at [packagist.org](https://packagist.org) (or use the GitHub service hook so new tags appear automatically).
5. In every Laravel app:

```bash
composer require stripe-lri/laravel:^0.1
```

Until a stable tag exists, you can require a branch: `composer require stripe-lri/laravel:dev-main`.

### B) Private Git (no Packagist)

In the **consumer** app’s `composer.json`, add a VCS repository (HTTPS or SSH):

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/YOUR_ORG/stripe-lri.git"
        }
    ],
    "require": {
        "stripe-lri/laravel": "^0.1"
    }
}
```

Private repos: use a [GitHub/GitLab token](https://getcomposer.org/doc/articles/authentication-for-private-packages.md) or SSH keys so Composer can clone.

### C) Local path (monorepo / same machine)

```json
"repositories": [
    { "type": "path", "url": "../stripe-lri", "options": { "symlink": true } }
],
"require": {
    "stripe-lri/laravel": "@dev"
}
```

Use any path to the folder that contains this package’s `composer.json`.

### After `composer require`

Laravel **auto-discovers** `StripeLriServiceProvider` (see `composer.json` → `extra.laravel.providers`). Then run:

```bash
php artisan stripe-lri:install
php artisan vendor:publish --tag=stripe-lri-migrations
```

## Configure (interactive)

After Composer finishes, run the installer. It publishes `config/stripe-lri.php` and asks whether the product is **credit-based**:

```bash
php artisan stripe-lri:install
```

It appends (or updates) in `.env`:

- `STRIPE_LRI_CREDIT_BASED=true|false`
- `STRIPE_LRI_REGISTER_ROUTES=true`

Set `STRIPE_LRI_REGISTER_ROUTES=false` if you register the same URLs yourself.

Optional:

- `STRIPE_LRI_USER_MODEL=App\\Models\\User`
- `STRIPE_LRI_USERS_TABLE=users`
- `STRIPE_SECRET` / `STRIPE_WEBHOOK_SECRET` (or `STRIPE_LRI_*` aliases from config)

## Webhook

`POST /stripe/webhook` is registered with CSRF disabled. Point Stripe CLI or the Dashboard to this URL and set `STRIPE_WEBHOOK_SECRET`.

## Indexchecker-style credits & scheduler

The **Indexchecker** app (`indexcheckerv2`) implements billing roughly as follows (for porting into your own `CreditLedger` implementation):

| Area | Indexchecker |
|------|----------------|
| **Core tables** | `packages` (plan_type, billing_cycle, credits_limit, Stripe ids), `subscriptions`, `subscription_items`, `credit_types` (+ `last_monthly_credit_added_at`), `payments`, `invoices`, `orders`, `credit_wallets`, `credit_transactions`, `credits_history`, `coupons` |
| **Purchase / renewal credits** | `App\Services\CreditTypeService`: `addCreditsForPurchase`, `addCreditsForRenewal` — monthly Stripe renewals reset balance to the period allowance; first month only on purchase for subscription/lifetime patterns |
| **Yearly + lifetime monthly refill** | `CreditTypeService::addMonthlyCreditsForYearlySubscriptions()` — selects `credit_types` with types `yearly_single`, `yearly_multiple`, `one_time` (lifetime `stripe`/`custom`), active, credits not null, `last_monthly_credit_added_at` null or &gt; 30 days ago; **sets** credits to monthly allowance (does not stack) |
| **Cron** | `bootstrap/app.php` → `credits:add-monthly-for-yearly` **daily**, `credits:process-history` **hourly** |
| **Webhooks** | `StripeWebhookController`: `checkout.session.completed`, `charge.succeeded`, subscription created/updated/deleted, `invoice.payment_succeeded` / `failed` — syncs subscriptions, payments, credits via `CreditTypeService`, `PackageAccessService`, etc. |

This package exposes **parallel Artisan names** (opt-in scheduler):

- `stripe-lri:credits:add-monthly-for-yearly` — calls `StripeLri\Contracts\CreditLedger::addMonthlyCreditsForYearlyAndLifetime()`
- `stripe-lri:credits:process-history` — calls `CreditLedger::processCreditsHistory()`

Set `STRIPE_LRI_SCHEDULE_ENABLED=true` and bind `CreditLedger` in your `AppServiceProvider` to a class that ports the Indexchecker logic. Default binding is `NullCreditLedger` (no-op).

Publish optional idempotency migration:

```bash
php artisan vendor:publish --tag=stripe-lri-migrations
```

## Next steps

Persist packages, coupons, and payments in **your** database (or extend this package). Controllers currently return **demo** payloads via `StripeLri\Support\DemoCatalog` so Inertia pages render without Stripe keys.
