<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services\Erasure;

use App\Modules\Admin\Services\DatabaseBackupService;
use App\Modules\Commerce\Models\OfflinePayment;
use App\Modules\Commerce\Models\Order;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Services\AreteCenterApplicationService;
use App\Modules\Compensation\Support\EngineScheduleWindow;
use App\Modules\Genealogy\Support\ReservedAdns;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\DistributorRequestService;
use App\Modules\Payments\Models\RefundIntent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Developer-only permanent erasure of one partner and their whole Genos
 * subtree (plus couple partners). Personal data is deleted or overwritten;
 * money and tax rows stay, stripped of personal details; the distributor and
 * user rows stay as anonymised tombstones so the rows that cascade from them
 * survive. The erased nodes leave the tree by pointing at themselves, which
 * frees the parent's slot.
 *
 * By the owner's decision this writes no audit row, dispatches no event and
 * logs nothing. Existing audit rows are left alone, so the hash chain holds.
 */
final class PartnerErasureService
{
    private const CHUNK = 1000;

    /** Every disk the erase deletes files from. */
    private const FILE_DISKS = ['kyc', 's3', 'grievance', DistributorRequestService::DISK, AreteCenterApplicationService::DISK, OfflinePayment::DISK];

    /**
     * The bucket-name ending each environment owns. Local and staging number
     * their users separately, so `user_5/` in another environment's bucket is
     * a different person: the erase never deletes from a bucket that is not
     * its own environment's.
     */
    /** Local dev shares the staging bucket for now (owner decision 2026-11-23: staging data is expendable). Never a prod bucket outside production. */
    private const OWN_BUCKET_SUFFIX = ['local' => ['-local', '-staging'], 'staging' => ['-staging'], 'production' => ['-prod']];

    private const ERASED_NAME = 'Erased partner';

    private const OPEN_ORDER_STATUSES = [
        Order::STATUS_PLACED,
        Order::STATUS_PAID,
        Order::STATUS_READY_TO_SHIP,
        Order::STATUS_SHIPPED,
        Order::STATUS_AWAITING_COLLECTION,
        Order::STATUS_REFUND_REQUESTED,
        Order::STATUS_REFUND_INSPECTION,
        Order::STATUS_REFUND_APPROVED,
    ];

    private const IN_FLIGHT_BATCH_STATUSES = [
        PayoutBatch::STATUS_PENDING,
        PayoutBatch::STATUS_PROCESSING,
        PayoutBatch::STATUS_APPROVED,
        PayoutBatch::STATUS_DISPATCHED,
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly DatabaseBackupService $backups,
        private readonly PayloadRedactor $redactor,
    ) {}

    /**
     * Read-only, no locks.
     *
     * @throws PartnerErasureRefused when no live partner has this APN
     */
    public function preview(string $adn): PartnerErasurePreview
    {
        $target = $this->findTarget($adn, false);
        $ids = $this->resolveSet($target->id, false);
        $userIds = $this->userIdsOf($ids);
        $customerIds = $this->pluckIn('customers', 'user_id', $userIds, 'id');
        $orderIds = $this->pluckIn('orders', 'customer_id', $customerIds, 'id');

        [$members, $leftAdns, $rightAdns] = $this->members($target->id, $ids);

        return new PartnerErasurePreview(
            targetId: $target->id,
            targetAdn: $target->adn,
            targetName: $target->fullName,
            members: $members,
            leftAdns: $leftAdns,
            rightAdns: $rightAdns,
            blockers: $this->blockers($ids, $orderIds),
            counts: [
                'orders' => count($orderIds),
                'invoices' => $this->countIn('invoices', 'order_id', $orderIds),
                'kyc_documents' => $this->countIn('kyc_documents', 'distributor_id', $ids),
                'wallet_entries' => $this->countIn('wallet_ledger_entries', 'distributor_id', $ids),
            ],
            fingerprint: $this->fingerprint($ids),
        );
    }

    /**
     * @throws AuthorizationException when the actor is not a developer
     * @throws PartnerErasureRefused when anything blocks the erase; nothing is changed
     */
    public function execute(string $adn, string $fingerprint, User $actor): PartnerErasureResult
    {
        // hasRole, not can(): Gate::before lets every admin pass every Gate.
        if (! $actor->hasRole('developer')) {
            throw new AuthorizationException;
        }

        if (EngineScheduleWindow::isActiveNow()) {
            throw new PartnerErasureRefused([$this->engineWindowBlocker()]);
        }

        // Refused before the backup: without proc_open, create() would throw
        // long before the blockers are checked again in the transaction.
        if ($this->backupUnavailable()) {
            throw new PartnerErasureRefused([$this->backupUnavailableBlocker()]);
        }

        return $this->backups->exclusively(function () use ($adn, $fingerprint): PartnerErasureResult {
            // A backup that throws stops here, before anything is erased.
            $backupFile = app()->isProduction() ? $this->backups->create('manual')->file : null;

            /** @var array{0: int, 1: list<array{0: string, 1: string, 2: bool}>} $outcome */
            $outcome = $this->db->connection()->transaction(fn (): array => $this->eraseInTransaction($adn, $fingerprint));
            [$erasedCount, $files] = $outcome;

            [$filesDeleted, $filesFailed] = $this->deleteFiles($files);

            return new PartnerErasureResult($erasedCount, $filesDeleted, $filesFailed, $backupFile);
        });
    }

