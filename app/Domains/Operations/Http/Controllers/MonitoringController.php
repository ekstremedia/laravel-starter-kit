<?php

namespace App\Domains\Operations\Http\Controllers;

use App\Domains\Users\Models\User;
use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class MonitoringController extends Controller
{
    private const VALID_TABS = ['activity', 'logs', 'pulse', 'horizon'];

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            // Closure rule rather than `exists:users,id` so the check goes
            // through App\Domains\Users\Models\User (pinned to the central connection).
            // The string rule would use the default connection, which can
            // be the tenant connection in some contexts.
            'user_id' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value !== null && ! User::whereKey($value)->exists()) {
                        $fail(__('validation.exists', ['attribute' => $attribute]));
                    }
                },
            ],
            'log_name' => ['nullable', 'string', 'max:100'],
            'event' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        // $request->validate() only returns keys that were present. Backfill
        // nulls so the Vue page's v-model bindings (filters.date_from, ...)
        // never hit `undefined` on first render.
        $filters = array_merge(
            ['user_id' => null, 'log_name' => null, 'event' => null, 'date_from' => null, 'date_to' => null],
            $filters,
        );

        $tab = $request->string('tab')->toString();
        if (! in_array($tab, self::VALID_TABS, true)) {
            $tab = 'activity';
        }

        $activities = null;
        $users = collect();
        $logNames = collect();
        $events = collect();

        if ($tab === 'activity') {
            $activities = Activity::query()
                ->with('causer:id,first_name,last_name,email')
                ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('causer_id', $v)->where('causer_type', (new User)->getMorphClass()))
                ->when($filters['log_name'] ?? null, fn ($q, $v) => $q->where('log_name', $v))
                ->when($filters['event'] ?? null, fn ($q, $v) => $q->where('event', $v))
                ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
                ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
                ->latest()
                ->paginate(25)
                ->withQueryString();

            // Cap the dropdown so a 100k-user install doesn't hydrate every
            // row on page load. If the user the admin wants isn't in the top
            // slice they can still filter by activity's causer_id directly.
            $users = User::orderBy('email')
                ->limit(500)
                ->get(['id', 'email', 'first_name', 'last_name']);
            // Distinct() on activity_log would full-scan the table every time
            // the page renders. The set of log_names / events barely changes,
            // so a short cache is a big hit on larger installations.
            $logNames = Cache::remember('monitoring.activity.log_names', 300, fn () => Activity::query()->select('log_name')->distinct()->whereNotNull('log_name')->pluck('log_name')->values()->all());
            $events = Cache::remember('monitoring.activity.events', 300, fn () => Activity::query()->select('event')->distinct()->whereNotNull('event')->pluck('event')->values()->all());
        }

        return Inertia::render('Admin/Monitoring', [
            'tab' => $tab,
            'activities' => $activities,
            'filters' => $filters,
            'users' => $users,
            'logNames' => $logNames,
            'events' => $events,
            'endpoints' => $this->iframeEndpoints(),
        ]);
    }

    /**
     * Legacy redirect for the old /admin/activity URL. Kept as a controller
     * method (rather than an inline route closure) so routes can be cached.
     */
    public function activityRedirect(Request $request): RedirectResponse
    {
        return redirect()->route(
            'admin.monitoring.index',
            ['tab' => 'activity'] + $request->query(),
        );
    }

    /**
     * Resolve iframe URLs from package config so custom paths keep working.
     *
     * @return array{logs: string, pulse: string, horizon: string}
     */
    private function iframeEndpoints(): array
    {
        return [
            'logs' => '/'.ltrim((string) config('log-viewer.route_path', 'log-viewer'), '/'),
            'pulse' => '/'.ltrim((string) config('pulse.path', 'pulse'), '/'),
            'horizon' => '/'.ltrim((string) config('horizon.path', 'horizon'), '/'),
        ];
    }
}
