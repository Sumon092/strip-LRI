# Stripe-LRI (Laravel)

Standalone **Stripe PHP SDK** integration surface for Laravel apps using **Inertia**. Ships routes and controllers aligned with billing, subscriptions, packages, coupons, transactions, invoices, and premium-customer admin screens (empty lists until you wire Stripe and your database).

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

- **`app/Http/Controllers/Admin/`** — Stripe-LRI billing admin (`BillingPackagesController`, `BillingUsersController`, `BillingCouponsController`, `BillingLedgerController`; namespaced so they do not clash with your own `Admin\*` demo controllers).
- **`app/Http/Controllers/Workspace/`** — workspace billing (`WorkspaceBillingController`).
- **`app/Http/Controllers/Webhooks/`** — `StripeWebhookController`, `StripeWebhookInfoController`.
- **`app/Http/Controllers/Concerns/`** — small traits shared by published controllers.
- **`app/Http/Requests/Billing/`** — form requests for packages and admin users.
- **`app/Models/Billing/`** — Eloquent models (`Package`, subscription product rows, etc.).
- **`app/Support/Billing/`** — presenters (`PackagePresenter`, `UserPresenter`, etc.).
- **`app/Contracts/CreditLedger.php`** and **`app/Services/Billing/NullCreditLedger.php`** — credit hook + default no-op.
- **`app/Console/Commands/`** — `StripeLriCreditsProcessHistory`, `StripeLriCreditsAddMonthlyForYearly` when credit-based.
- **`app/Providers/StripeLriServiceProvider.php`** — loads `routes/stripe-lri.php`, binds `CreditLedger`, registers credit Artisan + schedule. The installer adds this class to **`bootstrap/providers.php`** if missing.
- **`routes/stripe-lri.php`** — self-contained route definitions (no vendor route classes). You may edit URLs or middleware here.
- **`database/migrations/`** — copies migration PHP from the package; with `STRIPE_LRI_PUBLISHED_TO_APP=true` the **package** stops `loadMigrationsFrom` so only app migrations run.

When **`app/Providers/StripeLriServiceProvider.php`** is present, the **package** `StripeLri\StripeLriServiceProvider` becomes a **no-op** (only `vendor:publish` for config + `stripe-lri:install` remain useful). You can then run **`composer remove stripe-lri/laravel`** and keep only the copied files; billing continues to work from your `app/` tree.

Use **`--skip-app-publish`** only for legacy vendor-only mode (controllers and migrations from the package).

**Tables** (same as before):

- **When `STRIPE_LRI_CREDIT_BASED=false`:** exactly **nine** tables — `subscription_products`, `subscription_product_items`, `subscription_product_prices`, `subscriptions`, `subscription_items`, `subscription_product_user`, `payments`, `invoices`, **`coupons`**. (Admin “packages” maps to `subscription_products`.)
- **When `STRIPE_LRI_CREDIT_BASED=true`:** those nine plus **`credit_ledger`** (tenth table) and credit columns (`credits_limit` on products, `credits_purchased` on payments/invoices, `credits_balance` / `credits_expires_at` on `subscription_product_user`). No extra `credit_wallets` / `credit_types` / `credit_transactions` tables.
- **Webhook idempotency** is not stored in a package table by default; implement dedupe in your app (e.g. `processed_stripe_events`) if you need it.

Set `STRIPE_LRI_CREDIT_BASED` **before** the first `migrate` (the installer writes `.env` then runs `migrate` in a subprocess so the flag is picked up). If you later turn credits on and used a non–credit-based install, run **`stripe-lri:install --credit-based --force`** so credit migrations are copied, then migrate again.

Re-copy the latest package sources into the app with **`stripe-lri:install --force`** (overwrites published controllers, models, support, provider stub, `routes/stripe-lri.php`, and migrations where applicable).

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
| `STRIPE_LRI_REGISTER_WEBHOOK` | `true` | Keep **`true`**: registers **`GET` + `POST` `/stripe/webhook`**. Real Stripe billing depends on webhooks (payments, renewals, subscription lifecycle). Set `false` only if you register the same signed **`POST`** URL yourself elsewhere. |

### Stripe (production): treat as required

| Variable | Default if unset | Purpose |
|----------|------------------|---------|
| `STRIPE_SECRET` **or** `STRIPE_LRI_SECRET` | *(empty)* | Stripe secret API key (required to call Stripe and for server-side checkout). |
| `STRIPE_WEBHOOK_SECRET` **or** `STRIPE_LRI_WEBHOOK_SECRET` | *(empty)* | **Required** to verify webhook signatures. Without it, `POST /stripe/webhook` cannot accept trusted events (published handler responds **503** until set). |

### Optional — add when you need them

| Variable | Default if unset | Purpose |
|----------|------------------|---------|
| `STRIPE_LRI_USER_MODEL` | `App\Models\User` | Eloquent user class for package user admin. |
| `STRIPE_LRI_USERS_TABLE` | `users` | `users` table name for validation / queries. |
| `STRIPE_LRI_SCHEDULE_ENABLED` | `false` | When `true` **and** `STRIPE_LRI_CREDIT_BASED=true`, registers packaged credit scheduler tasks (see below). |
| `STRIPE_LRI_SCHEDULE_PROCESS_HISTORY` | `true` | Only if credit-based + schedule enabled: hourly `stripe-lri:credits:process-history`. |
| `STRIPE_LRI_SCHEDULE_MONTHLY_CREDITS` | `true` | Only if credit-based + schedule enabled: daily `stripe-lri:credits:add-monthly-for-yearly`. |

Bind `App\Contracts\CreditLedger` in your app before relying on the schedule commands; the default implementation is `App\Services\Billing\NullCreditLedger` (no-op) until you replace it.

## Webhook (mandatory for live billing)

Stripe is the source of truth for money and subscription state in production. Your app **must** receive signed **`POST`** events at `POST /stripe/webhook` (or an equivalent endpoint you own), with **`STRIPE_WEBHOOK_SECRET`** set to the signing secret for that endpoint. The route is registered with CSRF disabled. Use Stripe CLI (`stripe listen --forward-to …`) locally and the Dashboard **Developers → Webhooks** in deployed environments. Catalog rows from the admin UI (packages) are separate from lifecycle sync, which belongs in webhook handling you implement or extend.

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

- `stripe-lri:credits:add-monthly-for-yearly` — calls `App\Contracts\CreditLedger::addMonthlyCreditsForYearlyAndLifetime()`
- `stripe-lri:credits:process-history` — calls `App\Contracts\CreditLedger::processCreditsHistory()`

Set `STRIPE_LRI_SCHEDULE_ENABLED=true` and bind `CreditLedger` in your `AppServiceProvider` to a class that ports the Indexchecker logic. Default binding is `NullCreditLedger` (no-op).

## Next steps

Subscription products are persisted via `App\Http\Controllers\Admin\BillingPackagesController` and child tables. **Creating** a package is disabled until webhooks are usable **and** at least one signed event has been verified: `STRIPE_LRI_REGISTER_WEBHOOK=true`, `STRIPE_WEBHOOK_SECRET` set, migrations applied (`stripe_lri_webhook_health`), and `POST /stripe/webhook` has successfully processed an event (table row `id=1` gets `last_valid_event_at`). Use Stripe CLI (`stripe listen` + `stripe trigger …`) or a Dashboard test delivery. Coupons, transactions, invoices, premium customers, and workspace billing pages render **empty** paginators and zeroed summaries until you connect Stripe and your own queries or repositories.
