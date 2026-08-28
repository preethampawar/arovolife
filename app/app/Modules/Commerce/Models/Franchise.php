<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Models;

use App\Modules\Compensation\Models\AreteCenter;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $operator_distributor_id
 * @property int|null $arete_center_id
 * @property string $code
 * @property string $status
 * @property string|null $address_line
 * @property string|null $pincode
 * @property string|null $district
 * @property string|null $state
 * @property string|null $notes
 * @property string|null $admin_notes
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $applied_at
 * @property Carbon|null $activated_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Franchise extends Model
{
    public const string STATUS_PENDING_APPROVAL = 'pending_approval';

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_REJECTED = 'rejected';

    public const string STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'operator_distributor_id',
        'arete_center_id',
        'code',
        'status',
        'address_line',
        'pincode',
        'district',
        'state',
        'notes',
        'admin_notes',
        'reviewed_by',
        'reviewed_at',
        'applied_at',
        'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'applied_at' => 'datetime',
            'activated_at' => 'datetime',
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
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