    /**
     * @return array{0: int, 1: list<array{0: string, 1: string, 2: bool}>} [erased count, files as [disk, path, isPrefix]]
     */
    private function eraseInTransaction(string $adn, string $fingerprint): array
    {
        $target = $this->findTarget($adn, true);

        // Lock every member row in id order, then resolve again: locking reads
        // see the latest committed tree, so a joiner who committed in between
        // changes the set and fails the fingerprint below.
        foreach (array_chunk($this->resolveSet($target->id, true), self::CHUNK) as $chunk) {
            $this->db->table('distributors')->whereIn('id', $chunk)->orderBy('id')->lockForUpdate()->pluck('id');
        }
        $ids = $this->resolveSet($target->id, true);

        if (! hash_equals($this->fingerprint($ids), $fingerprint)) {
            throw new PartnerErasureRefused([[
                'code' => 'tree_changed',
                'message' => 'The tree changed since the preview — review again.',
            ]]);
        }

        $userIds = $this->userIdsOf($ids);
        $customerIds = $this->pluckIn('customers', 'user_id', $userIds, 'id');
        $orderIds = $this->pluckIn('orders', 'customer_id', $customerIds, 'id');

        $blockers = $this->blockers($ids, $orderIds);
        if ($blockers !== []) {
            throw new PartnerErasureRefused($blockers);
        }

        $files = $this->collectFiles($ids, $userIds, $orderIds, $customerIds);
        $emails = $this->pluckStringsIn('users', 'id', $userIds, 'email');

        $this->deleteRows($ids, $userIds, $customerIds, $emails);
        $this->anonymise($ids, $userIds, $customerIds, $orderIds);
        $this->assertDetached($ids);

        return [count($ids), $files];
    }

    /**
     * @return object{id: int, adn: string, fullName: string|null}
     */
    private function findTarget(string $adn, bool $lock): object
    {
        $query = $this->db->table('distributors as d')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->where('d.adn', $adn)
            ->select(['d.id', 'd.adn', 'd.erased_at', 'u.full_name']);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();

        if ($row === null) {
            throw new PartnerErasureRefused([['code' => 'not_found', 'message' => "No partner with APN {$adn}."]]);
        }
        if ($row->erased_at !== null) {
            throw new PartnerErasureRefused([['code' => 'already_erased', 'message' => "APN {$adn} has already been deleted."]]);
        }

        return (object) ['id' => (int) $row->id, 'adn' => (string) $row->adn, 'fullName' => $row->full_name];
    }

    /**
     * The target, every Genos descendant of any member, and every couple
     * partner of any member, repeated until nothing new is added.
     *
     * @return list<int> sorted ascending
     */
    private function resolveSet(int $targetId, bool $lock): array
    {
        $set = [$targetId => true];
        $frontier = [$targetId];

        while ($frontier !== []) {
            $found = [];
            foreach (array_chunk($frontier, self::CHUNK) as $chunk) {
                $descendants = $this->db->table('genealogy_closure')->whereIn('ancestor_id', $chunk);
                $partners = $this->db->table('distributors')
                    ->where(fn ($q) => $q->whereIn('id', $chunk)->orWhereIn('spouse_distributor_id', $chunk))
                    ->select(['id', 'spouse_distributor_id']);
                if ($lock) {
                    $descendants->lockForUpdate();
                    $partners->lockForUpdate();
                }

                foreach ($descendants->pluck('descendant_id') as $id) {
                    $found[] = (int) $id;
                }
                foreach ($partners->get() as $row) {
                    $found[] = (int) $row->id;
                    if ($row->spouse_distributor_id !== null) {
                        $found[] = (int) $row->spouse_distributor_id;
                    }
                }
            }

            $frontier = [];
            foreach ($found as $id) {
                if (! isset($set[$id])) {
                    $set[$id] = true;
                    $frontier[] = $id;
                }
            }
        }

        $ids = array_keys($set);
        sort($ids);

        return $ids;
    }

    /**
     * @param  list<int>  $ids
     */
    private function fingerprint(array $ids): string
    {
        return hash('sha256', implode(',', $ids));
    }

