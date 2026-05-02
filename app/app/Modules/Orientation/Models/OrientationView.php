<?php

declare(strict_types=1);

namespace App\Modules\Orientation\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OrientationView extends Model
{
    public $timestamps = false;

    protected $table = 'orientation_views';

    protected $fillable = [
        'distributor_id',
        'video_id',
        'started_at',
        'completed_at',
        'watch_percent',
        'quiz_passed_at',
        'playback_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'watch_percent' => 'integer',
            'quiz_passed_at' => 'datetime',
        ];
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }
}
