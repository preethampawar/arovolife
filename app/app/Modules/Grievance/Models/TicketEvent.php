<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Models;

use App\Modules\Grievance\Enums\TicketEventKind;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry in a ticket's append-only timeline.
 *
 * Rows are never updated or deleted: the quarterly internal audit (policy §6.6)
 * and any regulator request read this table as the record of what the company
 * did and when.
 *
 * @property int $id
 * @property int $ticket_id
 * @property TicketEventKind $kind
 * @property int|null $actor_user_id
 * @property string|null $from_value
 * @property string|null $to_value
 * @property string|null $note
 * @property Carbon $created_at
 * @property-read Ticket $ticket
 * @property-read User|null $actor
 */
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
        return [
            'kind' => TicketEventKind::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
