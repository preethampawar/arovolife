<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Services;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Genealogy\Events\LineChangeRejected;
use App\Modules\Genealogy\Models\LineChangeRequest;
use App\Modules\Genealogy\Services\Exceptions\LineChangeNotPendingError;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;

/**
 * Rejects a pending line-change request. Records the admin's note; no
 * placement is touched. The requester is free to submit a new request only
 * if still inside the 5-day window (RequestLineChange re-checks).
 */
final class RejectLineChange
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function __invoke(int $requestId, int $reviewerUserId, string $decisionNote): void
    {
        $this->db->connection()->transaction(function () use ($requestId, $reviewerUserId, $decisionNote): void {
            /** @var LineChangeRequest $request */
            $request = LineChangeRequest::query()->lockForUpdate()->findOrFail($requestId);
            if ($request->status !== 'pending') {
                throw new LineChangeNotPendingError("Line-change request {$requestId} is not pending (status={$request->status}).");
            }

            $now = Carbon::now();
            $request->status = 'rejected';
            $request->decision_note = mb_substr($decisionNote, 0, 1024);
            $request->reviewed_by = $reviewerUserId;
            $request->reviewed_at = $now;
            $request->save();

            AuditLog::create([
                'actor_id' => $reviewerUserId,
                'action' => 'genealogy.line_change.rejected',
                'subject_type' => 'distributor',
                'subject_id' => (int) $request->distributor_id,
                'details' => [
                    'request_id' => $requestId,
                    'decision_note' => $request->decision_note,
                ],
            ]);

            LineChangeRejected::dispatch(
                $requestId,
                (int) $request->distributor_id,
                (string) $request->decision_note,
                $reviewerUserId,
                $now,
            );
        });
    }
}
