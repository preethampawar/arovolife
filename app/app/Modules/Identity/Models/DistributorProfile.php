<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DistributorProfile extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'distributor_id',
        'gender',
        'marital_status',
        'wedding_anniversary_date',
        'highest_education',
        'occupation',
        'mother_tongue',
        'additional_language_1',
        'additional_language_2',
    ];

    protected function casts(): array
    {
        return [
            'gender' => 'string',
            'marital_status' => 'string',
            'wedding_anniversary_date' => 'date',
            'highest_education' => 'string',
        ];
    }

    /**
     * Delete the optional wedding anniversary date — on termination of the
     * ADN (Privacy Policy §5). A no-op when none was given.
     */
    public static function eraseWeddingAnniversary(int $distributorId): void
    {
        self::query()
            ->where('distributor_id', $distributorId)
            ->whereNotNull('wedding_anniversary_date')
            ->update(['wedding_anniversary_date' => null]);
    }

    /**
     * @return BelongsTo<Distributor, $this>
     */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }
}
