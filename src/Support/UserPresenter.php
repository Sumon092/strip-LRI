<?php

namespace App\Support\Billing;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class UserPresenter
{
    /**
     * @param  Collection<int, object{user_id: int|null, last_activity: int, ip_address: string|null}>  $latestSessions
     * @return array<string, mixed>
     */
    public static function row(Model $user, Collection $latestSessions): array
    {
        $session = $latestSessions->get($user->getKey());
        $lastLoginAt = null;
        $lastLoginIp = null;
        if ($session !== null) {
            $lastLoginAt = Carbon::createFromTimestamp((int) $session->last_activity)->toIso8601String();
            $lastLoginIp = $session->ip_address !== null ? (string) $session->ip_address : null;
        }

        $isActive = (bool) $user->getAttribute('is_active');
        $planCredits = (int) ($user->getAttribute('plan_credits') ?? 0);
        $remainingCredits = (int) ($user->getAttribute('remaining_credits') ?? 0);
        $creditsUsed = (int) ($user->getAttribute('credits_used') ?? 0);

        return [
            'id' => (int) $user->getKey(),
            'name' => (string) $user->getAttribute('name'),
            'email' => (string) $user->getAttribute('email'),
            'username' => $user->getAttribute('username') !== null ? (string) $user->getAttribute('username') : null,
            'role' => (string) $user->getAttribute('role'),
            'is_admin' => self::userIsAdmin($user),
            'credits_used' => $creditsUsed,
            'remaining_credits' => $remainingCredits,
            'plan_credits' => $planCredits,
            'type' => $planCredits >= 5000 ? 'Premium' : 'Free',
            'last_login_at' => $lastLoginAt,
            'last_login_ip' => $lastLoginIp,
            'status' => $isActive ? 'active' : 'inactive',
            'is_active' => $isActive,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'subscribed_at' => null,
            'created_at' => $user->created_at?->toIso8601String() ?? now()->toIso8601String(),
            'avatar' => $user->getAttribute('avatar') !== null ? (string) $user->getAttribute('avatar') : null,
        ];
    }

    /**
     * @return list<array{k: string, v: int}>
     */
    public static function signupTrendLast7Days(): array
    {
        /** @var class-string<Model> $class */
        $class = config('stripe-lri.models.user');

        $out = [];
        for ($i = 6; $i >= 0; $i--) {
            /** @var CarbonInterface $day */
            $day = now()->subDays($i)->startOfDay();
            $count = $class::query()
                ->where('created_at', '>=', $day)
                ->where('created_at', '<=', $day->copy()->endOfDay())
                ->count();
            $out[] = ['k' => $day->format('D'), 'v' => $count];
        }

        return $out;
    }

    /**
     * @param  list<int>|array<int, int>  $userIds
     * @return Collection<int, object{user_id: int|null, last_activity: int, ip_address: string|null}>
     */
    public static function latestSessionsForUserIds(array $userIds): Collection
    {
        if ($userIds === []) {
            return collect();
        }

        $ids = array_values(array_unique(array_map('intval', $userIds)));

        /** @var Collection<int, object{user_id: int|null, last_activity: int, ip_address: string|null}> $rows */
        $rows = DB::table('sessions')
            ->select(['user_id', 'last_activity', 'ip_address'])
            ->whereIn('user_id', $ids)
            ->whereNotNull('user_id')
            ->orderByDesc('last_activity')
            ->get();

        return $rows->groupBy('user_id')->map->first();
    }

    private static function userIsAdmin(Model $user): bool
    {
        if (method_exists($user, 'isAdmin')) {
            return (bool) $user->isAdmin();
        }

        return (string) $user->getAttribute('role') === 'admin';
    }
}
