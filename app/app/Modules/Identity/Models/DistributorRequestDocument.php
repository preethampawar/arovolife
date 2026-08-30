<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One supporting document on a distributor request. Stored on the private
 * `distributor-requests` disk under the request id; admin viewing is audited.
 *
 * @property int $id
 * @property int $request_id
 * @property string $type
 * @property string $original_name
 * @property string $object_storage_key
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $checksum_sha256
 * @property-read DistributorRequest $request
 */
final class DistributorRequestDocument extends Model
{
    protected $fillable = [
        'request_id', 'type', 'original_name', 'object_storage_key',
        'mime_type', 'size_bytes', 'checksum_sha256',
    ];

    protected function casts(): array
    {
        return ['size_bytes' => 'int'];
    }

    /** @return BelongsTo<DistributorRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(DistributorRequest::class, 'request_id');
    }

    public function typeLabel(): string
    {
        $requestType = $this->request?->type;

        return DistributorRequest::TYPES[$requestType]['documents'][$this->type]['label']
            ?? ucwords(str_replace('_', ' ', $this->type));
    }
}
