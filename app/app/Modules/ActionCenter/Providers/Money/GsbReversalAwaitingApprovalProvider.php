<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Money;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Compensation\Models\GsbReversalRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A GSB reversal raised and waiting to be signed off (R-92).
 *
 * The acting permission is `compensation.reversal.approve` — the checker half
 * — not `compliance.discipline`, which raised the request and cannot also
 * approve it. Without this the request would only be visible to someone who
 * happened to open Manual Controls, and maker-checker where the checker is
 * never told is maker-checker in name only.
 */
final class GsbReversalAwaitingApprovalProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'compensation.gsb_reversal_awaiting_approval';
    }

    public function group(): string
    {
        return ActionGroup::MONEY;
    }

    public function label(): string
    {
        return 'GSB reversals awaiting approval';
    }

    public function description(): string
    {
        return 'Requests to take a GSB credit back out of a distributor\'s wallet, waiting for a second person to sign them off.';
    }

    public function permission(): string
    {
        return 'compensation.reversal.approve';
    }

    public function severity(): string
    {
        return Severity::WARNING;
    }

    public function subjectType(): string
    {
        return 'gsb_reversal_request';
    }

    public function count(): int
    {
        return $this->baseQuery()->toBase()->count();
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        return $this->baseQuery()
            ->with('distributor')
            ->orderBy('gsb_reversal_requests.created_at')
            ->limit($limit)
            ->get()
            ->map(function (GsbReversalRequest $request): ActionItem {
                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $request->id,
                    title: $request->distributor->adn.' — cut-off '.$request->cutoff_date->toDateString(),
                    subtitle: 'Requested '.$this->ageLabel($request->created_at).' ago, awaiting approval',
                    occurredAt: $request->created_at,
                    dueAt: null,
                    severity: $this->severity(),
                    url: route('admin.compensation.manual-controls.index'),
                    meta: [
                        'net_gsb_paise' => (int) $request->net_gsb_paise,
                        'requested_by' => $request->requested_by,
                    ],
                );
            })
            ->values();
    }

    /** @return Builder<GsbReversalRequest> */
    private function baseQuery(): Builder
    {
        $query = GsbReversalRequest::query()
            ->where('gsb_reversal_requests.status', GsbReversalRequest::STATUS_PENDING);

        $this->excludeSnoozed($query->getQuery(), 'gsb_reversal_requests.id');

        return $query;
    }
}
