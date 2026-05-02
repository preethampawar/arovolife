<?php

declare(strict_types=1);

namespace App\Modules\Returns\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ReturnInspection extends Model
{
    protected $table = 'return_inspections';

    protected $fillable = [
        'return_request_id', 'received_at', 'condition',
        'inspector_user_id', 'notes',
    ];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }
}
