<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Models;

use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $distributor_id
 * @property int $from_placement_parent_id
 * @property int $to_placement_parent_id
 * @property string|null $chosen_side
 * @property Carbon $requested_at
 * @property Carbon|null $approved_at
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string $status
 * @property string|null $reason
 * @property string|null $decision_note
 * @property-read Distributor $distributor
 * @property-read Distributor $fromPlacementParent
 * @property-read Distributor $toPlacementParent
 * @property-read User|null $reviewer
 */
final class LineChangeRequest extends Model
{
    public $timestamps = false;

    protected $table = 'line_change_requests';

    protected $fillable = [
        'distributor_id',
        'from_placement_parent_id',
        'to_placement_parent_id',
        'chosen_side',
        'requested_at',
        'approved_at',
        'reviewed_by',
        'reviewed_at',
        'status',
        'reason',
        'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'distributor_id');
    }

    public function fromPlacementParent(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'from_placement_parent_id');
    }

    public function toPlacementParent(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'to_placement_parent_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
