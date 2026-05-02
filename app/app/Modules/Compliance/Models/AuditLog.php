<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'audit_log';

    protected $fillable = [
        'actor_id',
        'action',
        'subject_type',
        'subject_id',
        'before_hash',
        'after_hash',
        'details',
        'ip',
    ];

    protected $hidden = [
        'before_hash',
        'after_hash',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'details' => 'array',
            'subject_id' => 'integer',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
