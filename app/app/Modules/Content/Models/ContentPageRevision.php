<?php

declare(strict_types=1);

namespace App\Modules\Content\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A proposed change to the Compensation Plan page, waiting for a second
 * person's decision (R-125).
 *
 * Deliberately no `$touches`: creating, rejecting or withdrawing a proposal
 * must not move the page's updated_at, which is the version every plan
 * consent records.
 *
 * @property int $id
 * @property int $content_page_id
 * @property string $base_digest
 * @property string $title
 * @property string|null $meta_description
 * @property string|null $body
 * @property string $status
 * @property string $state
 * @property string $note
 * @property int|null $requested_by
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class ContentPageRevision extends Model
{
    public const STATE_PENDING = 'pending';

    public const STATE_APPROVED = 'approved';

    public const STATE_REJECTED = 'rejected';

    public const STATE_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'content_page_id', 'base_digest',
        'title', 'meta_description', 'body', 'status',
        'state', 'note', 'requested_by', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'content_page_id' => 'integer',
            'requested_by' => 'integer',
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->state === self::STATE_PENDING;
    }

    /** @return BelongsTo<ContentPage, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(ContentPage::class, 'content_page_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
