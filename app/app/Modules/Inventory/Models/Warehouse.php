<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $type
 * @property bool $fulfils_orders
 * @property string $status
 */
final class Warehouse extends Model
{
    public const DEFAULT_CODE = 'DEFAULT';

    public const TYPE_HUB = 'hub';

    public const TYPE_WAREHOUSE = 'warehouse';

    /**
     * Stored as 'franchise' for historical reasons — the franchise programme was
     * dropped on 2026-09-17 and the label everywhere it is shown is now
     * "Collection point". Renaming the stored value would need a data migration
     * against rows that already carry it, for a string nobody sees.
     */
    public const TYPE_FRANCHISE = 'franchise';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected $table = 'warehouses';

    protected $fillable = [
        'code', 'name', 'type', 'line1', 'city', 'state', 'pincode',
        'contact_phone_e164', 'fulfils_orders', 'status',
    ];

    protected function casts(): array
    {
        return [
            'fulfils_orders' => 'bool',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Warehouses an order may be picked from.
     *
     * @param  Builder<self>  $query
     */
    public function scopeFulfilling(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE)->where('fulfils_orders', true);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
