<?php

declare(strict_types=1);

namespace App\Modules\Public\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $phone_e164
 * @property string $address
 * @property string $purpose
 * @property string $message
 * @property string|null $reason
 * @property string $ip
 * @property string|null $user_agent
 * @property Carbon|null $privacy_consent_at
 * @property Carbon|null $handled_at
 * @property int|null $handled_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class ContactInquiry extends Model
{
    protected $table = 'contact_inquiries';

    protected $fillable = [
        'name',
        'email',
        'phone_e164',
        'address',
        'purpose',
        'message',
        'reason',
        'ip',
        'user_agent',
        'privacy_consent_at',
        'handled_at',
        'handled_by',
    ];

    protected function casts(): array
    {
        return [
            'privacy_consent_at' => 'datetime',
            'handled_at' => 'datetime',
        ];
    }
}
