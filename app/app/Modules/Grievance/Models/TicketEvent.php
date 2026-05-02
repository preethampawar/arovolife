<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TicketEvent extends Model
{
    protected $table = 'ticket_events';

    public $timestamps = false;

    protected $fillable = [
        'ticket_id', 'kind', 'actor_user_id',
        'from_value', 'to_value', 'note', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
