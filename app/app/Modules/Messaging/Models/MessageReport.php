<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $message_id
 * @property int $reported_by_user_id
 * @property string $category
 * @property string|null $reason
 * @property string $status
 * @property int|null $reviewed_by_user_id
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Message $message
 * @property-read User $reporter
 */
final class MessageReport extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_ACTIONED = 'actioned';

    /**
     * Why a message was reported. 'income_claim' leads because it is the one
     * category that carries a statutory consequence (DSR Rule 5(1)(d)).
     *
     * @var array<string, string>
     */
    public const CATEGORIES = [
        'income_claim' => 'Promised or implied earnings',
        'pressure' => 'Pressure to buy or to recruit',
        'harassment' => 'Harassment or abuse',
        'personal_data' => 'Asked for PAN, Aadhaar, bank or password details',
        'spam' => 'Spam or off-platform selling',
        'other' => 'Something else',
    ];

    protected $fillable = [
        'message_id',
        'reported_by_user_id',
        'category',
        'reason',
        'status',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_note',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
