<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\DistributorFamilyMember;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds and removes the family members a distributor registers for the
 * arovolife Family Card. The audit trail records what happened, the relation
 * and whether a child was involved — never the name.
 */
final class FamilyMemberService
{
    /**
     * @param  array{full_name: string, relation: string, is_minor: bool, turns_18_year: int|null}  $data
     *
     * @throws ValidationException when the distributor already has five, or a
     *                             child is added before KYC is approved
     */
    public function add(Distributor $distributor, User $actor, array $data, ?string $ip): DistributorFamilyMember
    {
        // A parent's consent for a child is verifiable only because we already
        // hold the parent's verified identity and age (DPDP Rules 2025 r.10) —
        // which is true once KYC is approved, not before.
        if ($data['is_minor'] && $actor->status !== 'active') {
            throw ValidationException::withMessages([
                'is_minor' => 'You can add a family member under 18 once your KYC has been approved.',
            ]);
        }

        return DB::transaction(function () use ($distributor, $actor, $data, $ip): DistributorFamilyMember {
            // Serialise concurrent adds for this distributor so two requests
            // can't both see four rows and make it seven.
            Distributor::query()->whereKey($distributor->id)->lockForUpdate()->first();

            $count = DistributorFamilyMember::query()->where('distributor_id', $distributor->id)->count();
            if ($count >= DistributorFamilyMember::MAX_PER_DISTRIBUTOR) {
                throw ValidationException::withMessages([
                    'full_name' => 'You have already added '.DistributorFamilyMember::MAX_PER_DISTRIBUTOR.' family members, which is the most the card allows. Remove one to add another.',
                ]);
            }

            $now = Carbon::now();
            $member = DistributorFamilyMember::create([
                'distributor_id' => $distributor->id,
                'full_name' => $data['full_name'],
                'relation' => $data['relation'],
                'is_minor' => $data['is_minor'],
                'turns_18_year' => $data['is_minor'] ? $data['turns_18_year'] : null,
                'consent_given_at' => $now,
                'guardian_consent_at' => $data['is_minor'] ? $now : null,
                'consent_version' => DistributorFamilyMember::CONSENT_VERSION,
                'consent_text_hash' => DistributorFamilyMember::consentTextHash($data['is_minor']),
                'consented_by_user_id' => $actor->id,
            ]);

            $this->audit($actor->id, 'profile.family_member.added', $distributor->id, $member, $ip, before: null, after: $member);

            return $member;
        });
    }

    /**
     * Remove one of the distributor's own family members. Scoped to the
     * distributor, so another distributor's row is a 404, not a deletion.
     */
    public function remove(Distributor $distributor, int $memberId, User $actor, ?string $ip, string $action = 'profile.family_member.removed'): void
    {
        $member = DistributorFamilyMember::query()
            ->where('distributor_id', $distributor->id)
            ->findOrFail($memberId);

        DB::transaction(function () use ($member, $actor, $distributor, $ip, $action): void {
            $member->delete();
            $this->audit($actor->id, $action, $distributor->id, $member, $ip, before: $member, after: null);
        });
    }

    private function audit(?int $actorId, string $action, int $distributorId, DistributorFamilyMember $member, ?string $ip, ?DistributorFamilyMember $before, ?DistributorFamilyMember $after): void
    {
        AuditLog::create([
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => 'distributor',
            'subject_id' => $distributorId,
            'before_hash' => AuditDigests::of($before ? $this->digestState($before) : null),
            'after_hash' => AuditDigests::of($after ? $this->digestState($after) : null),
            'details' => [
                'family_member_id' => $member->id,
                'relation' => $member->relation,
                'is_minor' => $member->is_minor,
                'consent_version' => $member->consent_version,
            ],
            'ip' => $ip,
        ]);
    }

    /**
     * The consent record a digest proves — everything about the row except
     * the name, which never enters the audit trail.
     *
     * @return array<string, mixed>
     */
    private function digestState(DistributorFamilyMember $member): array
    {
        return [
            'family_member_id' => $member->id,
            'relation' => $member->relation,
            'is_minor' => $member->is_minor,
            'turns_18_year' => $member->turns_18_year,
            'consent_given_at' => $member->consent_given_at->toIso8601String(),
            'guardian_consent_at' => $member->guardian_consent_at?->toIso8601String(),
            'consent_version' => $member->consent_version,
            'consent_text_hash' => $member->consent_text_hash,
            'consented_by_user_id' => $member->consented_by_user_id,
        ];
    }
}
