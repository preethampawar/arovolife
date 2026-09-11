<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Inventory\Models\Supplier;

/**
 * Suppliers carry no stock effect of their own — they are the counterparty on
 * purchase orders and goods receipts. CRUD only, with the same audited
 * before/after discipline as every other admin write in the module.
 */
final class SupplierService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data, int $actorUserId): Supplier
    {
        $supplier = Supplier::create($data);

        $this->audit('inventory.supplier.created', $supplier, null, $actorUserId);

        return $supplier;
    }

    /** @param  array<string, mixed>  $data */
    public function update(Supplier $supplier, array $data, int $actorUserId): Supplier
    {
        $before = AuditDigests::snapshot($supplier);
        $supplier->update($data);

        $this->audit('inventory.supplier.updated', $supplier, $before, $actorUserId);

        return $supplier;
    }

    public function archive(Supplier $supplier, int $actorUserId): void
    {
        $before = AuditDigests::snapshot($supplier);
        $supplier->update(['status' => Supplier::STATUS_ARCHIVED]);

        $this->audit('inventory.supplier.archived', $supplier, $before, $actorUserId);
    }

    public function reactivate(Supplier $supplier, int $actorUserId): void
    {
        $before = AuditDigests::snapshot($supplier);
        $supplier->update(['status' => Supplier::STATUS_ACTIVE]);

        $this->audit('inventory.supplier.reactivated', $supplier, $before, $actorUserId);
    }

    /** @param  array<string, mixed>|null  $before */
    private function audit(string $action, Supplier $supplier, ?array $before, int $actorUserId): void
    {
        AuditLog::create([
            'actor_id' => $actorUserId,
            'action' => $action,
            'subject_type' => Supplier::class,
            'subject_id' => $supplier->id,
            'before_hash' => $before !== null ? AuditDigests::of($before) : null,
            'after_hash' => AuditDigests::of($supplier),
            'details' => ['name' => $supplier->name, 'status' => $supplier->status],
        ]);
    }
}
