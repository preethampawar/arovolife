<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Warehouses carry no stock effect of their own — CRUD plus the small set of
 * lookups other inventory services need (the default hub, which locations may
 * fulfil an order, whether a code is safe to move stock into or out of).
 */
final class WarehouseService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data, int $actorUserId): Warehouse
    {
        $warehouse = Warehouse::create($data);

        $this->audit('inventory.warehouse.created', $warehouse, null, $actorUserId);

        return $warehouse;
    }

    /** @param  array<string, mixed>  $data */
    public function update(Warehouse $warehouse, array $data, int $actorUserId): Warehouse
    {
        $before = AuditDigests::snapshot($warehouse);
        $warehouse->update($data);

        $this->audit('inventory.warehouse.updated', $warehouse, $before, $actorUserId);

        return $warehouse;
    }

    public function archive(Warehouse $warehouse, int $actorUserId): void
    {
        if ($warehouse->code === Warehouse::DEFAULT_CODE) {
            throw new RuntimeException('The default warehouse cannot be archived.');
        }

        $before = AuditDigests::snapshot($warehouse);
        $warehouse->update(['status' => Warehouse::STATUS_ARCHIVED]);

        $this->audit('inventory.warehouse.archived', $warehouse, $before, $actorUserId);
    }

    public function reactivate(Warehouse $warehouse, int $actorUserId): void
    {
        $before = AuditDigests::snapshot($warehouse);
        $warehouse->update(['status' => Warehouse::STATUS_ACTIVE]);

        $this->audit('inventory.warehouse.reactivated', $warehouse, $before, $actorUserId);
    }

    public function default(): Warehouse
    {
        return Warehouse::query()->where('code', Warehouse::DEFAULT_CODE)->firstOrFail();
    }

    /** @return Collection<int, Warehouse> */
    public function fulfilling(): Collection
    {
        return Warehouse::query()->fulfilling()->orderBy('name')->get();
    }

    /** @throws RuntimeException when the warehouse does not exist or is not active */
    public function assertActive(string $code): Warehouse
    {
        $warehouse = Warehouse::query()->where('code', $code)->first();

        if ($warehouse === null) {
            throw new RuntimeException("Unknown warehouse [{$code}].");
        }

        if (! $warehouse->isActive()) {
            throw new RuntimeException("Warehouse [{$code}] is archived.");
        }

        return $warehouse;
    }

    /** @param  array<string, mixed>|null  $before */
    private function audit(string $action, Warehouse $warehouse, ?array $before, int $actorUserId): void
    {
        AuditLog::create([
            'actor_id' => $actorUserId,
            'action' => $action,
            'subject_type' => Warehouse::class,
            'subject_id' => $warehouse->id,
            'before_hash' => $before !== null ? AuditDigests::of($before) : null,
            'after_hash' => AuditDigests::of($warehouse),
            'details' => ['code' => $warehouse->code, 'status' => $warehouse->status],
        ]);
    }
}
