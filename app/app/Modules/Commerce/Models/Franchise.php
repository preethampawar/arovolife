<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Models;

use App\Modules\Compensation\Models\AreteCenter;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company-owned pickup / fulfilment point operated by a distributor.
 *
 * Not a shop and not a tree position. Stock is company consignment, sales stay
 * online and ADN-attributed, and the franchise code is deliberately unlike an
 * ADN so the two can never be confused. Operating one does not change the
 * operator's place in the Genos or what their downline earns.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int|null $operator_distributor_id
 * @property bool $is_company_primary
 * @property string|null $address_line
 * @property string|null $pincode
 * @property string|null $district
 * @property string|null $state
 * @property string $status
 * @property int|null $commission_rate_bp
 * @property int|null $arete_center_id
 * @property \Illuminate\Support\Carbon|null $applied_at
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property int|null $approved_by_user_id
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read Distributor|null $operator
 * @property-read AreteCenter|null $areteCenter
 * @property-read User|null $approvedBy
 */
final class Franchise extends Model
{
    protected $table = 'franchises';

    public const STATUS_PENDING = 'pending_approval';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'code', 'name', 'operator_distributor_id', 'is_company_primary',
        'address_line', 'pincode', 'district', 'state',
        'status', 'commission_rate_bp', 'arete_center_id',
        'applied_at', 'approved_at', 'approved_by_user_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_company_primary' => 'boolean',
            'commission_rate_bp' => 'integer',
            'applied_at' => 'date',
            'approved_at' => 'date',
        ];
    }

    /** @return BelongsTo<Distributor, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'operator_distributor_id');
    }

    /** @return BelongsTo<AreteCenter, $this> */
    public function areteCenter(): BelongsTo
    {
        return $this->belongsTo(AreteCenter::class, 'arete_center_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @param  Builder<Franchise>  $query
     * @return Builder<Franchise>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Franchises a buyer may choose at checkout.
     *
     * Suspended and closed franchises are excluded: a buyer must never be
     * offered a collection point that cannot hand them their order.
     *
     * @param  Builder<Franchise>  $query
     * @return Builder<Franchise>
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->active()->orderBy('state')->orderBy('district')->orderBy('name');
    }

    /**
     * Whether this franchise earns a commission at all.
     *
     * The company's own primary franchise has no operator, so there is nobody
     * to pay — and paying the company a commission out of its own revenue
     * would be a bookkeeping fiction.
     */
    public function earnsCommission(): bool
    {
        return $this->operator_distributor_id !== null
            && ! $this->is_company_primary
            && $this->status === self::STATUS_ACTIVE;
    }

    public function displayLocation(): string
    {
        return implode(', ', array_filter([$this->district, $this->state, $this->pincode]));
    }
}
