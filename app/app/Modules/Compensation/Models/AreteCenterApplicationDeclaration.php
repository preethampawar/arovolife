<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One accepted declaration on an ADC application (§A5 of the spec).
 *
 * @property int $id
 * @property int $application_id
 * @property string $declaration_key
 * @property string $version
 * @property Carbon $accepted_at
 * @property string|null $ip
 */
final class AreteCenterApplicationDeclaration extends Model
{
    protected $fillable = [
        'application_id', 'declaration_key', 'version', 'accepted_at', 'ip',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AreteCenterApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(AreteCenterApplication::class, 'application_id');
    }
}
