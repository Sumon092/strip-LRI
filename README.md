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

Laravel **auto-discovers** `StripeLriServiceProvider` (see `composer.json` → `extra.laravel.providers`). Run **one** installer command — it publishes config, copies **workable code into your app** (default), writes `.env`, and runs **`php artisan migrate`** unless you pass `--no-migrate`:

```bash
php artisan stripe-lri:install
```

Non-interactive example:

```bash
php artisan stripe-lri:install --no-interaction --credit-based
```

**Default install (no `--skip-app-publish`):**

- **`app/StripeLri/`** — controllers, models, form requests, and support classes (namespace `App\StripeLri\…`), transformed from the package so you edit your app, not `vendor`.
- **`database/migrations/`** — copies the same migration PHP files from the package; with `STRIPE_LRI_PUBLISHED_TO_APP=true` the provider **stops** `loadMigrationsFrom` on the package so only these app migrations run.
- **`routes/stripe-lri.php`** — three-line file that calls `StripeLri\Routing\StripeLriRouteRegistrar::register('App\StripeLri\Http\Controllers')` (route *definitions* stay in the small package registrar; **handlers** are your app classes).

Use **`--skip-app-publish`** only if you want the legacy vendor-only mode (controllers and migrations resolved from the package).

**Tables** (same as before):

- **When `STRIPE_LRI_CREDIT_BASED=false`:** exactly **nine** tables — `subscription_products`, `subscription_product_items`, `subscription_product_prices`, `subscriptions`, `subscription_items`, `subscription_product_user`, `payments`, `invoices`, **`coupons`**. (Admin “packages” maps to `subscription_products`.)
- **When `STRIPE_LRI_CREDIT_BASED=true`:** those nine plus **`credit_ledger`** (tenth table) and credit columns (`credits_limit` on products, `credits_purchased` on payments/invoices, `credits_balance` / `credits_expires_at` on `subscription_product_user`). No extra `credit_wallets` / `credit_types` / `credit_transactions` tables.
- **Webhook idempotency** is not stored in a package table by default; implement dedupe in your app (e.g. `processed_stripe_events`) if you need it.

Set `STRIPE_LRI_CREDIT_BASED` **before** the first `migrate` (the installer writes `.env` then runs `migrate` in a subprocess so the flag is picked up). If you later turn credits on and used a non–credit-based install, run **`stripe-lri:install --credit-based --force`** so credit migrations are copied, then migrate again.

Re-copy the latest package sources into `app/StripeLri` with **`stripe-lri:install --force`** (overwrites published PHP, migrations, and `routes/stripe-lri.php` where applicable).

**Note:** `composer require` cannot safely run Artisan for every project automatically. The supported flow is **`composer require`** then **`php artisan stripe-lri:install`**. To skip migrations (e.g. CI), use `--no-migrate`.

Optional — run install after every `composer install` on **this** app only (add to the **host** `composer.json` `scripts`):

```json
"post-install-cmd": [
    "@php artisan stripe-lri:install --no-interaction --credit-based"
]
```

Adjust flags to match your product; use `--no-migrate` in environments where you manage migrations separately.

## Configure (interactive)

After Composer finishes, run the installer. It publishes `config/stripe-lri.php` and asks whether the product is **credit-based**:

```bash
php artisan stripe-lri:install
```

## Environment variables

All keys below are read from **`.env`** via `config/stripe-lri.php` (after you publish config). Middleware arrays in that file are **not** driven by `.env`.

### Set by `php artisan stripe-lri:install`

| Variable | Example | Purpose |
|----------|---------|---------|
| `STRIPE_LRI_CREDIT_BASED` | `true` / `false` | From the install prompt. When `false`, credit-only **migrations** (columns + `credit_*` tables) are **not** loaded; credit Artisan commands and credit scheduler entries are **not** registered. |
| `STRIPE_LRI_REGISTER_ROUTES` | `true` | When `true`, the package registers workspace + admin **billing UI** routes. Set `false` if your app already defines those URLs. |
| `STRIPE_LRI_REGISTER_WEBHOOK` | `true` | When `true`, registers **`GET` + `POST` `/stripe/webhook`** (no edits to `routes/web.php`). Set `false` only if you define these routes yourself. |

