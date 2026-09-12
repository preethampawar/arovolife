<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\People;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Services\ActionCenterSettings;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A distributor whose KYC submission is waiting on a reviewer, older than the
 * KYC review SLA (plan §4, People). Mirrors `AdminKycController::index()`'s
 * default "pending" tab: `users.status = pending`, and only the primary half
 * of a couple registration surfaces (the secondary is reviewed alongside the
 * primary, never independently).
 *
 * Identified by ADN only — no PAN, Aadhaar, email or phone (CLAUDE.md PII
 * discipline).
 */
final class KycPendingReviewProvider extends AbstractProvider
{
    public function __construct(private readonly ActionCenterSettings $settings) {}

    public function key(): string
    {
        return 'kyc.pending_review';
    }

    public function group(): string
    {
        return ActionGroup::PEOPLE;
    }

    public function label(): string
    {
        return 'KYC pending review';
    }

    public function description(): string
    {
        return 'Distributor KYC submissions waiting on a reviewer past the review SLA. Review them from the KYC queue.';
    }

    public function permission(): string
    {
        return 'kyc.review';
    }

    public function severity(): string
    {
        return Severity::WARNING;
    }

    public function slaHours(): int
    {
        return $this->settings->kycReviewHours();
    }

    public function subjectType(): string
    {
        return 'distributor';
    }

    public function targetRoute(): string
    {
        return 'admin.kyc.show';
    }

    public function count(): int
    {
        return $this->baseQuery()->toBase()->count();
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        return $this->baseQuery()
            ->orderBy('distributors.created_at')
            ->limit($limit)
            ->get(['distributors.id', 'distributors.adn', 'distributors.created_at'])
            ->map(function (Distributor $distributor): ActionItem {
                $dueAt = $this->dueAt($distributor->created_at);

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $distributor->id,
                    title: (string) $distributor->adn,
                    subtitle: 'Submitted '.$this->ageLabel($distributor->created_at).' ago, awaiting review',
                    occurredAt: $distributor->created_at,
                    dueAt: $dueAt,
                    severity: $this->itemSeverity($dueAt),
                    url: route($this->targetRoute(), ['id' => $distributor->id]),
                    meta: ['adn' => (string) $distributor->adn],
                );
            })
            ->values();
    }

    /** @return Builder<Distributor> */
    private function baseQuery(): Builder
    {
        $query = Distributor::query()
            ->join('users', 'users.id', '=', 'distributors.user_id')
            ->where('users.status', 'pending')
            ->where(function (Builder $q): void {
                $q->whereNull('distributors.spouse_distributor_id')
                    ->orWhere('distributors.is_primary_couple', true);
            })
            ->where('distributors.created_at', '<=', $this->slaCutoff());

        $this->excludeSnoozed($query->getQuery(), 'distributors.id');

        return $query;
    }
}
