<?php

declare(strict_types=1);

namespace App\Modules\Consent\Services;

use App\Modules\Admin\Events\DistributorTerminated;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Withdraws a distributor's consent (C-06, R-52; DPDP 2023 §6(4)-(6)).
 *
 * Two things this has to get right, and they pull in opposite directions.
 *
 * **Withdrawal must be as easy as giving.** DPDP is explicit about it, and the
 * Privacy Policy has promised it since launch. So this is one authenticated
 * POST from the distributor's own profile — no ticket, no approval queue, no
 * "contact support".
 *
 * **But it ends the distributorship**, and the person doing it has to know
 * that before they do it, not afterwards. Every consent here is foundational:
 * the agreement, the ethics code, the plan, and the privacy notice that covers
 * the KYC data the platform is legally required to hold on a Direct Seller.
 * `privacy.md` §10.5 says so — "some withdrawals may make it impossible for us
 * to continue providing the service; in that case the ADN is terminated". The
 * confirmation screen states it in those words. Offering a withdrawal that
 * quietly breaks the account later would be worse than not offering one.
 *
 * The acceptance rows are marked, never deleted. Withdrawal does not
 * invalidate processing performed before it, so the evidence of what was
 * agreed and when has to survive — deleting it would destroy the platform's
 * own proof that the earlier processing was lawful.
 *
 * What this deliberately does NOT do is erase anything. Erasure is a separate
 * right with its own limits: DSR 2021 and the Income-tax Act require records
 * to be kept for eight years whatever the data principal wants, and §10.4 of
 * the policy says which fields are blocked and why. Conflating the two would
 * promise a deletion that cannot legally happen.
 */
final class WithdrawConsent
{
    /**
     * @return int the number of consent rows withdrawn
     */
    public function execute(Distributor $distributor, string $reason, ?Carbon $at = null): int
    {
        $at ??= Carbon::now();

        $live = DB::table('consents')
            ->where('distributor_id', $distributor->id)
            ->whereNull('withdrawn_at')
            ->count();

        if ($live === 0) {
            // Idempotent rather than an error: a double-submitted form must
            // not produce a second termination event.
            return 0;
        }

        $user = $distributor->user;

        if ($user === null) {
            throw new RuntimeException("Distributor {$distributor->id} has no user to terminate.");
        }

        $terminationReason = 'Consent withdrawn by the distributor on '.$at->format('d M Y')
            .'. Privacy Policy §10.5: withdrawal of consent for the processing required to operate an ADN '
            .'makes it impossible to continue providing the service.';

        DB::transaction(function () use ($distributor, $user, $at, $reason, $terminationReason, $live): void {
            DB::table('consents')
                ->where('distributor_id', $distributor->id)
                ->whereNull('withdrawn_at')
                ->update([
                    'withdrawn_at' => $at,
                    'withdrawal_reason' => mb_substr($reason, 0, 255),
                ]);

            $user->update([
                'status' => 'terminated',
                'closure_type' => 'consent_withdrawn',
            ]);

            $distributor->forceFill([
                'status' => 'inactive',
                'terminated_at' => $at,
                'termination_reason' => $terminationReason,
            ])->save();

            AuditLog::create([
                'actor_id' => $user->id,
                'action' => 'consent.withdrawn',
                'subject_type' => 'distributor',
                'subject_id' => $distributor->id,
                'details' => [
                    'adn' => $distributor->adn,
                    'consents_withdrawn' => $live,
                    'documents' => ConsentDocuments::types(),
                    // The distributor's own words, so a later DPDP enquiry can
                    // see why rather than only that.
                    'reason' => mb_substr($reason, 0, 255),
                ],
            ]);
        });

        DistributorTerminated::dispatch($distributor->id, $user->id, $terminationReason, $at);

        return $live;
    }

    /** Whether this distributor still has live consent to withdraw. */
    public function hasLiveConsent(Distributor $distributor): bool
    {
        return DB::table('consents')
            ->where('distributor_id', $distributor->id)
            ->whereNull('withdrawn_at')
            ->exists();
    }
}