### Optional — add when you need them

| Variable | Default if unset | Purpose |
|----------|------------------|---------|
| `STRIPE_LRI_USER_MODEL` | `App\Models\User` | Eloquent user class for package user admin. |
| `STRIPE_LRI_USERS_TABLE` | `users` | `users` table name for validation / queries. |
| `STRIPE_SECRET` **or** `STRIPE_LRI_SECRET` | *(empty)* | Stripe secret API key. |
| `STRIPE_WEBHOOK_SECRET` **or** `STRIPE_LRI_WEBHOOK_SECRET` | *(empty)* | Stripe webhook signing secret. |
| `STRIPE_LRI_SCHEDULE_ENABLED` | `false` | When `true` **and** `STRIPE_LRI_CREDIT_BASED=true`, registers packaged credit scheduler tasks (see below). |
| `STRIPE_LRI_SCHEDULE_PROCESS_HISTORY` | `true` | Only if credit-based + schedule enabled: hourly `stripe-lri:credits:process-history`. |
| `STRIPE_LRI_SCHEDULE_MONTHLY_CREDITS` | `true` | Only if credit-based + schedule enabled: daily `stripe-lri:credits:add-monthly-for-yearly`. |

Bind `StripeLri\Contracts\CreditLedger` in your app before relying on the schedule commands; the default implementation is a no-op.

## Webhook

`POST /stripe/webhook` is registered with CSRF disabled. Point Stripe CLI or the Dashboard to this URL and set `STRIPE_WEBHOOK_SECRET`.

## Indexchecker-style credits & scheduler

The **Indexchecker** app (`indexcheckerv2`) implements billing roughly as follows (for porting into your own `CreditLedger` implementation):

| Area | Indexchecker |
|------|----------------|
| **Core tables (this package)** | Nine: `subscription_products` (+ `subscription_product_items` / `subscription_product_prices`), `subscriptions`, `subscription_items`, `subscription_product_user`, `payments`, `invoices`, **`coupons`**. Credit-based adds a tenth table, **`credit_ledger`**, plus credit columns. Indexchecker used a different shape — port that logic into your own `CreditLedger` binding. |
| **Purchase / renewal credits** | `App\Services\CreditTypeService`: `addCreditsForPurchase`, `addCreditsForRenewal` — monthly Stripe renewals reset balance to the period allowance; first month only on purchase for subscription/lifetime patterns |
| **Yearly + lifetime monthly refill** | `CreditTypeService::addMonthlyCreditsForYearlySubscriptions()` — selects `credit_types` with types `yearly_single`, `yearly_multiple`, `one_time` (lifetime `stripe`/`custom`), active, credits not null, `last_monthly_credit_added_at` null or &gt; 30 days ago; **sets** credits to monthly allowance (does not stack) |
| **Cron** | `bootstrap/app.php` → `credits:add-monthly-for-yearly` **daily**, `credits:process-history` **hourly** |
| **Webhooks** | `StripeWebhookController`: `checkout.session.completed`, `charge.succeeded`, subscription created/updated/deleted, `invoice.payment_succeeded` / `failed` — syncs subscriptions, payments, credits via `CreditTypeService`, `PackageAccessService`, etc. |

This package exposes **parallel Artisan names** (opt-in scheduler):

- `stripe-lri:credits:add-monthly-for-yearly` — calls `StripeLri\Contracts\CreditLedger::addMonthlyCreditsForYearlyAndLifetime()`
- `stripe-lri:credits:process-history` — calls `CreditLedger::processCreditsHistory()`

Set `STRIPE_LRI_SCHEDULE_ENABLED=true` and bind `CreditLedger` in your `AppServiceProvider` to a class that ports the Indexchecker logic. Default binding is `NullCreditLedger` (no-op).

## Next steps

Subscription products are persisted via `StripeLri\Http\Controllers\Admin\AdminPackagesController` and child tables. Other admin billing screens (coupons, transactions, invoices list) still use **demo** payloads from `StripeLri\Support\DemoCatalog` until you wire them to your data.
