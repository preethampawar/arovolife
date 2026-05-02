<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Ticket extends Model
{
    protected $table = 'tickets';

    public const STATUS_OPEN = 'open';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'ticket_no', 'subject', 'body', 'category', 'severity', 'status',
        'customer_id', 'distributor_id', 'reporter_email', 'reporter_phone',
        'order_id', 'assigned_to_user_id',
        'sla_first_response_at', 'sla_resolution_at',
        'first_response_at', 'resolved_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'sla_first_response_at' => 'datetime',
            'sla_resolution_at' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(TicketEvent::class);
    }

    public function isSlaBreached(): bool
    {
        if (in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CLOSED], true)) {
            return false;
        }

        return $this->sla_resolution_at->isPast();
    }
}
