<?php

declare(strict_types=1);

namespace App\Modules\Returns\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BuybackDecision extends Model
{
    protected $table = 'buyback_decisions';

    protected $fillable = [
        'return_request_id', 'decision_matrix_version',
        'refund_base_paise', 'gst_adjustment_paise', 'admin_deduction_paise', 'net_refund_paise',
        'approved_by_user_id', 'approved_at',
    ];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }
}
