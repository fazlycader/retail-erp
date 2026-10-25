<?php

namespace App\Services\Reports;

use App\Enums\Role;
use App\Models\Location;
use App\Models\User;
use App\Support\CurrentLocation;
use Illuminate\Http\Request;

/**
 * Which locations a report or the dashboard covers. Admins: every active location; everyone else:
 * the active locations they work at. ?location=all or ?location={id} picks within that; by
 * default it is the current location (or all of them in "All locations" mode).
 */
final class ReportScope
{
    /**
     * @return array<int, int>
     */
    public static function allowed(User $user): array
    {
        return Location::query()->where('is_active', true)
            ->when($user->role !== Role::Admin, fn ($q) => $q->whereIn('id', $user->locationIds()))
            ->orderBy('id')->pluck('id')->all();
    }

    /**
     * @return array{ids: array<int, int>, selected: string}
     */
    public static function resolve(Request $request): array
    {
        $allowed = self::allowed($request->user());
        $param = (string) $request->query('location', '');

        if ($param === 'all') {
            return ['ids' => $allowed, 'selected' => 'all'];
        }

        if ($param !== '' && in_array((int) $param, $allowed, true)) {
            return ['ids' => [(int) $param], 'selected' => $param];
        }

        if (CurrentLocation::isAll()) {
            return ['ids' => $allowed, 'selected' => 'all'];
        }

        $current = CurrentLocation::id() ?? $request->user()->home_location_id;

        return in_array((int) $current, $allowed, true)
            ? ['ids' => [(int) $current], 'selected' => (string) $current]
            : ['ids' => $allowed, 'selected' => 'all'];
    }

    /**
     * @return array<int, array{id: int, name: string, code: string}>
     */
    public static function options(User $user): array
    {
        return Location::query()->whereIn('id', self::allowed($user))->orderBy('id')->get(['id', 'name', 'code'])
            ->map(fn (Location $l) => ['id' => $l->id, 'name' => $l->name, 'code' => $l->code])->all();
    }
}
