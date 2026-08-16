<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evidence attached to a grievance (policy §3.1).
 *
 * Always on the private disk and always streamed through a controller that
 * re-checks ownership — a grievance attachment is very often a screenshot of
 * a bank statement or a KYC document.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int|null $uploaded_by_user_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read Ticket $ticket
 * @property-read User|null $uploadedBy
 */
final class TicketAttachment extends Model
{
    protected $table = 'ticket_attachments';

    public $timestamps = false;

    protected $fillable = [
        'ticket_id', 'uploaded_by_user_id', 'disk', 'path',
        'original_name', 'mime_type', 'size_bytes', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function humanSize(): string
    {
        $kb = $this->size_bytes / 1024;

        return $kb < 1024
            ? number_format($kb, 0).' KB'
            : number_format($kb / 1024, 1).' MB';
    }
}