    /**
     * Every member except the target, with the target's leg each sits in.
     *
     * @param  list<int>  $ids
     * @return array{0: list<array{id: int, adn: string, name: string|null, depth: int|null, leg: 'L'|'R'|null, side: 'L'|'R'|null, parentAdn: string|null, isSpouse: bool}>, 1: list<string>, 2: list<string>}
     */
    private function members(int $targetId, array $ids): array
    {
        $depths = $this->db->table('genealogy_closure')
            ->where('ancestor_id', $targetId)
            ->pluck('depth', 'descendant_id')
            ->all();

        $legOf = [];
        $children = $this->db->table('distributors')
            ->where('placement_parent_id', $targetId)
            ->where('id', '!=', $targetId)
            ->whereIn('placement_side', ['L', 'R'])
            ->pluck('id', 'placement_side');
        foreach ($children as $side => $childId) {
            foreach ($this->db->table('genealogy_closure')->where('ancestor_id', $childId)->pluck('descendant_id') as $id) {
                $legOf[(int) $id] = $side === 'L' ? 'L' : 'R';
            }
        }

        $rows = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $rows = [...$rows, ...$this->db->table('distributors as d')
                ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
                ->leftJoin('distributors as p', 'p.id', '=', 'd.placement_parent_id')
                ->whereIn('d.id', $chunk)
                ->orderBy('d.id')
                ->get(['d.id', 'd.adn', 'u.full_name', 'd.placement_side', 'p.adn as parent_adn'])
                ->all()];
        }

