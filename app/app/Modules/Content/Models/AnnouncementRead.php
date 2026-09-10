<?php

declare(strict_types=1);

namespace App\Modules\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row per person who has opened an announcement.
 *
 * @property int $id
 * @property int $announcement_id
 * @property int $user_id
 * @property Carbon $read_at
 */
final class AnnouncementRead extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'announcement_id',
        'user_id',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }
}
