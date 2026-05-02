<?php

declare(strict_types=1);

namespace App\Modules\Consent\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Consent extends Model
{
    public $timestamps = false;

    protected $table = 'consents';

    protected $fillable = [
        'distributor_id',
        'document_type',
        'document_version',
        'doc_hash_sha256',
        'accepted_at',
        'ip',
        'user_agent',
    ];

    protected $hidden = ['doc_hash_sha256'];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }
}
