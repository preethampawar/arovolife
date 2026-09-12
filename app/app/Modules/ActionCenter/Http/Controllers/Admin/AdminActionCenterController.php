<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Http\Controllers\Admin;

use App\Modules\ActionCenter\Contracts\ActionProvider;
use App\Modules\ActionCenter\Exceptions\ActionCenterException;
use App\Modules\ActionCenter\Exceptions\UnknownActionType;
use App\Modules\ActionCenter\Services\ActionCenterRegistry;
use App\Modules\ActionCenter\Services\ActionCenterService;
use App\Modules\ActionCenter\Services\ActionCenterSettings;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The Action Center screens (plan §7). This controller reads only through
 * `ActionCenterRegistry`/`ActionCenterService` — it runs no query of its own
 * against a source table, it only shapes what the providers already return
 * for display, filtering and pagination.
 *
 * `action.center.view` (routed in `routes/web.php`) opens these two GET
 * screens. The two writes below additionally require the *provider's own*
 * permission (plan §5, §10.2) — enforced here via `Registry::findFor()`,
 * which hides a type from a viewer who cannot act on it behind the same
 * "unknown key" response the index already gives that viewer.
 */
final class AdminActionCenterController extends Controller
{
    private const PER_PAGE = 50;

    /** How many items each provider is asked for before filtering/paging. Providers already cap their own backlog. */
    private const FETCH_LIMIT = 500;

    public function index(Request $request, ActionCenterService $service): View
    {
        $summary = $service->summary($request->user())
            // Critical group first (plan §7); ties keep catalogue order
            // because sortByDesc is stable and ActionGroup::all() order is
            // how ActionCenterService::buildSummary() already emitted them.
            ->sortByDesc(fn (array $rows): int => Collection::make($rows)->max(
                fn (array $row): int => Severity::rank((string) $row['severity'])
            ));

        return view('admin.action-center.index', [
            'summary' => $summary,
            'groupLabels' => ActionGroup::all(),
        ]);
    }

    public function show(Request $request, string $key, ActionCenterRegistry $registry): View
    {
        $provider = $this->findOrAbort($registry, $request->user(), $key);

        $items = $provider->items(self::FETCH_LIMIT);

        $warehouses = $items
            ->map(function (ActionItem $item): ?string {
                $warehouseCode = $item->meta['warehouse_code'] ?? null;

                return $warehouseCode === null ? null : (string) $warehouseCode;
            })
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $severity = $request->query('severity');
        $age = $request->query('age');
        $warehouse = $request->query('warehouse');

        if (is_string($severity) && $severity !== '') {
            $items = $items->filter(fn (ActionItem $item): bool => $item->severity === $severity);
        }

        if (is_string($age) && $age !== '') {
            $items = $items->filter(fn (ActionItem $item): bool => self::matchesAgeBucket($item->ageHours(), $age));
        }

        if ($warehouses->isNotEmpty() && is_string($warehouse) && $warehouse !== '') {
            $items = $items->filter(fn (ActionItem $item): bool => ($item->meta['warehouse_code'] ?? null) === $warehouse);
        }

        $items = $items->values();

        $page = max(1, (int) $request->query('page', 1));

        $paginatedItems = new LengthAwarePaginator(
            $items->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values(),
            $items->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('admin.action-center.show', [
            'provider' => $provider,
            'items' => $paginatedItems,
            'warehouses' => $warehouses,
            'filters' => ['severity' => $severity, 'age' => $age, 'warehouse' => $warehouse],
            'maxSnoozeDays' => app(ActionCenterSettings::class)->maxSnoozeDays(),
        ]);
    }

    public function snooze(Request $request, string $key, ActionCenterRegistry $registry, ActionCenterService $service): RedirectResponse
    {
        // Only a viewer who holds THIS provider's own permission may snooze
        // one of its rows — never the blanket `action.center.view` (plan §5).
        $provider = $this->findOrAbort($registry, $request->user(), $key);

        $max = app(ActionCenterSettings::class)->maxSnoozeDays();

        $validated = $request->validate([
            'subject_id' => ['required', 'integer', 'min:1'],
            'days' => ['required', 'integer', 'min:1', 'max:'.$max],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        try {
            $service->snooze(
                $request->user(),
                $key,
                // The subject type is the provider's own, never the posted
                // value: a snooze row must key on what the provider queries.
                $provider->subjectType(),
                (int) $validated['subject_id'],
                (int) $validated['days'],
                $validated['reason'],
            );
        } catch (ActionCenterException $e) {
            abort($e->status(), $e->getMessage());
        }

        return redirect()->route('admin.action-center.show', $key)->with('status', 'Item snoozed.');
    }

    public function unsnooze(Request $request, string $key, ActionCenterRegistry $registry, ActionCenterService $service): RedirectResponse
    {
        $provider = $this->findOrAbort($registry, $request->user(), $key);

        $validated = $request->validate([
            'subject_id' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $service->unsnooze($request->user(), $key, $provider->subjectType(), (int) $validated['subject_id']);
        } catch (ActionCenterException $e) {
            abort($e->status(), $e->getMessage());
        }

        return redirect()->route('admin.action-center.show', $key)->with('status', 'Snooze removed.');
    }

    /**
     * `Registry::findFor()` throws for both an unknown key and a key the
     * viewer's permission does not cover — the same "not found" response
     * either way (plan §10.2: hide, never 403 with the type's existence
     * leaked). Turned into an actual HTTP 404 here rather than left to
     * bubble as an uncaught domain exception.
     */
    private function findOrAbort(ActionCenterRegistry $registry, User $user, string $key): ActionProvider
    {
        try {
            return $registry->findFor($user, $key);
        } catch (UnknownActionType $e) {
            abort($e->status(), $e->getMessage());
        }

        throw new UnknownActionType($key); // @phpstan-ignore-line unreachable — abort() above always throws
    }

    private static function matchesAgeBucket(int $ageHours, string $bucket): bool
    {
        return match ($bucket) {
            'day' => $ageHours < 24,
            'week' => $ageHours >= 24 && $ageHours < 24 * 7,
            'month' => $ageHours >= 24 * 7 && $ageHours < 24 * 30,
            'over_month' => $ageHours >= 24 * 30,
            default => true,
        };
    }
}