        $members = [];
        $left = [];
        $right = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            if ($id === $targetId) {
                continue;
            }
            $depth = isset($depths[$id]) ? (int) $depths[$id] : null;
            $leg = $legOf[$id] ?? null;
            $side = $row->placement_side === 'L' ? 'L' : ($row->placement_side === 'R' ? 'R' : null);
            $members[] = [
                'id' => $id,
                'adn' => (string) $row->adn,
                'name' => $row->full_name,
                'depth' => $depth,
                'leg' => $leg,
                'side' => $side,
                'parentAdn' => $row->parent_adn,
                'isSpouse' => $depth === null,
            ];
            if ($leg === 'L') {
                $left[] = (string) $row->adn;
            } elseif ($leg === 'R') {
                $right[] = (string) $row->adn;
            }
        }

        usort($members, static fn (array $a, array $b): int => [$a['depth'] ?? PHP_INT_MAX, $a['id']] <=> [$b['depth'] ?? PHP_INT_MAX, $b['id']]);

        return [$members, $left, $right];
    }

    /**
     * @param  list<int>  $ids
     * @param  list<int>  $orderIds
     * @return list<array{code: string, message: string}>
     */
    private function blockers(array $ids, array $orderIds): array
    {
        $blockers = [];
        $inSet = array_fill_keys($ids, true);

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $rows = $this->db->table('distributors')->whereIn('id', $chunk)->get(['id', 'adn', 'placement_parent_id', 'erased_at']);
            foreach ($rows as $row) {
                if (ReservedAdns::isReserved((string) $row->adn)) {
                    $blockers[] = ['code' => 'reserved', 'message' => "APN {$row->adn} is a company-reserved account."];
                } elseif ((int) $row->placement_parent_id === (int) $row->id && $row->erased_at === null) {
                    $blockers[] = ['code' => 'root', 'message' => "APN {$row->adn} is the root of the tree."];
                }
            }
        }

        if (EngineScheduleWindow::isActiveNow()) {
            $blockers[] = $this->engineWindowBlocker();
        }

        if ($this->backupUnavailable()) {
            $blockers[] = $this->backupUnavailableBlocker();
        }

        foreach ($this->foreignBuckets() as $disk => $bucket) {
            $blockers[] = ['code' => 'foreign_bucket', 'message' => "Files would be deleted from the S3 bucket \"{$bucket}\" (disk {$disk}), which is not this environment's own bucket. Point this environment at its own bucket first."];
        }

        foreach (array_chunk($orderIds, self::CHUNK) as $chunk) {
            $orders = $this->db->table('orders as o')
                ->join('customers as c', 'c.id', '=', 'o.customer_id')
                ->join('distributors as d', 'd.user_id', '=', 'c.user_id')
                ->whereIn('o.id', $chunk)
                ->whereIn('o.status', self::OPEN_ORDER_STATUSES)
                ->orderBy('o.id')
                ->get(['o.order_no', 'o.status', 'd.adn']);
            foreach ($orders as $row) {
                $blockers[] = ['code' => 'open_order', 'message' => "APN {$row->adn} has order {$row->order_no} still open ({$row->status})."];
            }

            // Hard rule 5: a delivered order still inside its 30-day window
            // can be cancelled for a full refund, so its owner must remain.
            $coolingOff = $this->db->table('order_cooling_off as oc')
                ->join('orders as o', 'o.id', '=', 'oc.order_id')
                ->join('customers as c', 'c.id', '=', 'o.customer_id')
                ->join('distributors as d', 'd.user_id', '=', 'c.user_id')
                ->whereIn('oc.order_id', $chunk)
                ->where('oc.status', 'open')
                ->where('oc.ends_at', '>', now())
                ->orderBy('o.id')
                ->get(['o.order_no', 'd.adn']);
            foreach ($coolingOff as $row) {
                $blockers[] = ['code' => 'cooling_off_open', 'message' => "APN {$row->adn} has order {$row->order_no} still inside its 30-day cooling-off period."];
            }

            $refunds = $this->db->table('refund_intents as ri')
                ->join('orders as o', 'o.id', '=', 'ri.order_id')
                ->join('customers as c', 'c.id', '=', 'o.customer_id')
                ->join('distributors as d', 'd.user_id', '=', 'c.user_id')
                ->whereIn('ri.order_id', $chunk)
                ->whereIn('ri.status', [RefundIntent::STATUS_CREATED, RefundIntent::STATUS_FAILED])
                ->orderBy('ri.id')
                ->get(['o.order_no', 'ri.status', 'd.adn']);
            foreach ($refunds as $row) {
                $blockers[] = ['code' => 'refund_unpaid', 'message' => "APN {$row->adn} is still owed a refund on order {$row->order_no} ({$row->status})."];
            }
        }

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $centres = $this->db->table('arete_centers as ac')
                ->join('distributors as d', 'd.id', '=', 'ac.assigned_distributor_id')
                ->whereIn('ac.assigned_distributor_id', $chunk)
                ->orderBy('ac.id')
                ->get(['ac.name', 'd.adn']);
            foreach ($centres as $row) {
                $blockers[] = ['code' => 'adc_owner', 'message' => "APN {$row->adn} owns the Arete centre \"{$row->name}\"."];
            }

            $lines = $this->db->table('payout_line_items as pli')
                ->join('payout_batches as pb', 'pb.id', '=', 'pli.payout_batch_id')
                ->join('distributors as d', 'd.id', '=', 'pli.distributor_id')
                ->whereIn('pli.distributor_id', $chunk)
                ->where('pli.status', PayoutLineItem::STATUS_PENDING)
                ->whereIn('pb.status', self::IN_FLIGHT_BATCH_STATUSES)
                ->orderBy('pb.id')
                ->get(['pb.id as batch_id', 'pb.status as batch_status', 'd.adn']);
            foreach ($lines as $row) {
                $blockers[] = ['code' => 'payout_in_flight', 'message' => "APN {$row->adn} has a payout in batch #{$row->batch_id} ({$row->batch_status})."];
            }

            $requests = $this->db->table('line_change_requests as lcr')
                ->join('distributors as d', 'd.id', '=', 'lcr.distributor_id')
                ->where('lcr.status', 'pending')
                ->where(fn ($q) => $q->whereIn('lcr.from_placement_parent_id', $chunk)->orWhereIn('lcr.to_placement_parent_id', $chunk))
                ->orderBy('lcr.id')
                ->get(['lcr.id', 'lcr.distributor_id', 'd.adn']);
            foreach ($requests as $row) {
                if (! isset($inSet[(int) $row->distributor_id])) {
                    $blockers[] = ['code' => 'line_change_pending', 'message' => "APN {$row->adn} has line-change request #{$row->id} pending that involves this tree."];
                }
            }

            $sponsored = $this->db->table('sponsorship as s')
                ->join('distributors as d', 'd.id', '=', 's.distributor_id')
                ->join('distributors as sp', 'sp.id', '=', 's.sponsor_id')
                ->whereIn('s.sponsor_id', $chunk)
                ->orderBy('s.id')
                ->get(['s.distributor_id', 'd.adn', 'sp.adn as sponsor_adn']);
            foreach ($sponsored as $row) {
                if (! isset($inSet[(int) $row->distributor_id])) {
                    $blockers[] = ['code' => 'sponsored_outside', 'message' => "APN {$row->adn} was sponsored by APN {$row->sponsor_adn} but is placed outside this tree."];
                }
            }
        }

        return $blockers;
    }

    /**
     * @return array{code: string, message: string}
     */
    private function engineWindowBlocker(): array
    {
        return ['code' => 'engine_window', 'message' => 'The compensation engines run between 00:00 and 05:00 IST. Try again after 05:00.'];
    }

    /** Production backs up before every erase, and this PHP cannot start mysqldump. */
    private function backupUnavailable(): bool
    {
        return app()->isProduction() && ! $this->backups->canStartProcesses();
    }

    /**
     * @return array{code: string, message: string}
     */
    private function backupUnavailableBlocker(): array
    {
        return ['code' => 'backup_unavailable', 'message' => 'This server cannot take the automatic backup from the web page, so the delete cannot run here. Run it on the server: php artisan partners:erase <APN>'];
    }

    /**
     * Files to remove once the transaction commits.
     *
     * @param  list<int>  $ids
     * @param  list<int>  $userIds
     * @param  list<int>  $orderIds
     * @param  list<int>  $customerIds
     * @return list<array{0: string, 1: string, 2: bool}> [disk, path, isPrefix]
     */
    private function collectFiles(array $ids, array $userIds, array $orderIds, array $customerIds): array
    {
        $files = [];

        foreach ($userIds as $userId) {
            $files[] = ['kyc', "user_{$userId}", true];
            $files[] = ['s3', "user_{$userId}", true];
        }
        foreach ($this->pluckStringsIn('users', 'id', $userIds, 'id_photo_path') as $path) {
            $files[] = ['s3', $path, false];
        }
        // Whole per-person folders as well as the files rows point to, so an
        // older copy a replace failed to delete goes too. A prefix is always
        // listed as a folder (`user_1/` never matches `user_10/`).
        foreach ($ids as $id) {
            $files[] = ['kyc', "distributor_{$id}", true];
            $files[] = ['kyc', "admin_{$id}", true];
        }
        foreach ($this->pluckStringsIn('kyc_documents', 'distributor_id', $ids, 'object_storage_key') as $path) {
            $files[] = ['kyc', $path, false];
            // The registration wizard's own folder, which holds only this person's uploads.
            $folder = dirname($path);
            if (preg_match('/^reg_[A-Za-z0-9-]{16,}$/', $folder) === 1) {
                $files[] = ['kyc', $folder, true];
            }
        }

        $requestIds = $this->pluckIn('distributor_requests', 'distributor_id', $ids, 'id');
        foreach ($requestIds as $requestId) {
            $files[] = [DistributorRequestService::DISK, "request_{$requestId}", true];
        }
        foreach ($this->pluckStringsIn('distributor_request_documents', 'request_id', $requestIds, 'object_storage_key') as $path) {
            $files[] = [DistributorRequestService::DISK, $path, false];
        }

        $applicationIds = $this->pluckIn('arete_center_applications', 'distributor_id', $ids, 'id');
        foreach ($applicationIds as $applicationId) {
            $files[] = [AreteCenterApplicationService::DISK, "application_{$applicationId}", true];
        }
        foreach ($this->pluckStringsIn('arete_center_application_documents', 'application_id', $applicationIds, 'object_storage_key') as $path) {
            $files[] = [AreteCenterApplicationService::DISK, $path, false];
        }

        foreach (array_chunk($this->ticketIds($ids, $customerIds), self::CHUNK) as $chunk) {
            foreach ($this->db->table('ticket_attachments')->whereIn('ticket_id', $chunk)->get(['disk', 'path']) as $row) {
                $files[] = [(string) $row->disk, (string) $row->path, false];
            }
            foreach ($this->db->table('tickets')->whereIn('id', $chunk)->pluck('ticket_no') as $ticketNo) {
                if (is_string($ticketNo) && $ticketNo !== '') {
                    $files[] = ['grievance', $ticketNo, true];
                }
            }
        }

        foreach ($this->offlinePaymentIds($ids, $orderIds) as $chunk) {
            foreach ($this->db->table('offline_payments')->whereIn('id', $chunk)->whereNotNull('proof_storage_key')->pluck('proof_storage_key') as $path) {
                $files[] = [OfflinePayment::DISK, $path, false];
            }
        }

        return $files;
    }

    /**
     * @param  list<int>  $ids
     * @param  list<int>  $userIds
     * @param  list<int>  $customerIds
     * @param  list<string>  $emails
     */
    private function deleteRows(array $ids, array $userIds, array $customerIds, array $emails): void
    {
        $requestIds = $this->pluckIn('distributor_requests', 'distributor_id', $ids, 'id');
        $this->deleteIn('distributor_request_documents', 'request_id', $requestIds);
        $this->deleteIn('distributor_requests', 'id', $requestIds);

        $applicationIds = $this->pluckIn('arete_center_applications', 'distributor_id', $ids, 'id');
        $this->deleteIn('arete_center_application_documents', 'application_id', $applicationIds);
        $this->deleteIn('arete_center_application_declarations', 'application_id', $applicationIds);
        $this->deleteIn('arete_center_applications', 'id', $applicationIds);

        // plan_change_notices before consents: it references a consent.
        foreach ([
            'distributor_profiles', 'distributor_nominees', 'distributor_family_members', 'plan_change_notices',
            'consents', 'orientation_views', 'cooling_off_events', 'attribution_touches', 'shared_carts',
            'line_change_requests', 'gsb_reversal_requests', 'kyc_documents', 'arete_center_members',
            'bv_ledger_entries', 'bv_propagation_log', 'group_bv_daily', 'group_bv_debts', 'gsb_carryforward',
            'gsb_cutoff_deferrals', 'gsb_personal_bv_topups', 'rank_qualifications', 'rank_provisional_standings',
            'rank_aogo_grants', 'lifetime_award_milestones', 'repurchase_cycles', 'purchase_offer_grants',
            'redeem_point_entries', 'fortune_bonus_participants', 'sponsorship',
        ] as $table) {
            $this->deleteIn($table, 'distributor_id', $ids);
        }

        // Credits to an upline ancestor are the upline's history and stay.
        $this->deleteIn('group_bv_credits', 'ancestor_id', $ids);
        $this->deleteIn('group_bv_reversals', 'ancestor_id', $ids);
        $this->deleteIn('genealogy_closure', 'descendant_id', $ids);

        $cartIds = $this->pluckIn('carts', 'customer_id', $customerIds, 'id');
        $this->deleteIn('cart_items', 'cart_id', $cartIds);
        $this->deleteIn('carts', 'id', $cartIds);
        $this->deleteIn('customer_addresses', 'customer_id', $customerIds);

        $ticketIds = $this->ticketIds($ids, $customerIds);
        $this->deleteIn('ticket_attachments', 'ticket_id', $ticketIds);
        $this->deleteIn('ticket_events', 'ticket_id', $ticketIds);
        $this->deleteIn('tickets', 'id', $ticketIds);

        $messageIds = $this->unique([
            ...$this->pluckIn('messages', 'from_user_id', $userIds, 'id'),
            ...$this->pluckIn('messages', 'to_user_id', $userIds, 'id'),
        ]);
        // Abuse reports filed by people outside the erase set are evidence of
        // the erased partner's conduct (owner decision 2026-11-23): keep the
        // reported message and its report; the sender row is a tombstone.
        $erasedUsers = array_flip($userIds);
        $reportedByOthers = [];
        foreach (array_chunk($this->pluckIn('messages', 'from_user_id', $userIds, 'id'), self::CHUNK) as $chunk) {
            foreach ($this->db->table('message_reports')->whereIn('message_id', $chunk)->get(['message_id', 'reported_by_user_id']) as $row) {
                if (! isset($erasedUsers[(int) $row->reported_by_user_id])) {
                    $reportedByOthers[(int) $row->message_id] = true;
                }
            }
        }
        $messageIds = array_values(array_filter($messageIds, fn (int $id): bool => ! isset($reportedByOthers[$id])));

        $this->deleteIn('message_reports', 'reported_by_user_id', $userIds);
        $this->deleteIn('message_reports', 'message_id', $messageIds);
        $this->deleteIn('messages', 'id', $messageIds);
        $this->deleteIn('message_blocks', 'blocker_user_id', $userIds);
        $this->deleteIn('message_blocks', 'blocked_user_id', $userIds);

        $userMorph = (new User)->getMorphClass();
        foreach (array_chunk($userIds, self::CHUNK) as $chunk) {
            $this->db->table('notifications')->where('notifiable_type', $userMorph)->whereIn('notifiable_id', $chunk)->delete();
            $this->db->table('model_has_roles')->where('model_type', $userMorph)->whereIn('model_id', $chunk)->delete();
            $this->db->table('model_has_permissions')->where('model_type', $userMorph)->whereIn('model_id', $chunk)->delete();
        }
        $this->deleteIn('announcement_reads', 'user_id', $userIds);
        $this->deleteIn('password_reset_tokens', 'email', $emails);
    }

    /**
     * @param  list<int>  $ids
     * @param  list<int>  $userIds
     * @param  list<int>  $customerIds
     * @param  list<int>  $orderIds
     */
    private function anonymise(array $ids, array $userIds, array $customerIds, array $orderIds): void
    {
        $now = Carbon::now()->format('Y-m-d H:i:s.v');

        // One hash for every row: the random secret is thrown away, and a
        // bcrypt per row would take minutes on a large subtree.
        $this->updateIn('users', 'id', $userIds, [
            'email' => new Expression("CONCAT('erased-', id, '@erased.invalid')"),
            'phone_e164' => new Expression("CONCAT('+0', LPAD(id, 14, '0'))"),
            'password_hash' => Hash::make(Str::random(64)),
            'full_name' => self::ERASED_NAME,
            'address' => null,
            'date_of_birth' => null,
            'id_photo_path' => null,
            'mfa_secret_enc' => null,
            'mfa_enabled_at' => null,
            'remember_token' => null,
            'email_verified_at' => null,
            'last_login_at' => null,
            'status' => 'terminated',
            'closure_type' => 'erased',
            'updated_at' => $now,
        ]);

        // Pointing at itself takes the node out of the tree and frees the
        // parent's slot; `state` stays as the GST place of supply.
        $this->updateIn('distributors', 'id', $ids, [
            'adn' => new Expression("CONCAT('ERASED-', id)"),
            'pan_hash' => null,
            'pan_last4' => null,
            'pan_encrypted' => null,
            'aadhaar_ref' => null,
            'aadhaar_last4' => null,
            'aadhaar_encrypted' => null,
            'bank_account_enc' => null,
            'bank_beneficiary_name_enc' => null,
            'bank_ifsc' => null,
            'bank_name' => null,
            'razorpay_contact_id' => null,
            'declared_sound_mind' => null,
            'declared_not_insolvent' => null,
            'declared_no_moral_turpitude' => null,
            'declarations_made_at' => null,
            'placement_id_at_registration' => null,
            'spouse_distributor_id' => null,
            'inactivity_notice_at' => null,
            'inactivity_notice_expires_at' => null,
            'termination_reason' => null,
            'sponsor_id' => new Expression('id'),
            'placement_parent_id' => new Expression('id'),
            'placement_side' => null,
            'depth' => 0,
            'is_primary_couple' => 0,
            'status' => 'inactive',
            'erased_at' => $now,
            'updated_at' => $now,
        ]);

        $this->updateIn('customers', 'id', $customerIds, [
            'display_name' => self::ERASED_NAME,
            'email_hash' => null,
            'email_enc' => null,
            'phone_hash' => null,
            'phone_enc' => null,
            'phone_last4' => null,
            'marketing_opt_in' => false,
            'updated_at' => $now,
        ]);

        // ship_state, buyer_gstin and buyer_legal_name stay: tax-invoice fields.
        $this->updateIn('orders', 'id', $orderIds, [
            'ship_name' => 'Erased',
            'ship_phone_e164' => null,
            'ship_line1' => null,
            'ship_line2' => null,
            'ship_city' => null,
            'ship_pincode' => null,
            'updated_at' => $now,
        ]);

        foreach ($this->offlinePaymentIds($ids, $orderIds) as $chunk) {
            $this->db->table('offline_payments')->whereIn('id', $chunk)->whereNotNull('proof_storage_key')
                ->update(['proof_storage_key' => null, 'proof_purged_at' => Carbon::now()]);
            $this->db->table('offline_payments')->whereIn('id', $chunk)->update([
                'payer_name' => null,
                'notes' => null,
                'confirmation_note' => null,
                'rejection_reason' => null,
                'proof_original_name' => null,
            ]);
        }

        $this->redactJson('payment_events', 'payload', $orderIds);
        $this->redactJson('payment_intents', 'raw_payload', $orderIds);
        $this->redactJson('shipment_events', 'payload', $orderIds);

        $this->updateIn('return_requests', 'order_id', $orderIds, ['notes' => null, 'receipt_note' => null]);
        $this->updateIn('payout_line_items', 'distributor_id', $ids, ['bank_account_last4' => null]);
    }

    /**
     * Last line of defence, read with locks so it sees the latest committed
     * tree: nobody outside the set may still hang under, or descend from, a
     * member. Throwing rolls the whole erase back.
     *
     * @param  list<int>  $ids
     */
    private function assertDetached(array $ids): void
    {
        $inSet = array_fill_keys($ids, true);

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $children = $this->db->table('distributors')->whereIn('placement_parent_id', $chunk)->lockForUpdate()->pluck('id');
            $outsider = $children->contains(fn ($id): bool => ! isset($inSet[(int) $id]));

            $closureLeft = $this->db->table('genealogy_closure')
                ->where(fn ($q) => $q->whereIn('descendant_id', $chunk)->orWhereIn('ancestor_id', $chunk))
                ->lockForUpdate()
                ->exists();

            if ($outsider || $closureLeft) {
                throw new PartnerErasureRefused([[
                    'code' => 'joined_during_erase',
                    'message' => 'Someone joined under this partner during the delete — try again.',
                ]]);
            }
        }
    }

    /**
     * The S3 disks the erase would delete from whose bucket is not this
     * environment's own. Disks on another driver (tests, the E2E stack) are
     * never foreign.
     *
     * @return array<string, string> disk => bucket
     */
    private function foreignBuckets(): array
    {
        $foreign = [];
        foreach (self::FILE_DISKS as $disk) {
            if (! $this->ownsDisk($disk)) {
                $foreign[$disk] = (string) config("filesystems.disks.{$disk}.bucket");
            }
        }

        return $foreign;
    }

    private function ownsDisk(string $disk): bool
    {
        $config = config("filesystems.disks.{$disk}");
        if (! is_array($config) || ($config['driver'] ?? null) !== 's3') {
            return true;
        }

        $bucket = (string) ($config['bucket'] ?? '');
        if ($bucket === '') {
            return false;
        }

        foreach (self::OWN_BUCKET_SUFFIX[(string) app()->environment()] ?? [] as $suffix) {
            if (str_ends_with($bucket, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Best-effort; a failure is counted, never thrown, because the database
     * erase has already committed.
     *
     * @param  list<array{0: string, 1: string, 2: bool}>  $files
     * @return array{0: int, 1: int} [deleted, failed]
     */
    private function deleteFiles(array $files): array
    {
        $deleted = 0;
        $failed = 0;
        $paths = [];

        foreach ($files as [$disk, $path, $isPrefix]) {
            // A ticket attachment names its own disk; never delete from a foreign bucket.
            if (! $this->ownsDisk($disk)) {
                $failed++;

                continue;
            }
            if (! $isPrefix) {
                $paths["{$disk}\0{$path}"] = [$disk, $path];

                continue;
            }
            try {
                foreach (Storage::disk($disk)->allFiles($path) as $file) {
                    $paths["{$disk}\0{$file}"] = [$disk, $file];
                }
            } catch (Throwable) {
                $failed++;
            }
        }

        foreach ($paths as [$disk, $path]) {
            try {
                if (Storage::disk($disk)->delete($path)) {
                    $deleted++;
                } else {
                    $failed++;
                }
            } catch (Throwable) {
                $failed++;
            }
        }

        return [$deleted, $failed];
    }

    /**
     * Replace personal keys inside a stored JSON payload; a payload that is not
     * a JSON object cannot be redacted key by key, so it is cleared.
     *
     * @param  list<int>  $orderIds
     */
    private function redactJson(string $table, string $column, array $orderIds): void
    {
        foreach (array_chunk($orderIds, self::CHUNK) as $chunk) {
            $rows = $this->db->table($table)->whereIn('order_id', $chunk)->whereNotNull($column)->get(['id', $column]);
            foreach ($rows as $row) {
                $original = (string) $row->{$column};
                $decoded = json_decode($original, true);
                $redacted = is_array($decoded) ? json_encode($this->redactor->redact($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
                if ($redacted !== $original) {
                    $this->db->table($table)->where('id', $row->id)->update([$column => $redacted === false ? null : $redacted]);
                }
            }
        }
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function userIdsOf(array $ids): array
    {
        return $this->pluckIn('distributors', 'id', $ids, 'user_id');
    }

    /**
     * @param  list<int>  $ids
     * @param  list<int>  $customerIds
     * @return list<int>
     */
    private function ticketIds(array $ids, array $customerIds): array
    {
        return $this->unique([
            ...$this->pluckIn('tickets', 'distributor_id', $ids, 'id'),
            ...$this->pluckIn('tickets', 'customer_id', $customerIds, 'id'),
        ]);
    }

    /**
     * @param  list<int>  $ids
     * @param  list<int>  $orderIds
     * @return list<list<int>> chunks
     */
    private function offlinePaymentIds(array $ids, array $orderIds): array
    {
        return array_chunk($this->unique([
            ...$this->pluckIn('offline_payments', 'distributor_id', $ids, 'id'),
            ...$this->pluckIn('offline_payments', 'order_id', $orderIds, 'id'),
        ]), self::CHUNK);
    }

    /**
     * @param  list<int|string>  $values
     * @return list<int>
     */
    private function pluckIn(string $table, string $column, array $values, string $pluck): array
    {
        return array_map(intval(...), $this->pluckStringsIn($table, $column, $values, $pluck));
    }

    /**
     * @param  list<int|string>  $values
     * @return list<string>
     */
    private function pluckStringsIn(string $table, string $column, array $values, string $pluck): array
    {
        $out = [];
        foreach (array_chunk($values, self::CHUNK) as $chunk) {
            foreach ($this->db->table($table)->whereIn($column, $chunk)->whereNotNull($pluck)->pluck($pluck) as $value) {
                $out[(string) $value] = true;
            }
        }

        return array_map(strval(...), array_keys($out));
    }

    /**
     * @param  list<int|string>  $values
     */
    private function countIn(string $table, string $column, array $values): int
    {
        $count = 0;
        foreach (array_chunk($values, self::CHUNK) as $chunk) {
            $count += $this->db->table($table)->whereIn($column, $chunk)->count();
        }

        return $count;
    }

    /**
     * @param  list<int|string>  $values
     */
    private function deleteIn(string $table, string $column, array $values): void
    {
        foreach (array_chunk($values, self::CHUNK) as $chunk) {
            $this->db->table($table)->whereIn($column, $chunk)->delete();
        }
    }

    /**
     * @param  list<int|string>  $values
     * @param  array<string, mixed>  $changes
     */
    private function updateIn(string $table, string $column, array $values, array $changes): void
    {
        foreach (array_chunk($values, self::CHUNK) as $chunk) {
            $this->db->table($table)->whereIn($column, $chunk)->update($changes);
        }
    }

    /**
     * @param  list<int>  $values
     * @return list<int>
     */
    private function unique(array $values): array
    {
        return array_values(array_unique($values));
    }
}
