<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One uploaded premises document on an ADC application (§A4 of the spec).
 *
 * @property int $id
 * @property int $application_id
 * @property string $type
 * @property string $original_name
 * @property string $object_storage_key
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $checksum_sha256
 * @property-read AreteCenterApplication $application
 */
final class AreteCenterApplicationDocument extends Model
{
    public const string TYPE_OWNERSHIP_OR_RENT = 'ownership_or_rent_proof';

    public const string TYPE_PREMISES_PHOTO = 'premises_photo';

    public const string TYPE_ADDRESS_PROOF = 'address_proof';

    public const string TYPE_TRADE_LICENCE = 'trade_licence';

    /** type => [label, required, max files] */
    public const array TYPES = [
        self::TYPE_OWNERSHIP_OR_RENT => ['label' => 'Premises ownership proof or rent / lease agreement', 'required' => true, 'max' => 1],
        self::TYPE_PREMISES_PHOTO => ['label' => 'Photos of the premises (exterior and interior)', 'required' => true, 'max' => 5],
        self::TYPE_ADDRESS_PROOF => ['label' => 'Address proof of the premises (electricity bill / property-tax receipt)', 'required' => true, 'max' => 1],
        // Optional: some local authorities require a Shops & Establishments
        // certificate even for non-retail premises. Its presence is not a
        // suggestion that the centre trades — the declarations say it may not.
        self::TYPE_TRADE_LICENCE => ['label' => 'Shops & Establishments registration certificate, if your local authority requires one for non-retail premises', 'required' => false, 'max' => 1],
    ];

    protected $fillable = [
        'application_id', 'type', 'original_name', 'object_storage_key',
        'mime_type', 'size_bytes', 'checksum_sha256',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'int',
        ];
    }

    /** @return BelongsTo<AreteCenterApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(AreteCenterApplication::class, 'application_id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type]['label'] ?? ucwords(str_replace('_', ' ', $this->type));
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }
}
