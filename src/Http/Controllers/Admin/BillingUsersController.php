<?php

namespace StripeLri\Http\Controllers\Admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use StripeLri\Http\Requests\AdminUserCreditsAdjustRequest;
use StripeLri\Http\Requests\AdminUserUpdateRequest;
use StripeLri\Services\DatabaseCreditLedger;
use StripeLri\Support\UserPresenter;

class BillingUsersController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var class-string<Model> $userClass */
        $userClass = config('stripe-lri.models.user');

        $search = (string) $request->query('search', '');
        $role = (string) $request->query('role', 'all');
        $status = (string) $request->query('status', 'all');
        $perPage = (int) $request->query('per_page', 12);
        if (! in_array($perPage, [12, 25, 50, 100], true)) {
            $perPage = 12;
        }

        $query = $userClass::query()->orderByDesc($userClass::make()->getKeyName());

        if ($role !== 'all') {
            $query->where('role', $role);
        }
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        if ($search !== '') {
            $term = '%'.mb_strtolower($search).'%';
            $query->where(function ($q) use ($term): void {
                $q->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(username) LIKE ?', [$term]);
            });
        }

        $paginator = $query->paginate($perPage)->withQueryString();
        $ids = $paginator->getCollection()->pluck($userClass::make()->getKeyName())->all();
        $sessions = UserPresenter::latestSessionsForUserIds($ids);

        $creditBased = (bool) config('stripe-lri.credit_based');
        $siteLimited = (bool) config('stripe-lri.site_limit');

        $creditMap = ($creditBased && $ids !== [])
            ? DB::table('subscription_product_user as spu')
                ->join('subscription_products as sp', 'sp.id', '=', 'spu.subscription_product_id')
                ->whereIn('spu.user_id', $ids)
                ->where('spu.is_active', true)
                ->groupBy('spu.user_id')
                ->select([
                    'spu.user_id',
                    DB::raw('SUM(spu.credits_balance) as total_balance'),
                    DB::raw('SUM(sp.credits_limit) as total_plan'),
                ])
                ->get()
                ->keyBy('user_id')
            : collect();

        $siteMap = ($siteLimited && $ids !== [])
            ? DB::table('subscription_product_user as spu')
                ->join('subscription_products as sp', 'sp.id', '=', 'spu.subscription_product_id')
                ->whereIn('spu.user_id', $ids)
                ->where('spu.is_active', true)
                ->groupBy('spu.user_id')
                ->select([
                    'spu.user_id',
                    DB::raw('SUM(spu.site_count) as total_site_count'),
                    DB::raw('SUM(sp.site_limit) as total_site_limit'),
                ])
                ->get()
                ->keyBy('user_id')
            : collect();

        $rows = $paginator->getCollection()->map(function (Model $u) use ($sessions, $creditMap, $creditBased, $siteMap, $siteLimited): array {
            $row  = UserPresenter::row($u, $sessions);
            $cred = $creditBased ? $creditMap->get((int) $u->getKey()) : null;
            if ($cred !== null) {
                $plan    = (int) $cred->total_plan;
                $balance = (int) $cred->total_balance;
                $row['plan_credits']      = $plan;
                $row['remaining_credits'] = $balance;
                $row['credits_used']      = max(0, $plan - $balance);
                $row['type']              = 'Premium';
            }
            $site = $siteLimited ? $siteMap->get((int) $u->getKey()) : null;
            if ($site !== null) {
                $row['site_count'] = (int) $site->total_site_count;
                $row['site_limit'] = (int) $site->total_site_limit;
            } else {
                $row['site_count'] = 0;
                $row['site_limit'] = 0;
            }
            return $row;
        })->values()->all();
        $paginator->setCollection(collect($rows));

        $stats = [
            'totalUsers' => $userClass::query()->count(),
            'activeUsers' => $userClass::query()->where('is_active', true)->count(),
            'newUsersThisWeek' => $userClass::query()->where('created_at', '>=', now()->subDays(7))->count(),
        ];

        return Inertia::render('Admin/Users', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
            'siteLimited' => (bool) config('stripe-lri.site_limit'),
            'stats' => $stats,
            'users' => $paginator,
            'signupTrend' => UserPresenter::signupTrendLast7Days(),
            'filters' => [
                'search' => $search,
                'role' => $role,
                'status' => $status,
                'per_page' => $perPage,
            ],
            'filterOptions' => [
                'roles' => [
                    ['value' => 'all', 'label' => 'All roles'],
                    ['value' => 'admin', 'label' => 'Admin'],
                    ['value' => 'user', 'label' => 'User'],
                ],
                'statuses' => [
                    ['value' => 'all', 'label' => 'All statuses'],
                    ['value' => 'active', 'label' => 'Active'],
                    ['value' => 'inactive', 'label' => 'Inactive'],
                ],
            ],
        ]);
    }

    public function show(int $user): Response
    {
        $model    = $this->resolveUser($user);
        $sessions = UserPresenter::latestSessionsForUserIds([(int) $model->getKey()]);

        return Inertia::render('Admin/Users/Show', array_merge(
            ['creditBased' => (bool) config('stripe-lri.credit_based'), 'siteLimited' => (bool) config('stripe-lri.site_limit'), 'user' => UserPresenter::row($model, $sessions)],
            $this->billingPayload((int) $model->getKey()),
        ));
    }

    public function edit(int $user): Response
    {
        $model    = $this->resolveUser($user);
        $sessions = UserPresenter::latestSessionsForUserIds([(int) $model->getKey()]);

        return Inertia::render('Admin/Users/Edit', array_merge(
            ['creditBased' => (bool) config('stripe-lri.credit_based'), 'siteLimited' => (bool) config('stripe-lri.site_limit'), 'user' => UserPresenter::row($model, $sessions)],
            $this->billingPayload((int) $model->getKey()),
        ));
    }

    /** @return array<string, mixed> */
    private function billingPayload(int $userId): array
    {
        $creditBased = (bool) config('stripe-lri.credit_based');
        $siteLimited = (bool) config('stripe-lri.site_limit');

        /** @var class-string<Model> $spuClass */
        $spuClass = config('stripe-lri.models.subscription_product_user');

        /** @var class-string<Model> $paymentClass */
        $paymentClass = config('stripe-lri.models.payment');

        $subscriptionCount = $spuClass::where('user_id', $userId)->where('is_active', true)->count();

        $creditPackages = [];
        $creditSummary  = null;
        if ($creditBased) {
            $spus = $spuClass::with('product.prices')
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->get();

            $creditPackages = $spus->map(fn (Model $spu): array => [
                'id'          => (int) $spu->getKey(),
                'planName'    => (string) ($spu->product?->plan_name ?? '—'),
                'planCredits' => (int) ($spu->product?->getAttribute('credits_limit') ?? 0),
                'credits'     => (int) ($spu->getAttribute('credits_balance') ?? 0),
                'expiresAt'   => $spu->expires_at?->format('M d, Y') ?? 'Ongoing',
                'planType'    => (string) ($spu->product?->prices->first()?->plan_type ?? 'monthly'),
            ])->all();

            $totalPlan     = array_sum(array_column($creditPackages, 'planCredits'));
            $totalBalance  = array_sum(array_column($creditPackages, 'credits'));
            $creditSummary = [
                'plan_credits'      => $totalPlan,
                'remaining_credits' => $totalBalance,
                'credits_used'      => max(0, $totalPlan - $totalBalance),
            ];
        }

        $recentPurchases = $paymentClass::with('product')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->take(5)
            ->get()
            ->map(fn (Model $p): array => [
                'id'       => (int) $p->getKey(),
                'planName' => (string) ($p->product?->plan_name ?? '—'),
                'amount'   => '$'.number_format((float) $p->getAttribute('amount'), 2),
                'status'   => ucfirst((string) $p->getAttribute('status')),
                'paidAt'   => \Carbon\Carbon::parse($p->getAttribute('paid_at') ?? $p->getAttribute('created_at'))->format('M d, Y'),
            ])->all();

        $creditTransactions = [];
        if ($creditBased) {
            $creditTransactions = DB::table('credit_ledger')
                ->where('user_id', $userId)
                ->orderByDesc('created_at')
                ->take(20)
                ->get()
                ->map(fn (object $r): array => [
                    'id'           => (int) $r->id,
                    'type'         => (string) ($r->type ?? ($r->delta >= 0 ? 'credit' : 'debit')),
                    'creditAmount' => (int) ($r->credit_amount ?? abs($r->delta)),
                    'entryType'    => (string) $r->entry_type,
                    'description'  => (string) ($r->description ?? ''),
                    'createdAt'    => \Carbon\Carbon::parse($r->created_at)->format('M d, Y H:i'),
                ])->all();
        }

        $siteSummary = null;
        if ($siteLimited) {
            $spusForSite = $creditBased
                ? $spus
                : $spuClass::with('product')
                    ->where('user_id', $userId)
                    ->where('is_active', true)
                    ->get();
            $siteSummary = [
                'site_count' => (int) $spusForSite->sum(fn (Model $s): int => (int) ($s->getAttribute('site_count') ?? 0)),
                'site_limit' => (int) $spusForSite->sum(fn (Model $s): int => (int) ($s->product?->getAttribute('site_limit') ?? 0)),
            ];
        }

        return compact('subscriptionCount', 'creditPackages', 'creditSummary', 'recentPurchases', 'creditTransactions', 'siteSummary');
    }

    public function update(AdminUserUpdateRequest $request, int $user): RedirectResponse
    {
        $model = $this->resolveUser($user);
        $data = $request->validated();

        if (array_key_exists('handle', $data)) {
            $handle = trim((string) $data['handle']);
            $data['username'] = $handle === '' ? null : $handle;
            unset($data['handle']);
        }

        if (array_key_exists('password', $data)) {
            $pw = $data['password'];
            if ($pw === null || $pw === '') {
                unset($data['password']);
            }
        }

        $model->fill($data);
        $model->save();

        return redirect()
            ->route('admin.users.show', $model->getKey())
            ->with('success', 'User updated.');
    }

    public function adjustCredits(AdminUserCreditsAdjustRequest $request, int $user): RedirectResponse
    {
        $model = $this->resolveUser($user);
        $validated = $request->validated();
        $amount = (int) $validated['amount'];
        $action = (string) $validated['action'];

        $delta = $action === 'add' ? $amount : -$amount;

        /** @var class-string<Model> $spuClass */
        $spuClass = config('stripe-lri.models.subscription_product_user');
        $spu = $spuClass::where('user_id', (int) $model->getKey())->where('is_active', true)->first();

        if ($spu !== null) {
            $newBalance = max(0, (int) $spu->getAttribute('credits_balance') + $delta);
            $spu->setAttribute('credits_balance', $newBalance);
            $spu->save();
        }

        if (config('stripe-lri.credit_based')) {
            DatabaseCreditLedger::recordEntry(
                userId: (int) $model->getKey(),
                productId: $spu ? (int) $spu->getAttribute('subscription_product_id') : null,
                delta: $delta,
                entryType: $action === 'add' ? 'manual_add' : 'manual_remove',
                description: 'Manual credit adjustment by admin',
            );
        }

        return redirect()
            ->route('admin.users.show', $model->getKey())
            ->with('success', $action === 'add' ? 'Credits added.' : 'Credits removed.');
    }

    public function impersonate(Request $request, int $user): RedirectResponse
    {
        if ($request->session()->has('impersonator_id')) {
            return redirect()
                ->route('admin.users.index')
                ->with('error', 'You are already viewing the app as another user. Switch back first.');
        }

        $admin = $request->user();
        $target = $this->resolveUser($user);

        if ($admin === null || ! method_exists($admin, 'isAdmin') || ! $admin->isAdmin()) {
            abort(403);
        }

        if ((int) $admin->getKey() === (int) $target->getKey()) {
            return redirect()
                ->route('admin.users.index')
                ->with('error', 'You are already signed in as this account.');
        }

        if (method_exists($target, 'isAdmin') && $target->isAdmin()) {
            return redirect()
                ->route('admin.users.index')
                ->with('error', 'Signing in as admin accounts is not allowed.');
        }

        $request->session()->put('impersonator_id', $admin->getKey());
        $request->session()->put('impersonator_name', (string) $admin->getAttribute('name'));

        Auth::login($target);
        $request->session()->regenerate();

        $home = method_exists($target, 'homeUrl') ? $target->homeUrl() : route('overview.index');

        return redirect()
            ->to($home)
            ->with('success', 'You are now signed in as '.$target->getAttribute('name').'.');
    }

    public function destroy(Request $request, int $user): RedirectResponse
    {
        $model = $this->resolveUser($user);

        if ($request->user()?->getKey() === $model->getKey()) {
            return redirect()
                ->route('admin.users.index')
                ->with('error', 'You cannot delete your own account.');
        }

        $admin = $request->user();

        // Log deletion if the host app provides the AccountDeletionLog model
        $logClass = config('stripe-lri.models.account_deletion_log');
        if ($logClass !== null && class_exists($logClass)) {
            $logClass::create([
                'subject_user_id' => (int) $model->getKey(),
                'name'            => (string) $model->getAttribute('name'),
                'email'           => (string) $model->getAttribute('email'),
                'removal_type'    => 'permanent',
                'admin_id'        => $admin ? (int) $admin->getKey() : null,
                'admin_name'      => $admin ? (string) $admin->getAttribute('name') : null,
            ]);
        }

        $model->delete();

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'User deleted.');
    }

    private function resolveUser(int $user): Model
    {
        /** @var class-string<Model> $userClass */
        $userClass = config('stripe-lri.models.user');

        /** @var Model $found */
        $found = $userClass::query()->whereKey($user)->firstOrFail();

        return $found;
    }
}
