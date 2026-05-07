<?php

namespace StripeLri\Http\Controllers\Admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use StripeLri\Http\Requests\AdminUserCreditsAdjustRequest;
use StripeLri\Http\Requests\AdminUserUpdateRequest;
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

        $rows = $paginator->getCollection()->map(
            fn (Model $u): array => UserPresenter::row($u, $sessions),
        )->values()->all();
        $paginator->setCollection(collect($rows));

        $stats = [
            'totalUsers' => $userClass::query()->count(),
            'activeUsers' => $userClass::query()->where('is_active', true)->count(),
            'newUsersThisWeek' => $userClass::query()->where('created_at', '>=', now()->subDays(7))->count(),
        ];

        return Inertia::render('Admin/Users', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
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
        $model = $this->resolveUser($user);
        $sessions = UserPresenter::latestSessionsForUserIds([(int) $model->getKey()]);

        return Inertia::render('Admin/Users/Show', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
            'user' => UserPresenter::row($model, $sessions),
            'subscriptionCount' => 0,
            'creditPackages' => [],
            'recentPurchases' => [],
            'creditTransactions' => [],
        ]);
    }

    public function edit(int $user): Response
    {
        $model = $this->resolveUser($user);
        $sessions = UserPresenter::latestSessionsForUserIds([(int) $model->getKey()]);

        return Inertia::render('Admin/Users/Edit', [
            'creditBased' => (bool) config('stripe-lri.credit_based'),
            'user' => UserPresenter::row($model, $sessions),
            'subscriptionCount' => 0,
            'creditPackages' => [],
            'recentPurchases' => [],
            'creditTransactions' => [],
        ]);
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

        if (array_key_exists('credits', $data)) {
            $data['remaining_credits'] = (int) $data['credits'];
            unset($data['credits']);
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

        $remaining = (int) $model->getAttribute('remaining_credits');
        if ($action === 'add') {
            $remaining += $amount;
        } else {
            $remaining = max(0, $remaining - $amount);
        }

        $model->setAttribute('remaining_credits', $remaining);
        $model->save();

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
