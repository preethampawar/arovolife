<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $distributor_id
 * @property Carbon $opened_at
 * @property Carbon|null $cancelled_at
 * @property string|null $refund_trigger_event_id
 * @property Carbon|null $reminder_d20_sent_at
 * @property Carbon|null $reminder_d7_sent_at
 * @property Carbon|null $reminder_d1_sent_at
 * @property-read Distributor $distributor
 */
final class CoolingOffEvent extends Model
{
    public $timestamps = false;

    protected $table = 'cooling_off_events';

    protected $fillable = [
        'distributor_id',
        'opened_at',
        'cancelled_at',
        'refund_trigger_event_id',
        'reminder_d20_sent_at',
        'reminder_d7_sent_at',
        'reminder_d1_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reminder_d20_sent_at' => 'datetime',
            'reminder_d7_sent_at' => 'datetime',
            'reminder_d1_sent_at' => 'datetime',
        ];
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }
}
